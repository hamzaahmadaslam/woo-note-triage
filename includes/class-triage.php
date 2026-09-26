<?php
/**
 * Queues new orders with a customer note, asks Jev about the note in the background, and records the result.
 *
 * @package Woo_Note_Triage
 */

namespace Woo_Note_Triage;

defined( 'ABSPATH' ) || exit;

/**
 * The checkout hooks, the Action Scheduler job and the writes to the order.
 *
 * The checkout request only queues a job; it never waits for TypeSafe. The job asks the two questions, then
 * writes the order meta, and adds a private order note when the category confidence reaches the threshold.
 * A note that was already triaged is never sent again.
 */
final class Triage {

	/** The Action Scheduler hook. */
	public const HOOK = 'woo_note_triage_run';

	/** The Action Scheduler group. */
	public const GROUP = 'woo-note-triage';

	/** Attempts per order before a retryable error (rate limit, overload, timeout) is recorded as an error. */
	public const MAX_ATTEMPTS = 3;

	/** Minutes to wait before the next attempt, multiplied by the attempt number. */
	public const RETRY_MINUTES = 5;

	/**
	 * Makes the Jev_Client for a job.
	 *
	 * @var \Closure
	 */
	private $client_factory;

	/**
	 * Creates the service.
	 *
	 * @param callable|null $client_factory Returns a Jev_Client. The default uses the saved key and model and
	 *                                      WordPress's HTTP API; tests pass a client with fixture answers.
	 */
	public function __construct( ?callable $client_factory = null ) {
		$this->client_factory = null !== $client_factory
			? \Closure::fromCallable( $client_factory )
			: static function (): Jev_Client {
				// In a background job: one quick retry, then the job is scheduled again a few minutes later.
				return new Jev_Client(
					Settings::api_key(),
					Settings::model(),
					array( WP_Transport::class, 'send' ),
					array(
						'timeout'  => 10,
						'retries'  => 1,
						'max_wait' => 5,
					)
				);
			};
	}

	/**
	 * Hooks into both checkouts and Action Scheduler. The checkout block's action is listed in WooCommerce's
	 * hook alternatives: https://developer.woocommerce.com/docs/block-development/reference/hooks/hook-alternatives/
	 * Orders created in the admin, through the REST API or by other plugins pass through neither checkout.
	 */
	public function register(): void {
		// Classic (shortcode) checkout: fires after the order is created, before payment.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_classic_checkout' ), 20, 3 );
		// Checkout block (Store API): the classic hook does not fire there. Same point: order ready, before payment.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_block_checkout' ), 20, 1 );
		add_action( self::HOOK, array( $this, 'run_job' ), 10, 2 );
	}

	/**
	 * Classic checkout.
	 *
	 * @param mixed $order_id    Order ID.
	 * @param mixed $posted_data Checkout fields (not used).
	 * @param mixed $order       The order, when WooCommerce passes it.
	 */
	public function on_classic_checkout( $order_id, $posted_data = array(), $order = null ): void {
		unset( $posted_data );
		$order = $order instanceof \WC_Order ? $order : wc_get_order( is_numeric( $order_id ) ? (int) $order_id : 0 );
		if ( $order instanceof \WC_Order ) {
			$this->maybe_queue( $order );
		}
	}

	/**
	 * Checkout block.
	 *
	 * @param mixed $order The order.
	 */
	public function on_block_checkout( $order ): void {
		if ( $order instanceof \WC_Order ) {
			$this->maybe_queue( $order );
		}
	}

	/**
	 * Queues a job for the order when triage is on, a key is set, the order has a note that was not triaged
	 * before, and no job for the order is waiting already. Returns whether a job was queued.
	 *
	 * @param \WC_Order $order The order.
	 */
	public function maybe_queue( \WC_Order $order ): bool {
		if ( ! Settings::get()['enabled'] ) {
			return false;
		}
		$raw = (string) $order->get_customer_note();
		if ( '' === Note_Text::clean( $raw ) || self::is_done( $order, $raw ) ) {
			return false;
		}
		// The key option is not autoloaded, so it is read only for orders that have a note.
		if ( '' === Settings::api_key() || ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}
		$args = array( 'order_id' => (int) $order->get_id() );
		// as_has_scheduled_action() matches the arguments. The $unique flag of as_enqueue_async_action() compares
		// only the hook and group, so it would allow a single order in the queue at a time.
		if ( as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			return false;
		}
		return as_enqueue_async_action( self::HOOK, $args, self::GROUP ) > 0;
	}

	/**
	 * The job: asks Jev about the order's note and records the result.
	 *
	 * @param mixed $order_id Order ID.
	 * @param mixed $attempt  1 for the first attempt.
	 */
	public function run_job( $order_id, $attempt = 1 ): void {
		$settings = Settings::get();
		// Switched off after the job was queued: send nothing.
		if ( ! $settings['enabled'] ) {
			return;
		}
		$order = wc_get_order( is_numeric( $order_id ) ? (int) $order_id : 0 );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$raw  = (string) $order->get_customer_note();
		$note = Note_Text::clean( $raw );
		if ( '' === $note || self::is_done( $order, $raw ) ) {
			return;
		}
		$attempt = max( 1, is_numeric( $attempt ) ? (int) $attempt : 1 );

		try {
			$client   = ( $this->client_factory )();
			$response = $client->ask( $note, Questions::questions() );
		} catch ( Jev_Error $error ) {
			$this->handle_error( $order, $error, $attempt );
			return;
		}

		$this->apply( $order, $raw, Decision::from_response( $response, $settings['threshold'] ) );
	}

	/**
	 * Records a result on the order: the meta always, the category and a private order note only when triaged.
	 *
	 * @param \WC_Order $order  The order.
	 * @param string    $raw    The customer note as stored, for the fingerprint.
	 * @param array     $result From Decision::from_response().
	 */
	public function apply( \WC_Order $order, string $raw, array $result ): void {
		$triaged = Decision::TRIAGED === $result['decision'];

		$order->update_meta_data( Meta::HASH, Note_Text::hash( $raw ) );
		$order->update_meta_data( Meta::STATUS, $result['decision'] );
		$order->update_meta_data( Meta::RESULT, Questions::json( $result ) );
		$order->delete_meta_data( Meta::ERROR );
		if ( null !== $result['urgency'] ) {
			$order->update_meta_data( Meta::URGENCY, Decision::number( (float) $result['urgency'] ) );
		} else {
			$order->delete_meta_data( Meta::URGENCY );
		}
		if ( $triaged ) {
			$order->update_meta_data( Meta::CATEGORY, (string) $result['category'] );
		} else {
			$order->delete_meta_data( Meta::CATEGORY );
		}
		$order->save();

		if ( $triaged ) {
			$order->add_order_note( Decision::order_note( $result ), 0, false );
		}

		/**
		 * Fires after a customer note was triaged, for your own follow-up (a message to a channel, a task).
		 *
		 * @param \WC_Order $order  The order.
		 * @param array     $result decision ("triaged" or "review"), category, category_confidence,
		 *                          category_probabilities, urgency (0 to 3), urgency_label, reasons, model.
		 */
		do_action( 'woo_note_triage_result', $order, $result );
	}

	/**
	 * Whether this exact note was already triaged (or put up for review) on the order.
	 *
	 * @param \WC_Order $order The order.
	 * @param string    $raw   The customer note as stored.
	 */
	public static function is_done( \WC_Order $order, string $raw ): bool {
		return Note_Text::hash( $raw ) === (string) $order->get_meta( Meta::HASH )
			&& in_array( (string) $order->get_meta( Meta::STATUS ), array( Decision::TRIAGED, Decision::REVIEW ), true );
	}

	/**
	 * Tries again later for a retryable error. Otherwise, or after the last attempt, records the error on the
	 * order (the orders list shows "Not triaged" with the message) and in the WooCommerce log, and removes the
	 * category of any earlier answer.
	 *
	 * @param \WC_Order $order   The order.
	 * @param Jev_Error $error   What went wrong.
	 * @param int       $attempt The attempt that failed.
	 */
	private function handle_error( \WC_Order $order, Jev_Error $error, int $attempt ): void {
		if ( $error->retryable() && $attempt < self::MAX_ATTEMPTS && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + self::RETRY_MINUTES * 60 * $attempt,
				self::HOOK,
				array(
					'order_id' => (int) $order->get_id(),
					'attempt'  => $attempt + 1,
				),
				self::GROUP
			);
			return;
		}
		$order->update_meta_data( Meta::STATUS, Decision::ERROR );
		$order->update_meta_data( Meta::ERROR, $error->getMessage() );
		// Left in place, an earlier answer's category would keep the order under that category in the orders list
		// filter while its column says "Not triaged".
		$order->delete_meta_data( Meta::CATEGORY );
		$order->save();
		if ( function_exists( 'wc_get_logger' ) ) {
			// The order number and the message only: never the note or the key.
			wc_get_logger()->warning( sprintf( 'Order %d: %s', (int) $order->get_id(), $error->getMessage() ), array( 'source' => 'woo-note-triage' ) );
		}
	}
}

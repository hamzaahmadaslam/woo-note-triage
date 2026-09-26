<?php
/**
 * The WP-CLI command: wp woo-note-triage backfill.
 *
 * @package Woo_Note_Triage
 */

namespace Woo_Note_Triage;

defined( 'ABSPATH' ) || exit;

/**
 * Triages the notes of orders placed before the plugin was active, or again with other settings.
 */
final class CLI {

	/** Orders read per page while looking for notes. */
	private const PAGE_SIZE = 100;

	/**
	 * Records results on orders.
	 *
	 * @var Triage
	 */
	private $triage;

	/**
	 * Makes the Jev_Client: function ( string $key, string $model ): Jev_Client.
	 *
	 * @var \Closure
	 */
	private $client_factory;

	/**
	 * Creates the command.
	 *
	 * @param Triage        $triage         Records results the same way as for new orders.
	 * @param callable|null $client_factory function ( string $key, string $model ): Jev_Client. The default uses
	 *                                      WordPress's HTTP API; tests pass a client with fixture answers.
	 */
	public function __construct( Triage $triage, ?callable $client_factory = null ) {
		$this->triage         = $triage;
		$this->client_factory = null !== $client_factory
			? \Closure::fromCallable( $client_factory )
			: static function ( string $key, string $model ): Jev_Client {
				// On the command line there is no request time limit: up to three retries with longer waits.
				return new Jev_Client(
					$key,
					$model,
					array( WP_Transport::class, 'send' ),
					array(
						'timeout'  => 10,
						'retries'  => 3,
						'max_wait' => 10,
					)
				);
			};
	}

	/**
	 * Triages the customer notes of recent orders.
	 *
	 * Each order with a customer note is one request to TypeSafe, with the note as the state and two fixed
	 * questions. Results are recorded on the orders as for new orders: order meta always, and a private order
	 * note with the category when the category confidence reaches the threshold. Orders whose note was
	 * triaged before are skipped unless you pass --force.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Look at orders created in the last this many days.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--dry-run]
	 * : Print the questions, the orders that would be sent and a token estimate. Nothing is sent, no order changes, and no key is needed.
	 *
	 * [--threshold=<confidence>]
	 * : Category confidence from 0 to 1 below which an order goes to review. Defaults to the value on the settings page.
	 *
	 * [--limit=<number>]
	 * : Send at most this many orders, newest first.
	 *
	 * [--force]
	 * : Ask again about orders whose note was already triaged.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # See what would be sent for the last 30 days, without sending anything
	 *     $ wp woo-note-triage backfill --days=30 --dry-run
	 *
	 *     # Triage the last week and act only on very confident answers
	 *     $ wp woo-note-triage backfill --days=7 --threshold=0.9
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Options.
	 */
	public function backfill( $args, $assoc_args ): void {
		unset( $args );
		$assoc_args = is_array( $assoc_args ) ? $assoc_args : array();

		$days = $assoc_args['days'] ?? '30';
		if ( ! self::is_count( $days ) || (int) $days > 3650 ) {
			\WP_CLI::error( '--days must be a whole number from 1 to 3650.' );
			return;
		}
		$limit = 0;
		if ( isset( $assoc_args['limit'] ) ) {
			if ( ! self::is_count( $assoc_args['limit'] ) ) {
				\WP_CLI::error( '--limit must be a whole number of 1 or more.' );
				return;
			}
			$limit = (int) $assoc_args['limit'];
		}
		$threshold = Settings::get()['threshold'];
		if ( isset( $assoc_args['threshold'] ) ) {
			if ( ! Decision::is_threshold( $assoc_args['threshold'] ) ) {
				\WP_CLI::error( '--threshold must be a number from 0 to 1.' );
				return;
			}
			$threshold = (float) $assoc_args['threshold'];
		}
		$format = (string) ( $assoc_args['format'] ?? 'text' );
		if ( 'text' !== $format && 'json' !== $format ) {
			\WP_CLI::error( '--format must be text or json.' );
			return;
		}
		$dry_run = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$force   = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$key     = Settings::api_key();
		if ( ! $dry_run && '' === $key ) {
			\WP_CLI::error( 'No TypeSafe API key. Save one under WooCommerce > Note triage, or define TYPESAFE_API_KEY in wp-config.php. --dry-run works without a key.' );
			return;
		}

		$found = $this->collect( time() - (int) $days * DAY_IN_SECONDS, $limit, $force );
		$run   = array(
			'version'       => Plugin::VERSION,
			'days'          => (int) $days,
			'threshold'     => $threshold,
			'model'         => Settings::model(),
			'orders'        => $found['orders'],
			'with_note'     => $found['with_note'],
			'skipped'       => $found['skipped'],
			'limit'         => $limit,
			'limit_reached' => $found['limit_reached'],
			'requests'      => 0,
			'input_tokens'  => 0,
		);

		if ( $dry_run ) {
			\WP_CLI::line( rtrim( 'json' === $format ? Report::dry_run_json( $run, $found['items'] ) : Report::dry_run_text( $run, $found['items'] ) ) );
			return;
		}

		$client = ( $this->client_factory )( $key, $run['model'] );
		if ( 'text' === $format && array() !== $found['items'] ) {
			\WP_CLI::log( sprintf( 'Asking Jev about %d %s.', count( $found['items'] ), 1 === count( $found['items'] ) ? 'note' : 'notes' ) );
		}

		$rows     = array();
		$answered = '';
		foreach ( $found['items'] as $item ) {
			try {
				$response = $client->ask( $item['note'], Questions::questions() );
			} catch ( Jev_Error $error ) {
				$run['model'] = '' !== $answered ? $answered : $run['model'];
				if ( array() !== $rows ) {
					\WP_CLI::line( rtrim( 'json' === $format ? Report::json( $run, $rows ) : Report::text( $run, $rows ) ) );
				}
				\WP_CLI::error( sprintf( 'Stopped at order #%d. %s Orders already triaged are skipped when you run the command again.', $item['order_id'], $error->getMessage() ) );
				return;
			}
			$result = Decision::from_response( $response, $threshold );
			++$run['requests'];
			$run['input_tokens'] += $result['input_tokens'];
			$answered             = '' !== $result['model'] ? $result['model'] : $answered;

			$order = wc_get_order( $item['order_id'] );
			if ( $order instanceof \WC_Order ) {
				$this->triage->apply( $order, $item['raw'], $result );
			}
			$rows[] = array(
				'order_id' => $item['order_id'],
				'date'     => $item['date'],
				'result'   => $result,
			);
		}

		$run['model'] = '' !== $answered ? $answered : $run['model'];
		\WP_CLI::line( rtrim( 'json' === $format ? Report::json( $run, $rows ) : Report::text( $run, $rows ) ) );
	}

	/**
	 * Finds the orders to send, newest first.
	 *
	 * @param int  $since Unix time; orders created after it.
	 * @param int  $limit At most this many orders to send; 0 for no limit.
	 * @param bool $force Include orders whose note was triaged before.
	 * @return array{items: array<int, array{order_id: int, date: string, raw: string, note: string}>, orders: int, with_note: int, skipped: int, limit_reached: bool}
	 */
	private function collect( int $since, int $limit, bool $force ): array {
		$found = array(
			'items'         => array(),
			'orders'        => 0,
			'with_note'     => 0,
			'skipped'       => 0,
			'limit_reached' => false,
		);
		for ( $page = 1; ; $page++ ) {
			$ids = wc_get_orders(
				array(
					'type'         => 'shop_order',
					'date_created' => '>' . $since,
					'orderby'      => 'date',
					'order'        => 'DESC',
					'limit'        => self::PAGE_SIZE,
					'paged'        => $page,
					'return'       => 'ids',
				)
			);
			if ( ! is_array( $ids ) || array() === $ids ) {
				break;
			}
			foreach ( $ids as $id ) {
				$order = wc_get_order( $id );
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}
				++$found['orders'];
				$raw  = (string) $order->get_customer_note();
				$note = Note_Text::clean( $raw );
				if ( '' === $note ) {
					continue;
				}
				++$found['with_note'];
				if ( ! $force && Triage::is_done( $order, $raw ) ) {
					++$found['skipped'];
					continue;
				}
				$created            = $order->get_date_created();
				$found['items'][] = array(
					'order_id' => (int) $order->get_id(),
					'date'     => $created ? gmdate( 'c', $created->getTimestamp() ) : '',
					'raw'      => $raw,
					'note'     => $note,
				);
				if ( $limit > 0 && count( $found['items'] ) >= $limit ) {
					$found['limit_reached'] = true;
					break 2;
				}
			}
			if ( count( $ids ) < self::PAGE_SIZE ) {
				break;
			}
			// Long runs: drop cached orders from memory between pages.
			if ( function_exists( '\WP_CLI\Utils\wp_clear_object_cache' ) ) {
				\WP_CLI\Utils\wp_clear_object_cache();
			}
		}
		return $found;
	}

	/**
	 * Whether a value typed on the command line is a whole number of 1 or more.
	 *
	 * @param mixed $value The value as typed.
	 */
	private static function is_count( $value ): bool {
		return 1 === preg_match( '/^[1-9]\d{0,8}$/', trim( (string) $value ) );
	}
}

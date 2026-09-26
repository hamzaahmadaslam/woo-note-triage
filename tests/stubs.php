<?php
/**
 * Stand-ins for the few WordPress, WooCommerce, Action Scheduler and WP-CLI functions the plugin calls, so the
 * tests can run the checkout hooks, the background job, the orders list filter, the settings page and the
 * backfill command without WordPress. They record what the code did.
 *
 * The HTTP functions throw: a test that tries to reach the network fails.
 *
 * @package Woo_Note_Triage
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO, Squiz.Commenting.FunctionComment.Missing, Generic.Files.OneObjectStructurePerFile

namespace {

	define( 'DAY_IN_SECONDS', 86400 );

	/**
	 * Clears the recorded state before each test.
	 */
	function reset_wordpress(): void {
		$GLOBALS['wnt_options'] = array();
		$GLOBALS['wnt_orders']  = array();
		$GLOBALS['wnt_actions'] = array();
		$GLOBALS['wnt_fired']   = array();
		$GLOBALS['wnt_log']     = array();
		$GLOBALS['typenow']     = '';
		$_GET                   = array();
		WP_CLI::$out            = array();
		putenv( 'TYPESAFE_API_KEY' );
		putenv( 'TYPESAFE_MODEL' );
	}

	function woo_note_triage_no_network(): void {
		throw new RuntimeException( 'A test tried to reach the network.' );
	}

	function wp_remote_post() {
		woo_note_triage_no_network();
	}

	function wp_remote_get() {
		woo_note_triage_no_network();
	}

	function wp_remote_request() {
		woo_note_triage_no_network();
	}

	function wp_safe_remote_post() {
		woo_note_triage_no_network();
	}

	function wp_safe_remote_get() {
		woo_note_triage_no_network();
	}

	function get_option( $name, $fallback = false ) {
		return array_key_exists( $name, $GLOBALS['wnt_options'] ) ? $GLOBALS['wnt_options'][ $name ] : $fallback;
	}

	function is_admin(): bool {
		return true;
	}

	function esc_html( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	function esc_html__( $text, $domain = 'default' ): string {
		return esc_html( $text );
	}

	function esc_html_e( $text, $domain = 'default' ): void {
		echo esc_html( $text );
	}

	function __( $text, $domain = 'default' ): string {
		return (string) $text;
	}

	function esc_attr( $text ): string {
		return esc_html( $text );
	}

	function esc_url( $url ): string {
		return esc_html( $url );
	}

	function admin_url( $path = '' ): string {
		return 'https://shop.example/wp-admin/' . ltrim( (string) $path, '/' );
	}

	function add_query_arg( ...$args ): string {
		$url   = (string) array_pop( $args );
		$query = is_array( $args[0] ) ? $args[0] : array( $args[0] => $args[1] );
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $query );
	}

	function current_user_can( $capability ): bool {
		return true;
	}

	function checked( $checked, $current = true, $display = true ): string {
		$result = (string) $checked === (string) $current ? " checked='checked'" : '';
		if ( $display ) {
			echo $result; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		return $result;
	}

	function wp_nonce_field( $action = -1 ): void {
		echo '<input type="hidden" name="_wpnonce" value="fixture-nonce">';
	}

	function submit_button(): void {
		echo '<input type="submit" name="submit" value="Save Changes">';
	}

	function sanitize_key( $key ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}

	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}

	function do_action( $hook, ...$args ): void {
		$GLOBALS['wnt_fired'][] = array( $hook, $args );
	}

	function wc_get_order( $id ) {
		return $GLOBALS['wnt_orders'][ (int) $id ] ?? false;
	}

	/**
	 * Supports what the backfill command asks: date_created ">timestamp", newest first, limit and paged, ids.
	 */
	function wc_get_orders( array $args ): array {
		$since  = (int) substr( (string) $args['date_created'], 1 );
		$orders = array_filter(
			$GLOBALS['wnt_orders'],
			static function ( WC_Order $order ) use ( $since ): bool {
				return $order->get_date_created()->getTimestamp() > $since;
			}
		);
		usort(
			$orders,
			static function ( WC_Order $a, WC_Order $b ): int {
				return $b->get_date_created()->getTimestamp() <=> $a->get_date_created()->getTimestamp();
			}
		);
		$page = array_slice( $orders, ( (int) $args['paged'] - 1 ) * (int) $args['limit'], (int) $args['limit'] );
		return array_map(
			static function ( WC_Order $order ): int {
				return $order->get_id();
			},
			$page
		);
	}

	function wc_get_logger(): object {
		return new class() {
			public function warning( $message, $context = array() ): void {
				$GLOBALS['wnt_log'][] = array( 'warning', $message, $context );
			}
		};
	}

	function as_has_scheduled_action( $hook, $args = null, $group = '' ): bool {
		foreach ( $GLOBALS['wnt_actions'] as $action ) {
			if ( $action['hook'] === $hook && $action['group'] === $group && ( null === $args || $action['args'] === $args ) ) {
				return true;
			}
		}
		return false;
	}

	function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false, $priority = 10 ): int {
		$GLOBALS['wnt_actions'][] = array(
			'hook'  => $hook,
			'args'  => $args,
			'group' => $group,
			'when'  => 'now',
		);
		return count( $GLOBALS['wnt_actions'] );
	}

	function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ): int {
		$GLOBALS['wnt_actions'][] = array(
			'hook'  => $hook,
			'args'  => $args,
			'group' => $group,
			'when'  => $timestamp,
		);
		return count( $GLOBALS['wnt_actions'] );
	}

	/**
	 * A stand-in for WooCommerce's order object with the methods the plugin calls.
	 */
	class WC_Order {

		/** @var int */
		public $id;

		/** @var string */
		public $customer_note;

		/** @var string */
		public $created;

		/** @var array<string, mixed> */
		public $meta = array();

		/** @var array<int, array{0: string, 1: int, 2: bool}> */
		public $notes = array();

		/** @var int */
		public $saves = 0;

		public function __construct( int $id, string $customer_note, string $created = '2026-09-20T10:00:00+00:00' ) {
			$this->id                     = $id;
			$this->customer_note          = $customer_note;
			$this->created                = $created;
			$GLOBALS['wnt_orders'][ $id ] = $this;
		}

		public function get_id(): int {
			return $this->id;
		}

		public function get_customer_note(): string {
			return $this->customer_note;
		}

		public function get_date_created(): DateTimeImmutable {
			return new DateTimeImmutable( $this->created );
		}

		public function get_meta( $key, $single = true ) {
			return $this->meta[ $key ] ?? '';
		}

		public function update_meta_data( $key, $value ): void {
			$this->meta[ $key ] = $value;
		}

		public function delete_meta_data( $key ): void {
			unset( $this->meta[ $key ] );
		}

		public function add_order_note( $note, $is_customer_note = 0, $added_by_user = false ): int {
			$this->notes[] = array( $note, $is_customer_note, $added_by_user );
			return count( $this->notes );
		}

		public function save(): int {
			++$this->saves;
			return $this->id;
		}
	}

	/**
	 * WP-CLI's output functions. error() throws instead of exiting so a test can see it.
	 */
	class WP_CLI {

		/** @var array<int, array{0: string, 1: string}> */
		public static $out = array();

		public static function line( $text = '' ): void {
			self::$out[] = array( 'line', (string) $text );
		}

		public static function log( $text ): void {
			self::$out[] = array( 'log', (string) $text );
		}

		public static function error( $text ): void {
			self::$out[] = array( 'error', (string) $text );
			throw new WP_CLI_Exit( (string) $text );
		}
	}

	/**
	 * Thrown by the WP_CLI::error() stand-in.
	 */
	class WP_CLI_Exit extends RuntimeException {
	}
}

namespace WP_CLI\Utils {

	function get_flag_value( $assoc_args, $flag, $fallback = null ) {
		return $assoc_args[ $flag ] ?? $fallback;
	}
}

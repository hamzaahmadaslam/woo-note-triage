<?php
/**
 * A "Note triage" column and a filter on the orders list, for both order storage modes.
 *
 * @package Woo_Note_Triage
 */

namespace Woo_Note_Triage;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce has two orders screens: WooCommerce > Orders backed by the HPOS tables (admin.php?page=wc-orders),
 * and the older screen for orders stored as posts (edit.php?post_type=shop_order). Each has its own hooks, and
 * this class uses both sets; only the screen in use fires its hooks.
 */
final class Orders_List {

	/** The query argument that carries the filter. */
	public const PARAM = 'woo_note_triage';

	/** The column key. */
	public const COLUMN = 'woo_note_triage';

	/** The filter value for orders waiting for review. */
	public const REVIEW = 'review';

	/**
	 * Hooks the column and the filter into both screens.
	 */
	public function register(): void {
		// Orders stored in the HPOS tables.
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'render_filter_hpos' ), 20, 2 );
		add_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', array( $this, 'filter_hpos_query' ), 20 );
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_column' ), 20 );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_column_hpos' ), 20, 2 );

		// Orders stored as posts. WooCommerce rebuilds the column list at priority 10, so this runs later.
		add_action( 'restrict_manage_posts', array( $this, 'render_filter_legacy' ), 20, 2 );
		add_filter( 'request', array( $this, 'filter_legacy_query' ), 20 );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column_legacy' ), 20, 2 );
	}

	/**
	 * Whether a filter value is one this plugin knows: a category or "review". Pure.
	 *
	 * @param string $value The value from the query string.
	 */
	public static function is_valid_filter( string $value ): bool {
		return self::REVIEW === $value || array_key_exists( $value, Questions::CATEGORIES );
	}

	/**
	 * The meta query clause for a filter value, or null for no filter. Pure.
	 *
	 * @param string $value A category key, "review", or "".
	 */
	public static function meta_clause( string $value ): ?array {
		if ( self::REVIEW === $value ) {
			return array(
				'key'   => Meta::STATUS,
				'value' => Decision::REVIEW,
			);
		}
		if ( array_key_exists( $value, Questions::CATEGORIES ) ) {
			return array(
				'key'   => Meta::CATEGORY,
				'value' => $value,
			);
		}
		return null;
	}

	/**
	 * Adds a clause to the query arguments and keeps any meta query already there (WooCommerce's own customer
	 * filter sets one). Pure.
	 *
	 * @param array $args   Query arguments.
	 * @param array $clause A meta query clause.
	 */
	public static function with_clause( array $args, array $clause ): array {
		$existing           = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
		$args['meta_query'] = array() === $existing ? array( $clause ) : array( 'relation' => 'AND', $existing, $clause ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- only when a shop manager picks this filter.
		return $args;
	}

	/**
	 * The filter value in the current request, or "" when there is none or it is not valid.
	 */
	public static function selected(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only list filter, like WordPress's own.
		$value = isset( $_GET[ self::PARAM ] ) && is_string( $_GET[ self::PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::PARAM ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return self::is_valid_filter( $value ) ? $value : '';
	}

	/**
	 * The filter on the HPOS screen.
	 *
	 * @param mixed $order_type Order type of the screen.
	 * @param mixed $which      "top" or "bottom".
	 */
	public function render_filter_hpos( $order_type, $which = 'top' ): void {
		if ( 'shop_order' === $order_type && 'top' === $which ) {
			$this->render_filter();
		}
	}

	/**
	 * The filter on the posts screen.
	 *
	 * @param mixed $post_type Post type of the screen.
	 * @param mixed $which     "top" or "bottom".
	 */
	public function render_filter_legacy( $post_type, $which = 'top' ): void {
		if ( 'shop_order' === $post_type && 'top' === $which ) {
			$this->render_filter();
		}
	}

	/**
	 * Applies the filter on the HPOS screen.
	 *
	 * @param mixed $args Arguments for wc_get_orders().
	 * @return mixed
	 */
	public function filter_hpos_query( $args ) {
		$clause = self::meta_clause( self::selected() );
		return is_array( $args ) && null !== $clause ? self::with_clause( $args, $clause ) : $args;
	}

	/**
	 * Applies the filter on the posts screen.
	 *
	 * @param mixed $query_vars Query variables of the request.
	 * @return mixed
	 */
	public function filter_legacy_query( $query_vars ) {
		global $typenow;
		if ( ! is_admin() || 'shop_order' !== $typenow || ! is_array( $query_vars ) ) {
			return $query_vars;
		}
		$clause = self::meta_clause( self::selected() );
		return null !== $clause ? self::with_clause( $query_vars, $clause ) : $query_vars;
	}

	/**
	 * Adds the column after the status column.
	 *
	 * @param mixed $columns Column keys and titles.
	 * @return mixed
	 */
	public function add_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		$title = esc_html__( 'Note triage', 'woo-note-triage' );
		$out   = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out[ self::COLUMN ] = $title;
			}
		}
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = $title;
		}
		return $out;
	}

	/**
	 * The column on the HPOS screen.
	 *
	 * @param mixed $column Column key.
	 * @param mixed $order  The order.
	 */
	public function render_column_hpos( $column, $order ): void {
		if ( self::COLUMN === $column && $order instanceof \WC_Order ) {
			$this->render_cell( $order );
		}
	}

	/**
	 * The column on the posts screen.
	 *
	 * @param mixed $column  Column key.
	 * @param mixed $post_id Order ID.
	 */
	public function render_column_legacy( $column, $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$order = wc_get_order( is_numeric( $post_id ) ? (int) $post_id : 0 );
		if ( $order instanceof \WC_Order ) {
			$this->render_cell( $order );
		}
	}

	/**
	 * Prints the dropdown.
	 */
	private function render_filter(): void {
		$selected = self::selected();
		echo '<label for="woo-note-triage-filter" class="screen-reader-text">' . esc_html__( 'Filter by customer note', 'woo-note-triage' ) . '</label>';
		echo '<select name="' . esc_attr( self::PARAM ) . '" id="woo-note-triage-filter">';
		echo '<option value="">' . esc_html__( 'All customer notes', 'woo-note-triage' ) . '</option>';
		foreach ( Questions::CATEGORY_LABELS as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $selected, $value, false ) . '>' . esc_html( ucfirst( $label ) ) . '</option>';
		}
		echo '<option value="' . esc_attr( self::REVIEW ) . '"' . selected( $selected, self::REVIEW, false ) . '>' . esc_html__( 'Needs review', 'woo-note-triage' ) . '</option>';
		echo '</select>';
	}

	/**
	 * Prints one cell: the category and urgency, "Needs review" with the two likeliest categories, or the error.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function render_cell( \WC_Order $order ): void {
		$lines = Decision::column_lines(
			(string) $order->get_meta( Meta::STATUS ),
			(string) $order->get_meta( Meta::RESULT ),
			(string) $order->get_meta( Meta::ERROR )
		);
		echo implode( '<br>', array_map( 'esc_html', $lines ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every line is escaped by esc_html.
	}
}

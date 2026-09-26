<?php
/**
 * Settings values and the orders list filter.
 *
 * @package Woo_Note_Triage
 */

use Woo_Note_Triage\Orders_List;
use Woo_Note_Triage\Settings;

test(
	'settings fall back to their defaults and refuse values that are not valid',
	static function (): void {
		assert_same(
			array(
				'enabled'   => true,
				'threshold' => 0.8,
				'model'     => 'jev-latest',
			),
			Settings::sanitize( array() )
		);
		assert_same(
			array(
				'enabled'   => false,
				'threshold' => 0.9,
				'model'     => 'jev-1.13.0',
			),
			Settings::sanitize(
				array(
					'enabled'   => false,
					'threshold' => '0.9',
					'model'     => ' jev-1.13.0 ',
				)
			)
		);
		assert_same( 'jev-latest', Settings::sanitize( array( 'model' => 'jev latest; drop' ) )['model'], 'a model name with spaces' );
		assert_same( '', Settings::parse_key( 'short' ), 'a key that is too short' );
		assert_same( 'abcdefgh12345678', Settings::parse_key( " abcdefgh 12345678\n" ), 'whitespace is removed' );
		assert_same( '', Settings::parse_key( array( 'x' ) ), 'not a string' );
		assert_same( '', Settings::parse_key( "key-with-\u{00E9}-accent" ), 'non-ASCII' );
	}
);

test(
	'the key and model come from the environment before the saved settings',
	static function (): void {
		assert_same( '', Settings::key_source(), 'no key anywhere' );
		$GLOBALS['wnt_options']['woo_note_triage_api_key'] = 'fixture-saved-key';
		assert_same( 'settings', Settings::key_source() );
		assert_same( 'fixture-saved-key', Settings::api_key() );
		$variable = 'TYPESAFE_API_KEY';
		putenv( "$variable=fixture-environment-key" );
		assert_same( 'environment', Settings::key_source() );
		assert_same( 'fixture-environment-key', Settings::api_key() );
		assert_same( 'jev-latest', Settings::model() );
		putenv( 'TYPESAFE_MODEL=jev-1.13.0' );
		assert_same( 'jev-1.13.0', Settings::model() );
	}
);

test(
	'the orders list filter adds one meta clause and keeps the clauses WooCommerce added',
	static function (): void {
		$clause = Orders_List::meta_clause( 'fraud-signal' );
		assert_same(
			array(
				'key'   => '_woo_note_triage_category',
				'value' => 'fraud-signal',
			),
			$clause
		);
		assert_same(
			array(
				'key'   => '_woo_note_triage_status',
				'value' => 'review',
			),
			Orders_List::meta_clause( 'review' )
		);
		assert_same( null, Orders_List::meta_clause( '' ) );
		assert_same( null, Orders_List::meta_clause( 'refund' ) );

		$customer = array(
			array(
				'key'     => '_customer_user',
				'value'   => 5,
				'compare' => '=',
			),
		);
		assert_same( array( 'relation' => 'AND', $customer, $clause ), Orders_List::with_clause( array( 'meta_query' => $customer ), $clause )['meta_query'] );

		$list                      = new Orders_List();
		$_GET['woo_note_triage']   = 'Fraud-Signal';
		assert_same(
			array(
				'type'       => 'shop_order',
				'meta_query' => array( $clause ),
			),
			$list->filter_hpos_query( array( 'type' => 'shop_order' ) ),
			'HPOS screen'
		);
		$GLOBALS['typenow'] = 'shop_order';
		assert_same( array( 'meta_query' => array( $clause ) ), $list->filter_legacy_query( array() ), 'posts screen' );
		$GLOBALS['typenow'] = 'post';
		assert_same( array(), $list->filter_legacy_query( array() ), 'another post type' );
		$_GET['woo_note_triage'] = "complaint' OR 1=1";
		assert_same( array( 'type' => 'shop_order' ), $list->filter_hpos_query( array( 'type' => 'shop_order' ) ), 'a value that is not a category' );
	}
);

test(
	'the column goes right after the status column',
	static function (): void {
		$columns = ( new Orders_List() )->add_column(
			array(
				'cb'           => '',
				'order_number' => 'Order',
				'order_status' => 'Status',
				'order_total'  => 'Total',
			)
		);
		assert_same( array( 'cb', 'order_number', 'order_status', 'woo_note_triage', 'order_total' ), array_keys( $columns ) );
		assert_same( 'Note triage', $columns['woo_note_triage'] );
	}
);

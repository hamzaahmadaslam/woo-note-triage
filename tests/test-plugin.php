<?php
/**
 * The plugin as a whole: the header, the examples, and the rule that only api.typesafe.ai is contacted.
 *
 * @package Woo_Note_Triage
 */

use Woo_Note_Triage\Plugin;
use Woo_Note_Triage\Report;

test(
	'the plugin header matches the code and declares HPOS compatibility',
	static function (): void {
		$root   = dirname( __DIR__ );
		$header = (string) file_get_contents( $root . '/woo-note-triage.php' );
		assert_contains( ' * Version:           ' . Plugin::VERSION . "\n", str_replace( "\r\n", "\n", $header ) );
		assert_contains( ' * Author:            Hamza Ahmad Aslam', $header );
		assert_contains( ' * Author URI:        https://hamzaahmadaslam.com', $header );
		assert_contains( ' * Requires PHP:      8.1', $header );
		assert_contains( ' * Requires Plugins:  woocommerce', $header );
		assert_contains( ' * WC requires at least: 8.0', $header );
		assert_contains( "declare_compatibility( 'custom_order_tables', WOO_NOTE_TRIAGE_FILE, true )", (string) file_get_contents( $root . '/includes/class-plugin.php' ) );
	}
);

test(
	'only the WordPress transport makes HTTP requests, and the client knows one address',
	static function (): void {
		$root  = dirname( __DIR__ );
		$files = array_merge( array( $root . '/woo-note-triage.php', $root . '/uninstall.php' ), glob( $root . '/includes/*.php' ) );
		foreach ( $files as $file ) {
			$code = (string) file_get_contents( $file );
			$name = basename( $file );
			foreach ( array( 'curl_', 'fsockopen', 'stream_socket_client', 'file_get_contents', 'fopen(', 'wp_remote_get', 'wp_safe_remote', 'wp_remote_request', 'download_url', 'WP_Http' ) as $call ) {
				assert_same( false, str_contains( $code, $call ), "$call in $name" );
			}
			if ( 'class-wp-transport.php' !== $name ) {
				assert_same( false, str_contains( $code, 'wp_remote_post' ), "wp_remote_post in $name" );
			}
		}
		preg_match_all( '#https?://[^\s\'"]+#', (string) file_get_contents( $root . '/includes/class-jev-client.php' ), $urls );
		assert_same( array( 'https://api.typesafe.ai/v1/systemone' ), $urls[0], 'addresses in the client' );
	}
);

test(
	'the tests themselves cannot reach the network',
	static function (): void {
		assert_same( false, in_array( 'https', stream_get_wrappers(), true ), 'the https stream wrapper' );
		assert_throws(
			RuntimeException::class,
			static function (): void {
				wp_remote_post( 'https://api.typesafe.ai/v1/systemone' );
			}
		);
	}
);

test(
	'the example files match what the code produces now',
	static function (): void {
		foreach ( woo_note_triage_examples() as $name => $content ) {
			$saved = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/examples/' . $name ) );
			assert_same( $content, $saved, "examples/$name (run php examples/build.php)" );
		}
	}
);

test(
	'the example shows every outcome: triaged, at the threshold, just below it, and details replaced before sending',
	static function (): void {
		$examples = woo_note_triage_examples();
		assert_contains( 'Threshold 0.80: 7 triaged, 3 for review', $examples['report.txt'] );
		assert_contains( '#1009  2026-09-25  gift message, confidence 0.80', $examples['report.txt'] );
		assert_contains( 'Review: category confidence 0.79 is below the threshold 0.80', $examples['report.txt'] );
		assert_contains( 'Would send 10 requests', $examples['dry-run.txt'] );
		assert_not_contains( '#1011', $examples['dry-run.txt'] );
		assert_same( 10, count( json_decode( $examples['report.json'], true )['orders'] ), 'orders in the JSON report' );

		$states = array_column( json_decode( $examples['dry-run.json'], true )['orders'], 'state', 'order_id' );
		assert_same( 'Please ship to my forwarding company, I will email you the final address once the payment clears. Call <number> if anything is unclear.', $states[1006] );
		assert_same( 'Is the blue one the same size as the green one? If not, swap it for the green. Reply to <email> please', $states[1007] );
		assert_not_contains( '7946', $examples['dry-run.json'] );
		assert_not_contains( 'sam.example', $examples['dry-run.json'] );
	}
);

test(
	'reports give token counts and no cost: no dollar amount in any output and no price constant in the plugin',
	static function (): void {
		$examples = woo_note_triage_examples();
		assert_contains( "Jev: model fixture, 10 requests, 11,913 input tokens\n", $examples['report.txt'] );
		assert_contains( "one per order: about 11,913 input tokens\n", $examples['dry-run.txt'] );
		foreach ( $examples as $name => $content ) {
			assert_same( 0, preg_match( '/\$\s?\d/', $content ), "a dollar amount in examples/$name" );
		}
		foreach ( get_declared_classes() as $class ) {
			if ( str_starts_with( $class, 'Woo_Note_Triage\\' ) ) {
				$constants = array_keys( ( new ReflectionClass( $class ) )->getConstants() );
				assert_same( array(), preg_grep( '/PRICE|COST/i', $constants ), "price constants in $class" );
			}
		}
		assert_same( false, method_exists( Report::class, 'cost' ), 'Report::cost()' );
	}
);

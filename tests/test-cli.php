<?php
/**
 * wp woo-note-triage backfill, with WP-CLI stand-ins and fixture answers.
 *
 * @package Woo_Note_Triage
 */

use Woo_Note_Triage\CLI;
use Woo_Note_Triage\Jev_Client;
use Woo_Note_Triage\Meta;
use Woo_Note_Triage\Triage;

/**
 * A backfill command whose client answers from the list, without retries.
 *
 * @param array      $responses Canned responses (see fixture_transport()).
 * @param array|null $calls     Filled with the calls made.
 */
function cli_with( array $responses, ?array &$calls ): CLI {
	$transport = fixture_transport( $responses, $calls );
	return new CLI(
		new Triage(),
		static function ( string $key, string $model ) use ( $transport ): Jev_Client {
			return new Jev_Client( $key, $model, $transport, array( 'retries' => 0 ) );
		}
	);
}

/**
 * Everything the command printed, one line per call.
 */
function cli_output(): string {
	return implode( "\n", array_column( WP_CLI::$out, 1 ) );
}

test(
	'backfill --dry-run lists the orders with a note from the last days and sends nothing, without a key',
	static function (): void {
		new WC_Order( 21, 'Gift wrap please', gmdate( 'c', time() - 2 * DAY_IN_SECONDS ) );
		new WC_Order( 22, '', gmdate( 'c', time() - 3 * DAY_IN_SECONDS ) );
		new WC_Order( 23, 'An older note', gmdate( 'c', time() - 40 * DAY_IN_SECONDS ) );
		cli_with( array(), $calls )->backfill(
			array(),
			array(
				'days'    => '30',
				'dry-run' => true,
			)
		);
		$output = cli_output();
		assert_contains( '(dry run: nothing is sent and no order changes)', $output );
		assert_contains( 'Orders created in the last 30 days: 2. With a customer note: 1. Already triaged, skipped: 0.', $output );
		assert_contains( '#21  ', $output );
		assert_not_contains( '#23', $output );
		assert_contains( '"question": "What kind of note is this?"', $output );
		assert_same( array(), $calls, 'requests' );
	}
);

test(
	'backfill sends each note once, records the results, and skips those orders on the next run',
	static function (): void {
		$GLOBALS['wnt_options']['woo_note_triage_api_key'] = 'fixture-key-not-real';
		new WC_Order( 31, 'Third parcel that arrived crushed, please check this one', gmdate( 'c', time() - DAY_IN_SECONDS ) );
		new WC_Order( 32, 'Thanks!', gmdate( 'c', time() - 3600 ) );
		$cli = cli_with( array( fixture( 'response-complaint.json' ), fixture( 'response-complaint.json' ) ), $calls );
		$cli->backfill(
			array(),
			array(
				'days'   => '7',
				'format' => 'json',
			)
		);
		$report = json_decode( WP_CLI::$out[0][1], true, 512, JSON_THROW_ON_ERROR );
		assert_same( 1, count( WP_CLI::$out ), 'JSON output is one block and nothing else' );
		assert_same( 2, $report['requests'] );
		assert_same( array( 32, 31 ), array_column( $report['orders'], 'order_id' ), 'newest first' );
		assert_same( 'jev-1.13.0', $report['model'] );
		assert_same( 'triaged', $GLOBALS['wnt_orders'][31]->meta[ Meta::STATUS ] );
		assert_same( 1, count( $GLOBALS['wnt_orders'][31]->notes ), 'private order notes' );

		WP_CLI::$out = array();
		$cli->backfill( array(), array( 'days' => '7' ) );
		assert_same( 2, count( $calls ), 'requests after the second run' );
		assert_contains( 'With a customer note: 2. Already triaged, skipped: 2. Sent: 0.', cli_output() );
	}
);

test(
	'backfill refuses bad options, and stops with a plain message when TypeSafe refuses the key',
	static function (): void {
		$cli     = cli_with( array( 401 ), $calls );
		$refused = array(
			'--days must be a whole number from 1 to 3650.' => array( 'days' => '0' ),
			'--limit must be a whole number of 1 or more.'  => array( 'limit' => 'all' ),
			'--threshold must be a number from 0 to 1.'     => array( 'threshold' => '1.5' ),
			'--format must be text or json.'                => array( 'format' => 'xml' ),
		);
		foreach ( $refused as $message => $options ) {
			$exit = assert_throws(
				WP_CLI_Exit::class,
				static function () use ( $cli, $options ): void {
					$cli->backfill( array(), $options );
				}
			);
			assert_same( $message, $exit->getMessage() );
		}
		$exit = assert_throws(
			WP_CLI_Exit::class,
			static function () use ( $cli ): void {
				$cli->backfill( array(), array() );
			}
		);
		assert_contains( 'No TypeSafe API key.', $exit->getMessage() );

		$GLOBALS['wnt_options']['woo_note_triage_api_key'] = 'fixture-key-not-real';
		new WC_Order( 41, 'Where is my parcel?', gmdate( 'c', time() - 3600 ) );
		$exit = assert_throws(
			WP_CLI_Exit::class,
			static function () use ( $cli ): void {
				$cli->backfill( array(), array() );
			}
		);
		assert_same( 'Stopped at order #41. TypeSafe refused the API key (HTTP 401). Check the key under WooCommerce > Note triage. Orders already triaged are skipped when you run the command again.', $exit->getMessage() );
		assert_same( false, isset( $GLOBALS['wnt_orders'][41]->meta[ Meta::STATUS ] ), 'the order is left unchanged' );
	}
);

test(
	'backfill --limit sends at most that many orders, newest first',
	static function (): void {
		$GLOBALS['wnt_options']['woo_note_triage_api_key'] = 'fixture-key-not-real';
		foreach ( array( 51, 52, 53 ) as $offset => $id ) {
			new WC_Order( $id, 'Gift wrap please', gmdate( 'c', time() - ( 3 - $offset ) * 3600 ) );
		}
		cli_with( array( fixture( 'response-complaint.json' ) ), $calls )->backfill( array(), array( 'limit' => '1' ) );
		assert_same( 1, count( $calls ), 'requests' );
		assert_contains( "Already triaged, skipped: 0. Sent: 1.\nStopped at --limit=1. Older orders were not looked at and are not counted above.\n", cli_output() );
		assert_contains( '#53  ', cli_output() );
	}
);

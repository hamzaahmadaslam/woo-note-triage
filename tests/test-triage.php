<?php
/**
 * The checkout hooks and the background job, with WordPress stand-ins and fixture answers.
 *
 * @package Woo_Note_Triage
 */

use Woo_Note_Triage\Jev_Client;
use Woo_Note_Triage\Meta;
use Woo_Note_Triage\Note_Text;
use Woo_Note_Triage\Triage;

/**
 * A Triage whose client answers from the list, without retries or waits inside one attempt.
 *
 * @param array      $responses Canned responses (see fixture_transport()).
 * @param array|null $calls     Filled with the calls made.
 */
function triage_with( array $responses, ?array &$calls ): Triage {
	$transport = fixture_transport( $responses, $calls );
	return new Triage(
		static function () use ( $transport ): Jev_Client {
			return new Jev_Client( 'fixture-key-not-real', 'jev-latest', $transport, array( 'retries' => 0 ) );
		}
	);
}

/**
 * Saves a key in the settings stand-in.
 */
function save_key(): void {
	$GLOBALS['wnt_options']['woo_note_triage_api_key'] = 'fixture-key-not-real';
}

test(
	'a new order with a note is queued once for the background job, from either checkout',
	static function (): void {
		save_key();
		$triage = triage_with( array(), $calls );
		$order  = new WC_Order( 7, 'Leave it with the neighbour please' );
		$triage->on_block_checkout( $order );
		$triage->on_classic_checkout( 7, array() );
		assert_same(
			array(
				array(
					'hook'  => 'woo_note_triage_run',
					'args'  => array( 'order_id' => 7 ),
					'group' => 'woo-note-triage',
					'when'  => 'now',
				),
			),
			$GLOBALS['wnt_actions']
		);
		assert_same( array(), $calls, 'the checkout never waits for TypeSafe' );
	}
);

test(
	'nothing is queued without a note, without a key, or with triage switched off',
	static function (): void {
		$triage = triage_with( array(), $calls );
		save_key();
		assert_same( false, $triage->maybe_queue( new WC_Order( 8, "  \n " ) ), 'an empty note' );
		$order = new WC_Order( 9, 'Gift wrap please' );
		$GLOBALS['wnt_options'] = array();
		assert_same( false, $triage->maybe_queue( $order ), 'no key' );
		save_key();
		$GLOBALS['wnt_options']['woo_note_triage_settings'] = array( 'enabled' => false );
		assert_same( false, $triage->maybe_queue( $order ), 'switched off' );
		assert_same( array(), $GLOBALS['wnt_actions'] );
	}
);

test(
	'the job records a confident answer: category, urgency, a private order note and the result hook',
	static function (): void {
		save_key();
		$order  = new WC_Order( 7, 'Third parcel in a row that arrived crushed. Call 0300 123 4567 before you ship this one.' );
		$triage = triage_with( array( fixture( 'response-complaint.json' ) ), $calls );
		$triage->run_job( 7 );

		assert_same( 1, count( $calls ), 'requests' );
		assert_same( 'Third parcel in a row that arrived crushed. Call <number> before you ship this one.', json_decode( $calls[0][1]['body'], true )['state'], 'what was sent' );
		assert_same( 'triaged', $order->meta[ Meta::STATUS ] );
		assert_same( 'complaint', $order->meta[ Meta::CATEGORY ] );
		assert_same( '2.71', $order->meta[ Meta::URGENCY ] );
		assert_same( Note_Text::hash( $order->customer_note ), $order->meta[ Meta::HASH ] );
		assert_same( 'complaint', json_decode( $order->meta[ Meta::RESULT ], true )['category'] );
		assert_same( 1, count( $order->notes ), 'order notes' );
		assert_same( 0, $order->notes[0][1], 'a private note, not one for the customer' );
		assert_contains( 'Customer note triage: complaint (confidence 0.90).', $order->notes[0][0] );
		assert_same( 'woo_note_triage_result', $GLOBALS['wnt_fired'][0][0] );
		assert_same( 1, $order->saves, 'saves' );
	}
);

test(
	'an unsure answer goes to review: no category and no order note',
	static function (): void {
		save_key();
		$GLOBALS['wnt_options']['woo_note_triage_settings'] = array( 'threshold' => 0.95 );
		$order                          = new WC_Order( 7, 'Is this the blue one or the navy one?' );
		$order->meta[ Meta::CATEGORY ]  = 'gift-message';
		$triage                         = triage_with( array( fixture( 'response-complaint.json' ) ), $calls );
		$triage->run_job( 7 );
		assert_same( 'review', $order->meta[ Meta::STATUS ] );
		assert_same( false, isset( $order->meta[ Meta::CATEGORY ] ), 'an old category is removed' );
		assert_same( array(), $order->notes, 'order notes' );
	}
);

test(
	'the same note is sent once; an edited note is sent again',
	static function (): void {
		save_key();
		$order  = new WC_Order( 7, 'Gift wrap please' );
		$triage = triage_with( array( fixture( 'response-complaint.json' ), fixture( 'response-complaint.json' ) ), $calls );
		$triage->run_job( 7 );
		$triage->run_job( 7 );
		assert_same( 1, count( $calls ), 'requests for the same note' );
		assert_same( false, $triage->maybe_queue( $order ), 'queued again' );
		$order->customer_note = 'Gift wrap please, and leave the receipt out';
		assert_same( true, $triage->maybe_queue( $order ), 'queued after an edit' );
		$triage->run_job( 7 );
		assert_same( 2, count( $calls ), 'requests after an edit' );
	}
);

test(
	'a rate limit schedules another attempt later; after the last attempt the error is recorded',
	static function (): void {
		save_key();
		$order  = new WC_Order( 7, 'Where is my order? It has been two weeks.' );
		$triage = triage_with( array( 429, 429 ), $calls );
		$triage->run_job( 7, 1 );
		assert_same( 1, count( $GLOBALS['wnt_actions'] ), 'scheduled attempts' );
		assert_same(
			array(
				'order_id' => 7,
				'attempt'  => 2,
			),
			$GLOBALS['wnt_actions'][0]['args']
		);
		assert_true( $GLOBALS['wnt_actions'][0]['when'] >= time() + 5 * 60, 'five minutes later' );
		assert_same( false, isset( $order->meta[ Meta::STATUS ] ), 'no status while waiting' );

		$triage->run_job( 7, 3 );
		assert_same( 'error', $order->meta[ Meta::STATUS ] );
		assert_same( 'The TypeSafe rate limit was reached (HTTP 429). Try again later.', $order->meta[ Meta::ERROR ] );
		assert_same( 1, count( $GLOBALS['wnt_actions'] ), 'no attempt after the last' );
		assert_same( 1, count( $GLOBALS['wnt_log'] ), 'log entries' );
		assert_same( 'Order 7: The TypeSafe rate limit was reached (HTTP 429). Try again later.', $GLOBALS['wnt_log'][0][1], 'the log holds no note text' );
	}
);

test(
	'a job queued before triage was switched off sends nothing',
	static function (): void {
		save_key();
		$order                                              = new WC_Order( 7, 'Gift wrap please' );
		$GLOBALS['wnt_options']['woo_note_triage_settings'] = array( 'enabled' => false );
		triage_with( array(), $calls )->run_job( 7 );
		assert_same( array(), $calls, 'requests' );
		assert_same( array(), $order->meta, 'order meta' );
	}
);

test(
	'a refused key is recorded at once without another attempt, and a deleted order is skipped',
	static function (): void {
		save_key();
		$order  = new WC_Order( 7, 'Where is my order?' );
		$triage = triage_with( array( 401 ), $calls );
		$triage->run_job( 7, 1 );
		assert_same( 'error', $order->meta[ Meta::STATUS ] );
		assert_same( array(), $GLOBALS['wnt_actions'], 'scheduled attempts' );
		$triage->run_job( 999 );
		assert_same( 1, count( $calls ), 'requests' );
	}
);

test(
	'an error after an earlier answer removes its category, so the order leaves that filter',
	static function (): void {
		save_key();
		$order  = new WC_Order( 7, 'The last two arrived cracked' );
		$triage = triage_with( array( fixture( 'response-complaint.json' ), 401 ), $calls );
		$triage->run_job( 7 );
		assert_same( 'complaint', $order->meta[ Meta::CATEGORY ] );
		// The note changes, as when a customer pays again after a failed payment and WooCommerce reuses the order,
		// and this time the key is refused.
		$order->customer_note = 'Please change the colour to navy';
		$triage->run_job( 7 );
		assert_same( 'error', $order->meta[ Meta::STATUS ] );
		assert_same( false, isset( $order->meta[ Meta::CATEGORY ] ), 'the category of the earlier answer' );
	}
);

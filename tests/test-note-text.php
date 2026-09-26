<?php
/**
 * Cleaning the note: the only text about an order that is sent.
 *
 * @package Woo_Note_Triage
 */

use Woo_Note_Triage\Note_Text;

test(
	'cleaning keeps the words and drops control characters, direction marks and extra space',
	static function (): void {
		$raw = "  Leave it at the back door\r\n\r\n\r\n\tthanks\x07 \u{202E}reversed\u{202C}  &amp; size &lt; 10  ";
		assert_same( "Leave it at the back door\n\nthanks reversed & size < 10", Note_Text::clean( $raw ) );
	}
);

test(
	'email addresses and numbers of nine or more digits are replaced; dates, times, prices and short numbers stay',
	static function (): void {
		$cases = array(
			'Mail me at jo.smith+shop@example.co.uk thanks'        => 'Mail me at <email> thanks',
			'Call +44 20 7946 0958 or (020) 7946-0958'             => 'Call <number> or <number>',
			'Card 4111 1111 1111 1111 exp 12/28'                   => 'Card <number> exp 12/28',
			'VAT number GB123456789 on the invoice'                => 'VAT number GB<number> on the invoice',
			'Deliver 2026-10-05 - 2026-10-07 between 10:30-12:00'  => 'Deliver 2026-10-05 - 2026-10-07 between 10:30-12:00',
			'Order #10452, total 1,299.00, flat 12, on 05.10.2026' => 'Order #10452, total 1,299.00, flat 12, on 05.10.2026',
		);
		foreach ( $cases as $raw => $expected ) {
			assert_same( $expected, Note_Text::clean( $raw ), $raw );
		}
	}
);

test(
	'a long note is cut to 2,000 characters without splitting a character, and an empty note stays empty',
	static function (): void {
		$cut = Note_Text::clean( str_repeat( 'é', 2500 ) );
		assert_same( 2000, Note_Text::length( $cut ), 'length of a cut note' );
		assert_same( '...', substr( $cut, -3 ), 'end of a cut note' );
		assert_same( 1, preg_match( '//u', $cut ), 'valid UTF-8 after cutting' );
		assert_same( '', Note_Text::clean( " \n\t\r\n " ), 'whitespace only' );
		assert_same( '', Note_Text::clean( "\u{200B}\u{FEFF}" ), 'invisible characters only' );
	}
);

test(
	'invalid UTF-8 is repaired so the note can be sent as JSON',
	static function (): void {
		$clean = Note_Text::clean( "Gift for Zo\xEB, thanks" );
		assert_same( 1, preg_match( '//u', $clean ), 'valid UTF-8' );
		assert_same( "Gift for Zo\u{FFFD}, thanks", $clean );
		assert_true( false !== json_encode( $clean ), 'JSON encoding works' );
	}
);

test(
	'the fingerprint that stops a note being sent twice changes when the note changes',
	static function (): void {
		assert_same( 64, strlen( Note_Text::hash( 'Gift wrap please' ) ) );
		assert_same( Note_Text::hash( 'Gift wrap please' ), Note_Text::hash( 'Gift wrap please' ) );
		assert_true( Note_Text::hash( 'Gift wrap please' ) !== Note_Text::hash( 'Gift wrap please.' ), 'an edited note has a new fingerprint' );
	}
);

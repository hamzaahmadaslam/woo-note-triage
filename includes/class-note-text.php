<?php
/**
 * Cleans a customer note before it is shown or sent. The cleaned note is the only text about an order that
 * leaves the site.
 *
 * @package Woo_Note_Triage
 */

namespace Woo_Note_Triage;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions over the note text: no WordPress calls, so the tests run them without WordPress.
 */
final class Note_Text {

	/** Characters of a note that are sent. A longer note is cut and ends with "...". */
	public const MAX_CHARS = 2000;

	/** A number with at least this many digits (a phone, card or account number) is replaced by "<number>". */
	public const MIN_REDACTED_DIGITS = 9;

	/**
	 * Returns the note as it is sent to TypeSafe, or an empty string when there is nothing to send.
	 *
	 * @param string $raw The customer note as WooCommerce stores it.
	 */
	public static function clean( string $raw ): string {
		$text = self::valid_utf8( $raw );
		// WooCommerce stores a "<" typed at checkout as "&lt;"; decode entities so the text reads as it was typed.
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\r\n", "\r", "\t" ), array( "\n", "\n", ' ' ), $text );
		// Control characters and the marks that change text direction, which could rearrange a terminal's output.
		$text = (string) preg_replace( '/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}-\x{009F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u', '', $text );
		$text = (string) preg_replace( '/[ \x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]+/u', ' ', $text );
		$text = (string) preg_replace( '/ ?\n ?/', "\n", $text );
		$text = (string) preg_replace( '/\n{3,}/', "\n\n", $text );
		$text = self::redact( trim( $text ) );
		return self::cut( $text, self::MAX_CHARS );
	}

	/**
	 * Replaces email addresses with "<email>" and numbers of nine or more digits with "<number>". Dates, times,
	 * prices, house numbers and short order numbers have fewer digits and stay as they are.
	 *
	 * @param string $text Note text.
	 */
	public static function redact( string $text ): string {
		$text = (string) preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9\-]+(?:\.[A-Z0-9\-]+)*\.[A-Z]{2,}/iu', '<email>', $text );
		// A run of digits joined by single spaces, dots, slashes, dashes or brackets, such as "+44 (0) 20 7946 0958",
		// "4111 1111 1111 1111" or "2026-10-05". A date range such as "2026-10-05 - 2026-10-07" is two runs.
		return (string) preg_replace_callback(
			'/\+?(?:\(\d+\) ?)?\d+(?:(?:[ .\/\-]|\) ?| ?\()\d+)*/u',
			static function ( array $match ): string {
				$digits = strlen( (string) preg_replace( '/\D/', '', $match[0] ) );
				return $digits >= self::MIN_REDACTED_DIGITS ? '<number>' : $match[0];
			},
			$text
		);
	}

	/**
	 * Cuts the text to at most `$max` characters, ending with "..." when it was longer.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum length in characters.
	 */
	public static function cut( string $text, int $max ): string {
		if ( $max < 4 || self::length( $text ) <= $max ) {
			return $text;
		}
		preg_match( '/^.{0,' . ( $max - 3 ) . '}/us', $text, $match );
		return rtrim( $match[0] ?? '' ) . '...';
	}

	/**
	 * Length in characters (not bytes). Works without the mbstring extension.
	 *
	 * @param string $text Valid UTF-8 text.
	 */
	public static function length( string $text ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text, 'UTF-8' );
		}
		return (int) preg_match_all( '/./us', $text );
	}

	/**
	 * The fingerprint of a note, stored on the order so the same note is not sent twice.
	 *
	 * @param string $raw The customer note as WooCommerce stores it.
	 */
	public static function hash( string $raw ): string {
		return hash( 'sha256', $raw );
	}

	/**
	 * Replaces invalid UTF-8 byte sequences with U+FFFD, so the text can be encoded as JSON.
	 *
	 * @param string $text Text in any state.
	 */
	private static function valid_utf8( string $text ): string {
		if ( '' === $text || 1 === preg_match( '//u', $text ) ) {
			return $text;
		}
		return htmlspecialchars_decode( htmlspecialchars( $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' ), ENT_NOQUOTES );
	}
}

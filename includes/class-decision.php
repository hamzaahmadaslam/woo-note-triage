<?php
/**
 * Turns Jev's answers into a decision for one order, and the fixed texts that describe it.
 *
 * @package Woo_Note_Triage
 */

namespace Woo_Note_Triage;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions: no WordPress calls.
 *
 * An order is "triaged" when the category answer is valid and its confidence is at or above the threshold, and
 * the urgency answer is valid. Anything else goes to "review": the order gets no category and no order note, and
 * shows up under "Needs review" in the orders list. The urgency is a position on a scale and never decides on
 * its own; it is shown with its probabilities.
 *
 * Every word in the texts below comes from this file or from Questions; Jev returns numbers only.
 */
final class Decision {

	public const TRIAGED = 'triaged';
	public const REVIEW  = 'review';
	public const ERROR   = 'error';

	/** Category confidence below which an order goes to review, unless the shop sets another value. */
	public const DEFAULT_THRESHOLD = 0.8;

	/**
	 * Reads a threshold from a setting or an option: a number from 0 to 1, clamped. Anything else gives the default.
	 *
	 * @param mixed $value   The value as typed or stored.
	 * @param float $fallback Returned when the value is not a number.
	 */
	public static function parse_threshold( $value, float $fallback = self::DEFAULT_THRESHOLD ): float {
		if ( is_string( $value ) ) {
			$value = trim( $value );
		}
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}
		$number = (float) $value;
		if ( ! is_finite( $number ) ) {
			return $fallback;
		}
		return max( 0.0, min( 1.0, $number ) );
	}

	/**
	 * Whether a value typed on the command line is a threshold: a number from 0 to 1.
	 *
	 * @param mixed $value The value as typed.
	 */
	public static function is_threshold( $value ): bool {
		if ( is_string( $value ) ) {
			$value = trim( $value );
		}
		return is_numeric( $value ) && (float) $value >= 0.0 && (float) $value <= 1.0;
	}

	/**
	 * The decision for one response.
	 *
	 * @param array $response  The decoded response (model, answers, usage).
	 * @param float $threshold Category confidence needed to act, from 0 to 1.
	 * @return array{decision: string, category: ?string, category_confidence: ?float, category_probabilities: array<string, float>, urgency: ?float, urgency_level: ?int, urgency_label: ?string, urgency_confidence: ?float, urgency_probabilities: array<string, float>, reasons: string[], threshold: float, model: string, input_tokens: int}
	 */
	public static function from_response( array $response, float $threshold ): array {
		$answers  = isset( $response['answers'] ) && is_array( $response['answers'] ) ? $response['answers'] : array();
		$category = self::read_category( $answers['category'] ?? null );
		$urgency  = self::read_urgency( $answers['urgency'] ?? null );

		$reasons = array();
		if ( null === $category ) {
			$reasons[] = 'no category answer from Jev';
		} elseif ( $category['confidence'] < $threshold ) {
			$reasons[] = sprintf( 'category confidence %s is below the threshold %s', self::number( $category['confidence'] ), self::number( $threshold ) );
		}
		if ( null === $urgency ) {
			$reasons[] = 'no urgency answer from Jev';
		}

		$usage = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : array();

		return array(
			'decision'               => array() === $reasons ? self::TRIAGED : self::REVIEW,
			'category'               => $category['choice'] ?? null,
			'category_confidence'    => $category['confidence'] ?? null,
			'category_probabilities' => $category['probabilities'] ?? array(),
			'urgency'                => $urgency['score'] ?? null,
			'urgency_level'          => $urgency['level'] ?? null,
			'urgency_label'          => isset( $urgency['level'] ) ? Questions::URGENCY_LABELS[ $urgency['level'] ] : null,
			'urgency_confidence'     => $urgency['confidence'] ?? null,
			'urgency_probabilities'  => $urgency['probabilities'] ?? array(),
			'reasons'                => $reasons,
			'threshold'              => $threshold,
			'model'                  => isset( $response['model'] ) && is_string( $response['model'] ) ? $response['model'] : '',
			'input_tokens'           => isset( $usage['input_tokens'] ) && is_numeric( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0,
		);
	}

	/**
	 * The private order note added when an order is triaged.
	 *
	 * @param array $result A result from from_response().
	 */
	public static function order_note( array $result ): string {
		$category = (string) $result['category'];
		$lines    = array(
			sprintf( 'Customer note triage: %s (confidence %s).', self::label( $category ), self::number( (float) $result['category_confidence'] ) ),
		);
		if ( null !== $result['urgency'] ) {
			$lines[] = sprintf(
				'Urgency %s of 3: %s.',
				self::number( (float) $result['urgency'] ),
				self::top( $result['urgency_probabilities'], 4, false )
			);
		}
		$lines[] = sprintf( 'Category probabilities: %s.', self::top( $result['category_probabilities'], 6, true ) );
		$lines[] = sprintf( 'Model %s, threshold %s.', '' !== $result['model'] ? $result['model'] : 'unknown', self::number( (float) $result['threshold'] ) );
		return implode( "\n", $lines );
	}

	/**
	 * The lines shown in the "Note triage" column of the orders list. Plain text: the caller escapes it.
	 *
	 * @param string $status      The status meta.
	 * @param string $result_json The result meta (JSON).
	 * @param string $error       The error meta.
	 * @return string[]
	 */
	public static function column_lines( string $status, string $result_json, string $error = '' ): array {
		$result = json_decode( $result_json, true );
		$result = is_array( $result ) ? $result : array();

		if ( self::TRIAGED === $status && isset( Questions::CATEGORY_LABELS[ $result['category'] ?? '' ] ) ) {
			$lines = array( ucfirst( self::label( (string) $result['category'] ) ) );
			if ( isset( $result['urgency'] ) && is_numeric( $result['urgency'] ) ) {
				$lines[] = sprintf( 'urgency %s of 3', self::number( (float) $result['urgency'], 1 ) );
			}
			return $lines;
		}
		if ( self::REVIEW === $status ) {
			$lines         = array( 'Needs review' );
			$probabilities = isset( $result['category_probabilities'] ) && is_array( $result['category_probabilities'] ) ? $result['category_probabilities'] : array();
			if ( array() !== $probabilities ) {
				$lines[] = self::top( $probabilities, 2, true );
			}
			return $lines;
		}
		if ( self::ERROR === $status ) {
			return array( 'Not triaged', Note_Text::cut( $error, 80 ) );
		}
		return array();
	}

	/**
	 * The short name of a category, such as "fraud signal".
	 *
	 * @param string $category Category key.
	 */
	public static function label( string $category ): string {
		return Questions::CATEGORY_LABELS[ $category ] ?? $category;
	}

	/**
	 * The most likely options with their probabilities, such as "complaint 0.94, question 0.05". Options that
	 * round to 0.00 are left out.
	 *
	 * @param array<string, float> $probabilities Highest first.
	 * @param int                  $count         How many to list at most.
	 * @param bool                 $categories    Whether the keys are category keys (shown by their names).
	 */
	public static function top( array $probabilities, int $count, bool $categories ): string {
		$parts = array();
		foreach ( $probabilities as $key => $probability ) {
			if ( count( $parts ) >= $count || ! is_numeric( $probability ) || '0.00' === self::number( (float) $probability ) ) {
				continue;
			}
			$parts[] = ( $categories ? self::label( (string) $key ) : (string) $key ) . ' ' . self::number( (float) $probability );
		}
		return implode( ', ', $parts );
	}

	/**
	 * A number with a dot and a fixed count of decimals, whatever the server's locale.
	 *
	 * @param float $value    Number.
	 * @param int   $decimals Decimals.
	 */
	public static function number( float $value, int $decimals = 2 ): string {
		return number_format( $value, $decimals, '.', '' );
	}

	/**
	 * Reads the category answer: a choice among the six options, with a confidence and probabilities.
	 *
	 * @param mixed $answer The answer under "category".
	 * @return array{choice: string, confidence: float, probabilities: array<string, float>}|null
	 */
	private static function read_category( $answer ): ?array {
		if ( ! is_array( $answer ) || 'choice' !== ( $answer['type'] ?? null ) ) {
			return null;
		}
		$choice     = $answer['choice'] ?? null;
		$confidence = self::probability( $answer['confidence'] ?? null );
		if ( ! is_string( $choice ) || ! array_key_exists( $choice, Questions::CATEGORIES ) || null === $confidence ) {
			return null;
		}
		$probabilities = array();
		$given         = isset( $answer['probabilities'] ) && is_array( $answer['probabilities'] ) ? $answer['probabilities'] : array();
		foreach ( array_keys( Questions::CATEGORIES ) as $key ) {
			$probability = self::probability( $given[ $key ] ?? null );
			if ( null !== $probability ) {
				$probabilities[ $key ] = $probability;
			}
		}
		arsort( $probabilities );
		return array(
			'choice'        => $choice,
			'confidence'    => $confidence,
			'probabilities' => $probabilities,
		);
	}

	/**
	 * Reads the urgency answer: a score from 0 to 3, the nearest level, a confidence, and probabilities keyed by
	 * the levels' short names (so they stay a JSON object).
	 *
	 * @param mixed $answer The answer under "urgency".
	 * @return array{score: float, level: int, confidence: float, probabilities: array<string, float>}|null
	 */
	private static function read_urgency( $answer ): ?array {
		if ( ! is_array( $answer ) || 'score' !== ( $answer['type'] ?? null ) ) {
			return null;
		}
		$score      = $answer['score'] ?? null;
		$confidence = self::probability( $answer['confidence'] ?? null );
		if ( ( ! is_int( $score ) && ! is_float( $score ) ) || ! is_finite( (float) $score ) || null === $confidence ) {
			return null;
		}
		$top   = count( Questions::URGENCY_LEVELS ) - 1;
		$score = max( 0.0, min( (float) $top, (float) $score ) );
		// The score is a probability-weighted position; the nearest level is the one it is closest to.
		$level = (int) round( $score );

		$probabilities = array();
		$given         = isset( $answer['probabilities'] ) && is_array( $answer['probabilities'] ) ? $answer['probabilities'] : array();
		foreach ( Questions::URGENCY_LABELS as $index => $label ) {
			$probability = self::probability( $given[ $index ] ?? null );
			if ( null !== $probability ) {
				$probabilities[ $label ] = $probability;
			}
		}
		arsort( $probabilities );
		return array(
			'score'         => $score,
			'level'         => $level,
			'confidence'    => $confidence,
			'probabilities' => $probabilities,
		);
	}

	/**
	 * A probability from 0 to 1, or null when the value is not a number.
	 *
	 * @param mixed $value The value from the response.
	 */
	private static function probability( $value ): ?float {
		if ( ( ! is_int( $value ) && ! is_float( $value ) ) || ! is_finite( (float) $value ) ) {
			return null;
		}
		return max( 0.0, min( 1.0, (float) $value ) );
	}
}

<?php
/**
 * Runs the tests: php tests/run.php
 *
 * A plain runner with no dependencies. Every test is a function; it fails when it throws. Warnings, notices and
 * deprecations are turned into failures too, so the CI run on PHP 8.1 to 8.4 catches them.
 *
 * No test can reach the network: the URL stream wrappers are removed below, and the WordPress HTTP functions in
 * stubs.php throw. Jev is replaced by fixture transports that return recorded-shape answers.
 *
 * @package Woo_Note_Triage
 */

error_reporting( E_ALL );
ini_set( 'display_errors', 'stderr' );
set_error_handler(
	static function ( int $severity, string $message, string $file, int $line ): bool {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

foreach ( array( 'http', 'https', 'ftp', 'ftps' ) as $woo_note_triage_wrapper ) {
	if ( in_array( $woo_note_triage_wrapper, stream_get_wrappers(), true ) ) {
		stream_wrapper_unregister( $woo_note_triage_wrapper );
	}
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'WOO_NOTE_TRIAGE_FILE', dirname( __DIR__ ) . '/woo-note-triage.php' );

require __DIR__ . '/stubs.php';
foreach ( array( 'meta', 'note-text', 'questions', 'decision', 'jev-error', 'jev-client', 'report', 'wp-transport', 'settings', 'triage', 'orders-list', 'cli', 'plugin' ) as $woo_note_triage_class ) {
	require dirname( __DIR__ ) . '/includes/class-' . $woo_note_triage_class . '.php';
}
require dirname( __DIR__ ) . '/examples/build.php';

/**
 * Registers a test.
 *
 * @param string   $name What the test shows.
 * @param callable $body The test.
 */
function test( string $name, callable $body ): void {
	$GLOBALS['woo_note_triage_tests'][] = array( $name, $body );
}

/**
 * Fails unless the two values are identical.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $what     What is compared.
 */
function assert_same( $expected, $actual, string $what = 'value' ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( sprintf( "%s differs.\n    expected: %s\n    actual:   %s", $what, var_export( $expected, true ), var_export( $actual, true ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}
}

/**
 * Fails unless the value is true.
 *
 * @param mixed  $value The value.
 * @param string $what  What must be true.
 */
function assert_true( $value, string $what ): void {
	if ( true !== $value ) {
		throw new RuntimeException( 'Expected true: ' . $what );
	}
}

/**
 * Fails unless the text contains the part.
 *
 * @param string $part The part.
 * @param string $text The text.
 */
function assert_contains( string $part, string $text ): void {
	if ( ! str_contains( $text, $part ) ) {
		throw new RuntimeException( sprintf( "Missing %s in:\n%s", var_export( $part, true ), $text ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}
}

/**
 * Fails if the text contains the part.
 *
 * @param string $part The part.
 * @param string $text The text.
 */
function assert_not_contains( string $part, string $text ): void {
	if ( str_contains( $text, $part ) ) {
		throw new RuntimeException( sprintf( "Unexpected %s in:\n%s", var_export( $part, true ), $text ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}
}

/**
 * Runs the function and returns what it threw; fails when it throws nothing or something else.
 *
 * @param string   $class The expected class.
 * @param callable $body  The code that should throw.
 */
function assert_throws( string $class, callable $body ): Throwable {
	try {
		$body();
	} catch ( Throwable $thrown ) {
		if ( ! $thrown instanceof $class ) {
			throw new RuntimeException( sprintf( 'Expected %s, got %s: %s', $class, get_class( $thrown ), $thrown->getMessage() ) );
		}
		return $thrown;
	}
	throw new RuntimeException( 'Expected ' . $class . ', nothing was thrown' );
}

/**
 * Reads a JSON file from tests/fixtures.
 *
 * @param string $name File name.
 */
function fixture( string $name ): array {
	return json_decode( (string) file_get_contents( __DIR__ . '/fixtures/' . $name ), true, 512, JSON_THROW_ON_ERROR );
}

/**
 * A response in the API's shape with the given answers.
 *
 * @param array|null $category Category answer, or null to leave it out.
 * @param array|null $urgency  Urgency answer, or null to leave it out.
 */
function response_with( ?array $category, ?array $urgency ): array {
	$answers = array();
	if ( null !== $category ) {
		$answers['category'] = $category;
	}
	if ( null !== $urgency ) {
		$answers['urgency'] = $urgency;
	}
	return array(
		'model'   => 'jev-1.13.0',
		'answers' => $answers,
		'usage'   => array(
			'input_tokens'  => 812,
			'output_tokens' => 40,
		),
	);
}

/**
 * A category answer.
 *
 * @param string $choice        The chosen option.
 * @param float  $confidence    Its confidence.
 * @param array  $probabilities Probabilities of some options; the rest are 0.
 */
function category_answer( string $choice, float $confidence, array $probabilities ): array {
	$all = array_fill_keys( array_keys( Woo_Note_Triage\Questions::CATEGORIES ), 0.0 );
	return array(
		'type'          => 'choice',
		'choice'        => $choice,
		'probabilities' => array_merge( $all, $probabilities ),
		'confidence'    => $confidence,
	);
}

/**
 * An urgency answer.
 *
 * @param float $score         The score from 0 to 3.
 * @param float $confidence    Its confidence.
 * @param array $probabilities Probabilities by level number; the rest are 0.
 */
function urgency_answer( float $score, float $confidence, array $probabilities ): array {
	return array(
		'type'          => 'score',
		'score'         => $score,
		'legend'        => array_map(
			static function ( array $level ): string {
				return $level['what'];
			},
			Woo_Note_Triage\Questions::URGENCY_LEVELS
		),
		'probabilities' => array_replace( array( 0.0, 0.0, 0.0, 0.0 ), $probabilities ),
		'confidence'    => $confidence,
	);
}

/**
 * A transport that answers from a list of canned responses, one per call, and records the calls.
 *
 * @param array $responses Each: a response array, or an int status, or array( 'error' => '...' ).
 * @param array $calls     Filled with array( url, request ) per call.
 */
function fixture_transport( array $responses, ?array &$calls ): Closure {
	$calls = array();
	return static function ( string $url, array $request ) use ( &$responses, &$calls ): array {
		$calls[] = array( $url, $request );
		$next    = array_shift( $responses );
		if ( null === $next ) {
			throw new RuntimeException( 'The fixture transport ran out of responses' );
		}
		if ( is_int( $next ) ) {
			return array(
				'status'      => $next,
				'body'        => '{"error":"fixture"}',
				'retry_after' => '',
			);
		}
		if ( isset( $next['error'] ) || isset( $next['status'] ) ) {
			return $next;
		}
		return array(
			'status'      => 200,
			'body'        => (string) json_encode( $next ),
			'retry_after' => '',
		);
	};
}

$GLOBALS['woo_note_triage_tests'] = array();
foreach ( glob( __DIR__ . '/test-*.php' ) as $woo_note_triage_file ) {
	require $woo_note_triage_file;
}

$woo_note_triage_failed = 0;
foreach ( $GLOBALS['woo_note_triage_tests'] as $woo_note_triage_index => list( $woo_note_triage_name, $woo_note_triage_body ) ) {
	reset_wordpress();
	try {
		$woo_note_triage_body();
		echo 'ok ', $woo_note_triage_index + 1, ' - ', $woo_note_triage_name, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	} catch ( Throwable $woo_note_triage_error ) {
		++$woo_note_triage_failed;
		echo 'not ok ', $woo_note_triage_index + 1, ' - ', $woo_note_triage_name, "\n    ", str_replace( "\n", "\n    ", $woo_note_triage_error->getMessage() ), "\n    at ", basename( $woo_note_triage_error->getFile() ), ':', $woo_note_triage_error->getLine(), "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
$woo_note_triage_total = count( $GLOBALS['woo_note_triage_tests'] );
echo "\n", $woo_note_triage_total, ' tests, ', $woo_note_triage_total - $woo_note_triage_failed, ' passed, ', $woo_note_triage_failed, " failed\n"; // phpcs:ignore WordPress.Security.EscapeOutput
exit( $woo_note_triage_failed > 0 ? 1 : 0 );

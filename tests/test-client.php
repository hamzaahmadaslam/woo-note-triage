<?php
/**
 * The client: what is sent, retries, and errors. A fixture transport stands in for TypeSafe.
 *
 * @package Woo_Note_Triage
 */

use Woo_Note_Triage\Jev_Client;
use Woo_Note_Triage\Jev_Error;
use Woo_Note_Triage\Questions;
use Woo_Note_Triage\WP_Transport;

/**
 * A client whose transport answers from the list, recording the calls and the waits.
 *
 * @param array      $responses Canned responses (see fixture_transport()).
 * @param array|null $calls     Filled with the calls made.
 * @param array|null $sleeps    Filled with the seconds waited.
 */
function client_with( array $responses, ?array &$calls, ?array &$sleeps ): Jev_Client {
	$sleeps = array();
	return new Jev_Client(
		'fixture-key-not-real',
		'jev-latest',
		fixture_transport( $responses, $calls ),
		array(
			'sleep' => static function ( int $seconds ) use ( &$sleeps ): void {
				$sleeps[] = $seconds;
			},
		)
	);
}

test(
	'a request goes to the one endpoint, with the key only in the Authorization header and the body the plugin built',
	static function (): void {
		$client   = client_with( array( fixture( 'response-complaint.json' ) ), $calls, $sleeps );
		$response = $client->ask( 'Thanks!', Questions::questions() );
		assert_same( 'complaint', $response['answers']['category']['choice'] );
		assert_same( 1, count( $calls ), 'requests' );
		list( $url, $request ) = $calls[0];
		assert_same( 'https://api.typesafe.ai/v1/systemone', $url );
		assert_same( 'Bearer fixture-key-not-real', $request['headers']['Authorization'] );
		assert_same( 'application/json', $request['headers']['Content-Type'] );
		assert_same( 10, $request['timeout'] );
		assert_same( Questions::request_body( 'Thanks!', 'jev-latest' ), json_decode( $request['body'], true ), 'body' );
		assert_not_contains( 'fixture-key-not-real', $request['body'] );
		assert_same( array(), $sleeps, 'waits' );
	}
);

test(
	'a 429 is tried again after the retry-after seconds, never waiting longer than the cap',
	static function (): void {
		$client = client_with(
			array(
				array(
					'status'      => 429,
					'body'        => '',
					'retry_after' => '3',
				),
				array(
					'status'      => 429,
					'body'        => '',
					'retry_after' => '120',
				),
				fixture( 'response-complaint.json' ),
			),
			$calls,
			$sleeps
		);
		$client->ask( 'Thanks!', Questions::questions() );
		assert_same( 3, count( $calls ), 'requests' );
		assert_same( array( 3, 10 ), $sleeps, 'waits in seconds' );
	}
);

test(
	'a 529 on every attempt becomes a plain error that can be retried later',
	static function (): void {
		$client = client_with( array( 529, 529, 529 ), $calls, $sleeps );
		$error  = assert_throws(
			Jev_Error::class,
			static function () use ( $client ): void {
				$client->ask( 'Thanks!', Questions::questions() );
			}
		);
		assert_same( 'TypeSafe is overloaded (HTTP 529). Try again later.', $error->getMessage() );
		assert_same( 529, $error->status() );
		assert_true( $error->retryable(), 'retryable' );
		assert_same( 3, count( $calls ), 'requests: one plus two retries' );
		assert_same( array( 1, 2 ), $sleeps, 'backoff in seconds' );
	}
);

test(
	'a refused key stops at once, and the key never appears in an error message',
	static function (): void {
		$client = client_with(
			array(
				array(
					'status'      => 401,
					'body'        => '{"error":"invalid key fixture-key-not-real"}',
					'retry_after' => '',
				),
			),
			$calls,
			$sleeps
		);
		$error = assert_throws(
			Jev_Error::class,
			static function () use ( $client ): void {
				$client->ask( 'Thanks!', Questions::questions() );
			}
		);
		assert_same( 'TypeSafe refused the API key (HTTP 401). Check the key under WooCommerce > Note triage.', $error->getMessage() );
		assert_same( false, $error->retryable() );
		assert_same( 1, count( $calls ), 'requests' );

		$client = client_with(
			array(
				array(
					'status'      => 422,
					'body'        => '{"detail":"questions.category.criteria is invalid; request had Bearer fixture-key-not-real"}',
					'retry_after' => '',
				),
			),
			$calls,
			$sleeps
		);
		$error = assert_throws(
			Jev_Error::class,
			static function () use ( $client ): void {
				$client->ask( 'Thanks!', Questions::questions() );
			}
		);
		assert_same( 'TypeSafe rejected the request as invalid (HTTP 422): {"detail":"questions.category.criteria is invalid; request had Bearer <key>"}', $error->getMessage() );
		assert_same( false, $error->retryable() );
	}
);

test(
	'a timeout is tried again, then reported as no answer within the time limit',
	static function (): void {
		$timeout = array( 'error' => 'cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received' );
		$client  = client_with( array( $timeout, $timeout, $timeout ), $calls, $sleeps );
		$error   = assert_throws(
			Jev_Error::class,
			static function () use ( $client ): void {
				$client->ask( 'Thanks!', Questions::questions() );
			}
		);
		assert_same( 'Could not reach TypeSafe: no answer within 10 seconds.', $error->getMessage() );
		assert_same( 0, $error->status() );
		assert_true( $error->retryable(), 'retryable' );
		assert_same( 3, count( $calls ), 'requests' );
	}
);

test(
	'without a key nothing is sent',
	static function (): void {
		$transport = fixture_transport( array(), $calls );
		$client    = new Jev_Client( '  ', 'jev-latest', $transport );
		$error     = assert_throws(
			Jev_Error::class,
			static function () use ( $client ): void {
				$client->ask( 'Thanks!', Questions::questions() );
			}
		);
		assert_contains( 'No TypeSafe API key is set.', $error->getMessage() );
		assert_same( array(), $calls, 'requests' );
	}
);

test(
	'an answer without answers is an error; a server error is not retried at once but can be later',
	static function (): void {
		$client = client_with(
			array(
				array(
					'status'      => 200,
					'body'        => '<html>maintenance</html>',
					'retry_after' => '',
				),
			),
			$calls,
			$sleeps
		);
		$error  = assert_throws(
			Jev_Error::class,
			static function () use ( $client ): void {
				$client->ask( 'Thanks!', Questions::questions() );
			}
		);
		assert_same( 'TypeSafe answered without answers.', $error->getMessage() );
		assert_same( false, $error->retryable() );

		$client = client_with( array( 503 ), $calls, $sleeps );
		$error  = assert_throws(
			Jev_Error::class,
			static function () use ( $client ): void {
				$client->ask( 'Thanks!', Questions::questions() );
			}
		);
		assert_same( 'TypeSafe returned HTTP 503. Try again later.', $error->getMessage() );
		assert_true( $error->retryable(), 'retryable later' );
		assert_same( 1, count( $calls ), 'requests' );
	}
);

test(
	'the WordPress transport refuses any address but the endpoint',
	static function (): void {
		assert_same(
			array( 'error' => 'refused a request to an address other than api.typesafe.ai' ),
			WP_Transport::send( 'https://example.com/v1/systemone', array() )
		);
		$error = assert_throws(
			RuntimeException::class,
			static function (): void {
				WP_Transport::send( Jev_Client::ENDPOINT, array() );
			}
		);
		assert_same( 'A test tried to reach the network.', $error->getMessage(), 'the endpoint goes to wp_remote_post, which the tests block' );
	}
);

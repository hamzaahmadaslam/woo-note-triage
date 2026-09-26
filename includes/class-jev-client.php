<?php
/**
 * A small client for TypeSafe's System One API (Jev).
 *
 * @package Woo_Note_Triage
 */

namespace Woo_Note_Triage;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one request (model, state, questions) and returns the decoded response. The HTTP call itself is a
 * transport function passed in, so the tests use fixture answers and never reach the network; in WordPress the
 * transport is WP_Transport::send().
 *
 * Retries 429 and 529 responses and failed connections a bounded number of times with backoff (honouring a
 * numeric retry-after header up to a cap) and turns everything else into a Jev_Error with a plain message.
 * The API key is only ever placed in the Authorization header and is removed from any message.
 */
final class Jev_Client {

	/** The only address the plugin sends anything to. */
	public const ENDPOINT = 'https://api.typesafe.ai/v1/systemone';

	/** TypeSafe's alias for its current stable model. */
	public const DEFAULT_MODEL = 'jev-latest';

	/**
	 * The API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * The model name sent with each request.
	 *
	 * @var string
	 */
	private $model;

	/**
	 * Transport: function ( string $url, array $request ): array.
	 *
	 * @var \Closure
	 */
	private $transport;

	/**
	 * Waits the given number of seconds between attempts.
	 *
	 * @var \Closure
	 */
	private $sleep;

	/**
	 * Seconds before one attempt gives up.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Extra attempts after a 429, a 529 or a failed connection.
	 *
	 * @var int
	 */
	private $retries;

	/**
	 * The longest wait between attempts, in seconds.
	 *
	 * @var int
	 */
	private $max_wait;

	/**
	 * Creates a client.
	 *
	 * @param string   $api_key   TypeSafe API key.
	 * @param string   $model     Model name; empty means DEFAULT_MODEL.
	 * @param callable $transport function ( string $url, array $request ): array. The request has `headers`,
	 *                            `body` and `timeout`. It returns `status`, `body` and `retry_after`, or `error`
	 *                            (a message) when no response arrived.
	 * @param array    $options   `timeout` (seconds, default 10), `retries` (default 2), `max_wait` (seconds,
	 *                            default 10) and `sleep` (a function taking seconds, default sleep()).
	 */
	public function __construct( #[\SensitiveParameter] string $api_key, string $model, callable $transport, array $options = array() ) {
		$this->api_key   = trim( $api_key );
		$this->model     = '' !== trim( $model ) ? trim( $model ) : self::DEFAULT_MODEL;
		$this->transport = \Closure::fromCallable( $transport );
		$this->sleep     = \Closure::fromCallable( $options['sleep'] ?? 'sleep' );
		$this->timeout   = max( 1, (int) ( $options['timeout'] ?? 10 ) );
		$this->retries   = max( 0, (int) ( $options['retries'] ?? 2 ) );
		$this->max_wait  = max( 0, (int) ( $options['max_wait'] ?? 10 ) );
	}

	/** The model name this client sends. */
	public function model(): string {
		return $this->model;
	}

	/**
	 * Asks the questions about the state and returns the decoded response (model, answers, usage).
	 *
	 * @param string $state     The cleaned note.
	 * @param array  $questions The questions (Questions::questions()).
	 * @return array<string, mixed>
	 * @throws Jev_Error When there is no key, TypeSafe refuses the request, or no answer arrives.
	 */
	public function ask( string $state, array $questions ): array {
		if ( '' === $this->api_key ) {
			throw new Jev_Error( 'No TypeSafe API key is set. Save one under WooCommerce > Note triage, or define TYPESAFE_API_KEY in wp-config.php.' );
		}
		$request = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'    => Questions::json(
				array(
					'model'     => $this->model,
					'state'     => $state,
					'questions' => $questions,
				)
			),
			'timeout' => $this->timeout,
		);

		for ( $attempt = 0; ; $attempt++ ) {
			$response = ( $this->transport )( self::ENDPOINT, $request );

			if ( ! is_array( $response ) || isset( $response['error'] ) || ! isset( $response['status'] ) ) {
				$reason = is_array( $response ) && isset( $response['error'] ) ? (string) $response['error'] : 'no response';
				if ( $attempt < $this->retries ) {
					$this->wait( $attempt, '' );
					continue;
				}
				throw new Jev_Error( 'Could not reach TypeSafe: ' . $this->connection_reason( $reason ), 0, true );
			}

			$status = (int) $response['status'];
			$body   = (string) ( $response['body'] ?? '' );

			if ( $status >= 200 && $status < 300 ) {
				$data = json_decode( $body, true );
				if ( ! is_array( $data ) || ! isset( $data['answers'] ) || ! is_array( $data['answers'] ) ) {
					throw new Jev_Error( 'TypeSafe answered without answers.', $status );
				}
				return $data;
			}

			if ( ( 429 === $status || 529 === $status ) && $attempt < $this->retries ) {
				$this->wait( $attempt, (string) ( $response['retry_after'] ?? '' ) );
				continue;
			}

			throw $this->http_error( $status, $body );
		}
	}

	/**
	 * Waits before the next attempt: the retry-after seconds when TypeSafe sends a number, otherwise 1, 2, 4 and
	 * so on, never longer than max_wait.
	 *
	 * @param int    $attempt     Attempts made so far, minus one.
	 * @param string $retry_after The retry-after header, if any.
	 */
	private function wait( int $attempt, string $retry_after ): void {
		$seconds = 1 === preg_match( '/^\d{1,6}$/', trim( $retry_after ) ) ? (int) trim( $retry_after ) : 2 ** min( $attempt, 10 );
		$seconds = min( $this->max_wait, max( 1, $seconds ) );
		( $this->sleep )( $seconds );
	}

	/**
	 * The error for a response TypeSafe refused.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Response body, used only for a 422's explanation.
	 */
	private function http_error( int $status, string $body ): Jev_Error {
		if ( 401 === $status ) {
			return new Jev_Error( 'TypeSafe refused the API key (HTTP 401). Check the key under WooCommerce > Note triage.', 401 );
		}
		if ( 422 === $status ) {
			$detail = $this->plain( $body, 200 );
			return new Jev_Error( 'TypeSafe rejected the request as invalid (HTTP 422)' . ( '' !== $detail ? ': ' . $detail : '.' ), 422 );
		}
		if ( 429 === $status ) {
			return new Jev_Error( 'The TypeSafe rate limit was reached (HTTP 429). Try again later.', 429, true );
		}
		if ( 529 === $status ) {
			return new Jev_Error( 'TypeSafe is overloaded (HTTP 529). Try again later.', 529, true );
		}
		if ( $status >= 500 ) {
			return new Jev_Error( sprintf( 'TypeSafe returned HTTP %d. Try again later.', $status ), $status, true );
		}
		return new Jev_Error( sprintf( 'TypeSafe returned HTTP %d.', $status ), $status );
	}

	/**
	 * Why a connection failed, in a few words.
	 *
	 * @param string $reason The transport's message.
	 */
	private function connection_reason( string $reason ): string {
		if ( 1 === preg_match( '/timed? ?out|curl error 28/i', $reason ) ) {
			return sprintf( 'no answer within %d seconds.', $this->timeout );
		}
		$plain = $this->plain( $reason, 200 );
		return '' !== $plain ? $plain : 'no response.';
	}

	/**
	 * One line of text: control characters removed, spaces collapsed, cut to `$max` characters, key removed.
	 *
	 * @param string $text Any text from a response or a transport.
	 * @param int    $max  Maximum length in characters.
	 */
	private function plain( string $text, int $max ): string {
		if ( '' !== $this->api_key ) {
			$text = str_replace( $this->api_key, '<key>', $text );
		}
		$text = Note_Text::clean( $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return Note_Text::cut( $text, $max );
	}
}

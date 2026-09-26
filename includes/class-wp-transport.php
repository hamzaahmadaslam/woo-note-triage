<?php
/**
 * The HTTP call to TypeSafe, through WordPress's HTTP API. This is the only place the plugin makes a request.
 *
 * @package Woo_Note_Triage
 */

namespace Woo_Note_Triage;

defined( 'ABSPATH' ) || exit;

/**
 * The transport Jev_Client uses inside WordPress.
 */
final class WP_Transport {

	/** Responses larger than this are cut; an answer for two questions is a few kilobytes. */
	private const MAX_RESPONSE_BYTES = 1048576;

	/**
	 * Sends one POST request with wp_remote_post().
	 *
	 * Redirects are not followed, so the request cannot end up on another host. The user agent names the plugin
	 * and its version only; WordPress's default user agent would include the site's address.
	 *
	 * @param string $url     Must be Jev_Client::ENDPOINT; anything else is refused.
	 * @param array  $request headers, body, timeout.
	 * @return array{status: int, body: string, retry_after: string}|array{error: string}
	 */
	public static function send( string $url, array $request ): array {
		if ( Jev_Client::ENDPOINT !== $url ) {
			return array( 'error' => 'refused a request to an address other than api.typesafe.ai' );
		}
		$response = wp_remote_post(
			$url,
			array(
				'timeout'             => (int) ( $request['timeout'] ?? 10 ),
				'redirection'         => 0,
				'httpversion'         => '1.1',
				'user-agent'          => 'woo-note-triage/' . Plugin::VERSION,
				'headers'             => $request['headers'] ?? array(),
				'body'                => (string) ( $request['body'] ?? '' ),
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
				'sslverify'           => true,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}
		$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( is_array( $retry_after ) ) {
			$retry_after = (string) reset( $retry_after );
		}
		return array(
			'status'      => (int) wp_remote_retrieve_response_code( $response ),
			'body'        => (string) wp_remote_retrieve_body( $response ),
			'retry_after' => (string) $retry_after,
		);
	}
}

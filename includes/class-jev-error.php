<?php
/**
 * An error from talking to TypeSafe, with a message fit to show to a shop manager.
 *
 * @package Woo_Note_Triage
 */

namespace Woo_Note_Triage;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the HTTP status (0 when no response arrived) and whether trying again later could succeed.
 */
final class Jev_Error extends \RuntimeException {

	/**
	 * HTTP status, or 0 when no response arrived.
	 *
	 * @var int
	 */
	private $status;

	/**
	 * Whether the same request may succeed later (rate limit, overload, timeout, server error).
	 *
	 * @var bool
	 */
	private $retryable;

	/**
	 * Creates the error.
	 *
	 * @param string $message   Plain message, never containing the API key.
	 * @param int    $status    HTTP status, or 0.
	 * @param bool   $retryable Whether trying again later could succeed.
	 */
	public function __construct( string $message, int $status = 0, bool $retryable = false ) {
		parent::__construct( $message, $status );
		$this->status    = $status;
		$this->retryable = $retryable;
	}

	/** HTTP status, or 0 when no response arrived. */
	public function status(): int {
		return $this->status;
	}

	/** Whether the same request may succeed later. */
	public function retryable(): bool {
		return $this->retryable;
	}
}

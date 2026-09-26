<?php
/**
 * The order meta keys the plugin writes.
 *
 * @package Woo_Note_Triage
 */

namespace Woo_Note_Triage;

defined( 'ABSPATH' ) || exit;

/**
 * Order meta keys. The leading underscore keeps them out of the custom fields box on the order screen.
 * They are written through the order object, so they work with both order storage modes (HPOS and posts).
 */
final class Meta {

	/** One of "triaged", "review" or "error". */
	public const STATUS = '_woo_note_triage_status';

	/** The category key, such as "complaint". Written only when the status is "triaged". */
	public const CATEGORY = '_woo_note_triage_category';

	/** The urgency score from 0 to 3, with two decimals. */
	public const URGENCY = '_woo_note_triage_urgency';

	/** The whole result as JSON: probabilities, confidences, model and threshold. */
	public const RESULT = '_woo_note_triage_result';

	/** SHA-256 of the customer note that was triaged, so the same note is never sent twice. */
	public const HASH = '_woo_note_triage_hash';

	/** The last error message. Written only when the status is "error". */
	public const ERROR = '_woo_note_triage_error';
}

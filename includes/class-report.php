<?php
/**
 * The text and JSON printed by `wp woo-note-triage backfill`.
 *
 * @package Woo_Note_Triage
 */

namespace Woo_Note_Triage;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions: the command gathers orders and answers, and this class writes them out. The example files in
 * `examples/` are made by the same functions.
 */
final class Report {

	/**
	 * The report after a run.
	 *
	 * @param array $run  version, days, threshold, model, orders, with_note, skipped, limit, limit_reached,
	 *                    requests, input_tokens.
	 * @param array $rows Each: order_id, date (ISO 8601), result (Decision::from_response()).
	 */
	public static function text( array $run, array $rows ): string {
		$triaged = self::sorted( $rows, Decision::TRIAGED );
		$review  = self::sorted( $rows, Decision::REVIEW );

		$lines   = array( 'woo-note-triage ' . $run['version'] );
		$lines[] = self::counts( $run ) . sprintf( ' Sent: %d.', count( $rows ) );
		if ( ! empty( $run['limit_reached'] ) ) {
			$lines[] = self::limit_line( $run );
		}
		$lines[] = sprintf(
			'Jev: model %s, %s %s, %s input tokens',
			'' !== (string) $run['model'] ? $run['model'] : 'unknown',
			number_format( (int) $run['requests'] ),
			1 === (int) $run['requests'] ? 'request' : 'requests',
			number_format( (int) $run['input_tokens'] )
		);
		$lines[] = sprintf( 'Threshold %s: %d triaged, %d for review', Decision::number( (float) $run['threshold'] ), count( $triaged ), count( $review ) );

		if ( array() !== $triaged ) {
			$lines[] = '';
			$lines[] = 'Triaged, most urgent first';
			$lines[] = '';
			foreach ( $triaged as $row ) {
				array_push( $lines, ...self::order_lines( $row ) );
			}
		}
		if ( array() !== $review ) {
			$lines[] = '';
			$lines[] = 'For review, most urgent first';
			$lines[] = '';
			foreach ( $review as $row ) {
				array_push( $lines, ...self::order_lines( $row ) );
			}
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The report for --dry-run: the questions, the orders that would be sent and a token estimate.
	 *
	 * @param array $run   version, days, threshold, model, orders, with_note, skipped, limit, limit_reached.
	 * @param array $items Each: order_id, date (ISO 8601), note (the cleaned note).
	 */
	public static function dry_run_text( array $run, array $items ): string {
		$tokens = 0;
		foreach ( $items as $item ) {
			$tokens += Questions::estimate_tokens( Questions::request_body( $item['note'], $run['model'] ) );
		}
		$lines = array(
			'woo-note-triage ' . $run['version'] . ' (dry run: nothing is sent and no order changes)',
			self::counts( $run ),
		);
		if ( ! empty( $run['limit_reached'] ) ) {
			$lines[] = self::limit_line( $run );
		}
		$lines[] = sprintf(
			'Would send %s %s to %s, one per order: about %s input tokens',
			number_format( count( $items ) ),
			1 === count( $items ) ? 'request' : 'requests',
			Jev_Client::ENDPOINT,
			number_format( $tokens )
		);
		$lines[] = sprintf( 'Model %s, threshold %s', $run['model'], Decision::number( (float) $run['threshold'] ) );
		$lines[] = '';
		$lines[] = 'Each request body is {"model", "state", "questions"}. The state is the cleaned note and nothing else.';
		$lines[] = 'The questions are the same for every order:';
		$lines[] = '';
		$lines[] = Questions::json( Questions::questions(), true );
		if ( array() !== $items ) {
			$lines[] = '';
			$lines[] = 'Orders that would be sent';
			$lines[] = '';
			foreach ( $items as $item ) {
				$lines[] = sprintf(
					'#%d  %s  %s characters, about %s tokens',
					(int) $item['order_id'],
					substr( (string) $item['date'], 0, 10 ),
					number_format( Note_Text::length( $item['note'] ) ),
					number_format( Questions::estimate_tokens( Questions::request_body( $item['note'], $run['model'] ) ) )
				);
			}
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The JSON report after a run: the same facts as text(), with every result in full, in the order processed.
	 *
	 * @param array $run  As for text().
	 * @param array $rows As for text().
	 */
	public static function json( array $run, array $rows ): string {
		$orders = array();
		foreach ( $rows as $row ) {
			$orders[] = array(
				'order_id'     => (int) $row['order_id'],
				'date_created' => (string) $row['date'],
			) + $row['result'];
		}
		$data = self::json_header( $run, false ) + array(
			'requests'     => (int) $run['requests'],
			'input_tokens' => (int) $run['input_tokens'],
			'triaged'      => count( self::sorted( $rows, Decision::TRIAGED ) ),
			'review'       => count( self::sorted( $rows, Decision::REVIEW ) ),
			'orders'       => $orders,
		);
		return Questions::json( $data, true ) . "\n";
	}

	/**
	 * The JSON for --dry-run: the questions once, then the state and token estimate of every request.
	 *
	 * @param array $run   As for dry_run_text().
	 * @param array $items As for dry_run_text().
	 */
	public static function dry_run_json( array $run, array $items ): string {
		$orders = array();
		$tokens = 0;
		foreach ( $items as $item ) {
			$estimate  = Questions::estimate_tokens( Questions::request_body( $item['note'], $run['model'] ) );
			$tokens   += $estimate;
			$orders[] = array(
				'order_id'         => (int) $item['order_id'],
				'date_created'     => (string) $item['date'],
				'estimated_tokens' => $estimate,
				'state'            => $item['note'],
			);
		}
		$data = self::json_header( $run, true ) + array(
			'endpoint'               => Jev_Client::ENDPOINT,
			'requests'               => count( $items ),
			'estimated_input_tokens' => $tokens,
			'questions'              => Questions::questions(),
			'orders'                 => $orders,
		);
		return Questions::json( $data, true ) . "\n";
	}

	/**
	 * The line that says how many orders were looked at.
	 *
	 * @param array $run The run facts.
	 */
	private static function counts( array $run ): string {
		return sprintf(
			'Orders created in the last %d %s: %s. With a customer note: %s. Already triaged, skipped: %s.',
			(int) $run['days'],
			1 === (int) $run['days'] ? 'day' : 'days',
			number_format( (int) $run['orders'] ),
			number_format( (int) $run['with_note'] ),
			number_format( (int) $run['skipped'] )
		);
	}

	/**
	 * The line under the counts when --limit stopped the search early: the counts leave out the older orders.
	 *
	 * @param array $run The run facts.
	 */
	private static function limit_line( array $run ): string {
		return sprintf( 'Stopped at --limit=%d. Older orders were not looked at and are not counted above.', (int) $run['limit'] );
	}

	/**
	 * The fields every JSON report starts with.
	 *
	 * @param array $run     The run facts.
	 * @param bool  $dry_run Whether this is a dry run.
	 */
	private static function json_header( array $run, bool $dry_run ): array {
		return array(
			'tool'                    => 'woo-note-triage',
			'version'                 => (string) $run['version'],
			'dry_run'                 => $dry_run,
			'days'                    => (int) $run['days'],
			'threshold'               => (float) $run['threshold'],
			'model'                   => (string) $run['model'],
			'orders_in_range'         => (int) $run['orders'],
			'orders_with_note'        => (int) $run['with_note'],
			'skipped_already_triaged' => (int) $run['skipped'],
			'limit_reached'           => ! empty( $run['limit_reached'] ),
		);
	}

	/**
	 * The lines for one order in the text report.
	 *
	 * @param array $row order_id, date, result.
	 * @return string[]
	 */
	private static function order_lines( array $row ): array {
		$result   = $row['result'];
		$category = null !== $result['category']
			? sprintf( '%s, confidence %s', Decision::label( (string) $result['category'] ), Decision::number( (float) $result['category_confidence'] ) )
			: 'no category';
		$urgency  = null !== $result['urgency'] ? sprintf( 'urgency %s of 3', Decision::number( (float) $result['urgency'] ) ) : 'no urgency';

		$lines = array( sprintf( '#%d  %s  %s  %s', (int) $row['order_id'], substr( (string) $row['date'], 0, 10 ), $category, $urgency ) );
		if ( array() !== $result['category_probabilities'] ) {
			$lines[] = '       Category: ' . Decision::top( $result['category_probabilities'], 3, true );
		}
		if ( array() !== $result['urgency_probabilities'] ) {
			$lines[] = '       Urgency: ' . Decision::top( $result['urgency_probabilities'], 4, false );
		}
		if ( array() !== $result['reasons'] ) {
			$lines[] = '       Review: ' . implode( '; ', $result['reasons'] );
		}
		return $lines;
	}

	/**
	 * The rows with one decision, most urgent first; rows without an urgency come last, then by order number.
	 *
	 * @param array  $rows     All rows.
	 * @param string $decision Decision::TRIAGED or Decision::REVIEW.
	 */
	private static function sorted( array $rows, string $decision ): array {
		$picked = array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $decision ): bool {
					return $decision === $row['result']['decision'];
				}
			)
		);
		usort(
			$picked,
			static function ( array $a, array $b ): int {
				$by_urgency = ( $b['result']['urgency'] ?? -1.0 ) <=> ( $a['result']['urgency'] ?? -1.0 );
				return 0 !== $by_urgency ? $by_urgency : ( (int) $b['order_id'] <=> (int) $a['order_id'] );
			}
		);
		return $picked;
	}
}

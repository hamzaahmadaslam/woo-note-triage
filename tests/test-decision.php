<?php
/**
 * From answers to a decision: the threshold logic and the fixed texts.
 *
 * @package Woo_Note_Triage
 */

use Woo_Note_Triage\Decision;
use Woo_Note_Triage\Questions;

test(
	'a confident answer is triaged with its category, urgency and probabilities',
	static function (): void {
		$result = Decision::from_response( fixture( 'response-complaint.json' ), 0.8 );
		assert_same( 'triaged', $result['decision'] );
		assert_same( 'complaint', $result['category'] );
		assert_same( 0.9, $result['category_confidence'] );
		assert_same( array( 'complaint', 'question', 'delivery-instruction', 'gift-message', 'fraud-signal', 'other' ), array_keys( $result['category_probabilities'] ), 'highest first' );
		assert_same( 2.71, $result['urgency'] );
		assert_same( 3, $result['urgency_level'] );
		assert_same( 'before shipping', $result['urgency_label'] );
		assert_same(
			array(
				'before shipping' => 0.72,
				'reply soon'      => 0.27,
				'when packing'    => 0.01,
				'nothing to do'   => 0.0,
			),
			$result['urgency_probabilities']
		);
		assert_same( array(), $result['reasons'] );
		assert_same( 'jev-1.13.0', $result['model'] );
		assert_same( 1034, $result['input_tokens'] );
	}
);

test(
	'a category confidence at the threshold is triaged; just below it goes to review with the reason',
	static function (): void {
		$urgency = urgency_answer( 2.0, 0.9, array( 2 => 1.0 ) );
		$answer  = static function ( float $confidence ) use ( $urgency ): array {
			return response_with(
				category_answer(
					'question',
					$confidence,
					array(
						'question' => 0.84,
						'other'    => 0.16,
					)
				),
				$urgency
			);
		};
		assert_same( 'triaged', Decision::from_response( $answer( 0.81 ), 0.8 )['decision'], 'above' );
		assert_same( 'triaged', Decision::from_response( $answer( 0.8 ), 0.8 )['decision'], 'at' );
		$below = Decision::from_response( $answer( 0.79 ), 0.8 );
		assert_same( 'review', $below['decision'], 'below' );
		assert_same( array( 'category confidence 0.79 is below the threshold 0.80' ), $below['reasons'] );
		assert_same( 'question', $below['category'], 'the top option is kept for review' );
		assert_same( 'review', Decision::from_response( $answer( 0.85 ), 0.9 )['decision'], 'a stricter threshold' );
	}
);

test(
	'the urgency confidence never decides, but a missing urgency answer sends the order to review',
	static function (): void {
		$category = category_answer(
			'complaint',
			0.95,
			array(
				'complaint' => 0.96,
				'question'  => 0.04,
			)
		);
		$split    = Decision::from_response(
			response_with(
				$category,
				urgency_answer(
					2.5,
					0.1,
					array(
						2 => 0.5,
						3 => 0.5,
					)
				)
			),
			0.8
		);
		assert_same( 'triaged', $split['decision'], 'a split urgency' );
		assert_same( 3, $split['urgency_level'], 'a score of 2.5 rounds to level 3' );
		$missing = Decision::from_response( response_with( $category, null ), 0.8 );
		assert_same( 'review', $missing['decision'], 'no urgency answer' );
		assert_same( array( 'no urgency answer from Jev' ), $missing['reasons'] );
		assert_same( null, $missing['urgency'] );
	}
);

test(
	'answers that do not fit the question go to review, never to a category',
	static function (): void {
		$urgency = urgency_answer( 1.0, 0.9, array( 1 => 1.0 ) );
		$broken  = array(
			'an option that was not offered' => array(
				'type'          => 'choice',
				'choice'        => 'refund',
				'confidence'    => 0.99,
				'probabilities' => array( 'refund' => 0.99 ),
			),
			'a yes/no answer'                => array(
				'type' => 'noul',
				'noul' => 0.99,
			),
			'a confidence written as text'   => array(
				'type'          => 'choice',
				'choice'        => 'complaint',
				'confidence'    => '0.99',
				'probabilities' => array(),
			),
			'a bare string'                  => 'complaint',
		);
		foreach ( $broken as $what => $answer ) {
			$result = Decision::from_response(
				array(
					'answers' => array(
						'category' => $answer,
						'urgency'  => $urgency,
					),
				),
				0.5
			);
			assert_same( 'review', $result['decision'], $what );
			assert_same( null, $result['category'], $what );
			assert_same( array( 'no category answer from Jev' ), $result['reasons'], $what );
		}
		assert_same( array( 'no category answer from Jev', 'no urgency answer from Jev' ), Decision::from_response( array(), 0.5 )['reasons'], 'an empty response' );
	}
);

test(
	'numbers out of range are clamped: a score above 3 counts as 3, a probability above 1 as 1',
	static function (): void {
		$result = Decision::from_response( response_with( category_answer( 'complaint', 1.2, array( 'complaint' => 1.5 ) ), urgency_answer( 3.7, 0.9, array( 3 => 1.0 ) ) ), 0.8 );
		assert_same( 1.0, $result['category_confidence'] );
		assert_same( 1.0, $result['category_probabilities']['complaint'] );
		assert_same( 3.0, $result['urgency'] );
		assert_same( 3, $result['urgency_level'] );
	}
);

test(
	'the private order note is fixed text with the numbers from the answer',
	static function (): void {
		$note = Decision::order_note( Decision::from_response( fixture( 'response-complaint.json' ), 0.8 ) );
		assert_same(
			"Customer note triage: complaint (confidence 0.90).\n" .
			"Urgency 2.71 of 3: before shipping 0.72, reply soon 0.27, when packing 0.01.\n" .
			"Category probabilities: complaint 0.92, question 0.07, delivery instruction 0.01.\n" .
			'Model jev-1.13.0, threshold 0.80.',
			$note
		);
	}
);

test(
	'the orders list column shows the category, the likeliest options for review, or the error',
	static function (): void {
		$triaged = Decision::from_response( fixture( 'response-complaint.json' ), 0.8 );
		assert_same( array( 'Complaint', 'urgency 2.7 of 3' ), Decision::column_lines( 'triaged', Questions::json( $triaged ) ) );
		$review = Decision::from_response( fixture( 'response-complaint.json' ), 0.95 );
		assert_same( array( 'Needs review', 'complaint 0.92, question 0.07' ), Decision::column_lines( 'review', Questions::json( $review ) ) );
		assert_same(
			array( 'Not triaged', 'TypeSafe refused the API key (HTTP 401). Check the key under WooCommerce > No...' ),
			Decision::column_lines( 'error', '', 'TypeSafe refused the API key (HTTP 401). Check the key under WooCommerce > Note triage.' )
		);
		assert_same( array(), Decision::column_lines( '', '' ), 'an order that was never triaged' );
	}
);

test(
	'thresholds from the settings page are clamped, and the command line accepts only 0 to 1',
	static function (): void {
		assert_same( 0.85, Decision::parse_threshold( ' 0.85 ' ) );
		assert_same( 0.8, Decision::parse_threshold( 'high' ) );
		assert_same( 0.8, Decision::parse_threshold( null ) );
		assert_same( 1.0, Decision::parse_threshold( '1.5' ) );
		assert_same( 0.0, Decision::parse_threshold( -1 ) );
		assert_true( Decision::is_threshold( '0.9' ), '0.9 is a threshold' );
		assert_true( Decision::is_threshold( '0' ), '0 is a threshold' );
		assert_same( false, Decision::is_threshold( '1.1' ) );
		assert_same( false, Decision::is_threshold( 'high' ) );
	}
);

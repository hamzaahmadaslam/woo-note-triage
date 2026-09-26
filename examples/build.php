<?php
/**
 * Rebuilds the example outputs from the synthetic orders in orders.json and the hand-written answers in
 * answers.json:
 *
 *     php examples/build.php
 *
 * It runs the plugin's own code (cleaning, questions, client, decisions, reports) with a fixture transport in
 * place of TypeSafe, so the probabilities are illustrations and the model shows as "fixture". A test checks that
 * the files here match what this script produces now.
 *
 * @package Woo_Note_Triage
 */

use Woo_Note_Triage\Decision;
use Woo_Note_Triage\Jev_Client;
use Woo_Note_Triage\Note_Text;
use Woo_Note_Triage\Plugin;
use Woo_Note_Triage\Questions;
use Woo_Note_Triage\Report;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
foreach ( array( 'meta', 'note-text', 'questions', 'decision', 'jev-error', 'jev-client', 'report', 'plugin' ) as $woo_note_triage_class ) {
	require_once dirname( __DIR__ ) . '/includes/class-' . $woo_note_triage_class . '.php';
}

/**
 * The example files, by name.
 *
 * @return array<string, string>
 */
function woo_note_triage_examples(): array {
	$orders  = json_decode( (string) file_get_contents( __DIR__ . '/orders.json' ), true, 512, JSON_THROW_ON_ERROR )['orders'];
	$answers = json_decode( (string) file_get_contents( __DIR__ . '/answers.json' ), true, 512, JSON_THROW_ON_ERROR )['answers'];

	$items = array();
	foreach ( $orders as $order ) {
		$note = Note_Text::clean( $order['customer_note'] );
		if ( '' !== $note ) {
			$items[] = array(
				'order_id' => $order['order_id'],
				'date'     => $order['date_created'],
				'raw'      => $order['customer_note'],
				'note'     => $note,
			);
		}
	}

	$run = array(
		'version'       => Plugin::VERSION,
		'days'          => 30,
		'threshold'     => Decision::DEFAULT_THRESHOLD,
		'model'         => Jev_Client::DEFAULT_MODEL,
		'orders'        => count( $orders ),
		'with_note'     => count( $items ),
		'skipped'       => 0,
		'limit'         => 0,
		'limit_reached' => false,
		'requests'      => 0,
		'input_tokens'  => 0,
	);
	$files = array(
		'dry-run.txt'  => Report::dry_run_text( $run, $items ),
		'dry-run.json' => Report::dry_run_json( $run, $items ),
		'request.json' => Questions::json( Questions::request_body( $items[0]['note'], $run['model'] ), true ) . "\n",
	);

	$model       = $run['model'];
	$rows        = array();
	$order_notes = array();
	foreach ( $items as $item ) {
		$fixture   = $answers[ (string) $item['order_id'] ];
		$transport = static function ( string $url, array $request ) use ( $fixture ): array {
			$body = json_decode( $request['body'], true );
			return array(
				'status'      => 200,
				'body'        => Questions::json(
					array(
						'model'   => 'fixture',
						'answers' => $fixture,
						// The fixture reports the plugin's own token estimate as usage.
						'usage'   => array(
							'input_tokens'  => Questions::estimate_tokens( $body ),
							'output_tokens' => 0,
						),
					)
				),
				'retry_after' => '',
			);
		};
		$client    = new Jev_Client( 'fixture-key-not-real', $model, $transport );
		$result    = Decision::from_response( $client->ask( $item['note'], Questions::questions() ), $run['threshold'] );

		++$run['requests'];
		$run['input_tokens'] += $result['input_tokens'];
		$run['model']         = $result['model'];
		$rows[]               = array(
			'order_id' => $item['order_id'],
			'date'     => $item['date'],
			'result'   => $result,
		);
		$order_notes[]        = Decision::TRIAGED === $result['decision']
			? sprintf( "Order #%d, private order note:\n%s", $item['order_id'], Decision::order_note( $result ) )
			: sprintf( "Order #%d: no order note, listed under Needs review (%s).", $item['order_id'], implode( '; ', $result['reasons'] ) );
	}

	$files['report.txt']      = Report::text( $run, $rows );
	$files['report.json']     = Report::json( $run, $rows );
	$files['order-notes.txt'] = implode( "\n\n", $order_notes ) . "\n";
	return $files;
}

if ( isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	foreach ( woo_note_triage_examples() as $woo_note_triage_name => $woo_note_triage_content ) {
		file_put_contents( __DIR__ . '/' . $woo_note_triage_name, $woo_note_triage_content );
		echo 'wrote examples/', $woo_note_triage_name, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}

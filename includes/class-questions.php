<?php
/**
 * The two questions asked about every customer note, and the request body that carries them.
 *
 * @package Woo_Note_Triage
 */

namespace Woo_Note_Triage;

defined( 'ABSPATH' ) || exit;

/**
 * Builds requests for TypeSafe's System One API (POST /v1/systemone: model, state, questions).
 *
 * The state is the cleaned note and nothing else. The questions are fixed text: the note never goes into a
 * question's instructions or criteria, so a note cannot rewrite the questions it is judged by.
 */
final class Questions {

	/** What the state is, repeated in each question because Jev reads each question on its own. */
	public const CONTEXT = 'The state is the text a customer typed into the order notes field while placing an order in an online shop.';

	/**
	 * The options of the category question, in the order they are sent. Each option says what it covers, what
	 * belongs to a neighbouring option instead, and a few notes it should match.
	 */
	public const CATEGORIES = array(
		'gift-message'         => array(
			'what'     => 'Words meant for the person who receives the order, to go on a card, a tag or a packing slip.',
			'not_for'  => 'Instructions to the shop or the courier about packing or delivery.',
			'examples' => array(
				'Happy birthday! Love from Grandma and Grandpa',
				'Congratulations on the new home, from everyone at the office',
			),
		),
		'delivery-instruction' => array(
			'what'     => 'Tells the shop or the courier how to pack the order, or where, when or how to deliver it.',
			'not_for'  => 'Words meant for the person who receives the order.',
			'examples' => array(
				'Leave the parcel behind the side gate if nobody answers',
				'Please gift wrap it and leave the receipt out',
				'Deliver after 5 pm, the office is closed in the morning',
			),
		),
		'question'             => array(
			'what'     => 'Asks the shop something, or asks for a change to the order, without reporting a problem.',
			'not_for'  => 'Reports that something went wrong.',
			'examples' => array(
				'Can you put our company name on the invoice?',
				'Will this arrive before Friday?',
				'Please change the colour to navy if you have it',
			),
		),
		'complaint'            => array(
			'what'     => 'Says something went wrong or the customer is unhappy, with this order, an earlier order or the shop.',
			'not_for'  => 'Questions or change requests that do not report a problem.',
			'examples' => array(
				'My last order arrived broken and nobody answered my email',
				'This is the third time I have ordered this because the first two never arrived',
			),
		),
		'fraud-signal'         => array(
			'what'     => 'The note contains a known warning sign of payment fraud or of a scam aimed at the shop.',
			'not_for'  => 'Ordinary gift or delivery instructions, including a gift sent to someone else\'s address.',
			'examples' => array(
				'Ship it to my freight forwarder, I will send the final address after payment',
				'I used my uncle\'s card, please ship today before he checks his statement',
				'I paid too much by mistake, please send the difference to this other account',
			),
		),
		'other'                => array(
			'what'     => 'Anything else: thanks, a remark, a test, or text that says nothing about this order.',
			'not_for'  => 'A note that fits one of the other options.',
			'examples' => array( 'Thanks!', 'Love your shop', 'test' ),
		),
	);

	/** Short names for the categories, used in order notes, the orders list and reports. */
	public const CATEGORY_LABELS = array(
		'gift-message'         => 'gift message',
		'delivery-instruction' => 'delivery instruction',
		'question'             => 'question',
		'complaint'            => 'complaint',
		'fraud-signal'         => 'fraud signal',
		'other'                => 'other',
	);

	/**
	 * The levels of the urgency score, from 0 to 3. Each level describes a situation, not a degree, because Jev
	 * judges every level on its own and never sees its number.
	 */
	public const URGENCY_LEVELS = array(
		array(
			'what'     => 'Nothing to do: the note asks nothing of the shop.',
			'examples' => array( 'Thanks!', 'Love your shop' ),
		),
		array(
			'what'     => 'While packing or delivering: the shop follows the note when the order ships, and no reply is needed.',
			'examples' => array( 'Happy birthday! Love from Grandma and Grandpa', 'Leave the parcel behind the side gate' ),
		),
		array(
			'what'     => 'Reply within a day or two: the customer asks something or wants a check, and the order can ship as it is.',
			'examples' => array( 'Can you put our company name on the invoice?', 'Will this arrive before Friday?' ),
		),
		array(
			'what'     => 'Before the order ships: the order may need to change or stop, or an unhappy customer needs a reply first.',
			'examples' => array(
				'Wrong address, please send it to my work address instead',
				'My last order arrived broken and nobody answered my email',
				'Ship it to my freight forwarder, I will send the final address after payment',
			),
		),
	);

	/** Short names for the urgency levels, by level number. */
	public const URGENCY_LABELS = array( 'nothing to do', 'when packing', 'reply soon', 'before shipping' );

	/**
	 * The questions sent with every note: a choice of category and a score of urgency, answered together.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function questions(): array {
		return array(
			'category' => array(
				'type'         => 'choice',
				'instructions' => array(
					'context'  => self::CONTEXT,
					'question' => 'What kind of note is this?',
					'focus'    => 'If the note does more than one thing, choose the part that most needs the shop\'s attention.',
				),
				'criteria'     => self::CATEGORIES,
			),
			'urgency'  => array(
				'type'         => 'score',
				'instructions' => array(
					'context'  => self::CONTEXT,
					'question' => 'How soon does someone at the shop need to act on this note?',
				),
				'criteria'     => self::URGENCY_LEVELS,
			),
		);
	}

	/**
	 * The exact body of the request for one note.
	 *
	 * @param string $note  The cleaned note (Note_Text::clean()).
	 * @param string $model The model name, such as "jev-latest".
	 * @return array{model: string, state: string, questions: array<string, array<string, mixed>>}
	 */
	public static function request_body( string $note, string $model ): array {
		return array(
			'model'     => $model,
			'state'     => $note,
			'questions' => self::questions(),
		);
	}

	/**
	 * A rough token count: one token per three characters of JSON, which overestimates English text.
	 *
	 * @param mixed $value Anything JSON can encode.
	 */
	public static function estimate_tokens( $value ): int {
		return (int) ceil( Note_Text::length( self::json( $value ) ) / 3 );
	}

	/**
	 * JSON as it is sent: slashes and non-ASCII characters left as they are.
	 *
	 * @param mixed $value  Anything JSON can encode.
	 * @param bool  $pretty Indent the output for people to read.
	 */
	public static function json( $value, bool $pretty = false ): string {
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;
		return (string) json_encode( $value, $pretty ? $flags | JSON_PRETTY_PRINT : $flags );
	}
}

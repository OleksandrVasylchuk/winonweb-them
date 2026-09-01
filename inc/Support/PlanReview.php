<?php
/**
 * Has the model check the reading of a section before a block is made from it.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The one thing left for a model to do once sections are wrapped.
 *
 * Wrapping removed the model from the middle of the pipeline: markup is copied,
 * not rewritten, so there is nothing to convert and nothing to get wrong. What
 * code cannot do is judge. `SectionPlan` decides structurally — a heading is
 * editable because it is a heading, six siblings are a listing because there
 * are six — and it names fields after whatever class the designer happened to
 * use. That gives an editor a sidebar of `subheading`, `btn` and `label_2`.
 *
 * So the model is asked three questions about a reading it did not produce:
 * what each field should be called, whether the repeat is really the site's
 * records or just the section's furniture, and what the section is. It is a
 * small request with a small answer — no markup in either direction — which is
 * why it costs seconds where converting a section cost minutes.
 *
 * A refusal, a timeout or a garbled reply is not an error. The structural plan
 * is already correct; the model only makes it legible.
 */
final class PlanReview {

	/**
	 * How long one review may take before the build stops waiting.
	 *
	 * Far shorter than a conversion's, and deliberately: this is a naming
	 * question about a page of JSON, not a rewrite. A review that needs three
	 * minutes has gone wrong, and waiting for it would give back the very
	 * hours wrapping was meant to save.
	 */
	private const TIMEOUT = 60;

	/**
	 * Who the model is being, and what it is not allowed to touch.
	 *
	 * Stated as a standing instruction rather than repeated per section, and
	 * the prohibition comes first on purpose: the previous pipeline's whole
	 * failure was a model that helpfully improved markup nobody asked it to
	 * change, and dropped 190 class names doing it.
	 */
	private const SYSTEM = 'You review how one section of a delivered web design was read, so that a WordPress block can be made from it.'
		. ' The design\'s markup, classes, colours, spacing and stylesheet are kept exactly as delivered and you never propose changes to any of them.'
		. ' You decide only what the editable parts should be called, whether a repeat is the site\'s records or the section\'s own furniture, and what the section is called in the block inserter.'
		. ' You answer with the given JSON shape and nothing else.';

	/**
	 * What the model is allowed to change, and nothing else.
	 *
	 * The shape is the plan's own: names, labels, and the kind. The paths, the
	 * types and the markup are structural facts and are never sent back for
	 * approval — a model that renamed a path would produce a block whose
	 * fields point at nothing.
	 */
	private const SCHEMA = array(
		'type'       => 'object',
		'properties' => array(
			'title'       => array(
				'type'        => 'string',
				'description' => 'What this section is, in two or three words, for the block title.',
			),
			'kind'        => array(
				'type'        => 'string',
				'enum'        => array( 'single', 'repeat', 'listing' ),
				'description' => 'listing when the repeated things are records the site will keep adding to (reports, case studies, people); repeat when they are part of this section\'s design (three tiles, five chips).',
			),
			'fields'      => array(
				'type'        => 'array',
				'description' => 'One entry per field, in the order given. Same length, same order.',
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'name'  => array(
							'type'        => 'string',
							'description' => 'lower_snake_case, what the thing is, not what it is styled as.',
						),
						'label' => array(
							'type'        => 'string',
							'description' => 'What an editor sees above the box.',
						),
					),
					'required'   => array( 'name', 'label' ),
				),
			),
			'item_name'   => array(
				'type'        => 'string',
				'description' => 'When kind is listing: the singular name of one of these things, as a person would say it — "Report", "Case study", "Team member". Empty otherwise. Two sections that show the same kind of thing must give the same word, so that they end up sharing one list rather than two.',
			),
			'item_fields' => array(
				'type'        => 'array',
				'description' => 'The same, for one row of a repeat. Empty when there is no repeat.',
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'name'  => array( 'type' => 'string' ),
						'label' => array( 'type' => 'string' ),
					),
					'required'   => array( 'name', 'label' ),
				),
			),
		),
		'required'   => array( 'title', 'kind', 'fields' ),
	);

	/**
	 * Ask the model to check one reading, and fold in what it says.
	 *
	 * @param string               $html  Section markup.
	 * @param array<string, mixed> $plan  What SectionPlan made of it.
	 * @param string               $label What the splitter called the section.
	 * @return array{plan:array<string,mixed>,title:string,item:string,reviewed:bool}
	 */
	public static function of( string $html, array $plan, string $label ): array {
		$unchanged = array(
			'plan'     => $plan,
			'title'    => $label,
			'item'     => '',
			'reviewed' => false,
		);

		if ( ! ModelGateway::ready() ) {
			return $unchanged;
		}

		$reply = ModelGateway::generate(
			self::SYSTEM,
			self::prompt( $html, $plan, $label ),
			self::SCHEMA,
			array( 'timeout' => self::TIMEOUT )
		);

		if ( ! is_array( $reply ) ) {
			return $unchanged;
		}

		return array(
			'plan'     => self::apply( $plan, $reply ),
			'item'     => self::singular( $reply ),
			'title'    => self::title( $reply, $label ),
			'reviewed' => true,
		);
	}

	/**
	 * What the model is shown.
	 *
	 * The markup is sent because a name follows from what a thing says, not
	 * from what it is called: `label_2` under a figure of 46,000 is a "metric
	 * caption", and nothing but the words reveals that.
	 *
	 * @param string               $html  Section markup.
	 * @param array<string, mixed> $plan  The reading to check.
	 * @param string               $label What the splitter called it.
	 * @return string
	 */
	private static function prompt( string $html, array $plan, string $label ): string {
		/*
		 * Parsed once for the whole section rather than once per field. A
		 * thirty-field section was being re-parsed thirty times to read thirty
		 * strings out of the same document, which is the sort of waste that
		 * turns a fast pass back into a slow one.
		 */
		$dom  = self::document( $html );
		$body = null;

		if ( null !== $dom ) {
			$body = ( new \DOMXPath( $dom ) )->query( '//body' )->item( 0 );
		}

		$fields = array();

		foreach ( (array) ( $plan['fields'] ?? array() ) as $field ) {
			$fields[] = array(
				'name' => (string) ( $field['name'] ?? '' ),
				'type' => (string) ( $field['type'] ?? '' ),
				'says' => self::says( $body, (string) ( $field['path'] ?? '' ) ),
			);
		}

		$rows = array();
		$item = $plan['item'] ?? null;

		foreach ( (array) ( is_array( $item ) ? ( $item['fields'] ?? array() ) : array() ) as $field ) {
			$rows[] = array(
				'name' => (string) ( $field['name'] ?? '' ),
				'type' => (string) ( $field['type'] ?? '' ),
			);
		}

		$brief = array(
			'You are checking how one section of a delivered web design was read, before a WordPress block is made from it.',
			'',
			'The markup is kept exactly as the designer wrote it. Nothing you say changes it. What you are deciding is what the editable parts should be CALLED, and whether the repeating part is the site\'s own records or this section\'s furniture.',
			'',
			'Rules:',
			'- Return the fields in the order given, one for one. Do not add, drop or reorder them.',
			'- Names are lower_snake_case and say what the thing IS, not how it looks. `heading`, `lede`, `price`, `read_more` — never `label_2`, `btn`, `span_3`.',
			'- Labels are what an editor reads above the box, in sentence case: "Card heading", "Price", "Link to the report".',
			'- `listing` means the site will keep adding to these: reports, case studies, people, posts. `repeat` means the design drew a fixed set: three tiles, five chips, four steps. If in doubt, `repeat` — a wrong listing hides content behind a query nobody set up.',
			'- The title is two or three words for the block inserter, not a sentence.',
			'- For a listing, `item_name` is the singular word for one of these things — "Report", "Case study", "Team member". It becomes a section of the admin the owner adds to, so it must read as a thing, not as a heading. Two sections showing the same kind of thing must answer with the same word.',
			'',
			'The section is called "' . $label . '" and was read as: ' . (string) ( $plan['kind'] ?? 'single' ) . '.',
			'',
			'Fields found, with what each one says in the design:',
			(string) wp_json_encode( $fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ),
		);

		if ( array() !== $rows ) {
			$brief[] = '';
			$brief[] = 'One row of the repeat holds:';
			$brief[] = (string) wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		}

		/*
		 * What this site's earlier imports ran into, so the judgement is made
		 * with the studio's history rather than from a cold start. One
		 * factual paragraph from the journal — see Lessons::brief().
		 */
		$learned = Lessons::brief();

		if ( '' !== $learned ) {
			$brief[] = '';
			$brief[] = 'History from this site\'s earlier imports, to weigh when judging listing against repeat: ' . $learned;
		}

		$brief[] = '';
		$brief[] = 'The section\'s markup:';
		$brief[] = self::trimmed( $html );

		return implode( "\n", $brief );
	}

	/**
	 * What the node at a path says, so a name can follow from it.
	 *
	 * @param DOMNode|null $body Section root, parsed once by the caller.
	 * @param string       $path Where the field sits.
	 * @return string
	 */
	private static function says( $body, string $path ): string {
		if ( ! $body instanceof \DOMNode ) {
			return '';
		}

		$node = SectionPlan::at( $body, $path );

		if ( null === $node ) {
			return '';
		}

		$says = trim( preg_replace( '/\s+/', ' ', $node->textContent ) ?? '' );

		return mb_substr( $says, 0, 120 );
	}

	/**
	 * Parse once, without libxml complaining about HTML5.
	 *
	 * @param string $html Markup.
	 * @return \DOMDocument|null
	 */
	private static function document( string $html ) {
		if ( '' === trim( $html ) ) {
			return null;
		}

		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );

		$ok = $dom->loadHTML(
			'<?xml encoding="utf-8" ?><html><body>' . $html . '</body></html>',
			LIBXML_NOWARNING | LIBXML_NOERROR
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $ok ? $dom : null;
	}

	/**
	 * The markup, short enough to be worth sending.
	 *
	 * @param string $html Section markup.
	 * @return string
	 */
	private static function trimmed( string $html ): string {
		$html = (string) preg_replace( '/\s+/', ' ', $html );

		return mb_strlen( $html ) > 6000 ? mb_substr( $html, 0, 6000 ) . ' …' : $html;
	}

	/**
	 * Fold the reply into the plan, keeping every structural fact.
	 *
	 * @param array<string, mixed> $plan  The reading.
	 * @param array<string, mixed> $reply What the model said.
	 * @return array<string, mixed>
	 */
	private static function apply( array $plan, array $reply ): array {
		$plan['fields'] = self::renamed( (array) ( $plan['fields'] ?? array() ), (array) ( $reply['fields'] ?? array() ) );

		$item = $plan['item'] ?? null;

		if ( is_array( $item ) ) {
			$item['fields'] = self::renamed( (array) ( $item['fields'] ?? array() ), (array) ( $reply['item_fields'] ?? array() ) );
			$plan['item']   = $item;
		}

		$kind = (string) ( $reply['kind'] ?? '' );

		/*
		 * The kind is taken only when there is something to repeat. A model
		 * that calls a one-off hero a "listing" would otherwise produce a
		 * block that queries posts and draws nothing.
		 */
		if ( in_array( $kind, array( 'single', 'repeat', 'listing' ), true ) ) {
			$plan['kind'] = is_array( $item ) ? $kind : 'single';
		}

		return $plan;
	}

	/**
	 * Rename fields in place, one for one, keeping paths and types.
	 *
	 * @param array<int, array<string, mixed>> $fields What the plan found.
	 * @param array<int, mixed>                $said   What the model returned.
	 * @return array<int, array<string, mixed>>
	 */
	private static function renamed( array $fields, array $said ): array {
		$taken = array();

		foreach ( $fields as $index => $field ) {
			$one = $said[ $index ] ?? null;

			if ( ! is_array( $one ) ) {
				continue;
			}

			$name = self::name( (string) ( $one['name'] ?? '' ) );

			/*
			 * A name is only taken when it is usable and unclaimed. Two fields
			 * called `heading` would collide in the field group and one would
			 * silently overwrite the other, so the structural name stands.
			 */
			if ( '' !== $name && ! isset( $taken[ $name ] ) ) {
				$fields[ $index ]['name'] = $name;
				$taken[ $name ]           = true;
			} else {
				$taken[ (string) $field['name'] ] = true;
			}

			$label = trim( (string) ( $one['label'] ?? '' ) );

			if ( '' !== $label ) {
				$fields[ $index ]['label'] = self::clamp( $label, 60 );
			}
		}

		return $fields;
	}

	/**
	 * A field name reduced to what ACF and PHP will both accept.
	 *
	 * @param string $name What the model said.
	 * @return string
	 */
	private static function name( string $name ): string {
		$name = strtolower( trim( $name ) );
		$name = (string) preg_replace( '/[^a-z0-9]+/', '_', $name );
		$name = trim( $name, '_' );

		// A name that starts with a digit is not a usable array key in a template.
		if ( '' !== $name && ctype_digit( $name[0] ) ) {
			$name = 'f_' . $name;
		}

		return mb_substr( $name, 0, 32 );
	}

	/**
	 * The singular name of one record in a listing, as a person would say it.
	 *
	 * This is what turns six cards into six things somebody can add a seventh
	 * of. The word becomes a section of the admin — "Reports", with an Add
	 * button — so it has to read as a thing rather than as a heading, and two
	 * sections showing the same kind of thing have to answer with the same word
	 * or the site ends up with two lists of reports.
	 *
	 * @param array<string, mixed> $reply What the model said.
	 * @return string Empty when this is not a listing.
	 */
	private static function singular( array $reply ): string {
		if ( 'listing' !== (string) ( $reply['kind'] ?? '' ) ) {
			return '';
		}

		$name = trim( (string) ( $reply['item_name'] ?? '' ) );
		$name = (string) preg_replace( '/\s+/', ' ', $name );

		// A sentence is not a name; a model that answered with one gets ignored.
		return mb_strlen( $name ) > 0 && mb_strlen( $name ) <= 32 ? $name : '';
	}

	/**
	 * The block title, or the splitter's label when the model gave nothing.
	 *
	 * @param array<string, mixed> $reply What the model said.
	 * @param string               $label What the splitter called it.
	 * @return string
	 */
	private static function title( array $reply, string $label ): string {
		$title = trim( (string) ( $reply['title'] ?? '' ) );

		return '' !== $title ? self::clamp( $title, 60 ) : $label;
	}

	/**
	 * Text cut to a limit at a word break, not mid-word.
	 *
	 * A raw `mb_substr` lands wherever the character count happens to fall —
	 * mid-word in a title, mid-code in something like "(HS 2004.10)". This
	 * backs up to the last space inside the limit before cutting, so the
	 * truncation reads as a truncation rather than a corruption.
	 *
	 * @param string $text  What the model said.
	 * @param int    $limit The character limit, ellipsis included.
	 * @return string
	 */
	private static function clamp( string $text, int $limit ): string {
		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, $limit - 1 );
		$space = mb_strrpos( $cut, ' ' );

		if ( false !== $space && $space > 0 ) {
			$cut = mb_substr( $cut, 0, $space );
		}

		return rtrim( $cut, ' ,.;:—-' ) . '…';
	}
}

<?php
/**
 * The guided route: a model corrects the structural conversion, or nothing happens.
 *
 * The claim under test is not "the model converts well" — that needs a model,
 * a key and money, and belongs in a manual run against a real archive. It is
 * the claim that makes the route safe to ship: **every piece of it degrades to
 * the structural conversion.** No key, no binary, a refusal, a timeout, a
 * reply that fails validation — each of those leaves the import exactly where
 * it would have been without the route at all.
 *
 * The rest is the material the model is handed. A brief that quietly stopped
 * including the resolved CSS, or a schema that lost a field, would not fail
 * anything visibly; it would just make conversions worse for a fortnight
 * before anybody noticed. So the brief is checked for the things it promises
 * to carry.
 *
 * @package Qwerty\Soft
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line test output, never a web page.

require __DIR__ . '/bootstrap.php';

use Qwerty\Soft\Support\BlockConverter;
use Qwerty\Soft\Support\ClaudeCli;
use Qwerty\Soft\Support\ConversionPrompt;
use Qwerty\Soft\Support\CssIndex;
use Qwerty\Soft\Support\DesignArchive;
use Qwerty\Soft\Support\DesignFacts;
use Qwerty\Soft\Support\DesignTokens;
use Qwerty\Soft\Support\ModelGateway;
use Qwerty\Soft\Support\RefinePrompt;
use Qwerty\Soft\Support\SectionSplitter;
use Qwerty\Soft\Support\SmartConverter;
use Qwerty\Soft\Support\Spend;

/**
 * One section of a fixture page, by the class on its wrapper.
 *
 * @param string $file    File inside tests/fixtures/design/.
 * @param string $wanted  Class to look for on the section.
 * @return array<string, mixed>|null
 */
function qsoft_fixture_section( string $file, string $wanted ): ?array {
	$split = SectionSplitter::split( qsoft_fixture( 'design' ) . '/' . $file );

	foreach ( $split['sections'] as $section ) {
		if ( in_array( $wanted, array_map( 'strval', (array) ( $section['classes'] ?? array() ) ), true ) ) {
			return $section;
		}
	}

	return null;
}

qsoft_group( 'Screenshots — found when the archive has them, absent when it does not' );

qsoft_test(
	'DesignArchive finds the fixture screenshot and matches it to nothing else',
	static function (): void {
		$root  = qsoft_fixture( 'design' );
		$shots = DesignArchive::screenshots( $root );

		qsoft_assert( isset( $shots['hero-viz'] ), 'the screenshots folder is read', array_keys( $shots ) );
		qsoft_assert( is_file( (string) ( $shots['hero-viz'] ?? '' ) ), 'the path it returns is a real file', $shots );

		/*
		 * The fixture's only screenshot stands in for a JS visual, not for a
		 * page. Matching it to Home.dc.html would hand the model a picture of
		 * one widget and tell it that is the page — worse than no picture.
		 */
		qsoft_assert( '' === DesignArchive::screenshot_for( $root, 'Home.dc.html' ), 'a screenshot named for nothing on the page is not offered as the page', DesignArchive::screenshot_for( $root, 'Home.dc.html' ) );
		qsoft_assert( '' === DesignArchive::screenshot_for( $root, 'About.dc.html' ), 'nor for another page', DesignArchive::screenshot_for( $root, 'About.dc.html' ) );

		// The design's own photographs are not screenshots, whatever they are named.
		foreach ( $shots as $key => $path ) {
			qsoft_assert(
				1 === preg_match( '#/(screenshots?|previews?|shots)/#i', str_replace( '\\', '/', $path ) ),
				'every hit came from a screenshots folder: ' . $key,
				$path
			);
		}

		/*
		 * The fixture keeps its own photographs in img/. Finding one of those
		 * would mean a build handing the model a picture of a single card and
		 * calling it the page.
		 */
		foreach ( $shots as $path ) {
			qsoft_assert( ! str_contains( str_replace( '\\', '/', $path ), '/img/' ), 'and none from the design\'s own images', $path );
		}
	}
);

qsoft_test(
	'a screenshot named for the page is matched to it',
	static function (): void {
		$root = qsoft_fixture( 'design' );
		$made = $root . '/screenshots/home.png';

		copy( $root . '/screenshots/hero-viz.png', $made );

		try {
			$found = str_replace( '\\', '/', DesignArchive::screenshot_for( $root, 'Home.dc.html' ) );

			qsoft_assert( $found === $made, 'Home.dc.html finds home.png', $found );
			qsoft_assert( '' === DesignArchive::screenshot_for( $root, 'About.dc.html' ), 'and About does not borrow it', DesignArchive::screenshot_for( $root, 'About.dc.html' ) );
		} finally {
			unlink( $made ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- A file this test made.
		}
	}
);

qsoft_group( 'The brief — what the model is actually told about the design' );

qsoft_test(
	'the resolved-CSS brief states the layout the stylesheet computes to',
	static function (): void {
		$root    = qsoft_fixture( 'design' );
		$section = qsoft_fixture_section( 'Home.dc.html', 'posts' );

		if ( ! qsoft_assert( null !== $section, 'the fixture still has a .posts section' ) ) {
			return;
		}

		$css   = CssIndex::from_directory( $root );
		$facts = DesignFacts::for_section( (string) $section['html'], $css );

		qsoft_assert( '' !== $facts, 'a brief was produced', $facts );
		qsoft_assert( str_contains( $facts, 'div.grid' ), 'the grid container is named', $facts );
		qsoft_assert( str_contains( $facts, 'grid' ) && str_contains( $facts, 'gap 24px' ), 'its display and gap are stated as facts', $facts );

		/*
		 * The point of the brief. The converter reading this markup sees a div
		 * of articles and has to guess; the stylesheet says grid, and the brief
		 * says grid without anybody running the cascade in their head.
		 */
		qsoft_assert( ! str_contains( $facts, 'var(' ), 'no custom property is left unresolved', $facts );
	}
);

qsoft_test(
	'the brief is bounded and says so when it is cut',
	static function (): void {
		$html = '<section class="deep">';

		for ( $i = 0; $i < 200; $i++ ) {
			$html .= '<div class="row-' . $i . '" style="display:flex;gap:8px"><p>Item ' . $i . '</p></div>';
		}

		$html .= '</section>';

		$facts = DesignFacts::for_section( $html, new CssIndex( array() ) );
		$lines = substr_count( $facts, "\n" ) + 1;

		qsoft_assert( $lines <= 95, 'a huge section does not produce a huge brief', $lines );
		qsoft_assert( str_contains( $facts, 'omitted' ), 'and it says out loud that it stopped early', $facts );
	}
);

qsoft_test(
	'a guided brief carries the structural conversion, the facts and the section',
	static function (): void {
		$root    = qsoft_fixture( 'design' );
		$section = qsoft_fixture_section( 'Home.dc.html', 'posts' );

		if ( ! qsoft_assert( null !== $section, 'the fixture still has a .posts section' ) ) {
			return;
		}

		$brief = ConversionPrompt::brief(
			$section,
			array(
				'baseline' => '<!-- wp:paragraph --><p>Structural</p><!-- /wp:paragraph -->',
				'facts'    => 'div.grid — grid · gap 24px',
				'css'      => '.grid{display:grid}',
				'shot'     => true,
			),
			array(
				'page'     => 'Fixture Co',
				'lang'     => 'en',
				'is_first' => false,
			)
		);

		qsoft_assert( str_contains( $brief, 'Structural' ), 'the structural conversion is in the brief', $brief );
		qsoft_assert( str_contains( $brief, 'div.grid — grid · gap 24px' ), 'so is the resolved-CSS brief' );
		qsoft_assert( str_contains( $brief, 'screenshot' ), 'the screenshot is announced when there is one' );
		qsoft_assert( str_contains( $brief, 'Fixture Co' ) && str_contains( $brief, '"en"' ), 'the page and its language are stated' );
		qsoft_assert( str_contains( $brief, '`h2` at most' ), 'a later section is told not to take the h1' );

		/*
		 * The raw stylesheet is a fallback for when no brief could be made,
		 * not a companion to it: sending both spends the window twice on the
		 * same information.
		 */
		qsoft_assert( ! str_contains( $brief, '.grid{display:grid}' ), 'raw CSS is left out when a brief was produced', $brief );
	}
);

qsoft_test(
	'the paste route is unchanged by the guided one',
	static function (): void {
		$plain  = ConversionPrompt::system();
		$guided = ConversionPrompt::system( true );

		qsoft_assert( ! str_contains( $plain, 'structural conversion' ), 'the plain system prompt says nothing about a baseline', $plain );
		qsoft_assert( str_contains( $guided, 'structural conversion' ), 'the guided one does' );
		qsoft_assert( str_contains( $guided, 'the brief is right' ), 'and settles which source wins on a disagreement' );
		qsoft_assert( str_contains( $guided, 'Blocks you may use' ) && str_contains( $guided, 'Colours' ), 'the guided prompt still carries the theme vocabulary' );
		qsoft_assert( strlen( $guided ) > strlen( $plain ), 'the guidance is added to the prompt, not swapped in for it', strlen( $guided ) . ' vs ' . strlen( $plain ) );

		$schema = ConversionPrompt::schema();
		$wide   = ConversionPrompt::guided_schema();

		foreach ( $schema['required'] as $field ) {
			qsoft_assert( in_array( $field, $wide['required'], true ), 'the guided schema still requires ' . $field, $wide['required'] );
		}

		qsoft_assert( in_array( 'changed', $wide['required'], true ), 'and requires the list of what it changed', $wide['required'] );
		qsoft_assert( ! isset( $schema['properties']['changed'] ), 'which the paste schema does not have', array_keys( $schema['properties'] ) );
	}
);

qsoft_group( 'The review pass — rendering the blocks to compare them' );

qsoft_test(
	'rendered output is the front end, minus the noise',
	static function (): void {
		$markup = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Latest</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Body copy.</p>\n<!-- /wp:paragraph -->";

		$rendered = RefinePrompt::render( $markup );

		qsoft_assert( str_contains( $rendered, '<h2' ) && str_contains( $rendered, 'Latest' ), 'the heading is rendered', $rendered );
		qsoft_assert( str_contains( $rendered, 'Body copy.' ), 'and so is the copy' );
		qsoft_assert( ! str_contains( $rendered, '<!--' ), 'block delimiters are gone — they are the input, not the result', $rendered );

		qsoft_assert( '' === RefinePrompt::render( '' ), 'nothing in, nothing out' );

		$long = RefinePrompt::render( str_repeat( "<!-- wp:paragraph -->\n<p>Filler.</p>\n<!-- /wp:paragraph -->\n\n", 4000 ), 5000 );

		qsoft_assert( strlen( $long ) < 5200, 'a long page is capped', strlen( $long ) );
		qsoft_assert( str_contains( $long, 'truncated' ), 'and says it was cut rather than pretending it is whole' );
	}
);

qsoft_test(
	'the review schema lets "nothing to change" be the answer',
	static function (): void {
		$schema = RefinePrompt::schema();

		qsoft_assert( in_array( 'verdict', $schema['required'], true ), 'a verdict is required', $schema['required'] );
		qsoft_assert( ! in_array( 'markup', $schema['required'], true ), 'markup is not — a match returns none', $schema['required'] );
		qsoft_assert( in_array( 'match', $schema['properties']['verdict']['enum'], true ), '"match" is one of the verdicts', $schema['properties']['verdict'] );
		qsoft_assert( str_contains( RefinePrompt::system(), 'is-layout-' ), 'the reviewer is told which differences are not differences', RefinePrompt::system() );
	}
);

qsoft_group( 'The floor — every failure lands on the structural conversion' );

qsoft_test(
	'with no route to a model, a guided conversion is the structural one',
	static function (): void {
		$root = qsoft_fixture( 'design' );

		$before_transport = get_option( ModelGateway::OPTION_TRANSPORT );
		$before_key       = get_option( 'qwerty_soft_anthropic_key' );

		// Ask for the API explicitly, with no key: the one arrangement that cannot work.
		update_option( ModelGateway::OPTION_TRANSPORT, 'api' );
		update_option( 'qwerty_soft_anthropic_key', '' );

		try {
			if ( ! qsoft_assert( ! ModelGateway::ready(), 'the gateway agrees it cannot run', ModelGateway::status() ) ) {
				return;
			}

			$section = qsoft_fixture_section( 'Home.dc.html', 'posts' );

			if ( ! qsoft_assert( null !== $section, 'the fixture still has a .posts section' ) ) {
				return;
			}

			$css    = CssIndex::from_directory( $root );
			$colors = DesignTokens::extract( $root )['colors'];

			$plain = new BlockConverter();
			$plain->use_design( DesignTokens::section_backgrounds( $root ), $colors, $css );

			$guided = new BlockConverter();
			$guided->use_design( DesignTokens::section_backgrounds( $root ), $colors, $css );

			$expected = $plain->convert( $section, false );

			$smart  = new SmartConverter( $root, $guided, $css, array( 'refine' => true ) );
			$actual = $smart->convert(
				$section,
				false,
				array(
					'page' => 'Fixture Co',
					'lang' => 'en',
				)
			);

			qsoft_assert( 'structural' === $actual['source'], 'the result says which pass produced it', $actual['source'] );
			qsoft_assert( $expected['markup'] === $actual['markup'], 'and it is the structural markup, byte for byte' );
			qsoft_assert( 0 === $actual['rounds'], 'no round was attempted', $actual['rounds'] );
			qsoft_assert( array() === $smart->calls(), 'and nothing was sent anywhere', $smart->calls() );
			qsoft_assert( '' !== trim( $actual['markup'] ), 'the section still converted', $actual['markup'] );
		} finally {
			update_option( ModelGateway::OPTION_TRANSPORT, $before_transport );
			update_option( 'qwerty_soft_anthropic_key', $before_key );
		}
	}
);

/**
 * Run one guided conversion with the model's reply decided in advance.
 *
 * A model that answers with something unusable is the failure mode that would
 * otherwise be invisible: tokens spent, the structural conversion silently
 * kept, and a report that reads as though the model had agreed with
 * everything. A real model cannot be asked to have a bad minute on demand, so
 * the reply is supplied here instead.
 *
 * @param array<string, mixed>|WP_Error $reply What the gateway should hand back.
 * @return array{result:array<string,mixed>,calls:array<int,array<string,mixed>>,structural:string}
 */
function qsoft_convert_with_reply( $reply ): array {
	$root    = qsoft_fixture( 'design' );
	$section = qsoft_fixture_section( 'Home.dc.html', 'posts' );

	$before_transport = get_option( ModelGateway::OPTION_TRANSPORT );
	$before_key       = get_option( 'qwerty_soft_anthropic_key' );

	// A route that reports itself ready, so the guided pass is attempted at all.
	update_option( ModelGateway::OPTION_TRANSPORT, 'api' );
	update_option( 'qwerty_soft_anthropic_key', 'sk-ant-test-not-a-real-key' );

	$stub = static function () use ( $reply ) {
		return $reply;
	};

	add_filter( 'qwerty_soft/model_reply', $stub, 10, 1 );

	try {
		$css    = CssIndex::from_directory( $root );
		$colors = DesignTokens::extract( $root )['colors'];

		$converter = new BlockConverter();
		$converter->use_design( DesignTokens::section_backgrounds( $root ), $colors, $css );

		$plain = new BlockConverter();
		$plain->use_design( DesignTokens::section_backgrounds( $root ), $colors, $css );

		$smart = new SmartConverter( $root, $converter, $css );

		return array(
			'result'     => $smart->convert( (array) $section, false ),
			'calls'      => $smart->calls(),
			'structural' => (string) $plain->convert( (array) $section, false )['markup'],
		);
	} finally {
		remove_filter( 'qwerty_soft/model_reply', $stub, 10 );
		update_option( ModelGateway::OPTION_TRANSPORT, $before_transport );
		update_option( 'qwerty_soft_anthropic_key', $before_key );
	}
}

qsoft_test(
	'a reply the validator rejects is thrown away, and said out loud',
	static function (): void {
		$run = qsoft_convert_with_reply(
			array(
				'markup'     => '<!-- wp:html --><script>alert(1)</script><!-- /wp:html -->',
				'summary'    => 'Should never be used.',
				'editable'   => array(),
				'concerns'   => array(),
				'changed'    => array( 'rebuilt the section' ),
				'_usage'     => array(
					'input_tokens'  => 900,
					'output_tokens' => 120,
				),
				'_model'     => 'claude-opus-5',
				'_transport' => 'api',
			)
		);

		$result = $run['result'];

		qsoft_assert( 'structural' === $result['source'], 'the section kept the structural conversion', $result['source'] );
		qsoft_assert( $run['structural'] === $result['markup'], 'byte for byte' );
		qsoft_assert( ! str_contains( $result['markup'], '<script' ), 'and nothing executable reached the page', $result['markup'] );
		qsoft_assert( array() === $result['changed'], 'the discarded reply\'s claims are not reported as changes', $result['changed'] );

		$said = false;

		foreach ( $result['concerns'] as $concern ) {
			$said = $said || str_contains( $concern, 'not valid block markup' );
		}

		qsoft_assert( $said, 'the section says the reply was thrown away', $result['concerns'] );

		$calls = $run['calls'];

		/*
		 * Two calls, not one: a rejected answer is worth asking about once
		 * more with the complaint attached, because what usually fails is the
		 * typing rather than the judgement. Here the stub returns the same bad
		 * markup both times, so both are recorded and both are discarded.
		 */
		qsoft_assert( 2 === count( $calls ), 'the first answer was retried once', $calls );

		foreach ( $calls as $index => $call ) {
			qsoft_assert( ! empty( $call['ok'] ), 'call ' . $index . ' succeeded — it did answer', $call );
			qsoft_assert( isset( $call['kept'] ) && false === $call['kept'], 'call ' . $index . ' had its answer thrown away', $call );
		}

		qsoft_assert( 1 === count( array_filter( $result['concerns'], static fn( $c ) => str_contains( $c, 'not valid block markup' ) ) ), 'and the editor is told once, not twice', $result['concerns'] );
	}
);

qsoft_test(
	'a retry fixes what the first answer got wrong',
	static function (): void {
		$bad  = '<!-- wp:html --><script>alert(1)</script><!-- /wp:html -->';
		$good = "<!-- wp:group {\"tagName\":\"section\"} -->\n<section class=\"wp-block-group\"><!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Latest from the workshop</h2>\n<!-- /wp:heading --></section>\n<!-- /wp:group -->";

		$replies = array( $bad, $good );
		$asked   = array();

		$stub = static function ( $reply, $system, $prompt ) use ( &$replies, &$asked ) {
			$asked[] = $prompt;

			return array(
				'markup'     => (string) array_shift( $replies ),
				'summary'    => 'The workshop list.',
				'editable'   => array(),
				'concerns'   => array(),
				'changed'    => array( 'made the cards a five-column grid' ),
				'_usage'     => array(
					'input_tokens'  => 900,
					'output_tokens' => 120,
				),
				'_model'     => 'claude-opus-5',
				'_transport' => 'api',
			);
		};

		$before_transport = get_option( ModelGateway::OPTION_TRANSPORT );
		$before_key       = get_option( 'qwerty_soft_anthropic_key' );

		update_option( ModelGateway::OPTION_TRANSPORT, 'api' );
		update_option( 'qwerty_soft_anthropic_key', 'sk-ant-test-not-a-real-key' );
		add_filter( 'qwerty_soft/model_reply', $stub, 10, 3 );

		try {
			$root      = qsoft_fixture( 'design' );
			$section   = qsoft_fixture_section( 'Home.dc.html', 'posts' );
			$css       = CssIndex::from_directory( $root );
			$converter = new BlockConverter();
			$converter->use_design( DesignTokens::section_backgrounds( $root ), DesignTokens::extract( $root )['colors'], $css );

			$smart  = new SmartConverter( $root, $converter, $css );
			$result = $smart->convert( (array) $section, false );

			qsoft_assert( 'guided' === $result['source'], 'the corrected answer was used', $result['source'] );
			qsoft_assert( $good === $result['markup'], 'and it is the second answer, not the first' );
			qsoft_assert( array() === array_filter( $result['concerns'], static fn( $c ) => str_contains( $c, 'not valid block markup' ) ), 'nothing is reported as thrown away, because the retry worked', $result['concerns'] );

			qsoft_assert( 2 === count( $asked ), 'two prompts were sent', count( $asked ) );

			/*
			 * The retry has to carry the original task as well as the
			 * complaint. A model handed only its own broken output and "this
			 * is invalid" will fix the syntax and lose the copy, because the
			 * section it was converting is no longer in front of it.
			 */
			qsoft_assert( str_contains( $asked[1], 'What was wrong' ), 'the retry says what was wrong', mb_substr( $asked[1], 0, 300 ) );
			qsoft_assert( str_contains( $asked[1], '<script>' ), 'and shows what came back' );
			qsoft_assert( str_contains( $asked[1], 'Latest from the workshop' ), 'and repeats the section itself' );
			qsoft_assert( str_contains( $asked[1], 'div.grid' ), 'and the resolved-CSS brief with it' );
		} finally {
			remove_filter( 'qwerty_soft/model_reply', $stub, 10 );
			update_option( ModelGateway::OPTION_TRANSPORT, $before_transport );
			update_option( 'qwerty_soft_anthropic_key', $before_key );
		}
	}
);

qsoft_test(
	'a reply that passes is used, and the structural one is left behind',
	static function (): void {
		$good = "<!-- wp:group {\"tagName\":\"section\"} -->\n<section class=\"wp-block-group\"><!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Latest from the workshop</h2>\n<!-- /wp:heading --></section>\n<!-- /wp:group -->";

		$run = qsoft_convert_with_reply(
			array(
				'markup'     => $good,
				'summary'    => 'The workshop list.',
				'editable'   => array( 'The heading' ),
				'concerns'   => array( 'The cards lost their images.' ),
				'changed'    => array( 'made the cards a five-column grid' ),
				'_usage'     => array(
					'input_tokens'  => 900,
					'output_tokens' => 120,
				),
				'_model'     => 'claude-opus-5',
				'_transport' => 'api',
			)
		);

		$result = $run['result'];

		qsoft_assert( 'guided' === $result['source'], 'the guided pass produced the result', $result['source'] );
		qsoft_assert( $good === $result['markup'], 'and its markup is what was kept' );
		qsoft_assert( in_array( 'made the cards a five-column grid', $result['changed'], true ), 'what it changed is reported', $result['changed'] );
		qsoft_assert( in_array( 'The cards lost their images.', $result['concerns'], true ), 'so is what it could not carry', $result['concerns'] );
		qsoft_assert( 1 === $result['rounds'], 'one round, because the review pass was not asked for', $result['rounds'] );
	}
);

qsoft_test(
	'a review that finds nothing is not counted as a correction',
	static function (): void {
		$guided = "<!-- wp:group {\"tagName\":\"section\"} -->\n<section class=\"wp-block-group\"><!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Latest from the workshop</h2>\n<!-- /wp:heading --></section>\n<!-- /wp:group -->";

		/*
		 * The review pass answers "match" most of the time and contributes
		 * nothing but honest notes. Counting that as a correction would make
		 * the report claim the model fixed a section it agreed with — and the
		 * number of corrected sections is what somebody reads to decide
		 * whether the second pass was worth paying for.
		 */
		$replies = array(
			array(
				'markup'   => $guided,
				'summary'  => 'The workshop list.',
				'editable' => array(),
				'concerns' => array(),
				'changed'  => array(),
			),
			array(
				'verdict'  => 'match',
				'changed'  => array(),
				'concerns' => array( 'The cards lost their images.' ),
			),
		);

		$stub = static function () use ( &$replies ) {
			$reply = array_shift( $replies );

			return array_merge(
				(array) $reply,
				array(
					'_usage'     => array(
						'input_tokens'  => 900,
						'output_tokens' => 120,
					),
					'_model'     => 'claude-opus-5',
					'_transport' => 'api',
				)
			);
		};

		$before_transport = get_option( ModelGateway::OPTION_TRANSPORT );
		$before_key       = get_option( 'qwerty_soft_anthropic_key' );

		update_option( ModelGateway::OPTION_TRANSPORT, 'api' );
		update_option( 'qwerty_soft_anthropic_key', 'sk-ant-test-not-a-real-key' );
		add_filter( 'qwerty_soft/model_reply', $stub, 10, 1 );

		try {
			$root      = qsoft_fixture( 'design' );
			$section   = qsoft_fixture_section( 'Home.dc.html', 'posts' );
			$css       = CssIndex::from_directory( $root );
			$converter = new BlockConverter();
			$converter->use_design( DesignTokens::section_backgrounds( $root ), DesignTokens::extract( $root )['colors'], $css );

			$smart  = new SmartConverter( $root, $converter, $css, array( 'refine' => true ) );
			$result = $smart->convert( (array) $section, false );

			qsoft_assert( 'guided' === $result['source'], 'the section is still credited to the guided pass', $result['source'] );
			qsoft_assert( $guided === $result['markup'], 'the markup is untouched by the review' );
			qsoft_assert( in_array( 'The cards lost their images.', $result['concerns'], true ), 'but what the review noticed is kept', $result['concerns'] );
			qsoft_assert( 2 === count( $smart->calls() ), 'both passes ran', $smart->calls() );
		} finally {
			remove_filter( 'qwerty_soft/model_reply', $stub, 10 );
			update_option( ModelGateway::OPTION_TRANSPORT, $before_transport );
			update_option( 'qwerty_soft_anthropic_key', $before_key );
		}
	}
);

qsoft_test(
	'a section nobody could ask about says so',
	static function (): void {
		$before_transport = get_option( ModelGateway::OPTION_TRANSPORT );
		$before_key       = get_option( 'qwerty_soft_anthropic_key' );

		update_option( ModelGateway::OPTION_TRANSPORT, 'api' );
		update_option( 'qwerty_soft_anthropic_key', '' );

		try {
			$root      = qsoft_fixture( 'design' );
			$section   = qsoft_fixture_section( 'Home.dc.html', 'posts' );
			$css       = CssIndex::from_directory( $root );
			$converter = new BlockConverter();
			$converter->use_design( DesignTokens::section_backgrounds( $root ), DesignTokens::extract( $root )['colors'], $css );

			$smart  = new SmartConverter( $root, $converter, $css );
			$result = $smart->convert( (array) $section, false );

			/*
			 * Without this flag the screen shows a conversion that came back
			 * unimproved from a button offering to improve it, which reads as
			 * "the model looked and found nothing wrong". No model looked.
			 */
			qsoft_assert( ! empty( $result['unavailable'] ), 'the result says no model was asked', $result );
			qsoft_assert( 'structural' === $result['source'], 'and it is the structural conversion', $result['source'] );
		} finally {
			update_option( ModelGateway::OPTION_TRANSPORT, $before_transport );
			update_option( 'qwerty_soft_anthropic_key', $before_key );
		}
	}
);

qsoft_test(
	'a gateway error leaves the structural conversion alone',
	static function (): void {
		$run = qsoft_convert_with_reply( new WP_Error( 'qwerty_soft_cli_timeout', 'The claude command was still running after 300 seconds and was stopped.' ) );

		$result = $run['result'];

		qsoft_assert( 'structural' === $result['source'], 'the section fell back', $result['source'] );
		qsoft_assert( $run['structural'] === $result['markup'], 'to exactly the structural markup' );
		qsoft_assert( ! empty( $run['calls'] ) && empty( $run['calls'][0]['ok'] ), 'the attempt is recorded as failed', $run['calls'] );
		qsoft_assert( str_contains( (string) $run['calls'][0]['error'], 'still running' ), 'with the reason kept for the report', $run['calls'] );
	}
);

qsoft_test(
	'a CLI that is not there fails with a reason, not an exception',
	static function (): void {
		$before = get_option( ClaudeCli::OPTION_BINARY );

		update_option( ClaudeCli::OPTION_BINARY, '/nowhere/at/all/claude' );
		ClaudeCli::forget();

		try {
			$status = ClaudeCli::status();

			qsoft_assert( false === $status['ready'], 'a missing binary is not ready', $status );
			qsoft_assert( '' !== $status['reason'], 'and says why in a sentence a person can act on', $status );

			$reply = ClaudeCli::generate( 'system', 'prompt', array( 'type' => 'object' ) );

			qsoft_assert( is_wp_error( $reply ), 'generating through it is an error, not a crash', $reply );
		} finally {
			update_option( ClaudeCli::OPTION_BINARY, $before );
			ClaudeCli::forget();
		}
	}
);

qsoft_test(
	'an explicit transport choice is honoured even when it cannot work',
	static function (): void {
		$before = get_option( ModelGateway::OPTION_TRANSPORT );

		try {
			update_option( ModelGateway::OPTION_TRANSPORT, 'api' );
			qsoft_assert( 'api' === ModelGateway::resolve(), 'asking for the API gets the API', ModelGateway::resolve() );

			update_option( ModelGateway::OPTION_TRANSPORT, 'cli' );
			qsoft_assert( 'cli' === ModelGateway::resolve(), 'asking for the CLI gets the CLI', ModelGateway::resolve() );

			/*
			 * Silently switching routes would bill an API key somebody
			 * deliberately took out of the loop, or spend a subscription
			 * somebody deliberately did not choose.
			 */
			update_option( ModelGateway::OPTION_TRANSPORT, 'nonsense' );
			qsoft_assert( 'auto' === ModelGateway::preference(), 'an unknown preference falls back to automatic', ModelGateway::preference() );
		} finally {
			update_option( ModelGateway::OPTION_TRANSPORT, $before );
		}
	}
);

qsoft_test(
	'a conversion that was never billed adds no money to the total',
	static function (): void {
		$usage = array(
			'input_tokens'  => 12000,
			'output_tokens' => 3000,
		);

		Spend::reset();

		$billed = Spend::record( $usage, 'claude-opus-5', true );

		qsoft_assert( $billed['cost'] > 0.0, 'an API conversion costs something', $billed );

		Spend::reset();

		$free = Spend::record( $usage, 'claude-opus-5', false );

		qsoft_assert( 0.0 === $free['cost'], 'a subscription conversion costs nothing', $free );
		qsoft_assert( 12000 === $free['input'] && 3000 === $free['output'], 'but its tokens are still counted', $free );

		Spend::reset();
	}
);

qsoft_finish();

<?php
/**
 * Converts a section with a model reading over the converter's shoulder.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The structural conversion, corrected by a model, checked, and kept only if
 * it is better.
 *
 * Three passes, of which only the first always runs:
 *
 * 1. **Structural.** {@see BlockConverter} converts the section from its HTML
 *    and the design's resolved CSS. This is the free, deterministic, offline
 *    result, and on a design whose construction the converter knows it is
 *    already what ships.
 * 2. **Guided.** The model is handed that result, a brief of what the CSS
 *    resolves to, and a screenshot when the archive has one, and asked to
 *    correct it. This is where a construction the converter has never seen —
 *    an unfamiliar grid, a composed hero, a layout the class names do not
 *    explain — gets understood rather than approximated.
 * 3. **Reviewed.** Optionally, the produced blocks are rendered with
 *    `do_blocks()` and the model is shown what WordPress actually made of
 *    them, next to the design, and asked what differs.
 *
 * The rule that makes the whole thing safe to switch on: **a later pass has to
 * earn its place.** Every candidate goes through {@see BlockMarkupValidator},
 * and anything that fails is discarded in favour of the last result that
 * passed. A model that is having a bad minute cannot make an import worse than
 * the structural conversion it started from — the floor is the offline result,
 * always.
 */
final class SmartConverter {

	/**
	 * Design root directory.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * The structural converter, already told about the design.
	 *
	 * @var BlockConverter
	 */
	private BlockConverter $converter;

	/**
	 * The design's stylesheets, resolved.
	 *
	 * @var CssIndex|null
	 */
	private ?CssIndex $css;

	/**
	 * Model, effort and whether to run the review pass.
	 *
	 * @var array<string, mixed>
	 */
	private array $options;

	/**
	 * Screenshot of the whole page being converted, or an empty string.
	 *
	 * @var string
	 */
	private string $shot = '';

	/**
	 * Directory the current page sits in, for resolving its image paths.
	 *
	 * @var string
	 */
	private string $page_dir = '';

	/**
	 * Screenshots belonging to the section being converted right now.
	 *
	 * @var array<int, string>
	 */
	private array $section_shots = array();

	/**
	 * Every model call made, so the caller can bill and report them.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = array();

	/**
	 * The design's own documentation, quoted once and reused per section.
	 *
	 * @var string|null
	 */
	private ?string $docs = null;

	/**
	 * Replies that came back and were thrown away, said in plain words.
	 *
	 * @var array<int, string>
	 */
	private array $discarded = array();

	/**
	 * Set up a converter for one design.
	 *
	 * @param string               $root      Design root.
	 * @param BlockConverter       $converter Structural converter.
	 * @param CssIndex|null        $css       Resolved stylesheets.
	 * @param array<string, mixed> $options   model, effort, refine, timeout.
	 */
	public function __construct( string $root, BlockConverter $converter, ?CssIndex $css, array $options = array() ) {
		$this->root      = $root;
		$this->converter = $converter;
		$this->css       = $css;
		$this->options   = $options;
	}

	/**
	 * Whether a guided conversion can run at all on this machine.
	 *
	 * @return bool
	 */
	public static function possible(): bool {
		return ModelGateway::ready();
	}

	/**
	 * Point the converter at the screenshot for the page being converted.
	 *
	 * @param string $file Page file, relative to the design root.
	 * @return void
	 */
	public function for_page( string $file ): void {
		$this->shot     = DesignArchive::screenshot_for( $this->root, $file );
		$this->page_dir = trim( str_replace( '\\', '/', dirname( $file ) ), '.' );
		$this->page_dir = trim( $this->page_dir, '/' );
	}

	/**
	 * Screenshots that belong to this section rather than to the page.
	 *
	 * A design exported from Claude Design draws some things with JavaScript —
	 * a chart, an animated diagram — and ships a picture of each beside the
	 * markup. The splitter has already swapped those elements for the picture,
	 * so the section arrives here with the exact image of the thing in it.
	 *
	 * That picture is worth far more to the model than the page screenshot is:
	 * it shows the one construction the markup cannot describe, at the size it
	 * is meant to be, with nothing else in the frame.
	 *
	 * @param string $html Section markup.
	 * @return array<int, string> Absolute paths, at most two.
	 */
	private function shots_in( string $html ): array {
		if ( ! str_contains( $html, '<img' ) ) {
			return array();
		}

		if ( ! preg_match_all( '#<img[^>]+src=["\']([^"\']+)["\']#i', $html, $matches ) ) {
			return array();
		}

		$found = array();
		$base  = rtrim( str_replace( '\\', '/', $this->root ), '/' );

		foreach ( $matches[1] as $src ) {
			$src = trim( html_entity_decode( $src, ENT_QUOTES ) );

			// Only the screenshots the splitter stood in; the design's own photographs are content.
			if ( ! preg_match( '#(^|/)(screenshots?|previews?|shots)/#i', $src ) ) {
				continue;
			}

			if ( str_starts_with( $src, 'http' ) || str_starts_with( $src, 'data:' ) ) {
				continue;
			}

			$path = '' !== $this->page_dir ? $base . '/' . $this->page_dir . '/' . $src : $base . '/' . $src;
			$path = self::tidy( $path );

			// A path that climbed out of the archive is not one of the design's files.
			if ( ! str_starts_with( $path, $base . '/' ) || ! is_file( $path ) ) {
				continue;
			}

			$found[ $path ] = true;

			if ( count( $found ) >= 2 ) {
				break;
			}
		}

		return array_keys( $found );
	}

	/**
	 * Resolve `..` and `.` in a path without touching the filesystem.
	 *
	 * PHP realpath() would do it, but it also resolves symlinks and returns false
	 * for a path that does not exist — and the check this feeds is precisely
	 * "is this still inside the archive", which has to be answered before the
	 * file is trusted enough to look at.
	 *
	 * @param string $path Path with forward slashes.
	 * @return string
	 */
	private static function tidy( string $path ): string {
		$out = array();

		foreach ( explode( '/', $path ) as $part ) {
			if ( '.' === $part || '' === $part ) {
				// Keep a leading empty segment so an absolute POSIX path stays absolute.
				if ( '' === $part && array() === $out ) {
					$out[] = $part;
				}

				continue;
			}

			if ( '..' === $part ) {
				array_pop( $out );
				continue;
			}

			$out[] = $part;
		}

		return implode( '/', $out );
	}

	/**
	 * The model calls made so far, oldest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function calls(): array {
		return $this->calls;
	}

	/**
	 * The structural converter underneath, already told about this design.
	 *
	 * Handed out so a caller that also needs one does not build a second —
	 * setting one up parses every stylesheet in the archive, which is the
	 * expensive part of converting a page.
	 *
	 * @return BlockConverter
	 */
	public function structural(): BlockConverter {
		return $this->converter;
	}

	/**
	 * Convert one section as well as this machine can.
	 *
	 * @param array<string, mixed> $section  Section record from SectionSplitter.
	 * @param bool                 $is_first Whether it opens the page.
	 * @param array<string, mixed> $context  page, lang.
	 * @return array<string, mixed>
	 */
	public function convert( array $section, bool $is_first = false, array $context = array() ): array {
		$this->discarded     = array();
		$this->section_shots = $this->shots_in( (string) $section['html'] );

		$baseline = $this->converter->convert( $section, $is_first );

		$result = array(
			'markup'      => (string) ( $baseline['markup'] ?? '' ),
			'summary'     => (string) ( $baseline['summary'] ?? '' ),
			'editable'    => array_map( 'strval', (array) ( $baseline['editable'] ?? array() ) ),
			'concerns'    => array_map( 'strval', (array) ( $baseline['concerns'] ?? array() ) ),
			'changed'     => array(),
			'source'      => 'structural',
			'rounds'      => 0,
			'unavailable' => false,
		);

		if ( ! ModelGateway::ready() ) {
			/*
			 * Not an error — the structural conversion is a real result and
			 * this is what a site without a key or a binary always gets. But
			 * it has to be said. A section that silently comes back
			 * unimproved, on a screen whose button offered to improve it,
			 * reads as the model having looked and found nothing wrong.
			 */
			$result['unavailable'] = true;

			return $result;
		}

		$facts = DesignFacts::for_section( (string) $section['html'], $this->css );

		$guided = $this->guided( $section, $result['markup'], $facts, $is_first, $context );

		if ( null !== $guided ) {
			$result['markup']   = $guided['markup'];
			$result['summary']  = '' !== $guided['summary'] ? $guided['summary'] : $result['summary'];
			$result['editable'] = array() !== $guided['editable'] ? $guided['editable'] : $result['editable'];
			$result['concerns'] = array_values( array_unique( array_merge( $result['concerns'], $guided['concerns'] ) ) );
			$result['changed']  = $guided['changed'];
			$result['source']   = 'guided';
			$result['rounds']   = 1;
		}

		if ( empty( $this->options['refine'] ) || '' === trim( $result['markup'] ) ) {
			return $this->with_discards( $result );
		}

		$reviewed = $this->converged( $section, $result['markup'], $facts );

		if ( null !== $reviewed ) {
			/*
			 * The review pass usually answers "match" and contributes nothing
			 * but honest notes about what blocks could not carry. Calling that
			 * outcome "reviewed" would make the report claim the model
			 * corrected a section it agreed with, and the count of corrected
			 * sections is the one number somebody checks to decide whether
			 * this was worth paying for. So the label follows the markup.
			 */
			if ( $reviewed['markup'] !== $result['markup'] ) {
				$result['markup'] = $reviewed['markup'];
				$result['source'] = 'reviewed';
			}

			$result['concerns'] = array_values( array_unique( array_merge( $result['concerns'], $reviewed['concerns'] ) ) );
			$result['changed']  = array_values( array_unique( array_merge( $result['changed'], $reviewed['changed'] ) ) );
			$result['rounds']   = (int) $result['rounds'] + (int) $reviewed['rounds'];

			return $this->with_discards( $result );
		}

		$result['rounds'] = (int) $result['rounds'] + 1;

		return $this->with_discards( $result );
	}

	/**
	 * Fold any thrown-away replies into the section's concerns.
	 *
	 * @param array<string, mixed> $result Conversion result.
	 * @return array<string, mixed>
	 */
	private function with_discards( array $result ): array {
		if ( array() === $this->discarded ) {
			return $result;
		}

		$result['concerns'] = array_values(
			array_unique( array_merge( (array) $result['concerns'], $this->discarded ) )
		);

		return $result;
	}

	/**
	 * Pass two: the model corrects the structural conversion.
	 *
	 * Returns null when nothing usable came back, which leaves the caller on
	 * the structural result.
	 *
	 * @param array<string, mixed> $section  Section record.
	 * @param string               $baseline Structural markup.
	 * @param string               $facts    Resolved-CSS brief.
	 * @param bool                 $is_first Whether it opens the page.
	 * @param array<string, mixed> $context  page, lang.
	 * @return array<string, mixed>|null
	 */
	private function guided( array $section, string $baseline, string $facts, bool $is_first, array $context ) {
		/*
		 * Only worth slicing when there is no brief to send instead — the
		 * brief is the same cascade, already resolved, and ConversionPrompt
		 * drops the raw CSS whenever it has one.
		 */
		$css = '' === trim( $facts ) && null !== $this->css
			? $this->css->rules_for( (string) $section['html'] )
			: '';

		$message = ConversionPrompt::brief(
			$section,
			array(
				'baseline' => $baseline,
				'facts'    => $facts,
				'css'      => $css,
				'shot'     => '' !== $this->shot,
				'closeups' => count( $this->section_shots ),
			),
			array(
				'page'     => (string) ( $context['page'] ?? '' ),
				'lang'     => (string) ( $context['lang'] ?? '' ),
				'is_first' => $is_first,
				'docs'     => $this->docs(),
			)
		);

		$system = ConversionPrompt::system( true );
		$schema = ConversionPrompt::guided_schema();

		$reply = $this->ask( $system, $message, $schema );

		if ( null === $reply ) {
			return null;
		}

		$markup = isset( $reply['markup'] ) ? (string) $reply['markup'] : '';
		$why    = $this->rejected( $markup );

		if ( '' !== $why ) {
			/*
			 * Worth exactly one more try. What fails here is almost never the
			 * judgement — the layout decision was right — but the typing: a
			 * brace missing from one block's settings, repeated across five
			 * identical cards. Throwing the whole answer away for that spends
			 * the call and keeps the worse conversion, when the model fixes it
			 * reliably once it is told which block and what was wrong.
			 *
			 * One retry, not a loop: a second failure means something the
			 * model is not going to talk its way out of, and the structural
			 * conversion is waiting either way.
			 */
			$this->not_kept( $why );

			$retry = $this->ask( $system, ConversionPrompt::correction( $message, $markup, $why ), $schema );

			$markup = null !== $retry && isset( $retry['markup'] ) ? (string) $retry['markup'] : '';
			$second = null === $retry ? __( 'the retry could not be sent', 'qwerty-soft-signal' ) : $this->rejected( $markup );

			if ( '' !== $second ) {
				if ( null !== $retry ) {
					$this->not_kept( $second );
				}

				$this->discard( 'guided', $why );

				return null;
			}

			$reply = $retry;
		}

		return array(
			'markup'   => $markup,
			'summary'  => isset( $reply['summary'] ) ? (string) $reply['summary'] : '',
			'editable' => isset( $reply['editable'] ) && is_array( $reply['editable'] ) ? array_map( 'strval', $reply['editable'] ) : array(),
			'concerns' => isset( $reply['concerns'] ) && is_array( $reply['concerns'] ) ? array_map( 'strval', $reply['concerns'] ) : array(),
			'changed'  => isset( $reply['changed'] ) && is_array( $reply['changed'] ) ? array_map( 'strval', $reply['changed'] ) : array(),
		);
	}

	/**
	 * Pass three: the model marks the rendered result against the design.
	 *
	 * @param array<string, mixed> $section Section record.
	 * @param string               $markup  Markup to review.
	 * @param string               $facts   Resolved-CSS brief.
	 * @return array<string, mixed>|null
	 */
	private function reviewed( array $section, string $markup, string $facts ) {
		$rendered = RefinePrompt::render( $markup );

		if ( '' === $rendered ) {
			return null;
		}

		$system  = RefinePrompt::system();
		$schema  = RefinePrompt::schema();
		$message = RefinePrompt::message(
			$section,
			$markup,
			$rendered,
			array(
				'facts'    => $facts,
				'shot'     => '' !== $this->shot,
				'closeups' => count( $this->section_shots ),
			)
		);

		$reply = $this->ask( $system, $message, $schema );

		if ( null === $reply ) {
			return null;
		}

		$concerns  = isset( $reply['concerns'] ) && is_array( $reply['concerns'] ) ? array_map( 'strval', $reply['concerns'] ) : array();
		$verdict   = isset( $reply['verdict'] ) ? (string) $reply['verdict'] : 'match';
		$corrected = isset( $reply['markup'] ) ? (string) $reply['markup'] : '';

		/*
		 * "match" is the common answer and means the pass found nothing to do.
		 * Its concerns are still worth keeping — they are the honest list of
		 * what blocks could not carry — but the markup stays as it was.
		 */
		if ( 'match' !== $verdict && '' !== trim( $corrected ) ) {
			$why = $this->rejected( $corrected );

			if ( '' !== $why ) {
				/*
				 * The same single retry the conversion gets, for the same
				 * reason: a correction that is right about the layout and
				 * wrong about a brace is worth one more sentence, not a
				 * thrown-away call. A review that fails twice keeps the
				 * markup it was reviewing, which was already valid.
				 */
				$this->not_kept( $why );

				$retry = $this->ask( $system, RefinePrompt::correction( $message, $corrected, $why ), $schema );

				$again = null !== $retry && isset( $retry['markup'] ) ? (string) $retry['markup'] : '';
				$still = null === $retry ? __( 'the retry could not be sent', 'qwerty-soft-signal' ) : $this->rejected( $again );

				if ( '' === $still && 'match' !== (string) ( $retry['verdict'] ?? 'match' ) ) {
					$corrected = $again;
					$concerns  = isset( $retry['concerns'] ) && is_array( $retry['concerns'] ) ? array_map( 'strval', $retry['concerns'] ) : $concerns;
					$reply     = $retry;
				} else {
					if ( null !== $retry && '' !== $still ) {
						$this->not_kept( $still );
					}

					$this->discard( 'reviewed', $why );

					$verdict = 'match';
				}
			}
		}

		if ( 'match' === $verdict || '' === trim( $corrected ) ) {
			return array() === $concerns ? null : array(
				'markup'   => $markup,
				'concerns' => $concerns,
				'changed'  => array(),
			);
		}

		return array(
			'markup'   => $corrected,
			'concerns' => $concerns,
			'changed'  => isset( $reply['changed'] ) && is_array( $reply['changed'] ) ? array_map( 'strval', $reply['changed'] ) : array(),
			'verdict'  => $verdict,
		);
	}

	/**
	 * Review, correct, render again, review again — until it says "match".
	 *
	 * One review pass finds what one review pass finds. A section it calls
	 * "off" gets a correction, and that correction is then never looked at:
	 * the pass that was meant to prove the blocks match the design stops one
	 * step before the proof. Looping closes that — each round renders what the
	 * last round produced and asks the same question of the new markup — and
	 * stops on the first "match", on the round limit, or as soon as a round
	 * changes nothing.
	 *
	 * The limit is small on purpose. A section that still disagrees after
	 * three passes disagrees about something blocks cannot express, and a
	 * fourth round would spend another call to say so again.
	 *
	 * @param array<string, mixed> $section Section record.
	 * @param string               $markup  Markup to check.
	 * @param string               $facts   Resolved-CSS brief.
	 * @return array{markup:string,concerns:array<int,string>,changed:array<int,string>,rounds:int}|null
	 */
	private function converged( array $section, string $markup, string $facts ) {
		$rounds   = max( 1, min( 4, (int) ( $this->options['rounds'] ?? 3 ) ) );
		$concerns = array();
		$changed  = array();
		$current  = $markup;
		$ran      = 0;

		for ( $round = 0; $round < $rounds; $round++ ) {
			$reviewed = $this->reviewed( $section, $current, $facts );
			++$ran;

			if ( null === $reviewed ) {
				break;
			}

			$concerns = array_merge( $concerns, $reviewed['concerns'] );
			$changed  = array_merge( $changed, $reviewed['changed'] );

			// Nothing left to correct, or nothing changed by correcting.
			if ( 'match' === (string) ( $reviewed['verdict'] ?? 'match' ) || $reviewed['markup'] === $current ) {
				$current = $reviewed['markup'];
				break;
			}

			$current = $reviewed['markup'];
		}

		if ( $current === $markup && array() === $concerns && array() === $changed ) {
			return null;
		}

		return array(
			'markup'   => $current,
			'concerns' => array_values( array_unique( $concerns ) ),
			'changed'  => array_values( array_unique( $changed ) ),
			'rounds'   => $ran,
		);
	}

	/**
	 * Ask the model, recording the call whether or not it worked.
	 *
	 * A failure is not fatal here and is not surfaced as an error: the caller
	 * still has the structural conversion, and an import that stops halfway
	 * because one section timed out is worse than one that quietly falls back
	 * for that section and says so in the report.
	 *
	 * @param string               $system System prompt.
	 * @param string               $prompt User message.
	 * @param array<string, mixed> $schema Reply schema.
	 * @return array<string, mixed>|null
	 */
	private function ask( string $system, string $prompt, array $schema ) {
		$options = array(
			'model'  => (string) ( $this->options['model'] ?? AnthropicClient::DEFAULT_MODEL ),
			'effort' => (string) ( $this->options['effort'] ?? 'high' ),
		);

		if ( isset( $this->options['timeout'] ) ) {
			$options['timeout'] = (int) $this->options['timeout'];
		}

		/*
		 * Push PHP's own deadline out before every call, not once per page.
		 *
		 * A page step is given fifteen minutes; a page of eight sections at
		 * high effort can want more than that, and the request would be killed
		 * mid-build with the work paid for and nothing kept. set_time_limit()
		 * restarts the count, so what has to fit is one model call rather than
		 * a whole page of them.
		 */
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 10 + ( isset( $options['timeout'] ) ? (int) $options['timeout'] : 300 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- One model call, bounded by the transport's own timeout.
		}

		$images = $this->images();

		if ( array() !== $images ) {
			$options['images'] = $images;
		}

		/*
		 * A sign of life before every model call, not only between sections.
		 *
		 * The watchdog decides a step has died when nothing has been heard
		 * from it for a lease. A section is several calls — convert, then
		 * review, then correct — and each of them can run to the transport's
		 * own timeout, so a section that is working perfectly well used to go
		 * quiet for longer than the lease and have its page started again by a
		 * second process. Both then converted the same page, and both wrote
		 * it. This is the cheapest possible fix: say "still here" at the one
		 * moment the process is about to disappear into a long call.
		 */
		ImportSession::beat();

		$reply = ModelGateway::generate( $system, $prompt, $schema, $options );

		// And again on the way out, so the lease is measured from the reply.
		ImportSession::beat();

		if ( is_wp_error( $reply ) ) {
			$this->calls[] = array(
				'ok'        => false,
				'error'     => $reply->get_error_message(),
				'usage'     => array(),
				'model'     => $options['model'],
				'transport' => ModelGateway::resolve(),
			);

			return null;
		}

		$this->calls[] = array(
			'ok'        => true,
			'error'     => '',
			'usage'     => isset( $reply['_usage'] ) && is_array( $reply['_usage'] ) ? $reply['_usage'] : array(),
			'model'     => isset( $reply['_model'] ) && '' !== (string) $reply['_model'] ? (string) $reply['_model'] : $options['model'],
			'transport' => isset( $reply['_transport'] ) ? (string) $reply['_transport'] : 'api',
			'notional'  => isset( $reply['_notional_cost'] ) ? (float) $reply['_notional_cost'] : 0.0,
		);

		return $reply;
	}

	/**
	 * What the design wrote about itself, read once for the whole design.
	 *
	 * Short on purpose: this rides along with every section of every page, so
	 * it buys the design system and the intent, not the change history.
	 *
	 * @return string
	 */
	private function docs(): string {
		if ( null === $this->docs ) {
			$this->docs = (string) DesignDocs::digest( $this->root, 6000 )['text'];
		}

		return $this->docs;
	}

	/**
	 * The pictures to send with this section, closest thing first.
	 *
	 * The section's own screenshots lead, because each shows one construction
	 * at full size with nothing else in the frame; the page screenshot follows
	 * as context. Both are optional and most archives have neither.
	 *
	 * @return array<int, string>
	 */
	private function images(): array {
		$images = $this->section_shots;

		if ( '' !== $this->shot ) {
			$images[] = $this->shot;
		}

		return $images;
	}

	/**
	 * Why a candidate cannot be kept, or an empty string when it can.
	 *
	 * @param string $markup Candidate markup.
	 * @return string
	 */
	private function rejected( string $markup ): string {
		if ( '' === trim( $markup ) ) {
			return __( 'the reply contained no markup', 'qwerty-soft-signal' );
		}

		$validator = new BlockMarkupValidator();

		if ( $validator->check( $markup ) ) {
			return '';
		}

		return implode( ' ', $validator->errors() );
	}

	/**
	 * Mark the reply that just arrived as one that was not used.
	 *
	 * A discarded reply is the one outcome that would otherwise be invisible:
	 * the tokens were spent, the section silently kept its structural
	 * conversion, and the report would show a build that looked as though the
	 * model had simply agreed with everything. It did not — it answered, and
	 * the answer was not safe to use. That is worth recording, both because it
	 * is true and because it is how a validator that has become too strict
	 * gets noticed rather than quietly eating every improvement.
	 *
	 * @param string $why What the validator objected to.
	 * @return void
	 */
	private function not_kept( string $why ): void {
		$last = count( $this->calls ) - 1;

		if ( $last >= 0 ) {
			$this->calls[ $last ]['kept']    = false;
			$this->calls[ $last ]['discard'] = $why;
		}
	}

	/**
	 * Note that a pass produced nothing usable, in words for the editor.
	 *
	 * @param string $pass Which pass produced it.
	 * @param string $why  What the validator objected to.
	 * @return void
	 */
	private function discard( string $pass, string $why ): void {
		$this->discarded[] = 'reviewed' === $pass
			? sprintf(
				/* translators: %s: what the validator objected to. */
				__( 'Claude proposed a correction to this section that would not have been valid block markup, so the section was left as it was (%s).', 'qwerty-soft-signal' ),
				$why
			)
			: sprintf(
				/* translators: %s: what the validator objected to. */
				__( 'Claude answered for this section but the result was not valid block markup, so the structural conversion was kept (%s).', 'qwerty-soft-signal' ),
				$why
			);
	}
}

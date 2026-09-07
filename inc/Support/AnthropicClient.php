<?php
/**
 * Minimal Anthropic Messages API client built on the WordPress HTTP API.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to POST /v1/messages over wp_remote_post().
 *
 * The official Anthropic PHP SDK is deliberately not used: it would put a
 * Composer vendor tree inside the theme, and this theme's whole premise is
 * that a client can upload the ZIP and activate it. The Messages API is one
 * JSON POST, so the dependency buys nothing here.
 *
 * The API key never leaves the server — every call originates in PHP, and the
 * admin screen only ever sees the generated markup.
 */
final class AnthropicClient {

	/**
	 * Messages endpoint.
	 */
	private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	/**
	 * Pinned API version. Anthropic keeps this stable; it is not the model.
	 */
	private const API_VERSION = '2023-06-01';

	/**
	 * Default model.
	 *
	 * Claude Opus 5 — 1M context, 128K max output. Note the ID carries no date
	 * suffix; appending one returns a 404.
	 */
	public const DEFAULT_MODEL = 'claude-opus-5';

	/**
	 * Models an administrator may pick in the settings screen.
	 *
	 * @return array<string, string> Model ID => human label.
	 */
	public static function models(): array {
		return array(
			'claude-opus-5'    => __( 'Claude Opus 5 — best quality (default)', 'qwerty-soft-signal' ),
			'claude-sonnet-5'  => __( 'Claude Sonnet 5 — faster and cheaper', 'qwerty-soft-signal' ),
			'claude-haiku-4-5' => __( 'Claude Haiku 4.5 — fastest, simple sections only', 'qwerty-soft-signal' ),
		);
	}

	/**
	 * Effort levels, which trade latency and cost against quality.
	 *
	 * @return array<string, string> Effort => human label.
	 */
	public static function effort_levels(): array {
		return array(
			'low'    => __( 'Low — quickest, for simple sections', 'qwerty-soft-signal' ),
			'medium' => __( 'Medium — balanced', 'qwerty-soft-signal' ),
			'high'   => __( 'High — recommended', 'qwerty-soft-signal' ),
			'xhigh'  => __( 'Extra high — slowest, for complex layouts', 'qwerty-soft-signal' ),
		);
	}

	/**
	 * The resolved API key, or an empty string.
	 *
	 * A wp-config.php constant wins over the stored option so a site can keep
	 * the key out of the database entirely.
	 *
	 * @return string
	 */
	public static function api_key(): string {
		if ( defined( 'QSOFT_ANTHROPIC_KEY' ) && is_string( QSOFT_ANTHROPIC_KEY ) ) {
			return trim( QSOFT_ANTHROPIC_KEY );
		}

		return trim( (string) get_option( 'qwerty_soft_anthropic_key', '' ) );
	}

	/**
	 * Whether the key comes from a constant rather than the database.
	 *
	 * @return bool
	 */
	public static function key_is_constant(): bool {
		return defined( 'QSOFT_ANTHROPIC_KEY' ) && '' !== trim( (string) QSOFT_ANTHROPIC_KEY );
	}

	/**
	 * Output ceiling a request starts with when the caller names none.
	 *
	 * Enough for any one section's block markup, and low enough that a
	 * non-streaming request answers inside the HTTP timeout.
	 */
	public const DEFAULT_MAX_TOKENS = 16000;

	/**
	 * Output ceiling a cut-off reply may be retried at.
	 *
	 * A reply that stopped at its ceiling is asked for once more with twice
	 * the room, up to here. Past this a single blocking request would run
	 * into the transport timeout, which is a worse ending than a truncation.
	 */
	public const MAX_OUTPUT_TOKENS = 32000;

	/**
	 * Where a correction prompt puts the brief it repeats.
	 *
	 * ConversionPrompt::correction() sends the whole original brief again,
	 * after the complaint, under this heading — and counts on the repeat
	 * being a cache hit. Caching is a prefix match at content-block
	 * boundaries, so a brief that arrives as the tail of one long block after
	 * a complaint the model has never seen can never be one. The client
	 * recognises the layout here and turns it into what the cache needs: the
	 * brief first, as a block of its own, byte-identical to the block the
	 * first request sent, with the complaint after it.
	 *
	 * Mirrors the text in ConversionPrompt::correction(); the two must agree.
	 */
	private const RESENT_BRIEF = "\n---\n\n### The original task, unchanged\n\n";

	/**
	 * Send one non-streaming Messages request and return the parsed payload.
	 *
	 * Streaming is not used: a WordPress admin request is a single blocking
	 * round trip either way, and Server-Sent Events cannot be surfaced through
	 * the REST response this screen consumes. The generation is bounded by
	 * max_tokens instead, well below the point where streaming becomes
	 * necessary to dodge HTTP timeouts.
	 *
	 * A reply cut off at that bound is sent once more with the bound raised
	 * — see {@see self::raised()} — before it is given up on. The first
	 * request is the common case and stays cheap; the retry is what stops a
	 * long section from being a dead end.
	 *
	 * @param string               $system   System prompt.
	 * @param string               $prompt   User message.
	 * @param array<string, mixed> $schema   JSON Schema the reply must satisfy.
	 * @param array<string, mixed> $options  Optional overrides: model, effort, max_tokens, timeout, images.
	 * @return array<string, mixed>|WP_Error Decoded JSON object on success.
	 */
	public static function generate( string $system, string $prompt, array $schema, array $options = array() ) {
		$key = self::api_key();

		if ( '' === $key ) {
			return new WP_Error(
				'qwerty_soft_no_key',
				__( 'No Anthropic API key is configured yet. Add one under Appearance → Design import.', 'qwerty-soft-signal' )
			);
		}

		$body    = self::request_body( $system, $prompt, $schema, $options );
		$headers = self::headers( $key, (string) $body['model'] );

		/*
		 * The timeout is generous because a high-effort request genuinely takes
		 * a while. WordPress defaults to 5 seconds, which would abort
		 * essentially every call.
		 */
		$timeout = isset( $options['timeout'] ) ? (int) $options['timeout'] : 180;

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$parsed = self::send( $body, $headers, $timeout );

			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}

			/*
			 * A declined request is a successful HTTP 200 carrying
			 * stop_reason "refusal", so this check has to happen before
			 * anything reads content — indexing content[0] would fatal on an
			 * empty array.
			 */
			if ( 'refusal' === ( $parsed['stop_reason'] ?? '' ) ) {
				return new WP_Error(
					'qwerty_soft_refusal',
					__( 'Anthropic declined this request. Rephrase the brief and try again — describe the section rather than pasting code from an unrelated system.', 'qwerty-soft-signal' )
				);
			}

			if ( 'max_tokens' !== ( $parsed['stop_reason'] ?? '' ) ) {
				return self::payload( $parsed, (string) $body['model'] );
			}

			$ceiling = (int) $body['max_tokens'];
			$raised  = self::raised( $ceiling );

			if ( 0 === $attempt && $raised > $ceiling ) {
				$body['max_tokens'] = $raised;
				continue;
			}

			break;
		}

		return new WP_Error(
			'qwerty_soft_truncated',
			__( 'The reply was cut off before it finished. Ask for a smaller section, or split the design into two.', 'qwerty-soft-signal' )
		);
	}

	/**
	 * The ceiling a cut-off reply is retried at.
	 *
	 * Double, capped; the same number back means there is no room left and
	 * no retry to make.
	 *
	 * @param int $ceiling The max_tokens the reply stopped at.
	 * @return int
	 */
	public static function raised( int $ceiling ): int {
		return max( $ceiling, min( self::MAX_OUTPUT_TOKENS, $ceiling * 2 ) );
	}

	/**
	 * The request body, from what the caller asked for.
	 *
	 * Pure, apart from reading screenshot files, so the shape the API is sent
	 * — which block carries a cache breakpoint, what a legacy model is spared
	 * — can be checked without a key or a network.
	 *
	 * @param string               $system  System prompt.
	 * @param string               $prompt  User message.
	 * @param array<string, mixed> $schema  JSON Schema the reply must satisfy.
	 * @param array<string, mixed> $options model, effort, max_tokens, images.
	 * @return array<string, mixed>
	 */
	public static function request_body( string $system, string $prompt, array $schema, array $options = array() ): array {
		$model  = isset( $options['model'] ) ? (string) $options['model'] : self::DEFAULT_MODEL;
		$effort = isset( $options['effort'] ) ? (string) $options['effort'] : 'high';

		if ( ! array_key_exists( $model, self::models() ) ) {
			$model = self::DEFAULT_MODEL;
		}

		if ( ! array_key_exists( $effort, self::effort_levels() ) ) {
			$effort = 'high';
		}

		$max_tokens = isset( $options['max_tokens'] ) ? (int) $options['max_tokens'] : self::DEFAULT_MAX_TOKENS;
		$images     = isset( $options['images'] ) && is_array( $options['images'] ) ? array_map( 'strval', $options['images'] ) : array();

		$body = array(
			'model'         => $model,
			'max_tokens'    => $max_tokens > 0 ? $max_tokens : self::DEFAULT_MAX_TOKENS,
			'system'        => array(
				array(
					'type'          => 'text',
					'text'          => $system,

					/*
					 * The system prompt carries the whole design-token
					 * vocabulary and never changes between generations, so
					 * caching it makes every request after the first cheaper.
					 */
					'cache_control' => array( 'type' => 'ephemeral' ),
				),
			),
			'messages'      => array(
				array(
					'role'    => 'user',
					'content' => self::content( $prompt, $images ),
				),
			),

			/*
			 * effort tunes how hard the model works; format constrains the
			 * reply to our schema so the response never has to be scraped out
			 * of prose.
			 */
			'output_config' => array(
				'format' => array(
					'type'   => 'json_schema',
					'schema' => $schema,
				),
			),
		);

		/*
		 * Haiku 4.5 predates adaptive thinking, effort and server-side
		 * fallbacks; it answers every one of them with a 400. It gets the
		 * plain request and the structured-output constraint only.
		 */
		if ( ! self::is_legacy_model( $model ) ) {
			/*
			 * Adaptive thinking lets the model decide how much to reason. On
			 * Claude Opus 5 it is already the default; sending it explicitly
			 * documents the intent. The older budget_tokens form and the
			 * temperature / top_p / top_k sampling parameters are rejected
			 * with a 400 on this model — do not add them back.
			 */
			$body['thinking'] = array( 'type' => 'adaptive' );

			$body['output_config']['effort'] = $effort;

			/*
			 * Claude Opus 5 runs safety classifiers that can decline a
			 * request outright. "default" lets Anthropic re-run a declined
			 * request on a suitable fallback model server-side instead of
			 * handing us a dead end.
			 */
			$body['fallbacks'] = 'default';
		}

		return $body;
	}

	/**
	 * The request headers for one model.
	 *
	 * @param string $key   API key.
	 * @param string $model Model the body names.
	 * @return array<string, string>
	 */
	private static function headers( string $key, string $model ): array {
		$headers = array(
			'x-api-key'         => $key,
			'anthropic-version' => self::API_VERSION,
			'content-type'      => 'application/json',
		);

		if ( ! self::is_legacy_model( $model ) ) {
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
		}

		return $headers;
	}

	/**
	 * One round trip, as the decoded response or why there is none.
	 *
	 * @param array<string, mixed>  $body    Request body.
	 * @param array<string, string> $headers Request headers.
	 * @param int                   $timeout Seconds to wait.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function send( array $body, array $headers, int $timeout ) {
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'headers'     => $headers,
				'body'        => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'qwerty_soft_http',
				sprintf(
					/* translators: %s: transport error message. */
					__( 'Could not reach the Anthropic API: %s', 'qwerty-soft-signal' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$parsed = json_decode( $raw, true );

		if ( 200 !== $status ) {
			return self::http_error( $status, is_array( $parsed ) ? $parsed : array() );
		}

		if ( ! is_array( $parsed ) ) {
			return new WP_Error( 'qwerty_soft_bad_json', __( 'The API returned a response that could not be read.', 'qwerty-soft-signal' ) );
		}

		return $parsed;
	}

	/**
	 * The structured reply inside a finished response.
	 *
	 * @param array<string, mixed> $parsed Decoded response.
	 * @param string               $model  Model that was asked for.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function payload( array $parsed, string $model ) {
		$text = '';

		foreach ( (array) ( $parsed['content'] ?? array() ) as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}

		$payload = json_decode( $text, true );

		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'qwerty_soft_bad_payload', __( 'The reply did not match the expected format.', 'qwerty-soft-signal' ) );
		}

		$payload['_usage'] = isset( $parsed['usage'] ) && is_array( $parsed['usage'] ) ? $parsed['usage'] : array();
		$payload['_model'] = isset( $parsed['model'] ) ? (string) $parsed['model'] : $model;

		return $payload;
	}

	/**
	 * Formats the API accepts as an image block, by file extension.
	 *
	 * @var array<string, string>
	 */
	private const IMAGE_TYPES = array(
		'png'  => 'image/png',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
	);

	/**
	 * Bytes a single image may weigh before it is left out.
	 *
	 * The API's own ceiling is higher, but base64 inflates a file by a third
	 * and the whole request still has to fit. A screenshot past this is a
	 * screenshot that would push the section's markup out of the window.
	 */
	private const MAX_IMAGE_BYTES = 3500000;

	/**
	 * The user turn: any screenshots, then the text, in cacheable blocks.
	 *
	 * Images go first on purpose. The instructions that follow refer to what
	 * is in them ("the section outlined in the screenshot"), and a reference
	 * reads better after its subject than before it.
	 *
	 * The text is one or two blocks. The first is the part that repeats
	 * between requests — the brief: the facts, the section's markup, the
	 * screenshots before it — and it carries the second of the request's
	 * cache breakpoints, after the system prompt's. A correction is the one
	 * case with a second block: {@see self::RESENT_BRIEF} explains why the
	 * brief is lifted out in front of the complaint rather than left behind
	 * it. Two breakpoints of the four allowed; the rest are kept in hand.
	 *
	 * @param string             $prompt User message.
	 * @param array<int, string> $images Absolute paths to screenshots.
	 * @return array<int, array<string, mixed>> Content blocks.
	 */
	private static function content( string $prompt, array $images ): array {
		$blocks = self::image_blocks( $images );

		list( $stable, $varying ) = self::split( $prompt );

		$blocks[] = array(
			'type'          => 'text',
			'text'          => $stable,
			'cache_control' => array( 'type' => 'ephemeral' ),
		);

		if ( '' !== $varying ) {
			$blocks[] = array(
				'type' => 'text',
				'text' => $varying,
			);
		}

		return $blocks;
	}

	/**
	 * The part of a prompt that repeats, and the part that does not.
	 *
	 * A first request is all repeatable: it is the brief a correction will
	 * send again. A correction is the complaint, then the brief; it comes
	 * back as the brief, then the complaint, so the brief's block matches
	 * the one already cached byte for byte.
	 *
	 * @param string $prompt User message.
	 * @return array{0:string,1:string} The stable text, and the varying text or empty.
	 */
	public static function split( string $prompt ): array {
		$at = strpos( $prompt, self::RESENT_BRIEF );

		if ( false === $at ) {
			return array( $prompt, '' );
		}

		$brief     = substr( $prompt, $at + strlen( self::RESENT_BRIEF ) );
		$complaint = substr( $prompt, 0, $at );

		if ( '' === $brief ) {
			return array( $prompt, '' );
		}

		return array( $brief, $complaint );
	}

	/**
	 * Screenshots as image blocks, leaving out any that cannot be sent.
	 *
	 * @param array<int, string> $images Absolute paths to screenshots.
	 * @return array<int, array<string, mixed>>
	 */
	private static function image_blocks( array $images ): array {
		$blocks = array();

		foreach ( $images as $path ) {
			$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

			if ( ! isset( self::IMAGE_TYPES[ $extension ] ) || ! is_file( $path ) ) {
				continue;
			}

			$size = (int) filesize( $path );

			if ( $size <= 0 || $size > self::MAX_IMAGE_BYTES ) {
				continue;
			}

			$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- A local file inside the design this request is converting.

			if ( ! is_string( $bytes ) || '' === $bytes ) {
				continue;
			}

			$blocks[] = array(
				'type'   => 'image',
				'source' => array(
					'type'       => 'base64',
					'media_type' => self::IMAGE_TYPES[ $extension ],
					'data'       => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The API's wire format for an image, not obfuscation.
				),
			);
		}

		return $blocks;
	}

	/**
	 * Whether a model rejects the thinking, effort and fallback parameters.
	 *
	 * @param string $model Model ID.
	 * @return bool
	 */
	public static function is_legacy_model( string $model ): bool {
		return str_starts_with( $model, 'claude-haiku-4-5' );
	}

	/**
	 * Turn a non-200 response into a message an editor can act on.
	 *
	 * @param int                  $status HTTP status.
	 * @param array<string, mixed> $parsed Decoded error body.
	 * @return WP_Error
	 */
	private static function http_error( int $status, array $parsed ): WP_Error {
		$detail = '';

		if ( isset( $parsed['error']['message'] ) && is_string( $parsed['error']['message'] ) ) {
			$detail = $parsed['error']['message'];
		}

		$messages = array(
			400 => __( 'The request was rejected as invalid.', 'qwerty-soft-signal' ),
			401 => __( 'The API key was not accepted. Check it and save again.', 'qwerty-soft-signal' ),
			403 => __( 'This API key does not have access to the selected model.', 'qwerty-soft-signal' ),
			404 => __( 'The selected model does not exist. Pick another one in the settings.', 'qwerty-soft-signal' ),
			413 => __( 'The design you pasted is too large. Send one section at a time.', 'qwerty-soft-signal' ),
			429 => __( 'Rate limit reached. Wait a minute and try again.', 'qwerty-soft-signal' ),
			500 => __( 'Anthropic had a server error. Try again shortly.', 'qwerty-soft-signal' ),
			529 => __( 'Anthropic is overloaded right now. Try again shortly.', 'qwerty-soft-signal' ),
		);

		$message = $messages[ $status ] ?? sprintf(
			/* translators: %d: HTTP status code. */
			__( 'The API returned an unexpected status (%d).', 'qwerty-soft-signal' ),
			$status
		);

		if ( '' !== $detail ) {
			$message .= ' ' . $detail;
		}

		return new WP_Error( 'qwerty_soft_api_' . $status, $message );
	}
}

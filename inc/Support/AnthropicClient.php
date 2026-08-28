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
	 * Send one non-streaming Messages request and return the parsed payload.
	 *
	 * Streaming is not used: a WordPress admin request is a single blocking
	 * round trip either way, and Server-Sent Events cannot be surfaced through
	 * the REST response this screen consumes. The generation is bounded by
	 * max_tokens instead, well below the point where streaming becomes
	 * necessary to dodge HTTP timeouts.
	 *
	 * @param string               $system   System prompt.
	 * @param string               $prompt   User message.
	 * @param array<string, mixed> $schema   JSON Schema the reply must satisfy.
	 * @param array<string, mixed> $options  Optional overrides: model, effort, max_tokens.
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

		$model  = isset( $options['model'] ) ? (string) $options['model'] : self::DEFAULT_MODEL;
		$effort = isset( $options['effort'] ) ? (string) $options['effort'] : 'high';

		if ( ! array_key_exists( $model, self::models() ) ) {
			$model = self::DEFAULT_MODEL;
		}

		if ( ! array_key_exists( $effort, self::effort_levels() ) ) {
			$effort = 'high';
		}

		/*
		 * Haiku 4.5 predates adaptive thinking, effort and server-side
		 * fallbacks; it answers every one of them with a 400. It gets the
		 * plain request and the structured-output constraint only.
		 */
		$legacy = self::is_legacy_model( $model );

		$body = array(
			'model'         => $model,
			'max_tokens'    => isset( $options['max_tokens'] ) ? (int) $options['max_tokens'] : 16000,
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
					'content' => self::content( $prompt, isset( $options['images'] ) && is_array( $options['images'] ) ? array_map( 'strval', $options['images'] ) : array() ),
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

		$headers = array(
			'x-api-key'         => $key,
			'anthropic-version' => self::API_VERSION,
			'content-type'      => 'application/json',
		);

		if ( ! $legacy ) {
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

			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
		}

		/*
		 * The timeout is generous because a high-effort request genuinely takes
		 * a while. WordPress defaults to 5 seconds, which would abort
		 * essentially every call.
		 */
		$timeout = isset( $options['timeout'] ) ? (int) $options['timeout'] : 180;

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

		/*
		 * A declined request is a successful HTTP 200 carrying
		 * stop_reason "refusal", so this check has to happen before anything
		 * reads content — indexing content[0] would fatal on an empty array.
		 */
		if ( 'refusal' === ( $parsed['stop_reason'] ?? '' ) ) {
			return new WP_Error(
				'qwerty_soft_refusal',
				__( 'Anthropic declined this request. Rephrase the brief and try again — describe the section rather than pasting code from an unrelated system.', 'qwerty-soft-signal' )
			);
		}

		if ( 'max_tokens' === ( $parsed['stop_reason'] ?? '' ) ) {
			return new WP_Error(
				'qwerty_soft_truncated',
				__( 'The reply was cut off before it finished. Ask for a smaller section, or split the design into two.', 'qwerty-soft-signal' )
			);
		}

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
	 * The user turn: the text, preceded by any screenshots.
	 *
	 * Images go first on purpose. The instructions that follow refer to what
	 * is in them ("the section outlined in the screenshot"), and a reference
	 * reads better after its subject than before it.
	 *
	 * @param string             $prompt User message.
	 * @param array<int, string> $images Absolute paths to screenshots.
	 * @return string|array<int, array<string, mixed>> Plain text, or content blocks.
	 */
	private static function content( string $prompt, array $images ) {
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

		if ( array() === $blocks ) {
			return $prompt;
		}

		$blocks[] = array(
			'type' => 'text',
			'text' => $prompt,
		);

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

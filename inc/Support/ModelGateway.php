<?php
/**
 * One way in to the model, whichever route this machine can actually take.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Support;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Chooses between the Anthropic API and the local Claude Code CLI.
 *
 * The two are not alternatives in the usual sense — they suit different
 * machines, and most sites only have one of them:
 *
 * - On a developer's own machine the `claude` binary is installed and signed
 *   in to a subscription. Converting a design through it costs nothing on top
 *   of the plan, which matters when a design is rebuilt a dozen times while it
 *   is being got right.
 * - On shared hosting there is no binary and PHP is usually forbidden from
 *   starting processes at all, so the API — one outbound HTTPS request — is
 *   the only thing that can work.
 *
 * "Automatic" therefore means "the free one if this machine has it, the API
 * otherwise", and it is the default. Everything above this class calls
 * {@see self::generate()} and never learns which route answered, apart from
 * the `_transport` key on the reply, which exists so the interface can be
 * honest about what a conversion cost.
 */
final class ModelGateway {

	/**
	 * Option holding the administrator's transport preference.
	 */
	public const OPTION_TRANSPORT = 'wow_signal_ai_transport';

	/**
	 * The routes an administrator may pick between.
	 *
	 * @return array<string, string> Value => label.
	 */
	public static function transports(): array {
		return array(
			'auto' => __( 'Automatic — the command line when this machine has it, the API otherwise', 'wow-signal' ),
			'cli'  => __( 'Claude Code on this machine — uses the signed-in subscription, no API charges', 'wow-signal' ),
			'api'  => __( 'Anthropic API — works on any host, billed per conversion', 'wow-signal' ),
		);
	}

	/**
	 * The stored preference, defaulting to automatic.
	 *
	 * @return string
	 */
	public static function preference(): string {
		$stored = (string) get_option( self::OPTION_TRANSPORT, 'auto' );

		return array_key_exists( $stored, self::transports() ) ? $stored : 'auto';
	}

	/**
	 * The route a conversion started now would take.
	 *
	 * An explicit choice is honoured even when it is not usable: a site that
	 * asked for the API and has no key should be told the key is missing, not
	 * quietly billed through some other route.
	 *
	 * @return string 'cli', 'api', or '' when neither can run.
	 */
	public static function resolve(): string {
		$preference = self::preference();

		if ( 'cli' === $preference ) {
			return 'cli';
		}

		if ( 'api' === $preference ) {
			return 'api';
		}

		if ( ClaudeCli::available() ) {
			return 'cli';
		}

		return '' !== AnthropicClient::api_key() ? 'api' : '';
	}

	/**
	 * Whether a conversion can be run at all right now.
	 *
	 * @return bool
	 */
	public static function ready(): bool {
		$route = self::resolve();

		if ( 'cli' === $route ) {
			return ClaudeCli::available();
		}

		if ( 'api' === $route ) {
			return '' !== AnthropicClient::api_key();
		}

		return false;
	}

	/**
	 * Everything the settings screen and Site Health need to say about this.
	 *
	 * @param bool $fresh Re-probe the CLI rather than trusting the cache.
	 * @return array<string, mixed>
	 */
	public static function status( bool $fresh = false ): array {
		$key   = AnthropicClient::api_key();
		$route = self::resolve();

		/*
		 * Probing means running the binary, which on a broken install is
		 * twenty seconds of nothing. A site that has explicitly chosen the API
		 * is not going to use the answer, so it is not asked for — the row is
		 * still reported, from what can be known without spawning anything.
		 */
		$cli = 'api' === self::preference()
			? array(
				'ready'   => false,
				'binary'  => ClaudeCli::binary(),
				'version' => '',
				'reason'  => __( 'Not checked — this site is set to use the API.', 'wow-signal' ),
			)
			: ClaudeCli::status( $fresh );

		$reason = '';

		if ( 'cli' === $route && ! $cli['ready'] ) {
			$reason = $cli['reason'];
		} elseif ( 'api' === $route && '' === $key ) {
			$reason = __( 'No Anthropic API key is saved yet.', 'wow-signal' );
		} elseif ( '' === $route ) {
			$reason = __( 'Neither route is set up: Claude Code is not installed here, and no API key has been saved.', 'wow-signal' );
		}

		return array(
			'preference' => self::preference(),
			'route'      => $route,
			'ready'      => self::ready(),
			'reason'     => $reason,
			'billed'     => 'api' === $route,
			'cli'        => $cli,
			'api'        => array(
				'ready'     => '' !== $key,
				'constant'  => AnthropicClient::key_is_constant(),
				'ends_with' => '' !== $key ? substr( $key, -4 ) : '',
			),
		);
	}

	/**
	 * Generate one structured reply, whichever way this machine can.
	 *
	 * @param string               $system  System prompt.
	 * @param string               $prompt  User message.
	 * @param array<string, mixed> $schema  JSON Schema the reply must satisfy.
	 * @param array<string, mixed> $options model, effort, timeout, images, dirs, max_tokens.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function generate( string $system, string $prompt, array $schema, array $options = array() ) {
		/**
		 * Answer a generation without reaching a model.
		 *
		 * Returning anything other than null short-circuits the call: the
		 * value is handed back to the caller as though a model had produced
		 * it. Intended for a site that routes generations through its own
		 * gateway, and for the tests, which have to be able to produce a
		 * deliberately bad reply — the behaviour worth testing here is what
		 * happens to one, and a real model cannot be asked for it.
		 *
		 * @since 1.4.0
		 *
		 * @param array<string, mixed>|\WP_Error|null $reply   Reply to use, or null to call a model.
		 * @param string                              $system  System prompt.
		 * @param string                              $prompt  User message.
		 * @param array<string, mixed>                $schema  Reply schema.
		 * @param array<string, mixed>                $options Model, effort, images and the rest.
		 */
		$given = apply_filters( 'wow_signal/model_reply', null, $system, $prompt, $schema, $options );

		if ( null !== $given ) {
			return $given;
		}

		$route = self::resolve();

		if ( 'cli' === $route ) {
			return ClaudeCli::generate( $system, $prompt, $schema, $options );
		}

		if ( 'api' === $route ) {
			$reply = AnthropicClient::generate( $system, $prompt, $schema, $options );

			if ( is_array( $reply ) ) {
				$reply['_transport'] = 'api';
			}

			return $reply;
		}

		return new WP_Error(
			'wow_signal_no_route',
			__( 'There is no way to reach a model from here yet. Install Claude Code on this machine, or add an Anthropic API key under Appearance → Design import.', 'wow-signal' )
		);
	}

	/**
	 * Whether work done through a route is charged for.
	 *
	 * A conversion run through the local CLI on a subscription bills nothing,
	 * so its tokens are counted and its money is not. Recording the notional
	 * figure as though it had been charged would make the screen claim a cost
	 * that never appeared on anybody's invoice.
	 *
	 * @param string $transport Transport that answered, as reported on a reply.
	 * @return bool
	 */
	public static function is_billable( string $transport ): bool {
		return 'cli' !== $transport;
	}
}

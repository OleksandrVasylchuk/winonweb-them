<?php
/**
 * Site Health checks for the hosting environment.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Modules;

use Qwerty\Soft\Contracts\Module;
use Qwerty\Soft\Support\AnthropicClient;
use Qwerty\Soft\Support\ClaudeCli;
use Qwerty\Soft\Support\DesignFonts;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the site owner, before anything fails, whether the host can run the
 * parts of the theme that depend on it.
 *
 * The front end needs nothing beyond WordPress itself. The design importer
 * needs ZipArchive, the DOM extension, a writable uploads folder and outbound
 * HTTPS — to Anthropic for conversions and to Google Fonts for a design's
 * typefaces. Each of those is reported under Tools → Site Health with a plain
 * sentence about what will and will not work, so a missing extension shows up
 * as a status line rather than as a failed upload half-way through a job.
 */
final class SiteHealth implements Module {

	/**
	 * Hosts the importer reaches out to, with what each one is for.
	 *
	 * @return array<string, string>
	 */
	private function outbound(): array {
		return array(
			'https://api.anthropic.com/'    => __( 'design conversions through the Anthropic API', 'qwerty-soft-signal' ),
			'https://fonts.googleapis.com/' => __( 'downloading a design’s Google Fonts', 'qwerty-soft-signal' ),
		);
	}

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'tests' ) );
		add_filter( 'debug_information', array( $this, 'debug_information' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Add the theme's tests to Site Health.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $tests Registered tests.
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function tests( array $tests ): array {
		$tests['direct']['qwerty_soft_extensions'] = array(
			'label' => __( 'Qwerty Soft — Signal: PHP extensions for the design importer', 'qwerty-soft-signal' ),
			'test'  => array( $this, 'test_extensions' ),
		);

		$tests['direct']['qwerty_soft_uploads'] = array(
			'label' => __( 'Qwerty Soft — Signal: uploads folder is writable', 'qwerty-soft-signal' ),
			'test'  => array( $this, 'test_uploads' ),
		);

		$tests['async']['qwerty_soft_outbound'] = array(
			'label'             => __( 'Qwerty Soft — Signal: outbound HTTPS for the design importer', 'qwerty-soft-signal' ),
			'test'              => rest_url( 'qwerty-soft-signal/v1/health/outbound' ),
			'has_rest'          => true,
			'async_direct_test' => array( $this, 'test_outbound' ),
		);

		return $tests;
	}

	/**
	 * ZipArchive and DOM — what unpacking and reading a design need.
	 *
	 * @return array<string, mixed>
	 */
	public function test_extensions(): array {
		$missing = array();

		if ( ! class_exists( 'ZipArchive' ) ) {
			$missing[] = 'zip';
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			$missing[] = 'dom';
		}

		if ( array() === $missing ) {
			return $this->result(
				'qwerty_soft_extensions',
				'good',
				__( 'The PHP extensions the design importer needs are installed', 'qwerty-soft-signal' ),
				__( 'ZipArchive and DOM are available, so design archives can be unpacked and read.', 'qwerty-soft-signal' )
			);
		}

		return $this->result(
			'qwerty_soft_extensions',
			'recommended',
			__( 'A PHP extension the design importer needs is missing', 'qwerty-soft-signal' ),
			sprintf(
				/* translators: %s: comma-separated list of PHP extension names. */
				__( 'The theme itself runs fine, but Appearance → Design import cannot work without: %s. Ask your host to enable the extension.', 'qwerty-soft-signal' ),
				implode( ', ', $missing )
			)
		);
	}

	/**
	 * Whether images and fonts from a design can be written.
	 *
	 * @return array<string, mixed>
	 */
	public function test_uploads(): array {
		$uploads = wp_upload_dir();

		if ( empty( $uploads['error'] ) && wp_is_writable( (string) $uploads['basedir'] ) ) {
			return $this->result(
				'qwerty_soft_uploads',
				'good',
				__( 'The uploads folder is writable', 'qwerty-soft-signal' ),
				__( 'Design archives, their images and their fonts can be stored.', 'qwerty-soft-signal' )
			);
		}

		return $this->result(
			'qwerty_soft_uploads',
			'critical',
			__( 'The uploads folder is not writable', 'qwerty-soft-signal' ),
			__( 'Media uploads, the logo step of the theme setup and the design importer all need to write to wp-content/uploads. Check the folder’s permissions with your host.', 'qwerty-soft-signal' )
		);
	}

	/**
	 * Whether the server can reach the services the importer talks to.
	 *
	 * @return array<string, mixed>
	 */
	public function test_outbound(): array {
		$blocked = array();

		foreach ( $this->outbound() as $url => $purpose ) {
			$response = wp_safe_remote_head(
				$url,
				array(
					'timeout'     => 5,
					'redirection' => 0,
				)
			);

			// Any HTTP answer at all proves the route is open; only a transport error matters.
			if ( is_wp_error( $response ) ) {
				$blocked[] = sprintf( '%s — %s', wp_parse_url( $url, PHP_URL_HOST ), $purpose );
			}
		}

		if ( array() === $blocked ) {
			return $this->result(
				'qwerty_soft_outbound',
				'good',
				__( 'The server can reach the services the design importer uses', 'qwerty-soft-signal' ),
				__( 'api.anthropic.com and fonts.googleapis.com both answered.', 'qwerty-soft-signal' )
			);
		}

		return $this->result(
			'qwerty_soft_outbound',
			'recommended',
			__( 'The server cannot reach a service the design importer uses', 'qwerty-soft-signal' ),
			sprintf(
				/* translators: %s: list of blocked hosts with what each is used for. */
				__( 'Everything else in the theme is unaffected. Blocked: %s. Usually a firewall rule on the host; the support desk can open outbound HTTPS to these hosts.', 'qwerty-soft-signal' ),
				implode( '; ', $blocked )
			)
		);
	}

	/**
	 * REST endpoint backing the asynchronous outbound test.
	 *
	 * @return void
	 */
	public function routes(): void {
		register_rest_route(
			'qwerty-soft-signal/v1',
			'/health/outbound',
			array(
				'methods'             => 'GET',
				'callback'            => function (): WP_REST_Response {
					return rest_ensure_response( $this->test_outbound() );
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'view_site_health_checks' );
				},
			)
		);
	}

	/**
	 * A section in Site Health → Info with the theme's own state.
	 *
	 * @param array<string, array<string, mixed>> $info Debug information sections.
	 * @return array<string, array<string, mixed>>
	 */
	public function debug_information( array $info ): array {
		$fonts = class_exists( DesignFonts::class ) ? DesignFonts::count() : 0;

		$info['qwerty-soft-signal'] = array(
			'label'  => __( 'Qwerty Soft — Signal', 'qwerty-soft-signal' ),
			'fields' => array(
				'version' => array(
					'label' => __( 'Theme version', 'qwerty-soft-signal' ),
					'value' => QSOFT_VERSION,
				),
				'php'     => array(
					'label' => __( 'PHP version', 'qwerty-soft-signal' ),
					'value' => PHP_VERSION,
				),
				'zip'     => array(
					'label' => __( 'ZipArchive', 'qwerty-soft-signal' ),
					'value' => class_exists( 'ZipArchive' ) ? __( 'Available', 'qwerty-soft-signal' ) : __( 'Missing', 'qwerty-soft-signal' ),
				),
				'api_key' => array(
					'label'   => __( 'Anthropic API key', 'qwerty-soft-signal' ),
					'value'   => '' !== AnthropicClient::api_key() ? __( 'Configured', 'qwerty-soft-signal' ) : __( 'Not set', 'qwerty-soft-signal' ),
					'private' => true,
				),

				/*
				 * The other route to a model. Reported without running the
				 * binary: Site Health loads on a page request like any other,
				 * and probing a broken install would hold it open. What is
				 * shown here is what can be known for free — whether PHP may
				 * start a process at all, and whether the command is findable.
				 */
				'cli'     => array(
					'label' => __( 'Claude Code command', 'qwerty-soft-signal' ),
					'value' => self::cli_summary(),
				),
				'fonts'   => array(
					'label' => __( 'Font families imported from designs', 'qwerty-soft-signal' ),
					'value' => (string) $fonts,
				),
				'locale'  => array(
					'label' => __( 'Site language', 'qwerty-soft-signal' ),
					'value' => get_locale(),
				),
			),
		);

		return $info;
	}

	/**
	 * Whether the local Claude Code route could work on this server.
	 *
	 * @return string
	 */
	private static function cli_summary(): string {
		if ( ! ClaudeCli::can_spawn() ) {
			return __( 'Unavailable — this server does not let PHP start other programs', 'qwerty-soft-signal' );
		}

		$binary = ClaudeCli::binary();

		return '' !== $binary
			/* translators: %s: path to the claude binary. */
			? sprintf( __( 'Found at %s', 'qwerty-soft-signal' ), $binary )
			: __( 'Not installed on this server', 'qwerty-soft-signal' );
	}

	/**
	 * Shape one Site Health result the way core expects it.
	 *
	 * @param string $test        Test slug.
	 * @param string $status      good|recommended|critical.
	 * @param string $label       Headline.
	 * @param string $description One-paragraph explanation.
	 * @return array<string, mixed>
	 */
	private function result( string $test, string $status, string $label, string $description ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Theme', 'qwerty-soft-signal' ),
				'color' => 'good' === $status ? 'blue' : 'orange',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '',
			'test'        => $test,
		);
	}
}

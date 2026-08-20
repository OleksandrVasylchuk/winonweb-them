<?php
/**
 * Site Health checks for the hosting environment.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Modules;

use Wow\Signal\Contracts\Module;
use Wow\Signal\Support\AnthropicClient;
use Wow\Signal\Support\DesignFonts;
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
			'https://api.anthropic.com/'    => __( 'design conversions through the Anthropic API', 'wow-signal' ),
			'https://fonts.googleapis.com/' => __( 'downloading a design’s Google Fonts', 'wow-signal' ),
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
		$tests['direct']['wow_signal_extensions'] = array(
			'label' => __( 'WOW — Signal: PHP extensions for the design importer', 'wow-signal' ),
			'test'  => array( $this, 'test_extensions' ),
		);

		$tests['direct']['wow_signal_uploads'] = array(
			'label' => __( 'WOW — Signal: uploads folder is writable', 'wow-signal' ),
			'test'  => array( $this, 'test_uploads' ),
		);

		$tests['async']['wow_signal_outbound'] = array(
			'label'             => __( 'WOW — Signal: outbound HTTPS for the design importer', 'wow-signal' ),
			'test'              => rest_url( 'wow-signal/v1/health/outbound' ),
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
				'wow_signal_extensions',
				'good',
				__( 'The PHP extensions the design importer needs are installed', 'wow-signal' ),
				__( 'ZipArchive and DOM are available, so design archives can be unpacked and read.', 'wow-signal' )
			);
		}

		return $this->result(
			'wow_signal_extensions',
			'recommended',
			__( 'A PHP extension the design importer needs is missing', 'wow-signal' ),
			sprintf(
				/* translators: %s: comma-separated list of PHP extension names. */
				__( 'The theme itself runs fine, but Appearance → Design import cannot work without: %s. Ask your host to enable the extension.', 'wow-signal' ),
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
				'wow_signal_uploads',
				'good',
				__( 'The uploads folder is writable', 'wow-signal' ),
				__( 'Design archives, their images and their fonts can be stored.', 'wow-signal' )
			);
		}

		return $this->result(
			'wow_signal_uploads',
			'critical',
			__( 'The uploads folder is not writable', 'wow-signal' ),
			__( 'Media uploads, the logo step of the theme setup and the design importer all need to write to wp-content/uploads. Check the folder’s permissions with your host.', 'wow-signal' )
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
				'wow_signal_outbound',
				'good',
				__( 'The server can reach the services the design importer uses', 'wow-signal' ),
				__( 'api.anthropic.com and fonts.googleapis.com both answered.', 'wow-signal' )
			);
		}

		return $this->result(
			'wow_signal_outbound',
			'recommended',
			__( 'The server cannot reach a service the design importer uses', 'wow-signal' ),
			sprintf(
				/* translators: %s: list of blocked hosts with what each is used for. */
				__( 'Everything else in the theme is unaffected. Blocked: %s. Usually a firewall rule on the host; the support desk can open outbound HTTPS to these hosts.', 'wow-signal' ),
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
			'wow-signal/v1',
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

		$info['wow-signal'] = array(
			'label'  => __( 'WOW — Signal', 'wow-signal' ),
			'fields' => array(
				'version' => array(
					'label' => __( 'Theme version', 'wow-signal' ),
					'value' => WOW_SIGNAL_VERSION,
				),
				'php'     => array(
					'label' => __( 'PHP version', 'wow-signal' ),
					'value' => PHP_VERSION,
				),
				'zip'     => array(
					'label' => __( 'ZipArchive', 'wow-signal' ),
					'value' => class_exists( 'ZipArchive' ) ? __( 'Available', 'wow-signal' ) : __( 'Missing', 'wow-signal' ),
				),
				'api_key' => array(
					'label'   => __( 'Anthropic API key', 'wow-signal' ),
					'value'   => '' !== AnthropicClient::api_key() ? __( 'Configured', 'wow-signal' ) : __( 'Not set', 'wow-signal' ),
					'private' => true,
				),
				'fonts'   => array(
					'label' => __( 'Font families imported from designs', 'wow-signal' ),
					'value' => (string) $fonts,
				),
				'locale'  => array(
					'label' => __( 'Site language', 'wow-signal' ),
					'value' => get_locale(),
				),
			),
		);

		return $info;
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
				'label' => __( 'Theme', 'wow-signal' ),
				'color' => 'good' === $status ? 'blue' : 'orange',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '',
			'test'        => $test,
		);
	}
}

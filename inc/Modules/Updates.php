<?php
/**
 * Self-hosted theme updates.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Modules;

use Qwerty\Soft\Contracts\Module;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a site that bought the theme see new versions under Appearance → Themes.
 *
 * The theme is not in the WordPress.org directory, so core has nobody to ask.
 * This module asks a JSON manifest on the studio's own server instead, once
 * every twelve hours, and hands the answer to the same transient core reads
 * for every other theme. From there the standard update screen, the one-click
 * install and WP-CLI's `theme update` all work as they always do.
 *
 * What leaves the site: the installed version and a one-way hash of the site
 * URL, so the server can rate-limit and count installs. Nothing else, and the
 * whole check can be switched off with the `qwerty_soft/check_updates` filter.
 *
 * What is trusted: nothing in the manifest until it has been checked. Every
 * URL must be HTTPS and on an allow-listed host, every version must parse,
 * and when the manifest carries a SHA-256 the package is downloaded by this
 * module and hashed before core is allowed to unpack it.
 *
 * Manifest shape:
 *
 *     {
 *       "version":      "1.3.0",
 *       "download_url": "https://qwerty-soft.com/downloads/qwerty-soft-signal-1.3.0.zip",
 *       "requires":     "6.7",
 *       "requires_php": "8.1",
 *       "tested":       "7.0",
 *       "sha256":       "…64 hex characters…",
 *       "details_url":  "https://qwerty-soft.com/themes/signal/changelog/"
 *     }
 *
 * `npm run build:zip` writes exactly this file next to the archive.
 */
final class Updates implements Module {

	/**
	 * Where the manifest lives unless a filter moves it.
	 */
	private const MANIFEST_URL = 'https://qwerty-soft.com/themes/signal/update.json';

	/**
	 * Transient holding the last manifest fetch.
	 */
	public const TRANSIENT = 'qwerty_soft_update_check';

	/**
	 * Transient holding a one-off reason why an available update is not offered.
	 */
	private const NOTICE_TRANSIENT = 'qwerty_soft_update_blocked';

	/**
	 * How long a fetched manifest is trusted, in seconds.
	 */
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Seconds to wait for the manifest server.
	 */
	private const TIMEOUT = 10;

	/**
	 * Hook the module.
	 *
	 * Nothing here runs on the front end: the transient is only ever written
	 * from wp-admin, cron and WP-CLI, which is where core's own checks run.
	 *
	 * @return void
	 */
	public function register(): void {
		/**
		 * Filter whether the theme checks for updates at all.
		 *
		 * Return false to make the theme completely silent: no request is
		 * made and no update is ever offered. A site that deploys from
		 * version control will want this.
		 *
		 * @since 1.3.0
		 *
		 * @param bool $check Whether to check. Default true.
		 */
		if ( ! (bool) apply_filters( 'qwerty_soft/check_updates', true ) ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_themes', array( $this, 'inject' ) );
		add_action( 'delete_site_transient_update_themes', array( $this, 'forget' ) );
		add_filter( 'upgrader_pre_download', array( $this, 'verify_package' ), 10, 4 );
		add_action( 'admin_notices', array( $this, 'blocked_notice' ) );
	}

	/**
	 * The manifest URL, after filtering.
	 *
	 * @return string
	 */
	public function manifest_url(): string {
		/**
		 * Filter where the update manifest is fetched from.
		 *
		 * A reseller or an agency hosting its own builds points this at
		 * its own server. The download URL inside the manifest must then be
		 * on the same host, or be allowed through `qwerty_soft/update_download_hosts`.
		 *
		 * @since 1.3.0
		 *
		 * @param string $url HTTPS URL of the JSON manifest.
		 */
		return (string) apply_filters( 'qwerty_soft/update_manifest_url', self::MANIFEST_URL );
	}

	/**
	 * Hosts a package may be downloaded from.
	 *
	 * @return array<int, string> Lower-case host names.
	 */
	private function allowed_hosts(): array {
		$manifest_host = strtolower( (string) wp_parse_url( $this->manifest_url(), PHP_URL_HOST ) );
		$hosts         = '' !== $manifest_host ? array( $manifest_host ) : array();

		/**
		 * Filter the hosts an update package may be downloaded from.
		 *
		 * By default only the host serving the manifest is trusted, so a
		 * manifest that has been tampered with cannot point the site at a
		 * package somewhere else. Add a CDN host here if downloads are served
		 * from one.
		 *
		 * @since 1.3.0
		 *
		 * @param array<int, string> $hosts Host names, without scheme or port.
		 */
		$hosts = (array) apply_filters( 'qwerty_soft/update_download_hosts', $hosts );

		$clean = array();
		foreach ( $hosts as $host ) {
			if ( is_string( $host ) && '' !== $host ) {
				$clean[] = strtolower( $host );
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Add the theme to core's update transient when a newer version exists.
	 *
	 * @param mixed $transient The `update_themes` site transient being set.
	 * @return mixed
	 */
	public function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$manifest = $this->manifest();
		if ( null === $manifest ) {
			return $transient;
		}

		$slug = get_template();
		$item = array(
			'theme'        => $slug,
			'new_version'  => $manifest['version'],
			'url'          => $manifest['details_url'],
			'package'      => $manifest['download_url'],
			'requires'     => $manifest['requires'],
			'requires_php' => $manifest['requires_php'],
		);

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		$newer   = version_compare( $manifest['version'], QSOFT_VERSION, '>' );
		$blocked = $newer ? $this->unmet_requirement( $manifest ) : '';

		if ( $newer && '' === $blocked ) {
			$transient->response[ $slug ] = $item;
			unset( $transient->no_update[ $slug ] );
			return $transient;
		}

		$transient->no_update[ $slug ] = $item;
		unset( $transient->response[ $slug ] );

		if ( '' !== $blocked ) {
			set_transient( self::NOTICE_TRANSIENT, $blocked, self::CACHE_TTL );
		}

		return $transient;
	}

	/**
	 * Why a newer version cannot be installed here, or an empty string.
	 *
	 * @param array<string, string> $manifest Validated manifest.
	 * @return string Translated sentence, empty when requirements are met.
	 */
	private function unmet_requirement( array $manifest ): string {
		if ( '' !== $manifest['requires_php'] && version_compare( PHP_VERSION, $manifest['requires_php'], '<' ) ) {
			return sprintf(
				/* translators: 1: new theme version, 2: required PHP version, 3: current PHP version. */
				__( 'Qwerty Soft — Signal %1$s is available but needs PHP %2$s; this server runs PHP %3$s. Ask your host to upgrade PHP, then check for updates again.', 'qwerty-soft-signal' ),
				$manifest['version'],
				$manifest['requires_php'],
				PHP_VERSION
			);
		}

		$wp = (string) get_bloginfo( 'version' );
		if ( '' !== $manifest['requires'] && version_compare( $wp, $manifest['requires'], '<' ) ) {
			return sprintf(
				/* translators: 1: new theme version, 2: required WordPress version, 3: current WordPress version. */
				__( 'Qwerty Soft — Signal %1$s is available but needs WordPress %2$s; this site runs WordPress %3$s. Update WordPress first.', 'qwerty-soft-signal' ),
				$manifest['version'],
				$manifest['requires'],
				$wp
			);
		}

		return '';
	}

	/**
	 * Tell an administrator, once, why an available update is being held back.
	 *
	 * @return void
	 */
	public function blocked_notice(): void {
		if ( ! current_user_can( 'update_themes' ) ) {
			return;
		}

		$reason = get_transient( self::NOTICE_TRANSIENT );
		if ( ! is_string( $reason ) || '' === $reason ) {
			return;
		}

		delete_transient( self::NOTICE_TRANSIENT );

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html( $reason )
		);
	}

	/**
	 * Drop the cached manifest whenever core drops its own.
	 *
	 * "Check again" on Dashboard → Updates deletes the `update_themes`
	 * transient; forgetting ours at the same moment is what makes that
	 * button reach the manifest server instead of the cache.
	 *
	 * @return void
	 */
	public function forget(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * The validated manifest, from cache or from the server.
	 *
	 * A failed fetch is cached too, for the same twelve hours: a server that
	 * is down should not be asked again on every admin page load.
	 *
	 * @return array<string, string>|null
	 */
	public function manifest(): ?array {
		$cached = get_transient( self::TRANSIENT );

		if ( is_array( $cached ) && array_key_exists( 'manifest', $cached ) ) {
			return is_array( $cached['manifest'] ) ? $cached['manifest'] : null;
		}

		$manifest = $this->fetch();

		set_transient(
			self::TRANSIENT,
			array(
				'checked'  => time(),
				'manifest' => $manifest,
			),
			self::CACHE_TTL
		);

		return $manifest;
	}

	/**
	 * Fetch and validate the manifest. Never throws; returns null on any doubt.
	 *
	 * @return array<string, string>|null
	 */
	private function fetch(): ?array {
		$url = $this->manifest_url();

		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return null;
		}

		/*
		 * The installed version and a one-way hash of the site URL let the
		 * server rate-limit and count installs. The hash cannot be reversed
		 * into the URL; nothing else about the site is sent.
		 */
		$url = add_query_arg(
			array(
				'version' => rawurlencode( QSOFT_VERSION ),
				'site'    => substr( hash( 'sha256', (string) site_url() ), 0, 16 ),
			),
			$url
		);

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => 'Qwerty Soft-Signal/' . QSOFT_VERSION . '; ' . home_url( '/' ),
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body || strlen( $body ) > 64 * KB_IN_BYTES ) {
			return null;
		}

		$data = json_decode( $body, true );

		return is_array( $data ) ? $this->validate( $data ) : null;
	}

	/**
	 * Check every field of a decoded manifest.
	 *
	 * @param array<string, mixed> $data Decoded JSON.
	 * @return array<string, string>|null Clean string fields, or null if anything is off.
	 */
	public function validate( array $data ): ?array {
		$version = isset( $data['version'] ) && is_string( $data['version'] ) ? trim( $data['version'] ) : '';
		if ( 1 !== preg_match( '/^\d+(\.\d+){1,3}(-[A-Za-z0-9.]+)?$/', $version ) ) {
			return null;
		}

		$download = $this->https_url( $data['download_url'] ?? null );
		if ( '' === $download ) {
			return null;
		}

		$host = strtolower( (string) wp_parse_url( $download, PHP_URL_HOST ) );
		if ( '' === $host || ! in_array( $host, $this->allowed_hosts(), true ) ) {
			return null;
		}

		// Packages are only ever archives; anything else is a mistake or worse.
		if ( 'zip' !== strtolower( pathinfo( (string) wp_parse_url( $download, PHP_URL_PATH ), PATHINFO_EXTENSION ) ) ) {
			return null;
		}

		$details = $this->https_url( $data['details_url'] ?? null );

		$sha256 = isset( $data['sha256'] ) && is_string( $data['sha256'] ) ? strtolower( trim( $data['sha256'] ) ) : '';
		if ( '' !== $sha256 && 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
			return null;
		}

		return array(
			'version'      => $version,
			'download_url' => $download,
			'details_url'  => $details,
			'requires'     => $this->version_field( $data['requires'] ?? null ),
			'requires_php' => $this->version_field( $data['requires_php'] ?? null ),
			'tested'       => $this->version_field( $data['tested'] ?? null ),
			'sha256'       => $sha256,
		);
	}

	/**
	 * A value as an HTTPS URL, or an empty string.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function https_url( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$url = esc_url_raw( trim( $value ), array( 'https' ) );
		if ( '' === $url || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * A value as a dotted version number, or an empty string.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function version_field( $value ): string {
		if ( is_int( $value ) || is_float( $value ) ) {
			$value = (string) $value;
		}

		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );

		return 1 === preg_match( '/^\d+(\.\d+){0,3}$/', $value ) ? $value : '';
	}

	/**
	 * Download the package ourselves and refuse it if the hash is wrong.
	 *
	 * Core asks this filter before it downloads anything. Returning a local
	 * file path makes core unpack that file instead; returning a WP_Error
	 * stops the upgrade with the message shown to the administrator. Any
	 * package that is not ours — another theme, a plugin, core itself — is
	 * passed through untouched.
	 *
	 * @param mixed                $reply      False, or a short-circuit value from another filter.
	 * @param string               $package    Package URL.
	 * @param \WP_Upgrader         $upgrader   The upgrader instance.
	 * @param array<string, mixed> $hook_extra Extra arguments passed to the upgrader.
	 * @return mixed
	 */
	public function verify_package( $reply, $package, $upgrader, $hook_extra ) {
		unset( $upgrader, $hook_extra );

		if ( false !== $reply || ! is_string( $package ) ) {
			return $reply;
		}

		$manifest = $this->manifest();
		if ( null === $manifest || $package !== $manifest['download_url'] || '' === $manifest['sha256'] ) {
			return $reply;
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$actual = hash_file( 'sha256', $file );

		if ( ! is_string( $actual ) || ! hash_equals( $manifest['sha256'], $actual ) ) {
			wp_delete_file( $file );

			return new WP_Error(
				'qwerty_soft_update_checksum',
				sprintf(
					/* translators: %s: theme version. */
					__( 'The downloaded Qwerty Soft — Signal %s package does not match the checksum published with it, so it was not installed. Try again later; if it keeps happening, download the theme from your account and upload it by hand.', 'qwerty-soft-signal' ),
					$manifest['version']
				)
			);
		}

		return $file;
	}
}

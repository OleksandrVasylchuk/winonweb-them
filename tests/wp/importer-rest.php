<?php
/**
 * The build, driven the way the screen drives it: through the REST routes.
 *
 * The build test (importer-build.php) calls SiteAssembler straight and proves
 * the site comes out right. This file proves the orchestration around it: that /build/start
 * refuses to trample a build already running, that /build/step takes the same
 * claim a cron tick takes, that a stop is honoured and a continue clears it,
 * that a page started too many times is set aside and comes back at the end —
 * and that the end, when it comes from cron, is the same end.
 *
 * Every write is rolled back by the harness and every file removed.
 *
 * @package Qwerty\Soft
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line test output, never a web page.

/*
 * ACF Pro stand-ins, before WordPress loads.
 *
 * /build/start refuses a wrapped build on a site without ACF Pro — rightly,
 * because the pages could not be edited afterwards — and the sandbox has
 * none. The build itself writes fields.json and calls nothing of ACF's, so
 * the constant Pro sets and a function that does nothing get the routes past
 * the gate without changing what they build.
 */
if ( ! function_exists( 'acf_add_local_field_group' ) ) {
	/**
	 * Stand-in for ACF's field group registration.
	 *
	 * @param mixed $group Ignored.
	 * @return bool
	 */
	function acf_add_local_field_group( $group ): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test stand-in for the plugin's own function.
		unset( $group );

		return true;
	}
}

if ( ! defined( 'ACF_PRO' ) ) {
	define( 'ACF_PRO', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The plugin's own constant, stood in for.
}

require __DIR__ . '/bootstrap.php';

use Qwerty\Soft\Support\BuildRunner;
use Qwerty\Soft\Support\BuildStep;
use Qwerty\Soft\Support\DesignArchive;
use Qwerty\Soft\Support\ImportSession;

/**
 * Call one of the importer's routes as the screen would.
 *
 * @param string               $route  Route under the importer namespace, e.g. '/build/start'.
 * @param array<string, mixed> $params Body parameters.
 * @return WP_REST_Response
 */
function qsoft_rest( string $route, array $params = array() ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/qwerty-soft-signal/v1' . $route );
	$request->set_body_params( $params );

	return rest_do_request( $request );
}

/**
 * The error code a response carries, or '' for a success.
 *
 * @param WP_REST_Response $response Response.
 * @return string
 */
function qsoft_rest_code( WP_REST_Response $response ): string {
	$data = $response->get_data();

	return $response->is_error() && is_array( $data ) ? (string) ( $data['code'] ?? '' ) : '';
}

/**
 * Unpack the fixture and start a build of it through the route.
 *
 * @param array<string, mixed> $extra Extra parameters for /build/start.
 * @return array{root:string, slug:string, response:WP_REST_Response}
 */
function qsoft_start_fixture_build( array $extra = array() ): array {
	$unpacked = DesignArchive::unpack( qsoft_fixture( 'design.zip' ), 'REST fixture' );

	if ( ! is_array( $unpacked ) ) {
		return array(
			'root'     => '',
			'slug'     => '',
			'response' => new WP_REST_Response( null, 500 ),
		);
	}

	return array(
		'root'     => (string) $unpacked['path'],
		'slug'     => (string) $unpacked['slug'],
		'response' => qsoft_rest( '/build/start', array_merge( array( 'slug' => $unpacked['slug'] ), $extra ) ),
	);
}

qsoft_test(
	'The build, step by step, through the routes',
	static function (): void {
		$user = get_current_user_id();

		delete_user_meta( $user, '_qwerty_soft_build_job' );
		delete_option( 'qwerty_soft_build_lock_' . $user );
		wp_clear_scheduled_hook( BuildRunner::HOOK, array( $user ) );

		$started  = qsoft_start_fixture_build();
		$root     = $started['root'];
		$response = $started['response'];

		if ( ! qsoft_assert( 200 === $response->get_status(), '/build/start answers 200', $response->get_data() ) ) {
			return;
		}

		$start = $response->get_data();
		$id    = (string) ( $start['job'] ?? '' );
		$steps = (array) ( $start['steps'] ?? array() );

		qsoft_assert( '' !== $id, 'the reply names the job' );
		qsoft_assert( 2 === (int) ( $start['done'] ?? 0 ), 'two steps are already behind us when the reply comes', $start['done'] ?? null );
		qsoft_assert( 'chrome' === ( $steps[0]['key'] ?? '' ) && 'finish' === ( end( $steps )['key'] ?? '' ), 'the steps run from the chrome to the finish', array_column( $steps, 'key' ) );
		qsoft_assert( count( $steps ) + 2 === (int) ( $start['total'] ?? 0 ), 'the total counts the steps and the two already done', $start['total'] ?? null );

		// ---- a second start is refused while the first is running ------

		$again = qsoft_rest( '/build/start', array( 'slug' => $started['slug'] ) );

		qsoft_assert( 409 === $again->get_status(), 'a second /build/start while one runs is refused with 409', $again->get_data() );
		qsoft_assert( 'qwerty_soft_build_running' === qsoft_rest_code( $again ), 'and says why', qsoft_rest_code( $again ) );
		qsoft_assert( (string) ( $again->get_data()['data']['job'] ?? '' ) === $id, 'naming the build that is in the way', $again->get_data() );

		$job = ImportSession::job( $id );

		qsoft_assert( null !== $job && array() === (array) ( $job['completed'] ?? array( 'x' ) ), 'the running job was left exactly as it was', $job['completed'] ?? null );

		// ---- the claim is the same one a tick takes ---------------------

		qsoft_assert( BuildRunner::claim( $user, 'chrome', (array) $job ), 'another worker takes the claim on the chrome' );

		$busy = qsoft_rest(
			'/build/step',
			array(
				'job' => $id,
				'key' => 'chrome',
			)
		);

		qsoft_assert( 409 === $busy->get_status() && 'qwerty_soft_busy' === qsoft_rest_code( $busy ), 'a request for the step somebody holds is turned away rather than run beside it', $busy->get_data() );

		BuildRunner::release( $user );

		$job = ImportSession::job( $id );

		qsoft_assert( null !== $job && empty( $job['completed']['chrome'] ), 'and the step was not counted as done', $job['completed'] ?? null );

		// ---- chrome ----------------------------------------------------

		$chrome = qsoft_rest(
			'/build/step',
			array(
				'job' => $id,
				'key' => 'chrome',
			)
		);

		qsoft_assert( 200 === $chrome->get_status(), 'the chrome step builds', $chrome->get_data() );
		qsoft_assert( 3 === (int) ( $chrome->get_data()['done'] ?? 0 ), 'and moves the count on by one', $chrome->get_data()['done'] ?? null );

		$job = ImportSession::job( $id );

		qsoft_assert( null !== $job && ! empty( $job['completed']['chrome'] ), 'the job records the chrome as done' );
		qsoft_assert( null !== $job && 1 === (int) ( $job['attempts']['chrome'] ?? 0 ), 'and counts the one attempt it took', $job['attempts'] ?? null );
		qsoft_assert( false === get_option( 'qwerty_soft_build_lock_' . $user ), 'the claim was given back afterwards' );

		// ---- a stop is honoured, a continue clears it -------------------

		$pages = array_values( array_filter( $steps, static fn( array $s ): bool => 'page' === ( $s['key'] ?? '' ) ) );
		$first = (string) ( $pages[0]['file'] ?? '' );

		qsoft_assert( '' !== $first, 'there is a page to build', array_column( $steps, 'key' ) );

		$stop = qsoft_rest( '/build/stop' );

		qsoft_assert( 200 === $stop->get_status() && ! empty( $stop->get_data()['stopped'] ), 'the build can be stopped from the route', $stop->get_data() );

		$refused = qsoft_rest(
			'/build/step',
			array(
				'job'  => $id,
				'key'  => 'page',
				'file' => $first,
			)
		);

		qsoft_assert( 409 === $refused->get_status() && 'qwerty_soft_stopped' === qsoft_rest_code( $refused ), 'a step asked of a stopped build is refused', $refused->get_data() );

		$job = ImportSession::job( $id );

		qsoft_assert( null !== $job && empty( $job['completed'][ 'page:' . $first ] ), 'and nothing was built', $job['completed'] ?? null );

		$resume = qsoft_rest( '/build/resume' );

		qsoft_assert( 200 === $resume->get_status(), 'continuing is a route too', $resume->get_data() );
		qsoft_assert( ! BuildRunner::stopped(), 'and it clears the stop' );

		wp_clear_scheduled_hook( BuildRunner::HOOK, array( $user ) );

		// ---- the pages -------------------------------------------------

		$built = array();

		foreach ( $pages as $page ) {
			$file = (string) $page['file'];
			$step = qsoft_rest(
				'/build/step',
				array(
					'job'  => $id,
					'key'  => 'page',
					'file' => $file,
				)
			);

			if ( ! qsoft_assert( 200 === $step->get_status(), $file . ': builds through the route', $step->get_data() ) ) {
				continue;
			}

			$built[ $file ] = (int) ( $step->get_data()['result']['id'] ?? 0 );

			qsoft_assert( $built[ $file ] > 0 && 'page' === get_post_type( $built[ $file ] ), $file . ': the reply names the page it made', $step->get_data()['result'] ?? null );
		}

		$job = ImportSession::job( $id );

		qsoft_assert( null !== $job && 3 + count( $pages ) === (int) ( $job['done'] ?? 0 ), 'every page moved the count on by one', $job['done'] ?? null );

		// ---- a page built twice is the same page ------------------------

		$twice = qsoft_rest(
			'/build/step',
			array(
				'job'  => $id,
				'key'  => 'page',
				'file' => $first,
			)
		);

		qsoft_assert( 200 === $twice->get_status() && ( $built[ $first ] ?? -1 ) === (int) ( $twice->get_data()['result']['id'] ?? 0 ), 'a page asked for again is updated, not duplicated', $twice->get_data()['result']['id'] ?? null );

		$job = ImportSession::job( $id );

		qsoft_assert( null !== $job && 2 === (int) ( $job['attempts'][ 'page:' . $first ] ?? 0 ), 'and the second attempt is counted', $job['attempts'] ?? null );

		// ---- a page started too often is set aside, then comes back -----

		$last = (string) ( end( $pages )['file'] ?? '' );
		$mark = 'page:' . $last;

		$job['attempts'][ $mark ] = BuildStep::MAX_ATTEMPTS;
		unset( $job['completed'][ $mark ] );
		$job['done'] = 2 + count( $job['completed'] );
		ImportSession::update_job( $job );

		$aside = qsoft_rest(
			'/build/step',
			array(
				'job'  => $id,
				'key'  => 'page',
				'file' => $last,
			)
		);

		qsoft_assert( 200 === $aside->get_status() && ! empty( $aside->get_data()['set_aside'] ), 'a page that has used up its attempts is set aside rather than run again', $aside->get_data() );

		$job = ImportSession::job( $id );

		qsoft_assert( null !== $job && in_array( $mark, (array) ( $job['deferred'] ?? array() ), true ), 'and remembered for a second pass', $job['deferred'] ?? null );
		qsoft_assert( null !== $job && ! empty( $job['completed'][ $mark ] ), 'while counting as done for now, so the build moves on' );

		$finish = qsoft_rest(
			'/build/step',
			array(
				'job' => $id,
				'key' => 'finish',
			)
		);

		qsoft_assert( 409 === $finish->get_status() && 'qwerty_soft_retrying' === qsoft_rest_code( $finish ), 'finishing with a page set aside puts it back first, and says so', $finish->get_data() );
		qsoft_assert( array( $mark ) === (array) ( $finish->get_data()['data']['retry'] ?? array() ), 'naming the page', $finish->get_data()['data'] ?? null );

		$job = ImportSession::job( $id );

		qsoft_assert( null !== $job && empty( $job['completed'][ $mark ] ) && ! empty( $job['retried'] ), 'the page is back in the queue, with the second pass marked as spent', $job );
		qsoft_assert( null !== $job && ! empty( $job['unattended'] ), 'a browser-driven build hands its second pass to the server, which reads the job rather than the list' );
		qsoft_assert( false !== wp_next_scheduled( BuildRunner::HOOK, array( $user ) ), 'and a tick is booked to run it' );

		// ---- the same end, from cron -----------------------------------

		wp_clear_scheduled_hook( BuildRunner::HOOK, array( $user ) );

		$more = BuildRunner::tick( $user, true );

		qsoft_assert( false === $more, 'one burst of the tick runs the page that was set aside and then the finish' );
		qsoft_assert( null === ImportSession::running(), 'the job is ended' );
		qsoft_assert( ( $built[ $last ] ?? -1 ) > 0 && 'page' === get_post_type( $built[ $last ] ), 'the page that was set aside is on the site' );

		$report = ImportSession::last_report();

		qsoft_assert( null !== $report && count( (array) ( $report['report']['pages'] ?? array() ) ) >= count( $pages ), 'the report of the finished build is kept for the screen', $report );
		qsoft_assert( ! is_dir( $root ), 'the unpacked design was removed once the build was done' );

		wp_clear_scheduled_hook( BuildRunner::HOOK, array( $user ) );
	}
);

qsoft_test(
	'A start with force replaces the running build; a stopped one is not in the way',
	static function (): void {
		$user = get_current_user_id();

		delete_user_meta( $user, '_qwerty_soft_build_job' );
		delete_option( 'qwerty_soft_build_lock_' . $user );

		$first = qsoft_start_fixture_build();

		if ( ! qsoft_assert( 200 === $first['response']->get_status(), 'the first build starts', $first['response']->get_data() ) ) {
			return;
		}

		$first_id = (string) ( $first['response']->get_data()['job'] ?? '' );

		wp_schedule_single_event( time() + 600, BuildRunner::HOOK, array( $user ) );

		$forced = qsoft_rest(
			'/build/start',
			array(
				'slug'  => $first['slug'],
				'force' => true,
			)
		);

		qsoft_assert( 200 === $forced->get_status(), 'a start with force is allowed while one runs', $forced->get_data() );

		$second_id = (string) ( $forced->get_data()['job'] ?? '' );

		qsoft_assert( '' !== $second_id && $second_id !== $first_id, 'and it is a new job' );
		qsoft_assert( null === ImportSession::job( $first_id ), 'the old job is gone' );
		qsoft_assert( ! BuildRunner::stopped(), 'the new one is not carrying the old one\'s stop' );
		qsoft_assert( false === wp_next_scheduled( BuildRunner::HOOK, array( $user ) ), 'and the old booking went with it, so no tick runs the old job against the new record' );

		$stale = qsoft_rest(
			'/build/step',
			array(
				'job' => $first_id,
				'key' => 'chrome',
			)
		);

		qsoft_assert( 404 === $stale->get_status() && 'qwerty_soft_no_job' === qsoft_rest_code( $stale ), 'a tab still holding the old id is told its build is over', $stale->get_data() );

		// A stopped build is not in the way of a new one.
		qsoft_rest( '/build/stop' );

		$after_stop = qsoft_rest( '/build/start', array( 'slug' => $first['slug'] ) );

		qsoft_assert( 200 === $after_stop->get_status(), 'a build that was stopped does not block a new start', $after_stop->get_data() );

		// A build whose design has gone is ended, and says so.
		$third_id = (string) ( $after_stop->get_data()['job'] ?? '' );

		DesignArchive::remove( $first['root'] );

		$gone = qsoft_rest(
			'/build/step',
			array(
				'job' => $third_id,
				'key' => 'chrome',
			)
		);

		qsoft_assert( 404 === $gone->get_status(), 'a step of a build whose design has gone is refused', $gone->get_data() );
		qsoft_assert( null === ImportSession::running(), 'and the job is ended rather than left to stall' );

		wp_clear_scheduled_hook( BuildRunner::HOOK, array( $user ) );
	}
);

qsoft_test(
	'A picture named in a script this system cannot hold is still found by the page',
	static function (): void {
		/*
		 * The archive names the picture in Cyrillic and the page names it
		 * the same way, once plainly and once percent-encoded. The unpack
		 * used to write `----.jpg` and the page kept pointing at the
		 * original; now the file is transliterated, the change is written
		 * down beside the design, and the media map follows it.
		 */
		$zip_path = get_temp_dir() . 'qsoft-cyrillic-' . wp_generate_password( 8, false, false ) . '.zip';
		$zip      = new ZipArchive();

		if ( ! qsoft_assert( true === $zip->open( $zip_path, ZipArchive::CREATE ), 'a test archive can be written' ) ) {
			return;
		}

		$zip->addFromString( 'site/index.html', '<html><body><img src="pics/фото.jpg" alt=""><img src="pics/%D1%84%D0%BE%D1%82%D0%BE.jpg" alt=""></body></html>' );
		$zip->addFromString( 'site/pics/фото.jpg', (string) file_get_contents( qsoft_fixture( 'design/img/one.jpg' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- A fixture file.
		$zip->close();

		try {
			$unpacked = DesignArchive::unpack( $zip_path, 'Cyrillic names' );

			if ( ! qsoft_assert( is_array( $unpacked ), 'the archive unpacks', is_wp_error( $unpacked ) ? $unpacked->get_error_message() : null ) ) {
				return;
			}

			$root    = (string) $unpacked['path'];
			$renamed = (array) ( $unpacked['renamed'] ?? array() );

			qsoft_assert( 2 === (int) $unpacked['files'], 'both files were written', $unpacked['files'] );
			qsoft_assert( array() === $unpacked['skipped'], 'nothing was skipped', $unpacked['skipped'] );
			qsoft_assert( 'site/pics/foto.jpg' === ( $renamed['site/pics/фото.jpg'] ?? '' ), 'the picture was transliterated, and the change written down', $renamed );
			qsoft_assert( is_file( $root . '/site/pics/foto.jpg' ), 'the file on disk has the new name' );
			qsoft_assert( DesignArchive::renamed( $root ) === $renamed, 'the change survives to a later request, beside the design' );

			$index = DesignArchive::index( $root );

			qsoft_assert( 1 === count( $index['pages'] ) && 1 === (int) $index['images'], 'the manifest is not mistaken for a page or a picture', array( count( $index['pages'] ), $index['images'] ) );

			$map = \Qwerty\Soft\Support\SiteBuilder::import_media( $root );

			if ( ! qsoft_assert( is_array( $map ) && isset( $map['site/pics/foto.jpg'] ), 'the picture was imported under its new name', $map ) ) {
				return;
			}

			qsoft_assert( isset( $map['site/pics/фото.jpg'] ) && $map['site/pics/фото.jpg'] === $map['site/pics/foto.jpg'], 'and the map answers to the original name as well' );

			$page   = (string) file_get_contents( $root . '/site/index.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- The file this test unpacked.
			$linked = \Qwerty\Soft\Support\SiteBuilder::relink_media( $page, $map, 'site' );
			$url    = (string) $map['site/pics/foto.jpg']['url'];

			qsoft_assert( 2 === substr_count( $linked, $url ), 'the page finds its picture by the original name, plain or percent-encoded', $linked );
			qsoft_assert( ! str_contains( $linked, 'фото' ) && ! str_contains( $linked, '%D1%84' ), 'and no reference to the old name is left', $linked );
		} finally {
			if ( is_file( $zip_path ) ) {
				unlink( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- The archive this test wrote.
			}
		}
	}
);

qsoft_finish();

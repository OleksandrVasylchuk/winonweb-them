<?php
/**
 * SectionSplitter against the fixture design, plus its flat-page guard.
 *
 * The splitter reads files, not the database, so these checks need no
 * transaction; they run under the harness anyway so the output reads the same
 * and a stray write would still be caught.
 *
 * @package Qwerty\Soft
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line test output, never a web page.
// phpcs:disable WordPress.WP.AlternativeFunctions -- A temporary file the test writes and removes.

require __DIR__ . '/bootstrap.php';

use Qwerty\Soft\Support\SectionSplitter;

/**
 * Everything a split produced as one string, for "must not contain" checks.
 *
 * @param array<string, mixed> $split SectionSplitter::split() result.
 * @return string
 */
function qsoft_split_text( array $split ): string {
	$pieces = array(
		(string) ( $split['header']['html'] ?? '' ),
		(string) ( $split['footer']['html'] ?? '' ),
	);

	foreach ( (array) $split['sections'] as $section ) {
		$pieces[] = (string) $section['html'];
	}

	return implode( "\n", $pieces );
}

qsoft_test(
	'SectionSplitter: the fixture pages',
	static function (): void {
		$expected = array(
			'Home.dc.html'    => array(
				'first'    => 'Ship the signal, not the noise',
				'sections' => 4,
			),
			'About.dc.html'   => array(
				'first'    => 'A studio of four',
				'sections' => 3,
			),
			'Contact.dc.html' => array(
				'first'    => 'Tell us what you are building',
				'sections' => 2,
			),
		);

		foreach ( $expected as $file => $want ) {
			$split = SectionSplitter::split( qsoft_fixture( 'design/' . $file ) );
			$all   = qsoft_split_text( $split );

			qsoft_assert( is_array( $split['header'] ), $file . ': header found through the SiteNav import' );
			qsoft_assert( str_contains( (string) ( $split['header']['html'] ?? '' ), 'logo.svg' ), $file . ': header carries the logo image', $split['header']['html'] ?? null );
			qsoft_assert( is_array( $split['footer'] ), $file . ': footer found through the SiteFooter import' );
			qsoft_assert( str_contains( (string) ( $split['footer']['html'] ?? '' ), 'Fixture Co' ), $file . ': footer has its text', $split['footer']['html'] ?? null );

			$labels = array_map( static fn( array $s ): string => (string) $s['label'], (array) $split['sections'] );

			qsoft_assert( count( $split['sections'] ) === $want['sections'], $file . ': ' . $want['sections'] . ' sections', $labels );

			$first = (string) ( $split['sections'][0]['text'] ?? '' );
			qsoft_assert( str_contains( $first, $want['first'] ), $file . ': the first section is the one with the h1', $first );

			qsoft_assert( ! str_contains( $all, 'Get the monthly note' ), $file . ': the NewsletterPopup (hint-size 0px,0px) is left out' );
			qsoft_assert( ! str_contains( $all, 'popup-email' ), $file . ': no trace of the popup form' );
			qsoft_assert( ! str_contains( $all, '<sc-for' ), $file . ': no <sc-for> template loop survives' );
			qsoft_assert( ! str_contains( $all, '{{' ), $file . ': no {{ placeholder }} survives' );
			qsoft_assert( ! str_contains( $all, '<dc-import' ), $file . ': no <dc-import> tag survives' );
			qsoft_assert( ! str_contains( $all, '<x-dc' ) && ! str_contains( $all, '<helmet' ), $file . ': the x-dc and helmet wrappers are unwrapped' );
			qsoft_assert( ! str_contains( $all, '<script' ) && ! str_contains( $all, '<link' ), $file . ': scripts and links are stripped' );
		}

		$home = SectionSplitter::split( qsoft_fixture( 'design/Home.dc.html' ) );

		qsoft_assert( 'Fixture Co — Signal for small teams' === $home['title'], 'Home: title read from the helmet', $home['title'] );

		$hero = $home['sections'][0] ?? array();
		qsoft_assert( 'div' === ( $hero['tag'] ?? '' ), 'Home: the hero div outside any <section> is adopted as a section', $hero['tag'] ?? null );
		qsoft_assert( 1 === ( $hero['heading']['level'] ?? 0 ) || str_contains( (string) ( $hero['html'] ?? '' ), '<h1' ), 'Home: the hero carries the h1', $hero['heading'] ?? null );
		qsoft_assert( ! str_contains( (string) ( $hero['html'] ?? '' ), '<hero-viz' ), 'Home: the <hero-viz> custom element is unwrapped' );

		$band = $home['sections'][1] ?? array();
		qsoft_assert( str_contains( (string) ( $band['html'] ?? '' ), 'background-image:url(img/band.jpg)' ), 'Home: the inline background-image survives for the converter', $band['html'] ?? null );

		$proof = $home['sections'][3] ?? array();
		qsoft_assert( str_contains( (string) ( $proof['html'] ?? '' ), '46,000+' ), 'Home: the metric figure is in the fourth section', $proof['label'] ?? null );
		qsoft_assert( str_contains( (string) ( $proof['html'] ?? '' ), 'data:image/png;base64,' ), 'Home: the data: image is still inline at this stage' );
		qsoft_assert( 1 === (int) ( $proof['lists'] ?? 0 ) || str_contains( (string) ( $proof['html'] ?? '' ), '<dl' ), 'Home: the FAQ list is in the fourth section' );
	}
);

qsoft_test(
	'SectionSplitter: a flat page of 3000 cards splits fast and coarse',
	static function (): void {
		$cards = '';

		for ( $i = 1; $i <= 3000; $i++ ) {
			$cards .= '<div class="card"><img src="img/one.jpg" alt=""><h3>Card ' . $i . '</h3><p>Body copy for card number ' . $i . '.</p></div>' . "\n";
		}

		$html = '<!doctype html><html lang="en"><head><title>Flat</title></head><body>'
			. '<header><nav><a href="index.html">Home</a></nav></header>'
			. '<main><h1>Three thousand cards</h1><div class="grid">' . $cards . '</div></main>'
			. '<footer><p>Footer</p></footer></body></html>';

		$file = tempnam( sys_get_temp_dir(), 'qs-flat-' );

		if ( false === $file ) {
			qsoft_assert( false, 'could not create a temporary file' );
			return;
		}

		$file = $file . '.html';
		file_put_contents( $file, $html );

		try {
			$started = microtime( true );
			$split   = SectionSplitter::split( $file );
			$elapsed = microtime( true ) - $started;

			qsoft_assert( $elapsed < 1.0, sprintf( 'split in under a second (took %.3fs)', $elapsed ) );
			qsoft_assert( count( $split['sections'] ) < 10, 'fewer than 10 sections', count( $split['sections'] ) );
			qsoft_assert( count( $split['sections'] ) >= 1, 'at least one section was found' );
			qsoft_assert( is_array( $split['header'] ) && is_array( $split['footer'] ), 'header and footer were still found' );

			$text = qsoft_split_text( $split );
			qsoft_assert( str_contains( $text, 'Card 3000' ), 'the last card is still in the output' );
		} finally {
			unlink( $file );
		}
	}
);

qsoft_finish();

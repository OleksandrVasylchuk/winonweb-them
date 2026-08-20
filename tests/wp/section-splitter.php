<?php
/**
 * SectionSplitter against the fixture design, plus its flat-page guard.
 *
 * The splitter reads files, not the database, so these checks need no
 * transaction; they run under the harness anyway so the output reads the same
 * and a stray write would still be caught.
 *
 * @package Wow\Signal
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line test output, never a web page.
// phpcs:disable WordPress.WP.AlternativeFunctions -- A temporary file the test writes and removes.

require __DIR__ . '/bootstrap.php';

use Wow\Signal\Support\SectionSplitter;

/**
 * Everything a split produced as one string, for "must not contain" checks.
 *
 * @param array<string, mixed> $split SectionSplitter::split() result.
 * @return string
 */
function wow_split_text( array $split ): string {
	$pieces = array(
		(string) ( $split['header']['html'] ?? '' ),
		(string) ( $split['footer']['html'] ?? '' ),
	);

	foreach ( (array) $split['sections'] as $section ) {
		$pieces[] = (string) $section['html'];
	}

	return implode( "\n", $pieces );
}

wow_test(
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
			$split = SectionSplitter::split( wow_fixture( 'design/' . $file ) );
			$all   = wow_split_text( $split );

			wow_assert( is_array( $split['header'] ), $file . ': header found through the SiteNav import' );
			wow_assert( str_contains( (string) ( $split['header']['html'] ?? '' ), 'logo.svg' ), $file . ': header carries the logo image', $split['header']['html'] ?? null );
			wow_assert( is_array( $split['footer'] ), $file . ': footer found through the SiteFooter import' );
			wow_assert( str_contains( (string) ( $split['footer']['html'] ?? '' ), 'Fixture Co' ), $file . ': footer has its text', $split['footer']['html'] ?? null );

			$labels = array_map( static fn( array $s ): string => (string) $s['label'], (array) $split['sections'] );

			wow_assert( count( $split['sections'] ) === $want['sections'], $file . ': ' . $want['sections'] . ' sections', $labels );

			$first = (string) ( $split['sections'][0]['text'] ?? '' );
			wow_assert( str_contains( $first, $want['first'] ), $file . ': the first section is the one with the h1', $first );

			wow_assert( ! str_contains( $all, 'Get the monthly note' ), $file . ': the NewsletterPopup (hint-size 0px,0px) is left out' );
			wow_assert( ! str_contains( $all, 'popup-email' ), $file . ': no trace of the popup form' );
			wow_assert( ! str_contains( $all, '<sc-for' ), $file . ': no <sc-for> template loop survives' );
			wow_assert( ! str_contains( $all, '{{' ), $file . ': no {{ placeholder }} survives' );
			wow_assert( ! str_contains( $all, '<dc-import' ), $file . ': no <dc-import> tag survives' );
			wow_assert( ! str_contains( $all, '<x-dc' ) && ! str_contains( $all, '<helmet' ), $file . ': the x-dc and helmet wrappers are unwrapped' );
			wow_assert( ! str_contains( $all, '<script' ) && ! str_contains( $all, '<link' ), $file . ': scripts and links are stripped' );
		}

		$home = SectionSplitter::split( wow_fixture( 'design/Home.dc.html' ) );

		wow_assert( 'Fixture Co — Signal for small teams' === $home['title'], 'Home: title read from the helmet', $home['title'] );

		$hero = $home['sections'][0] ?? array();
		wow_assert( 'div' === ( $hero['tag'] ?? '' ), 'Home: the hero div outside any <section> is adopted as a section', $hero['tag'] ?? null );
		wow_assert( 1 === ( $hero['heading']['level'] ?? 0 ) || str_contains( (string) ( $hero['html'] ?? '' ), '<h1' ), 'Home: the hero carries the h1', $hero['heading'] ?? null );
		wow_assert( ! str_contains( (string) ( $hero['html'] ?? '' ), '<hero-viz' ), 'Home: the <hero-viz> custom element is unwrapped' );

		$band = $home['sections'][1] ?? array();
		wow_assert( str_contains( (string) ( $band['html'] ?? '' ), 'background-image:url(img/band.jpg)' ), 'Home: the inline background-image survives for the converter', $band['html'] ?? null );

		$proof = $home['sections'][3] ?? array();
		wow_assert( str_contains( (string) ( $proof['html'] ?? '' ), '46,000+' ), 'Home: the metric figure is in the fourth section', $proof['label'] ?? null );
		wow_assert( str_contains( (string) ( $proof['html'] ?? '' ), 'data:image/png;base64,' ), 'Home: the data: image is still inline at this stage' );
		wow_assert( 1 === (int) ( $proof['lists'] ?? 0 ) || str_contains( (string) ( $proof['html'] ?? '' ), '<dl' ), 'Home: the FAQ list is in the fourth section' );
	}
);

wow_test(
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

		$file = tempnam( sys_get_temp_dir(), 'wow-flat-' );

		if ( false === $file ) {
			wow_assert( false, 'could not create a temporary file' );
			return;
		}

		$file = $file . '.html';
		file_put_contents( $file, $html );

		try {
			$started = microtime( true );
			$split   = SectionSplitter::split( $file );
			$elapsed = microtime( true ) - $started;

			wow_assert( $elapsed < 1.0, sprintf( 'split in under a second (took %.3fs)', $elapsed ) );
			wow_assert( count( $split['sections'] ) < 10, 'fewer than 10 sections', count( $split['sections'] ) );
			wow_assert( count( $split['sections'] ) >= 1, 'at least one section was found' );
			wow_assert( is_array( $split['header'] ) && is_array( $split['footer'] ), 'header and footer were still found' );

			$text = wow_split_text( $split );
			wow_assert( str_contains( $text, 'Card 3000' ), 'the last card is still in the output' );
		} finally {
			unlink( $file );
		}
	}
);

wow_finish();

<?php
/**
 * The site's own words, and who is allowed to overwrite them.
 *
 * A field's name is positional: SectionPlan calls a footer's labels `label`,
 * `label_2`, `label_3` in the order it meets them, and the option key
 * `options_{block}_{name}` is the whole of a row's identity. So a generator
 * that learns to see one more element shifts every later name down a place,
 * and rows written by an earlier build go on sitting under names that now
 * mean something else. That is how a live footer came to read its brand as
 * "© Chalir. All rights reserved." while the copyright line showed the
 * tagline twice.
 *
 * What a rebuild must not touch is a person's words. What it must be free to
 * put right is its own. These are the two halves of that, and the shift is the
 * case that needs both at once.
 *
 * @package Qwerty\Soft
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line test output, never a web page.

require __DIR__ . '/bootstrap.php';

use Qwerty\Soft\Support\SiteOptions;

qsoft_test(
	'seed(): the design\'s own words are refreshed, a person\'s are left alone',
	static function (): void {
		SiteOptions::reset();

		// A first build.
		SiteOptions::seed(
			array(
				'options_qsoft_test_label'   => 'CHALIR',
				'options_qsoft_test_label_2' => 'Every right reserved.',
				'options_qsoft_test_link'    => array(
					'url'    => '#top',
					'title'  => 'Home',
					'target' => '',
				),
			)
		);

		qsoft_assert( 'CHALIR' === get_option( 'options_qsoft_test_label' ), 'a row that was not there is written' );
		qsoft_assert( is_array( get_option( 'options_qsoft_test_link' ) ), 'a link is written whole, as the array it is' );

		$registered = (array) get_option( SiteOptions::REGISTER, array() );

		qsoft_assert( in_array( 'options_qsoft_test_label', $registered, true ), 'and the row is registered, so undoing the import can find it', $registered );

		// Somebody rewrites one of them, and one they were never given.
		update_option( 'options_qsoft_test_label_2', 'Written by a person on a Tuesday.', false );

		/*
		 * A second build of the same design, with the generator having learned
		 * to see one more element: what `label` names has moved on, and
		 * `label_3` is new.
		 */
		SiteOptions::seed(
			array(
				'options_qsoft_test_label'   => 'Fixture Co.',
				'options_qsoft_test_label_2' => 'CHALIR',
				'options_qsoft_test_label_3' => 'Every right reserved.',
				'options_qsoft_test_link'    => array(
					'url'    => '#top',
					'title'  => 'Home',
					'target' => '',
				),
			)
		);

		qsoft_assert(
			'Fixture Co.' === get_option( 'options_qsoft_test_label' ),
			'a row still holding exactly what the last build wrote is the design talking to itself, and is put right',
			get_option( 'options_qsoft_test_label' )
		);

		qsoft_assert(
			'Written by a person on a Tuesday.' === get_option( 'options_qsoft_test_label_2' ),
			'a row holding anything else was written by somebody, and is left alone',
			get_option( 'options_qsoft_test_label_2' )
		);

		qsoft_assert(
			'Every right reserved.' === get_option( 'options_qsoft_test_label_3' ),
			'and the name the shift made room for is seeded'
		);

		/*
		 * A row seeded before there was any record of what was written cannot
		 * be told from one somebody typed, and the benefit of the doubt goes
		 * to the person.
		 */
		delete_option( SiteOptions::SEEDED );
		update_option( 'options_qsoft_test_label', 'CHALIR', false );

		SiteOptions::seed( array( 'options_qsoft_test_label' => 'Fixture Co.' ) );

		qsoft_assert(
			'CHALIR' === get_option( 'options_qsoft_test_label' ),
			'a row from before there was a record of what was written is left alone',
			get_option( 'options_qsoft_test_label' )
		);
	}
);

qsoft_test(
	'reset(): everything the import wrote goes, and nothing else',
	static function (): void {
		SiteOptions::reset();

		update_option( 'options_qsoft_test_kept', 'not the importer\'s', false );

		SiteOptions::seed( array( 'options_qsoft_test_gone' => 'the importer\'s' ) );

		qsoft_assert( 1 === SiteOptions::reset(), 'the rows it wrote are counted as they go' );
		qsoft_assert( false === get_option( 'options_qsoft_test_gone', false ), 'the row it wrote is gone' );
		qsoft_assert( 'not the importer\'s' === get_option( 'options_qsoft_test_kept' ), 'a row it never wrote is untouched' );
		qsoft_assert( false === get_option( SiteOptions::SEEDED, false ), 'and the record of what was written goes with them' );

		delete_option( 'options_qsoft_test_kept' );
	}
);

qsoft_finish();

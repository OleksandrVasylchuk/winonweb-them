/**
 * wow/contact-form — post-submit focus management.
 *
 * The form works completely without this file: it is a plain POST, the server
 * validates, and the browser lands back on the page with the messages already
 * in the HTML. All this adds is moving focus to the error summary so a
 * keyboard or screen-reader user is not left at the top of the document
 * hunting for what went wrong (WCAG 3.3.1).
 */
( function () {
	'use strict';

	function boot() {
		var summary = document.querySelector( '[data-wow-contact-summary]' );

		if ( ! summary ) {
			return;
		}

		// Let the browser finish its own fragment scroll first.
		window.requestAnimationFrame( function () {
			summary.focus( { preventScroll: false } );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();

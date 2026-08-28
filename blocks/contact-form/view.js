/**
 * qs/contact-form — post-submit focus management.
 *
 * The form works completely without this file: it is a plain POST, the server
 * validates, and the browser lands back on the page with the messages already
 * in the HTML. All this adds is moving focus to whichever notice is present —
 * the error summary or the success message — so a keyboard or screen-reader
 * user is not left at the top of the document hunting for what happened
 * (WCAG 3.3.1, 4.1.3). Without JS the URL fragment still scrolls to it.
 */
( function () {
	'use strict';

	function boot() {
		var notice = document.querySelector( '[data-qs-contact-summary], [data-qs-contact-status]' );

		if ( ! notice ) {
			return;
		}

		// Let the browser finish its own fragment scroll first.
		window.requestAnimationFrame( function () {
			notice.focus( { preventScroll: false } );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();

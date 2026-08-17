/**
 * wow/faq — optional "one answer open at a time" behaviour.
 *
 * Everything the accordion needs already works without this file: <details>
 * handles opening, closing, keyboard operation and screen-reader state on its
 * own. This only adds the exclusive-open refinement, and only where an editor
 * asked for it.
 */
( function () {
	'use strict';

	function closeSiblings( accordion, opened ) {
		var items = accordion.querySelectorAll( ':scope > details[open]' );

		Array.prototype.forEach.call( items, function ( item ) {
			if ( item !== opened ) {
				item.open = false;
			}
		} );
	}

	function init( accordion ) {
		accordion.addEventListener( 'toggle', function ( event ) {
			var target = event.target;

			if ( target && 'DETAILS' === target.tagName && target.open ) {
				closeSiblings( accordion, target );
			}
		}, true );
	}

	function boot() {
		var accordions = document.querySelectorAll( '.wow-faq.is-exclusive' );

		Array.prototype.forEach.call( accordions, init );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();

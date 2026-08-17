/**
 * wow/slider — arrow controls.
 *
 * The markup is already a usable scroller: swipe, trackpad, shift-scroll and
 * arrow keys all work with no script at all. This file reveals the arrow
 * buttons (hidden in the HTML so a no-JS visitor never meets a dead control)
 * and keeps their disabled state in sync with the scroll position.
 */
( function () {
	'use strict';

	function slideStep( viewport ) {
		var slide = viewport.querySelector( '.wow-slider__slide' );

		if ( ! slide ) {
			return viewport.clientWidth;
		}

		var styles = window.getComputedStyle( viewport.querySelector( '.wow-slider__track' ) );
		var gap = parseFloat( styles.columnGap || styles.gap || '0' ) || 0;

		return slide.getBoundingClientRect().width + gap;
	}

	function sync( viewport, prev, next ) {
		// One pixel of slack absorbs sub-pixel scroll positions.
		var maxScroll = viewport.scrollWidth - viewport.clientWidth - 1;

		prev.disabled = viewport.scrollLeft <= 0;
		next.disabled = viewport.scrollLeft >= maxScroll;
	}

	function init( slider ) {
		var viewport = slider.querySelector( '.wow-slider__viewport' );
		var controls = slider.querySelector( '[data-wow-slider-controls]' );

		if ( ! viewport || ! controls ) {
			return;
		}

		var prev = controls.querySelector( '[data-wow-slider-prev]' );
		var next = controls.querySelector( '[data-wow-slider-next]' );

		if ( ! prev || ! next ) {
			return;
		}

		// Nothing overflows: the cards already fit, so arrows would be noise.
		if ( viewport.scrollWidth <= viewport.clientWidth + 1 ) {
			return;
		}

		controls.hidden = false;

		function scrollBy( direction ) {
			var reduced =
				window.matchMedia &&
				window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

			viewport.scrollBy( {
				left: direction * slideStep( viewport ),
				behavior: reduced ? 'auto' : 'smooth',
			} );
		}

		prev.addEventListener( 'click', function () {
			scrollBy( -1 );
		} );

		next.addEventListener( 'click', function () {
			scrollBy( 1 );
		} );

		var frame = null;

		viewport.addEventListener( 'scroll', function () {
			if ( null !== frame ) {
				return;
			}

			frame = window.requestAnimationFrame( function () {
				frame = null;
				sync( viewport, prev, next );
			} );
		} );

		window.addEventListener( 'resize', function () {
			controls.hidden = viewport.scrollWidth <= viewport.clientWidth + 1;
			sync( viewport, prev, next );
		} );

		sync( viewport, prev, next );
	}

	function boot() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '.wow-slider' ),
			init
		);
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();

/**
 * wow/slider — arrow controls.
 *
 * The markup is already a usable scroller: swipe, trackpad, shift-scroll and
 * arrow keys all work with no script at all. This file reveals the arrow
 * buttons (hidden in the HTML so a no-JS visitor never meets a dead control)
 * and keeps their disabled state in sync with the scroll position.
 *
 * Disabled arrows use aria-disabled rather than the disabled attribute: a
 * button that becomes disabled while focused drops keyboard focus to <body>.
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

	function setDisabled( button, disabled ) {
		button.setAttribute( 'aria-disabled', disabled ? 'true' : 'false' );
		button.classList.toggle( 'is-disabled', disabled );
	}

	function isDisabled( button ) {
		return 'true' === button.getAttribute( 'aria-disabled' );
	}

	function hasOverflow( viewport ) {
		return viewport.scrollWidth > viewport.clientWidth + 1;
	}

	function sync( viewport, prev, next ) {
		// One pixel of slack absorbs sub-pixel scroll positions.
		var maxScroll = viewport.scrollWidth - viewport.clientWidth - 1;
		// scrollLeft is negative in RTL; the magnitude is what matters.
		var position = Math.abs( viewport.scrollLeft );

		setDisabled( prev, position <= 0 );
		setDisabled( next, position >= maxScroll );
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
		// The resize listener below still runs, so a rotated tablet gets them.
		controls.hidden = ! hasOverflow( viewport );

		function scrollBy( direction ) {
			var reduced =
				window.matchMedia &&
				window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

			// In a right-to-left document scrollLeft runs from 0 into the
			// negatives, so "next" has to move the other way.
			var rtl =
				'rtl' === window.getComputedStyle( viewport ).direction;

			viewport.scrollBy( {
				left: direction * ( rtl ? -1 : 1 ) * slideStep( viewport ),
				behavior: reduced ? 'auto' : 'smooth',
			} );
		}

		prev.addEventListener( 'click', function () {
			if ( ! isDisabled( prev ) ) {
				scrollBy( -1 );
			}
		} );

		next.addEventListener( 'click', function () {
			if ( ! isDisabled( next ) ) {
				scrollBy( 1 );
			}
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

		var resizeFrame = null;
		var resizeTimer = null;

		function onResize() {
			resizeFrame = null;
			resizeTimer = null;
			controls.hidden = ! hasOverflow( viewport );
			sync( viewport, prev, next );
		}

		// rAF coalesces bursts; the timeout guarantees a run even in a
		// background tab, where rAF never fires.
		window.addEventListener( 'resize', function () {
			if ( null !== resizeFrame ) {
				return;
			}

			resizeFrame = window.requestAnimationFrame( function () {
				if ( null !== resizeTimer ) {
					window.clearTimeout( resizeTimer );
				}
				onResize();
			} );

			resizeTimer = window.setTimeout( function () {
				if ( null !== resizeFrame ) {
					window.cancelAnimationFrame( resizeFrame );
				}
				onResize();
			}, 100 );
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

/**
 * qs/metric — count-up animation.
 *
 * Progressive enhancement only. The correct number is already rendered by
 * PHP; this file animates towards it and bails out completely when the
 * visitor prefers reduced motion or the browser lacks IntersectionObserver.
 */
( function () {
	'use strict';

	var DURATION = 1100;

	// Mirrors render.php and edit.js: digits, optional thousands spaces, dot decimal.
	var COUNTABLE = /^\d[\d\s]*(\.\d+)?$/;

	function prefersReducedMotion() {
		return (
			window.matchMedia &&
			window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches
		);
	}

	/** Match the source formatting (decimals, thousands separators). */
	function formatter( raw ) {
		var decimals = ( raw.split( '.' )[ 1 ] || '' ).length;
		var grouped = raw.indexOf( ' ' ) > -1;

		return function ( value ) {
			var text = value.toFixed( decimals );

			if ( grouped ) {
				var parts = text.split( '.' );
				parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, ' ' );
				text = parts.join( '.' );
			}

			return text;
		};
	}

	function animate( figure ) {
		var raw = figure.getAttribute( 'data-qs-count-to' ) || '';
		var number = figure.querySelector( '.qs-metric__number' );

		if ( ! number || ! COUNTABLE.test( raw ) ) {
			return;
		}

		var target = parseFloat( raw.replace( /\s/g, '' ) );

		if ( isNaN( target ) ) {
			return;
		}

		var format = formatter( raw );
		var start = null;
		var done = false;

		/**
		 * Put the real figure on screen and stop animating.
		 *
		 * This is the safety net. requestAnimationFrame stops firing whenever
		 * the tab goes to the background, so without it a visitor who switches
		 * tabs mid-count comes back to a number frozen part-way — a made-up
		 * statistic presented as fact. Both the timer and the visibility
		 * handler below fall through to here.
		 */
		function settle() {
			if ( done ) {
				return;
			}

			done = true;
			// Restore the server-rendered string exactly.
			number.textContent = raw;
			document.removeEventListener( 'visibilitychange', onHide );
		}

		function onHide() {
			if ( document.hidden ) {
				settle();
			}
		}

		function step( timestamp ) {
			if ( done ) {
				return;
			}

			if ( null === start ) {
				start = timestamp;
			}

			var progress = Math.min( ( timestamp - start ) / DURATION, 1 );
			// easeOutCubic — fast first, settles gently on the real figure.
			var eased = 1 - Math.pow( 1 - progress, 3 );

			number.textContent = format( target * eased );

			if ( progress < 1 ) {
				window.requestAnimationFrame( step );
			} else {
				settle();
			}
		}

		document.addEventListener( 'visibilitychange', onHide );

		// Independent of the animation loop, so it fires even if rAF never does.
		window.setTimeout( settle, DURATION + 400 );

		number.textContent = format( 0 );
		window.requestAnimationFrame( step );
	}

	function boot() {
		var figures = document.querySelectorAll( '.qs-metric__figure[data-qs-count-to]' );

		if ( ! figures.length ) {
			return;
		}

		if ( prefersReducedMotion() || ! ( 'IntersectionObserver' in window ) ) {
			return;
		}

		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						observer.unobserve( entry.target );
						animate( entry.target );
					}
				} );
			},
			{ rootMargin: '0px 0px -15% 0px', threshold: 0.25 }
		);

		Array.prototype.forEach.call( figures, function ( figure ) {
			observer.observe( figure );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();

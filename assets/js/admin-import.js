/**
 * The design import screen.
 *
 * Plain DOM against the REST endpoints — no build step, matching the rest of
 * the theme. Every network reply is treated as data to render, never as HTML
 * to inject: the only place server markup is written as HTML is the preview
 * pane, and that string has already been validated and passed through
 * wp_kses_post() on the server.
 */
( function ( wp ) {
	'use strict';

	var cfg = window.qwertySoftImport || {};
	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var apiFetch = wp.apiFetch;

	apiFetch.use( apiFetch.createNonceMiddleware( cfg.nonce ) );

	var app = document.getElementById( 'qs-import-app' );

	if ( ! app ) {
		return;
	}

	/** Current selection. */
	var state = {
		design: null,
		page: null,
		sections: [],
		results: {},
		built: null,
		savedPage: null,
		busy: false,
		spend: null,
		limit: null,
		summary: cfg.summary || null,
		confirmingReset: false,
		confirmingPurge: false,
		// Build options live here so a re-render does not reset the form.
		publish: false,
		language: '',
		// The unpacked design is kept; the clean-up panel removes it when asked.
		keepArchive: true,
		// How careful the build is: fast, corrected, or corrected and checked.
		mode: 'fast',
		// What the page list is filtered by, when it is long enough to need one.
		pageFilter: '',
		// Whether the preview puts the design beside the blocks instead of showing the blocks alone.
		compare: false,
		// What that choice sets: whether the model corrects each section, and whether it reviews its own work.
		smart: false,
		refine: false,
		// The live verdict from /model: which route works from here, and why not.
		model: null,
		modelChecking: false,
		// Designs already unpacked on the server, and what they weigh.
		designs: [],
		archive: ( cfg.summary && cfg.summary.archive ) || null,
		// Preview-before-build: which page is open, and which sections stay in.
		preview: null,
		includes: {},
		// The stepwise build in progress, if any.
		job: null,
		// The application inside the design, when the design is one, and the reading of it.
		source: null,
		reading: null,
		// Whether the server finishes the build on its own.
		unattended: false,
		// Whether the admin and utility screens are built too.
		utility: false,
		// Which file wins an address two pages both want: slug => file.
		pick: {},
		// Whether a stalled build is being carried on by hand right now.
		resuming: false,
		// The running account of what the importer is doing.
		log: { lines: [], since: 0, timer: null, busy: false, job: null },
		// The one-second interval that moves the clock on the working step.
		clock: null,
		// The site owner's own instructions for the chosen design.
		notes: '',
		notesSaved: true,
		// The design's own stylesheet, fetched once and given to every preview pane.
		designCss: '',
		designCssSlug: '',
		designCssWaiting: [],
	};

	/**
	 * The file picker is created once and re-attached on every render, so
	 * choosing an archive survives a re-render (a cancelled clean-up, a page
	 * click) instead of quietly emptying the field.
	 */
	var archiveInput = null;

	/**
	 * Money, written the way a person reads it.
	 *
	 * Every figure on this screen is derived from published list prices, so it
	 * is an estimate and is always labelled as one. Sub-cent amounts round to
	 * "under $0.01" rather than to "$0.00", which would read as free.
	 */
	function money( dollars ) {
		var amount = Number( dollars );

		if ( ! isFinite( amount ) || amount <= 0 ) {
			return '$0.00';
		}

		if ( amount < 0.01 ) {
			return __( 'under $0.01', 'qwerty-soft-signal' );
		}

		return '$' + amount.toFixed( 2 );
	}

	/** A token count, abbreviated once it stops being worth reading in full. */
	function tokens( count ) {
		var value = Number( count ) || 0;

		return value >= 1000 ? Math.round( value / 1000 ) + 'k' : String( value );
	}

	/** Minutes until the hourly conversion allowance comes back. */
	function minutes( seconds ) {
		return Math.max( 1, Math.ceil( ( Number( seconds ) || 0 ) / 60 ) );
	}

	/** A size on disk, in the unit a person would pick. */
	function bytesText( bytes ) {
		var value = Number( bytes ) || 0;

		if ( value >= 1048576 ) {
			/* translators: %s: size in megabytes. */
			return sprintf( __( '%s MB', 'qwerty-soft-signal' ), ( value / 1048576 ).toFixed( 1 ) );
		}

		/* translators: %s: size in kilobytes. */
		return sprintf( __( '%s KB', 'qwerty-soft-signal' ), String( Math.max( 1, Math.round( value / 1024 ) ) ) );
	}

	/** What converting the not-yet-converted sections would cost, roughly. */
	function outstandingEstimate() {
		return state.sections.reduce( function ( total, section ) {
			var done = state.results[ section.position ];

			if ( done && ! done.error ) {
				return total;
			}

			return total + ( Number( section.estimate ) || 0 );
		}, 0 );
	}

	/**
	 * The pages a build would actually touch, in the chosen language.
	 *
	 * A multilingual archive holds the same site three times over. Counting
	 * all of it would treble every figure on this panel, and the one figure
	 * that matters here is money.
	 */
	function plannedPages() {
		var design = state.design;

		if ( ! design || ! design.pages ) {
			return [];
		}

		if ( ! state.language ) {
			return design.pages;
		}

		var prefix = state.language + '/';

		var inLanguage = design.pages.filter( function ( page ) {
			var path = page.path || page.file || '';

			if ( 0 === path.indexOf( prefix ) || path.indexOf( '/' + prefix ) > -1 ) {
				return true;
			}

			/*
			 * A tooling screen is usually filed under no language at all, so
			 * the language filter would drop every one of them and the box
			 * asking for them would do nothing. The server applies the same
			 * rule; this is what keeps the count on the button honest.
			 */
			return state.utility && isUtility( page ) && ! languageOf( page );
		} );

		// A design whose files are not filed by language is one site, not none.
		return dedupeBySlug( dropUtility( inLanguage.length ? inLanguage : design.pages ) );
	}

	/*
	 * The tooling screens, out unless they were asked for.
	 *
	 * The list below folds them away as "most sites do not want these", and
	 * the build used to include them anyway whenever they happened to sit
	 * under the chosen language — so the count on the button and the pages
	 * that appeared afterwards disagreed. The server applies the same rule
	 * from the same flag; this is only what the screen counts.
	 */
	function dropUtility( pages ) {
		if ( state.utility ) {
			return pages;
		}

		return pages.filter( function ( page ) {
			return ! isUtility( page );
		} );
	}

	/** Every page in the design, in the chosen language, tooling included. */
	function pagesInLanguage() {
		var design = state.design;

		if ( ! design || ! design.pages ) {
			return [];
		}

		if ( ! state.language ) {
			return design.pages;
		}

		var prefix = state.language + '/';

		var inLanguage = design.pages.filter( function ( page ) {
			var path = page.path || page.file || '';

			return 0 === path.indexOf( prefix ) || path.indexOf( '/' + prefix ) > -1;
		} );

		return inLanguage.length ? inLanguage : design.pages;
	}

	/**
	 * How many sections a build of the current design would convert.
	 *
	 * Sections chosen in a preview win over the design's own count: leaving
	 * four sections out of a page changes what the build costs, and a figure
	 * that ignored that would be wrong in the direction that matters.
	 */
	function plannedSections() {
		return plannedPages().reduce( function ( total, page ) {
			var chosen = state.includes[ page.file ];

			if ( chosen ) {
				return total + chosen.length;
			}

			return total + ( Number( page.sections ) || 1 );
		}, 0 );
	}

	/**
	 * Roughly what a guided build would cost through the API, in dollars.
	 *
	 * The server priced each page when it indexed the design; this adds up the
	 * ones this build would actually convert, and doubles it when the review
	 * pass is on because that is a second call per section.
	 */
	function plannedCost() {
		var total = plannedPages().reduce( function ( sum, page ) {
			return sum + ( Number( page.estimate ) || 0 );
		}, 0 );

		/*
		 * A reviewed section is one guided call plus up to three review
		 * rounds — the pass repeats until the model says the blocks match the
		 * design. Estimating it at two would understate a build by a third.
		 */
		return total * ( state.refine ? 4 : 1 );
	}

	/**
	 * How many model calls a checked build would make.
	 *
	 * One guided call per section, then the review pass, which repeats until
	 * the model says the blocks match the design and gives up after three
	 * rounds. Four calls per section is the ceiling and the number worth
	 * planning around, because a design the conversion reads badly is exactly
	 * the one that runs every round.
	 */
	function plannedCalls() {
		return plannedSections() * ( state.refine ? 4 : 1 );
	}

	/**
	 * How long a checked build takes, said out loud.
	 *
	 * The card used to read "up to an hour" whatever the design was. On a
	 * handoff of two hundred sections that is not a rounding error — it is
	 * wrong by most of a working day, and somebody plans around it. The calls
	 * are made one after another; nothing here runs in parallel. So the honest
	 * figure is their number times how long one of them takes.
	 */
	function plannedTime() {
		/*
		 * A section is a small request and the review rounds are smaller.
		 * Forty seconds is the middle of what this pipeline actually spends.
		 */
		var seconds = plannedCalls() * 40;
		var hours = seconds / 3600;

		if ( hours >= 1.5 ) {
			return sprintf(
				/* translators: %s: a number of hours. */
				__( 'about %s hours', 'qwerty-soft-signal' ),
				String( Math.round( hours * 2 ) / 2 )
			);
		}

		if ( seconds >= 2400 ) {
			return __( 'about an hour', 'qwerty-soft-signal' );
		}

		return sprintf(
			/* translators: %d: a number of minutes. */
			__( 'about %d minutes', 'qwerty-soft-signal' ),
			minutes( seconds )
		);
	}

	/** Whether a model can be reached from this machine at all. */
	function modelReady() {
		return !! ( state.model && state.model.ready );
	}

	/** Ask the server which route works from here. */
	function loadModel() {
		state.modelChecking = true;

		apiFetch( { path: '/qwerty-soft-signal/v1/model' } )
			.then( function ( status ) {
				state.model = status;
			} )
			.catch( function () {
				state.model = { ready: false, route: '', reason: '' };
			} )
			.then( function () {
				state.modelChecking = false;
				render();
			} );
	}

	/** How many sections still need converting. */
	function outstandingCount() {
		return state.sections.filter( function ( section ) {
			var done = state.results[ section.position ];

			return ! done || done.error;
		} ).length;
	}

	/** Make an element with attributes and children in one call. */
	function el( tag, attrs, children ) {
		var node = document.createElement( tag );

		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( 'class' === key ) {
				node.className = attrs[ key ];
			} else if ( 'text' === key ) {
				node.textContent = attrs[ key ];
			} else if ( key.indexOf( 'on' ) === 0 ) {
				node.addEventListener( key.slice( 2 ).toLowerCase(), attrs[ key ] );
			} else if ( attrs[ key ] !== null && attrs[ key ] !== undefined ) {
				node.setAttribute( key, attrs[ key ] );
			}
		} );

		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
			}
		} );

		return node;
	}

	/**
	 * A preview pane: markup with the design's stylesheet behind it.
	 *
	 * The iframe is sandboxed to nothing at all — no scripts, no forms, no
	 * navigation — because what goes in it is a design somebody uploaded. It
	 * grows to fit its content once, so a section is seen whole rather than
	 * through a letterbox.
	 *
	 * @param {string} html      Markup, already filtered on the server.
	 * @param {string} className Classes for the frame.
	 * @param {number} cap       Tallest the frame may grow, in pixels.
	 * @return {HTMLElement} The iframe.
	 */
	function frame( html, className, cap ) {
		var node = el( 'iframe', {
			class: className,
			/*
			 * No scripts, no forms, no navigation — the markup came out of
			 * somebody's archive. Same-origin is allowed and nothing else:
			 * without it the frame cannot be measured, and a pane that
			 * cannot be measured cannot be fitted to its section.
			 */
			sandbox: 'allow-same-origin',
			title: __( 'Preview', 'qwerty-soft-signal' ),
		} );

		var paint = function () {
			var css = state.designCss || '';

			node.srcdoc =
				'<!doctype html><html><head><meta charset="utf-8">' +
				'<style>html{background:#fff}body{margin:0;padding:0;overflow-x:hidden}img{max-width:100%;height:auto}</style>' +
				( css ? '<style>' + css + '</style>' : '' ) +
				'</head><body>' +
				html +
				'</body></html>';
		};

		paint();

		/*
		 * Height follows the content. A fixed pane cuts a hero in half, and
		 * two panes of different heights make a comparison impossible to read.
		 */
		var fit = function () {
			try {
				var doc = node.contentDocument;

				if ( doc && doc.body && doc.body.scrollHeight > 0 ) {
					// Shrink to a short section; never grow past what the eye can take in.
					node.style.height = Math.min( cap || 640, Math.max( 160, doc.body.scrollHeight + 8 ) ) + 'px';
				}
			} catch ( error ) {
				// A frame that will not measure keeps the height the CSS gave it.
			}
		};

		/*
		 * Measured more than once on purpose. A frame measured the instant it
		 * loads has no pictures in it yet and reports the height of the text
		 * alone, which cut every hero in half.
		 */
		node.addEventListener( 'load', function () {
			fit();
			window.setTimeout( fit, 300 );
			window.setTimeout( fit, 1200 );

			try {
				var win = node.contentWindow;

				if ( win && win.ResizeObserver && node.contentDocument && node.contentDocument.body ) {
					new win.ResizeObserver( fit ).observe( node.contentDocument.body );
				}
			} catch ( error ) {
				// Without an observer the timers above are enough.
			}
		} );

		state.designCssWaiting.push( paint );

		return node;
	}

	/**
	 * Fetch the stylesheet a page is styled by, then repaint every pane with it.
	 *
	 * Per page, not per design. A developer handoff holds several projects at
	 * once, each with a complete stylesheet of its own; loading all of them
	 * together dressed every preview in whichever project sorted last.
	 *
	 * @param {string} slug Design slug.
	 * @param {string} file Page the styling is wanted for, or '' for the design as a whole.
	 * @return {void}
	 */
	function loadDesignCss( slug, file ) {
		var want = slug + '::' + ( file || '' );

		if ( ! slug || state.designCssSlug === want ) {
			return;
		}

		state.designCssSlug = want;
		state.designCss = '';

		apiFetch( {
			path:
				'/qwerty-soft-signal/v1/designs/' +
				encodeURIComponent( slug ) +
				'/stylesheet' +
				( file ? '?page=' + encodeURIComponent( file ) : '' ),
		} )
			.then( function ( result ) {
				state.designCss = result.css || '';

				state.designCssWaiting.forEach( function ( paint ) {
					paint();
				} );
			} )
			.catch( function () {
				// No stylesheet: the panes still show the markup, unstyled.
			} );
	}

	/**
	 * A path a person can read at a glance.
	 *
	 * A handoff nests its pages six folders deep, and the folders are named
	 * after the package rather than after the page — so the useful part is the
	 * end. The whole path stays on the row as a title attribute for anybody who
	 * needs it.
	 *
	 * \@param {string} path Path inside the design.
	 * \@return {string} The last two segments, with a leading ellipsis when cut.
	 */
	function shortPath( path ) {
		var parts = String( path || '' ).split( '/' );

		return parts.length > 2 ? '…/' + parts.slice( -2 ).join( '/' ) : String( path || '' );
	}

	/** Announce progress and errors to everyone, including screen readers. */
	function say( message, isError ) {
		var live = document.getElementById( 'qs-import-status' );

		if ( live ) {
			live.textContent = message;
			live.className = 'qs-import__status' + ( isError ? ' is-error' : '' );
		}
	}

	function errorText( error ) {
		if ( ! error ) {
			return __( 'Something went wrong.', 'qwerty-soft-signal' );
		}

		return error.message || String( error );
	}

	// ---------------------------------------------------------------- upload

	function renderUpload() {
		if ( ! archiveInput ) {
			archiveInput = el( 'input', {
				type: 'file',
				id: 'qs-archive',
				accept: '.zip,application/zip',
				class: 'qs-import__file',
			} );
		}

		var input = archiveInput;

		var button = el( 'button', {
			type: 'submit',
			class: 'button button-primary',
			text: __( 'Upload and read the design', 'qwerty-soft-signal' ),
		} );

		var form = el( 'form', {
			class: 'qs-import__upload',
			onSubmit: function ( event ) {
				event.preventDefault();

				if ( ! input.files || ! input.files[ 0 ] ) {
					say( __( 'Choose a ZIP file first.', 'qwerty-soft-signal' ), true );
					return;
				}

				var body = new FormData();
				body.append( 'archive', input.files[ 0 ] );

				button.disabled = true;
				say( __( 'Unpacking and reading the design…', 'qwerty-soft-signal' ) );

				apiFetch( { path: '/qwerty-soft-signal/v1/designs', method: 'POST', body: body } )
					.then( function ( result ) {
						button.disabled = false;
						say(
							sprintf(
								/* translators: 1: number of files, 2: number of pages. */
								__( 'Read %1$d files and found %2$d pages.', 'qwerty-soft-signal' ),
								result.files,
								result.pages.length
							)
						);
						chooseDesign( result );
						loadDesigns();
					} )
					.catch( function ( error ) {
						button.disabled = false;
						say( errorText( error ), true );
					} );
			},
		}, [
			el( 'label', { for: 'qs-archive', class: 'qs-import__label', text: __( 'Design archive (.zip)', 'qwerty-soft-signal' ) } ),
			input,
			button,
		] );

		return el( 'section', { class: 'qs-import__step' }, [
			el( 'h2', { text: __( '1. Upload the design', 'qwerty-soft-signal' ) } ),
			form,
			renderDesigns(),
		] );
	}

	/*
	 * Designs unpacked on an earlier visit. Without this list a reload meant
	 * uploading the same archive again — or leaving the old copy in uploads
	 * with no way to see it was there.
	 */
	function renderDesigns() {
		if ( ! state.designs.length ) {
			return null;
		}

		var list = el( 'ul', { class: 'qs-import__designs' } );

		state.designs.forEach( function ( design ) {
			var active = state.design && state.design.slug === design.slug;

			list.appendChild(
				el( 'li', {}, [
					el( 'button', {
						type: 'button',
						class: 'qs-import__design' + ( active ? ' is-active' : '' ),
						'aria-pressed': active ? 'true' : 'false',
						onClick: function () {
							chooseDesign( design );
							render();
						},
					}, [
						el( 'span', { class: 'qs-import__page-title', text: design.slug } ),
						el( 'span', {
							class: 'qs-import__page-meta',
							text: sprintf(
								/* translators: 1: number of pages, 2: number of images. */
								__( '%1$d pages · %2$d images', 'qwerty-soft-signal' ),
								design.pages.length,
								design.images
							),
						} ),
						'app' === design.kind
							? el( 'span', {
								class: 'qs-import__tag',
								text: __( 'JavaScript app — no markup to convert', 'qwerty-soft-signal' ),
							} )
							: null,
					] ),
				] )
			);
		} );

		return el( 'div', { class: 'qs-import__designs-wrap' }, [
			el( 'h3', { text: __( 'Already uploaded', 'qwerty-soft-signal' ) } ),
			list,
		] );
	}

	function chooseDesign( design ) {
		state.design = design;
		state.page = null;
		state.sections = [];
		state.results = {};
		state.preview = null;
		state.includes = {};
		state.savedPage = null;

		/*
		 * Match the state to what the language picker shows.
		 *
		 * The picker lists the archive's languages and renders the first as
		 * selected, but an empty language means "every page in the archive" to
		 * the server. Left alone, a three-language design reads as "building
		 * en" on screen and quietly creates the Russian and Chinese pages too
		 * — three times the pages, three times the cost of a guided build.
		 */
		state.language =
			design && design.languages && design.languages.length > 1
				? design.languages[ 0 ]
				: '';

		loadSource( design );
		loadNotes( design );

		/*
		 * No stylesheet yet. The panes are the only thing that needs one, and
		 * which one they need depends on the page opened in them.
		 */
		state.designCss = '';
		state.designCssSlug = '';
	}

	// ----------------------------------------------------- what is happening

	/*
	 * The running account. Every step that takes real time writes a line on
	 * the server as it happens; this reads the lines it has not seen yet and
	 * keeps reading while anything is running.
	 *
	 * It is the answer to the one question a long import cannot otherwise
	 * answer — "is this still working, and on what?" — and because the lines
	 * live on the server rather than in the tab, the account survives a
	 * reload, a closed laptop and a build that finishes without anybody
	 * watching.
	 */
	function watchLog() {
		if ( state.log.timer ) {
			return;
		}

		var poll = function () {
			// What the panel looked like before this reply, to tell a change from a heartbeat.
			var before = {
				done: state.job ? state.job.done : -1,
				currentKey: state.job ? ( state.job.currentKey || '' ) : '',
				stalled: !! ( state.job && state.job.stall ),
				finished: !! ( state.job && state.job.finished ),
				stopped: !! ( state.job && state.job.stopped ),
			};

			apiFetch( { path: '/qwerty-soft-signal/v1/log?since=' + state.log.since } )
				.then( function ( result ) {
					var lines = result.lines || [];

					if ( lines.length ) {
						state.log.lines = state.log.lines.concat( lines ).slice( -60 );
						state.log.since = lines[ lines.length - 1 ].seq;
					}

					state.log.job = result.job || null;

					/*
					 * An unattended build ends by deleting its job, so the
					 * poll never sees a finished one — what arrives instead is
					 * the report. Taking it here is what turns a watched build
					 * into the list of what it made, without a reload.
					 */
					if ( result.built && result.built.report && ! state.built ) {
						state.built = result.built.report;

						if ( state.job ) {
							state.job.running = false;
							state.job.finished = true;
							state.job.stall = null;
						}
					}

					/*
					 * A build the server is running reports its own progress:
					 * the panel follows the job record rather than the steps
					 * this tab has taken, because it has taken none.
					 */
					if ( result.job && state.job && state.job.unattended ) {
						state.job.done = result.job.done;
						state.job.total = result.job.total;

						/*
						 * Which row is working, since when, and when it last
						 * spoke. This tab did not start the build, so all
						 * three come from the server or the panel has nothing
						 * moving on it at all.
						 */
						state.job.currentKey = result.job.current || '';
						state.job.stepStarted = result.job.started ? result.job.started * 1000 : 0;
						state.job.beat = result.job.beat ? result.job.beat * 1000 : 0;
						state.job.made = result.job.made || {};

						/*
						 * The steps themselves.
						 *
						 * The server has sent these on every poll for as long
						 * as there has been a server-run build, and nothing
						 * ever copied them onto the job — so a tab watching an
						 * unattended build drew the bar, the percentage and no
						 * list at all. Three complaints came out of this one
						 * omission: no rows, no links on the rows, and a head
						 * that could not say which page it was on because it
						 * had no pages to count.
						 */
						state.job.prep = result.job.prep || [];
						state.job.steps = result.job.steps || [];

						/*
						 * Sent as a list of keys; read here as a lookup, which
						 * is what the row test wants.
						 */
						var completed = {};

						( result.job.completed || [] ).forEach( function ( key ) {
							completed[ key ] = true;
						} );

						state.job.completed = completed;
						state.job.starting = ! result.job.done;

						/*
						 * A build that has gone quiet. The server works this
						 * out because only the server knows when the last step
						 * spoke and whether cron still has a tick booked; the
						 * panel's job is to say so and offer the way out.
						 */
						state.job.stall = state.resuming ? null : result.job.stall || null;

						/*
						 * Stopped by hand, which the server holds rather than
						 * this tab — so a stop made in one tab, or before a
						 * reload, is still a stop here, with Continue beside
						 * it rather than a spinner that will never move.
						 */
						state.job.stopped = ! state.resuming && !! result.job.stopped;

						if ( state.job.stopped ) {
							state.job.running = false;
						}

						if ( result.job.finished ) {
							state.job.running = false;
							state.job.finished = true;
							state.job.stall = null;
							state.job.stopped = false;
						}
					}

					/*
					 * Redraw only when something on the panel actually moved.
					 *
					 * The poll used to call render() every two seconds for as
					 * long as a build ran, which rebuilt the whole screen —
					 * including an open preview, whose two iframes were
					 * destroyed and recreated each time. That is the flicker:
					 * not an animation, a page being thrown away and made
					 * again while somebody was reading it.
					 *
					 * A log line on its own changes the beat line and nothing
					 * else, and the beat line's clock ticks on its own.
					 */
					var moved = ! state.job
						|| state.job.done !== before.done
						|| ( state.job.currentKey || '' ) !== before.currentKey
						|| !! state.job.stall !== before.stalled
						|| !! state.job.finished !== before.finished
						|| !! state.job.stopped !== before.stopped;

					if ( moved || ! state.preview ) {
						render();
					} else {
						refreshLive();
					}

					/*
					 * The clock has to be started from here rather than from
					 * the render: a render happens only when something on the
					 * screen changed, and a step that takes fifteen minutes
					 * changes nothing for fifteen minutes.
					 */
					if ( result.running ) {
						startClock();
					}

					var busy = result.running || ( state.job && state.job.running ) || ( state.reading && state.reading.running ) || state.log.busy;

					if ( ! busy ) {
						window.clearInterval( state.log.timer );
						state.log.timer = null;
						render();
					}
				} )
				.catch( function () {
					window.clearInterval( state.log.timer );
					state.log.timer = null;
				} );
		};

		poll();
		state.log.timer = window.setInterval( poll, 2000 );
	}

	/*
	 * The site owner's own instructions for this design.
	 *
	 * Everything else the model is told comes out of the archive. This is the
	 * one place a person can say the thing the archive does not: which page is
	 * the real home page, that the placeholder prices must not be carried
	 * over, that the second hero is the one to use. It is written into the
	 * design folder and leads every brief.
	 */
	function loadNotes( design ) {
		state.notes = '';
		state.notesSaved = true;

		if ( ! design ) {
			return;
		}

		apiFetch( { path: '/qwerty-soft-signal/v1/designs/' + encodeURIComponent( design.slug ) + '/instructions' } )
			.then( function ( result ) {
				state.notes = result.notes || '';
				render();
			} )
			.catch( function () {
				// No instructions is the normal case; nothing to report.
			} );
	}

	function saveNotes( button ) {
		var field = document.getElementById( 'qs-import-notes' );

		if ( ! field || ! state.design ) {
			return;
		}

		button.disabled = true;

		apiFetch( {
			path: '/qwerty-soft-signal/v1/designs/' + encodeURIComponent( state.design.slug ) + '/instructions',
			method: 'POST',
			data: { notes: field.value },
		} )
			.then( function ( result ) {
				state.notes = result.notes || '';
				state.notesSaved = true;
				say( __( 'Saved. These instructions lead every brief for this design.', 'qwerty-soft-signal' ) );
				render();
			} )
			.catch( function ( error ) {
				button.disabled = false;
				say( errorText( error ), true );
			} );
	}

	function renderNotes() {
		if ( ! state.design ) {
			return null;
		}

		var field = el( 'textarea', {
			id: 'qs-import-notes',
			class: 'qs-import__field qs-import__notes-field',
			rows: '4',
			placeholder: __( 'For example: the real home page is index-v2.html · keep the English copy, ignore the German folder · the prices are placeholders, leave them out · use the dark header everywhere', 'qwerty-soft-signal' ),
			onInput: function () {
				state.notesSaved = false;
			},
		} );

		field.value = state.notes;

		var save = el( 'button', {
			type: 'button',
			class: 'button',
			text: state.notesSaved ? __( 'Saved', 'qwerty-soft-signal' ) : __( 'Save instructions', 'qwerty-soft-signal' ),
			onClick: function ( event ) {
				saveNotes( event.target );
			},
		} );

		return el( 'details', { class: 'qs-import__notes' }, [
			el( 'summary', { text: __( 'Your instructions for this design', 'qwerty-soft-signal' ) } ),
			el( 'p', {
				class: 'qs-import__hint',
				text: __( 'Anything you write here is put at the top of every brief Claude works from, above the design\'s own documentation, and it stays with this design. Plain sentences are enough.', 'qwerty-soft-signal' ),
			} ),
			field,
			el( 'p', { class: 'qs-import__actions' }, [ save ] ),
		] );
	}

	/*
	 * Rejoin a build already in progress. The job lives on the server, so a
	 * screen opened halfway through one — in another tab, or in the same tab
	 * after a reload — should show it running rather than an empty form.
	 */
	function resumeWatching() {
		apiFetch( { path: '/qwerty-soft-signal/v1/log?since=0' } )
			.then( function ( result ) {
				var lines = result.lines || [];

				state.log.lines = lines.slice( -60 );
				state.log.since = lines.length ? lines[ lines.length - 1 ].seq : 0;

				/*
				 * A build that finished while nobody was watching — or that
				 * finished and then had its tab reloaded. The job is gone by
				 * then, deliberately, but what it made is not, and an empty
				 * upload form is the worst possible answer to an hour of work.
				 */
				if ( result.built && result.built.report ) {
					state.built = result.built.report;

					say(
						sprintf(
							/* translators: %d: number of pages the build made. */
							_n(
								'The last build is finished — %d page is on the site.',
								'The last build is finished — %d pages are on the site.',
								result.built.report.pages.length,
								'qwerty-soft-signal'
							),
							result.built.report.pages.length
						)
					);
				}

				if ( result.job && ! result.job.finished ) {
					var rejoinedDone = {};

					( result.job.completed || [] ).forEach( function ( key ) {
						rejoinedDone[ key ] = true;
					} );

					state.job = {
						id: result.job.id,
						done: result.job.done,
						total: result.job.total,
						running: true,
						errors: [],
						unattended: !! result.job.unattended,
						rejoined: true,

						// Everything the panel needs to draw the list, not just the bar.
						prep: result.job.prep || [],
						steps: result.job.steps || [],
						completed: rejoinedDone,
						currentKey: result.job.current || '',
						stepStarted: result.job.started ? result.job.started * 1000 : 0,
						beat: result.job.beat ? result.job.beat * 1000 : 0,
						made: result.job.made || {},
						stall: result.job.stall || null,
					};

					say(
						result.job.unattended
							? __( 'A build is running on the server. This is where it has got to.', 'qwerty-soft-signal' )
							: __( 'A build was left unfinished. Its progress is below.', 'qwerty-soft-signal' )
					);

					watchLog();
				}

				render();
			} )
			.catch( function () {
				// Nothing to rejoin; the screen is fine as it is.
			} );
	}

	function renderLog() {
		if ( ! state.log.lines.length ) {
			return null;
		}

		var list = el( 'ol', { class: 'qs-import__log-lines' } );

		state.log.lines.slice( -24 ).forEach( function ( line ) {
			list.appendChild(
				el( 'li', { class: 'qs-import__log-line is-' + ( line.stage || 'build' ) }, [
					el( 'span', { class: 'qs-import__log-stage', text: line.stage || '' } ),
					el( 'span', { class: 'qs-import__log-text', text: line.message } ),
				] )
			);
		} );

		var head = [
			el( 'summary', {
				text: state.log.timer
					? __( 'What is happening now', 'qwerty-soft-signal' )
					: __( 'What happened', 'qwerty-soft-signal' ),
			} ),
			list,
		];

		var panel = el( 'details', { class: 'qs-import__log' + ( state.log.timer ? ' is-live' : '' ) }, head );

		// Open while there is something to watch; foldable once it is over.
		if ( state.log.timer ) {
			panel.setAttribute( 'open', 'open' );
		}

		return panel;
	}

	/*
	 * Repaint the two things that move on their own, and nothing else.
	 *
	 * A log line arriving does not change the shape of the screen, so redrawing
	 * the screen for one is waste — and while a preview is open it is worse
	 * than waste, because rebuilding the panel throws its iframes away and
	 * makes them again, which reads as a flicker every two seconds. This puts
	 * the new lines and the new heartbeat text into the panel that is already
	 * there.
	 */
	function refreshLive() {
		var list = document.querySelector( '.qs-import__log-lines' );

		if ( list ) {
			var fresh = renderLog();
			var lines = fresh ? fresh.querySelector( '.qs-import__log-lines' ) : null;

			if ( lines ) {
				list.textContent = '';

				while ( lines.firstChild ) {
					list.appendChild( lines.firstChild );
				}
			}
		}

		var beat = document.querySelector( '.qs-import__beat-text' );
		var last = state.log.lines.length ? state.log.lines[ state.log.lines.length - 1 ] : null;

		if ( beat && last && last.message ) {
			beat.textContent = last.message;
		}
	}

	// ------------------------------------------------ a design that is an app

	/*
	 * When the design has no markup of its own, ask the server what
	 * application it is and which URLs it serves. Only then — a design made of
	 * pages needs none of this and should not pay a request for it.
	 */
	function loadSource( design ) {
		state.reading = null;

		/*
		 * Asked for whenever the design has components in it, not only when it
		 * has nothing else. Reading the first page turns the design into a
		 * static one, and a panel that keyed off "this design is an app" would
		 * have vanished at that moment, taking the other ten pages with it.
		 */
		if ( ! design || ! design.components ) {
			state.source = null;
			return;
		}

		state.source = { loading: true, slug: design.slug, routes: [], projects: [] };

		loadProject( design.slug, '' );
	}

	/*
	 * Read one application out of the archive.
	 *
	 * An archive can hold several — a site, an admin panel, a widget meant to
	 * ship as a plugin — and which of them belongs on this site is a question
	 * only a person can answer. So the routes shown are always the routes of
	 * one named project, and choosing another asks the server again rather
	 * than guessing locally.
	 */
	function loadProject( slug, project ) {
		var path = '/qwerty-soft-signal/v1/designs/' + encodeURIComponent( slug ) + '/source';

		if ( project ) {
			path += '?project=' + encodeURIComponent( project );
		}

		apiFetch( { path: path } )
			.then( function ( result ) {
				result.slug = slug;
				state.source = result;
			} )
			.catch( function () {
				state.source = null;
			} )
			.then( function () {
				render();
			} );
	}

	/** Read one route's components and write the page they render. */
	function readRoute( route ) {
		return apiFetch( {
			path: '/qwerty-soft-signal/v1/designs/' + encodeURIComponent( state.source.slug ) + '/render',
			method: 'POST',
			data: { route: route.path, project: state.source.project || '' },
		} ).then( function ( result ) {
			route.rendered = {
				file: result.file,
				title: result.title,
				sections: result.sections,
				words: result.words,
				notes: result.notes || [],
			};

			if ( result.spend ) {
				state.spend = result.spend;
			}

			if ( result.limit ) {
				state.limit = result.limit;
			}

			return result;
		} );
	}

	/*
	 * One route at a time, on purpose. Each is a long model call, and reading
	 * eleven pages at once would either hit the hourly allowance or hold
	 * eleven requests open against a server that will close them.
	 */
	function readRoutes( routes ) {
		state.reading = { running: true, done: 0, total: routes.length, errors: [] };
		render();
		watchLog();

		var chain = Promise.resolve();

		routes.forEach( function ( route ) {
			chain = chain.then( function () {
				if ( ! state.reading || ! state.reading.running ) {
					return null;
				}

				say(
					sprintf(
						/* translators: 1: route path, 2: how many are done, 3: how many there are. */
						__( 'Reading %1$s — %2$d of %3$d…', 'qwerty-soft-signal' ),
						route.path,
						state.reading.done + 1,
						state.reading.total
					)
				);

				return readRoute( route )
					.catch( function ( error ) {
						state.reading.errors.push( route.path + ' — ' + errorText( error ) );
					} )
					.then( function () {
						state.reading.done += 1;
						render();
					} );
			} );
		} );

		return chain.then( function () {
			if ( state.reading ) {
				state.reading.running = false;
			}

			var failed = state.reading ? state.reading.errors.length : 0;
			var made = routes.length - failed;

			say(
				failed
					? sprintf(
						/* translators: 1: pages written, 2: routes that failed. */
						__( 'Read %1$d pages; %2$d could not be read. The pages that were read are in the list below.', 'qwerty-soft-signal' ),
						made,
						failed
					)
					: sprintf(
						/* translators: %d: number of pages. */
						_n( 'Read %d page out of the application. It is an ordinary design now — pick a page below.', 'Read %d pages out of the application. It is an ordinary design now — pick a page below.', made, 'qwerty-soft-signal' ),
						made
					),
				failed > 0
			);

			// The rendered pages are real files now, so the design has to be read again.
			var slug = state.source ? state.source.slug : '';

			return loadDesigns().then( function () {
				var refreshed = state.designs.filter( function ( design ) {
					return design.slug === slug;
				} )[ 0 ];

				if ( refreshed ) {
					var source = state.source;
					var reading = state.reading;

					chooseDesign( refreshed );

					// chooseDesign asks the server again; keep what is already known until it answers.
					state.source = source;
					state.reading = reading;
				}

				render();
			} );
		} );
	}

	function loadDesigns() {
		return apiFetch( { path: '/qwerty-soft-signal/v1/designs' } )
			.then( function ( result ) {
				state.designs = result.designs || [];
				state.archive = result.archive || null;
			} )
			.catch( function () {
				// The list is a convenience; the upload form still works without it.
			} )
			.then( function () {
				render();
			} );
	}

	/*
	 * The panel that turns an application into a design.
	 *
	 * It is the only place on this screen where a model is not optional: a
	 * page that exists solely as components cannot be read any other way, and
	 * saying that plainly is better than offering a button that would produce
	 * an empty page.
	 */
	/*
	 * Exactly what this build will make, before it is asked to make it.
	 *
	 * One row per page: the title the page will carry, the address it will
	 * live at, and how much of it there is. Open by default when the list is
	 * short enough to read at a glance, folded when it is not — but always
	 * present, because "which pages" is the question this screen was worst at
	 * answering.
	 */
	function renderPlanned() {
		var pages = plannedPages();

		if ( ! pages.length ) {
			return null;
		}

		var list = el( 'ul', { class: 'qs-import__planned' } );

		pages.forEach( function ( page ) {
			var others = page.alternatives && page.alternatives.length > 1 ? page.alternatives : null;

			var row = [
				el( 'span', { class: 'qs-import__planned-title', text: page.title || page.path || page.file } ),
				el( 'code', { class: 'qs-import__planned-slug', text: '/' + ( page.slug || '' ) + '/' } ),
				el( 'span', {
					class: 'qs-import__planned-facts',
					text: sprintf(
						/* translators: %d: number of sections on the page. */
						_n( '%d section', '%d sections', Number( page.sections ) || 0, 'qwerty-soft-signal' ),
						Number( page.sections ) || 0
					),
				} ),
			];

			/*
			 * The same page in two versions: the site's own, and the copy a
			 * feature blueprint ships with that feature added. Only one can
			 * have the address, so the row says which is being taken and lets
			 * it be swapped — rather than building both and leaving WordPress
			 * to resolve a URL that now points at neither.
			 */
			if ( others ) {
				var choice = el(
					'select',
					{
						class: 'qs-import__planned-pick',
						'aria-label': sprintf(
							/* translators: %s: the address two pages both want. */
							__( 'Which version of %s to build', 'qwerty-soft-signal' ),
							'/' + ( page.slug || '' ) + '/'
						),
						onChange: function ( event ) {
							state.pick[ page.slug || page.file ] = event.target.value;
							render();
						},
					},
					others.map( function ( option ) {
						return el( 'option', {
							value: option.file,
							text: sprintf(
								/* translators: 1: the folder the version came from, 2: its section count. */
								__( '%1$s — %2$d sections', 'qwerty-soft-signal' ),
								treeOf( option ) || '/',
								Number( option.sections ) || 0
							),
						} );
					} )
				);

				choice.value = page.file;

				row.push(
					el( 'span', { class: 'qs-import__planned-versions' }, [
						el( 'span', {
							class: 'qs-import__planned-clash',
							text: sprintf(
								/* translators: %d: how many versions of this page the archive holds. */
								__( '%d versions of this page', 'qwerty-soft-signal' ),
								others.length
							),
						} ),
						choice,
					] )
				);
			}

			list.appendChild(
				el( 'li', { class: 'qs-import__planned-row' + ( others ? ' is-clash' : '' ) }, row )
			);
		} );

		return el( 'details', { class: 'qs-import__planned-wrap', open: pages.length <= 20 ? 'open' : null }, [
			el( 'summary', {
				text: sprintf(
					/* translators: %d: how many pages the build will make. */
					_n(
						'The page this build will make',
						'The %d pages this build will make',
						pages.length,
						'qwerty-soft-signal'
					),
					pages.length
				),
			} ),
			list,
		] );
	}

	/*
	 * Which application in the archive, when the archive holds more than one.
	 *
	 * A handoff package is regularly three projects in a trench coat: the
	 * site, an admin panel, a widget that is meant to ship as a plugin. Only
	 * one of them belongs on this WordPress site, and nothing in the code can
	 * tell which — the difference is a product decision, not a fact about the
	 * files. So all of them are listed with what is known about each, one is
	 * chosen, and its pages can be previewed before anything is imported.
	 */
	function renderProjectChoice( source ) {
		var projects = source.projects || [];

		if ( projects.length < 2 ) {
			return null;
		}

		var list = el( 'ul', { class: 'qs-import__projects' } );

		projects.forEach( function ( project ) {
			var input = el( 'input', {
				type: 'radio',
				name: 'qs-import-project',
				id: 'qs-import-project-' + project.dir.replace( /[^a-z0-9]+/gi, '-' ),
				onChange: function () {
					state.source.loading = true;
					render();
					loadProject( source.slug, project.dir );
				},
			} );

			input.checked = !! project.chosen;

			list.appendChild(
				el( 'li', { class: 'qs-import__project' + ( project.chosen ? ' is-chosen' : '' ) }, [
					el( 'label', { for: input.id }, [
						input,
						el( 'span', { class: 'qs-import__project-body' }, [
							el( 'span', { class: 'qs-import__project-name', text: project.name } ),
							el( 'span', {
								class: 'qs-import__project-meta',
								text: sprintf(
									/* translators: 1: framework, 2: pages it serves, 3: component files. */
									__( '%1$s · %2$d pages · %3$d components', 'qwerty-soft-signal' ),
									'vue' === project.framework ? 'Vue' : 'React',
									project.routes,
									project.components
								),
							} ),
							el( 'span', { class: 'qs-import__project-dir', text: project.dir || '/' } ),
						] ),
					] ),
				] )
			);
		} );

		return el( 'div', { class: 'qs-import__projects-wrap' }, [
			el( 'p', {
				class: 'qs-import__hint',
				text: sprintf(
					/* translators: %d: how many applications the archive holds. */
					__(
						'This archive holds %d applications. Only one of them becomes this site — pick it, then preview a page before importing anything.',
						'qwerty-soft-signal'
					),
					projects.length
				),
			} ),
			list,
		] );
	}

	function renderSource() {
		var source = state.source;

		if ( ! source || source.loading || ! source.found ) {
			return null;
		}

		var routes = ( source.routes || [] ).filter( function ( route ) {
			return ! route.dynamic;
		} );

		var dynamic = ( source.routes || [] ).filter( function ( route ) {
			return route.dynamic;
		} );

		/*
		 * No routes is normally nothing to show — except when the archive
		 * holds several applications and the one being looked at happens to
		 * be the empty one. Hiding the panel there would hide the chooser
		 * too, and with it the only way back to the application that does
		 * have pages.
		 */
		var several = ( source.projects || [] ).length > 1;

		if ( ! routes.length && ! several ) {
			return null;
		}

		var reading = state.reading && state.reading.running;

		var body = [
			el( 'h3', { text: __( 'Read the pages out of the application', 'qwerty-soft-signal' ) } ),
		];

		var chooser = renderProjectChoice( source );

		if ( chooser ) {
			body.push( chooser );
		}

		// Chosen an application that serves nothing: say so, and leave the chooser up.
		if ( ! routes.length ) {
			body.push(
				el( 'p', {
					class: 'qs-import__verdict',
					text: __(
						'This one has no pages of its own — no router and no page components. Pick another above.',
						'qwerty-soft-signal'
					),
				} )
			);

			return el( 'div', { class: 'qs-import__source' }, body );
		}

		body.push(
			el( 'p', {
				class: 'qs-import__hint',
				text: sprintf(
					/* translators: 1: framework name, e.g. React, 2: number of pages. */
					__( 'This design is a %1$s application: its %2$d pages exist as components, not as markup. Claude reads each route — the page component, everything it renders, the layout around it and the stylesheet — and writes down the page it produces. From then on it is an ordinary page here: preview it, convert it, build it.', 'qwerty-soft-signal' ),
					'vue' === source.framework ? 'Vue' : 'React',
					routes.length
				),
			} )
		);

		if ( ! modelReady() ) {
			body.push(
				el( 'p', {
					class: 'qs-import__verdict',
					text: sprintf(
						/* translators: %s: why no model can be reached. */
						__( 'This is the one thing on this screen that cannot be done offline, and no model can be reached from here. %s', 'qwerty-soft-signal' ),
						( state.model && state.model.reason ) || ''
					),
				} )
			);
		} else {
			body.push(
				el( 'p', {
					class: 'qs-import__estimate',
					text: 'cli' === ( state.model && state.model.route )
						? sprintf(
							/* translators: %d: number of pages. */
							_n(
								'%d call through Claude Code on this machine, which uses the subscription it is signed in to. Nothing is billed.',
								'One call per page — %d in all — through Claude Code on this machine, which uses the subscription it is signed in to. Nothing is billed.',
								routes.length,
								'qwerty-soft-signal'
							),
							routes.length
						)
						: sprintf(
							/* translators: %d: number of pages. */
							_n(
								'%d call through the Anthropic API. A page of components is a long read, so expect this one to cost more than a section conversion.',
								'One call per page — %d in all — through the Anthropic API. A page of components is a long read, so expect these to cost more than a section conversion.',
								routes.length,
								'qwerty-soft-signal'
							),
							routes.length
						),
				} )
			);
		}

		var list = el( 'ul', { class: 'qs-import__source-routes' } );

		routes.forEach( function ( route ) {
			var done = route.rendered;

			list.appendChild(
				el( 'li', { class: 'qs-import__source-route' + ( done ? ' is-read' : '' ) }, [
					el( 'span', { class: 'qs-import__source-route-main' }, [
						el( 'span', { class: 'qs-import__page-title', text: route.title } ),
						el( 'span', {
							class: 'qs-import__page-meta',
							text: route.path + ' · ' + route.component,
						} ),
						done
							? el( 'span', {
								class: 'qs-import__tag is-done',
								text: sprintf(
									/* translators: 1: number of sections, 2: number of words. */
									__( 'read — %1$d sections, %2$d words', 'qwerty-soft-signal' ),
									done.sections,
									done.words
								),
							} )
							: null,
					] ),
					el( 'button', {
						type: 'button',
						class: 'button',
						disabled: reading || ! modelReady() ? 'disabled' : null,
						text: done ? __( 'Read again', 'qwerty-soft-signal' ) : __( 'Read', 'qwerty-soft-signal' ),
						onClick: function () {
							readRoutes( [ route ] );
						},
					} ),
				] )
			);
		} );

		body.push( list );

		if ( state.reading ) {
			var bar = el( 'progress', {
				class: 'qs-import__bar',
				max: String( state.reading.total ),
				value: String( state.reading.done ),
			} );

			body.push( bar );

			if ( state.reading.errors.length ) {
				body.push( bullets( state.reading.errors, 'qs-import__error-list' ) );
			}
		}

		var actions = [];

		if ( reading ) {
			actions.push(
				el( 'button', {
					type: 'button',
					class: 'button',
					text: __( 'Stop after this page', 'qwerty-soft-signal' ),
					onClick: function () {
						state.reading.running = false;
						say( __( 'Stopping — the page being read now will finish.', 'qwerty-soft-signal' ) );
						render();
					},
				} )
			);
		} else {
			actions.push(
				el( 'button', {
					type: 'button',
					class: 'button button-primary',
					disabled: modelReady() ? null : 'disabled',
					text: __( 'Read every page', 'qwerty-soft-signal' ),
					onClick: function () {
						readRoutes( routes );
					},
				} )
			);

			var unread = routes.filter( function ( route ) {
				return ! route.rendered;
			} );

			if ( unread.length && unread.length !== routes.length ) {
				actions.push(
					el( 'button', {
						type: 'button',
						class: 'button',
						disabled: modelReady() ? null : 'disabled',
						text: sprintf(
							/* translators: %d: number of pages not read yet. */
							__( 'Read the %d not read yet', 'qwerty-soft-signal' ),
							unread.length
						),
						onClick: function () {
							readRoutes( unread );
						},
					} )
				);
			}
		}

		body.push( el( 'p', { class: 'qs-import__actions' }, actions ) );

		/*
		 * A route with a parameter in it is not a page, it is a page per
		 * record. Reading one would produce a page about whichever product
		 * happened to be first, which is a worse answer than none.
		 */
		if ( dynamic.length ) {
			var details = el( 'details', { class: 'qs-import__skipped' }, [
				el( 'summary', {
					text: sprintf(
						/* translators: %d: number of routes. */
						_n(
							'%d route takes a parameter and is not read',
							'%d routes take a parameter and are not read',
							dynamic.length,
							'qwerty-soft-signal'
						),
						dynamic.length
					),
				} ),
				el( 'p', {
					text: __( 'These are one page per record — a product, a project, an order. Build the site from the pages above first, then add the records as posts or products; a single rendered example of each would be a page of one arbitrary record.', 'qwerty-soft-signal' ),
				} ),
			] );

			details.appendChild(
				bullets(
					dynamic.map( function ( route ) {
						return route.path + ' — ' + route.component;
					} ),
					'qs-import__built'
				)
			);

			body.push( details );
		}

		return el( 'div', { class: 'qs-import__source' }, body );
	}

	// ----------------------------------------------------------------- pages

	/*
	 * A design's pages, arranged the way a person looks for one.
	 *
	 * A real handoff produces a list nobody can read: the same page three
	 * times over because it has three languages, admin prototypes filed
	 * between the pages of the actual site, and every row carrying six folders
	 * of package name. So: one row per page with its languages on it, the
	 * site's own pages first, everything internal folded away, and a filter
	 * once there are more rows than fit in a glance.
	 */

	/** The language folder a page sits in, as the server worked it out. */
	function languageOf( page ) {
		return String( page.language || '' );
	}

	/**
	 * The same page in another language has the same key.
	 *
	 * The language folder is taken out of the path wherever it sits, so
	 * `…/v14/en/reports.html` and `…/v14/ru/reports.html` become one row and
	 * `…/v15/en/robert-ai.html` stays its own.
	 */
	function pageKey( page ) {
		var path = String( page.path || page.file || '' );
		var lang = languageOf( page );

		return lang ? path.split( '/' ).filter( function ( part, index, parts ) {
			return part !== lang || parts.indexOf( lang ) !== index;
		} ).join( '/' ) : path;
	}

	/**
	 * Which site inside the archive a page belongs to.
	 *
	 * Its path above the language folder. A handoff carries the site and then
	 * one copy of a few of its pages per feature blueprint, each showing that
	 * feature in place — so `01_Website_Baseline/…/en/reports.html` and
	 * `05_AI_Roadmap/V14_…/en/reports.html` are two versions of one page, and
	 * telling them apart starts with knowing which tree each came out of.
	 */
	function treeOf( page ) {
		var path = String( page.path || page.file || '' );
		var lang = languageOf( page );
		var parts = path.split( '/' );

		if ( ! lang ) {
			return parts.slice( 0, -1 ).join( '/' );
		}

		var at = parts.indexOf( lang );

		return at > 0 ? parts.slice( 0, at ).join( '/' ) : '';
	}

	/**
	 * One page per address, with the others kept as alternatives.
	 *
	 * Two files heading for `/reports/` is not a mistake in the archive; it is
	 * the archive saying "here is the page, and here is the page with the new
	 * section in it". Building both is the mistake — WordPress lets a draft and
	 * a published page share a slug, and the live URL then resolves to neither.
	 *
	 * The default is the fullest version — most sections — and where two are
	 * the same size, the one from the largest site in the archive. Both halves
	 * earn their place: a blueprint's `robert-ai.html` is the designed page
	 * while the baseline site has a five-section stub of it, and a blueprint's
	 * `reports.html` is the same page as the site's with one section swapped.
	 * Size picks the first correctly; the tie-break picks the second. The
	 * screen lets either be changed per address.
	 */
	function dedupeBySlug( pages ) {
		var design = state.design;
		var sizes = {};

		( ( design && design.pages ) || [] ).forEach( function ( page ) {
			var tree = treeOf( page );

			sizes[ tree ] = ( sizes[ tree ] || 0 ) + 1;
		} );

		var groups = {};
		var order = [];

		pages.forEach( function ( page ) {
			var slug = page.slug || page.file;

			if ( ! groups[ slug ] ) {
				groups[ slug ] = [];
				order.push( slug );
			}

			groups[ slug ].push( page );
		} );

		return order.map( function ( slug ) {
			var group = groups[ slug ];

			if ( 1 === group.length ) {
				return group[ 0 ];
			}

			var picked = null;

			if ( state.pick[ slug ] ) {
				group.forEach( function ( page ) {
					if ( page.file === state.pick[ slug ] ) {
						picked = page;
					}
				} );
			}

			if ( ! picked ) {
				picked = group.slice().sort( function ( a, b ) {
					return (
						( Number( b.sections ) || 0 ) - ( Number( a.sections ) || 0 ) ||
						( sizes[ treeOf( b ) ] || 0 ) - ( sizes[ treeOf( a ) ] || 0 )
					);
				} )[ 0 ];
			}

			picked.alternatives = group;

			return picked;
		} );
	}

	/**
	 * The losing versions of every address, across every language.
	 *
	 * The screen decides which file wins `/reports/` for the language it is
	 * showing; the build may be running all three. So the choice is applied by
	 * tree — whichever site a version came out of, its whole language set goes
	 * or stays together — and what comes back is the list of files the build
	 * must leave alone.
	 */
	function excludedFiles() {
		var design = state.design;

		if ( ! design || ! design.pages ) {
			return [];
		}

		var kept = {};

		plannedPages().forEach( function ( page ) {
			if ( page.alternatives && page.alternatives.length > 1 ) {
				kept[ page.slug || page.file ] = treeOf( page );
			}
		} );

		return design.pages
			.filter( function ( page ) {
				var slug = page.slug || '';

				return kept[ slug ] !== undefined && treeOf( page ) !== kept[ slug ];
			} )
			.map( function ( page ) {
				return page.file;
			} );
	}

	/** Whether a page belongs to the site or to the tooling around it. */
	function isUtility( page ) {
		return /(^|\/)(admin|admin_private|customer|private|internal|prototype|prototypes|dashboard)(\/|$)/i.test( String( page.path || page.file || '' ) );
	}

	/**
	 * Group the language variants of each page into one row.
	 *
	 * @param {Array} pages Page rows from the index.
	 * @return {Array} One entry per page, each with every language it has.
	 */
	function groupPages( pages ) {
		var order = [];
		var byKey = {};

		pages.forEach( function ( page ) {
			var key = pageKey( page );

			if ( ! byKey[ key ] ) {
				byKey[ key ] = { key: key, pages: [], utility: isUtility( page ) };
				order.push( key );
			}

			byKey[ key ].pages.push( page );
		} );

		return order.map( function ( key ) {
			var group = byKey[ key ];

			/*
			 * The row speaks for the language being built, so that is the one
			 * whose title, section count and preview the row shows.
			 */
			group.pages.sort( function ( a, b ) {
				var wanted = state.language || '';
				var rank = function ( page ) {
					var lang = languageOf( page );

					if ( wanted && lang === wanted ) {
						return 0;
					}

					return lang ? 1 : 2;
				};

				return rank( a ) - rank( b ) || ( b.sections || 0 ) - ( a.sections || 0 );
			} );

			group.lead = group.pages[ 0 ];

			return group;
		} );
	}

	function renderPages() {
		if ( ! state.design ) {
			return null;
		}

		var design = state.design;
		var groups = groupPages( design.pages || [] );
		var needle = ( state.pageFilter || '' ).trim().toLowerCase();

		if ( needle ) {
			groups = groups.filter( function ( group ) {
				return (
					String( group.lead.title || '' ).toLowerCase().indexOf( needle ) >= 0 ||
					String( group.key || '' ).toLowerCase().indexOf( needle ) >= 0
				);
			} );
		}

		var site = groups.filter( function ( group ) {
			return ! group.utility;
		} );

		var utility = groups.filter( function ( group ) {
			return group.utility;
		} );

		/** One row: the page, its languages, and the button that opens it. */
		var row = function ( group ) {
			var page = group.lead;
			var active = state.page && state.page.file === page.file;
			var previewing = state.preview && state.preview.file === page.file;
			var chosen = state.includes[ page.file ];

			var facts = page.shell
				? __( 'an empty app shell, nothing to convert', 'qwerty-soft-signal' )
				: sprintf(
					/* translators: 1: number of sections, 2: number of words. */
					__( '%1$d sections · %2$d words', 'qwerty-soft-signal' ),
					page.sections,
					page.words || 0
				);

			/*
			 * The card is the list item, not a button.
			 *
			 * It used to be one button holding everything, with the language
			 * chips nested inside it — a button inside a button, which is
			 * invalid, and which browsers resolve by guessing. Now the title
			 * is the one thing that chooses the page, and every other control
			 * sits beside it on a footer of its own.
			 */
			var body = [
				el( 'button', {
					type: 'button',
					class: 'qs-import__page',
					'aria-pressed': active ? 'true' : 'false',
					title: page.file,
					onClick: function () {
						choosePage( page );
					},
				}, [
					el( 'span', { class: 'qs-import__page-title', text: page.title } ),
					el( 'span', { class: 'qs-import__page-path', text: shortPath( group.key || page.file ) } ),
				] ),
				el( 'p', { class: 'qs-import__page-facts', text: facts } ),
			];

			if ( chosen ) {
				body.push(
					el( 'p', {
						class: 'qs-import__page-kept',
						text: sprintf(
							/* translators: %d: number of sections chosen for the build. */
							_n( '%d section kept', '%d sections kept', chosen.length, 'qwerty-soft-signal' ),
							chosen.length
						),
					} )
				);
			}

			var foot = el( 'div', { class: 'qs-import__page-foot' } );

			/*
			 * The languages this page exists in, as chips rather than as three
			 * more rows. Pressing one opens that translation.
			 */
			if ( group.pages.length > 1 ) {
				var langs = el( 'span', { class: 'qs-import__langs', role: 'group', 'aria-label': __( 'Language', 'qwerty-soft-signal' ) } );

				group.pages.forEach( function ( variant ) {
					var code = languageOf( variant ) || '—';
					var here = state.page && state.page.file === variant.file;

					langs.appendChild(
						el( 'button', {
							type: 'button',
							class: 'qs-import__lang' + ( here ? ' is-active' : '' ),
							'aria-pressed': here ? 'true' : 'false',
							title: variant.file,
							text: code.toUpperCase(),
							onClick: function () {
								choosePage( variant );
							},
						} )
					);
				} );

				foot.appendChild( langs );
			} else {
				foot.appendChild( el( 'span' ) );
			}

			foot.appendChild(
				el( 'button', {
					type: 'button',
					class: 'button qs-import__page-preview',
					'aria-pressed': previewing ? 'true' : 'false',
					text: previewing ? __( 'Previewing', 'qwerty-soft-signal' ) : __( 'Preview', 'qwerty-soft-signal' ),
					onClick: function () {
						openPreview( page );
					},
				} )
			);

			body.push( foot );

			return el( 'li', { class: 'qs-import__page-item' + ( active ? ' is-active' : '' ) }, body );
		};

		var list = el( 'ul', { class: 'qs-import__pages' } );

		site.forEach( function ( group ) {
			list.appendChild( row( group ) );
		} );

		var children = [ el( 'h2', { text: __( '2. Pick a page', 'qwerty-soft-signal' ) } ) ];

		/*
		 * What the archive turned out to be, when it is not a static design.
		 * Said once, here, rather than as "nothing on this page could be
		 * converted" repeated for every page after a build has run.
		 */
		if ( design.diagnosis ) {
			children.push( el( 'p', { class: 'qs-import__verdict', text: design.diagnosis } ) );
		}

		var notes = renderNotes();

		if ( notes ) {
			children.push( notes );
		}

		var source = renderSource();

		if ( source ) {
			children.push( source );
		}

		if ( design.languages && design.languages.length > 1 ) {
			children.push(
				el( 'p', {
					class: 'qs-import__hint',
					text: sprintf(
						/* translators: %s: comma-separated language codes. */
						__( 'This design has %s. Import one language first; the others can be added later with a translation plugin.', 'qwerty-soft-signal' ),
						design.languages.join( ', ' )
					),
				} )
			);
		}

		if ( design.skipped && design.skipped.length ) {
			var listed = design.skipped.length;
			var total = design.dropped || listed;

			var skipped = el( 'details', { class: 'qs-import__skipped' }, [
				el( 'summary', {
					text: total > listed
						? sprintf(
							/* translators: 1: total files not unpacked, 2: how many of them are listed. */
							__( '%1$d files were not unpacked (first %2$d listed)', 'qwerty-soft-signal' ),
							total,
							listed
						)
						: sprintf(
							/* translators: %d: number of files. */
							__( '%d files were not unpacked', 'qwerty-soft-signal' ),
							listed
						),
				} ),
			] );

			var ul = el( 'ul', {} );
			design.skipped.forEach( function ( line ) {
				ul.appendChild( el( 'li', { text: line } ) );
			} );
			skipped.appendChild( ul );
			children.push( skipped );
		}

		children.push( renderAuto( design ) );

		/*
		 * A filter, once the list is longer than a glance. A twenty-page
		 * handoff is not read, it is searched.
		 */
		if ( ( design.pages || [] ).length > 8 ) {
			var filter = el( 'input', {
				type: 'search',
				id: 'qs-import-filter',
				class: 'qs-import__field qs-import__filter',
				placeholder: sprintf(
					/* translators: %d: number of pages. */
					__( 'Filter %d pages…', 'qwerty-soft-signal' ),
					( design.pages || [] ).length
				),
				onInput: function ( event ) {
					state.pageFilter = event.target.value;
					render();

					var again = document.getElementById( 'qs-import-filter' );

					if ( again ) {
						again.focus();
						again.setSelectionRange( again.value.length, again.value.length );
					}
				},
			} );

			filter.value = state.pageFilter || '';

			children.push( filter );
		}

		children.push( list );

		/*
		 * The tooling that came with the design — admin prototypes, customer
		 * dashboards — is real and importable and almost never what somebody
		 * is looking for, so it is here and folded.
		 */
		if ( utility.length ) {
			var extra = el( 'ul', { class: 'qs-import__pages' } );

			utility.forEach( function ( group ) {
				extra.appendChild( row( group ) );
			} );

			children.push(
				el( 'details', { class: 'qs-import__utility' }, [
					el( 'summary', {
						text: sprintf(
							/* translators: %d: number of pages. */
							_n( '%d admin or utility page', '%d admin and utility pages', utility.length, 'qwerty-soft-signal' ),
							utility.length
						),
					} ),
					el( 'p', {
						class: 'qs-import__hint',
						text: __( 'Screens the design ships for running the site rather than for visiting it. Import them if you want them; most sites do not.', 'qwerty-soft-signal' ),
					} ),
					extra,
				] )
			);
		}

		children.push( renderPreview() );

		return el( 'section', { class: 'qs-import__step' }, children );
	}

	// --------------------------------------------------------------- preview

	/*
	 * What the build would make of one page, before it makes it. The server
	 * runs the very same conversion the build does and sends both halves
	 * back; the only decision left here is which sections to keep.
	 */
	function openPreview( page ) {
		if ( state.preview && state.preview.file === page.file && state.preview.data ) {
			state.preview = null;
			render();
			return;
		}

		state.preview = { file: page.file, loading: true };

		// The stylesheet this page links, not the pile the whole handoff holds.
		loadDesignCss( state.design.slug, page.file );

		render();
		say( sprintf( /* translators: %s: page title. */ __( 'Converting “%s” for the preview…', 'qwerty-soft-signal' ), page.title ) );

		apiFetch( {
			path:
				'/qwerty-soft-signal/v1/designs/' +
				encodeURIComponent( state.design.slug ) +
				'/preview?file=' +
				encodeURIComponent( page.file ),
		} )
			.then( function ( data ) {
				if ( ! state.preview || state.preview.file !== page.file ) {
					return;
				}

				state.preview = { file: page.file, data: data };

				// Everything in by default; a section is left out on purpose, never by omission.
				if ( ! state.includes[ page.file ] ) {
					state.includes[ page.file ] = data.sections.map( function ( section ) {
						return section.position;
					} );
				}

				say(
					sprintf(
						/* translators: 1: page title, 2: number of sections. */
						__( '“%1$s” previewed: %2$d sections. Untick any you do not want built.', 'qwerty-soft-signal' ),
						data.title,
						data.sections.length
					)
				);
				render();
				scrollToPreview();
			} )
			.catch( function ( error ) {
				if ( state.preview && state.preview.file === page.file ) {
					state.preview = { file: page.file, error: errorText( error ) };
				}

				say( errorText( error ), true );
				render();
			} );
	}

	function scrollToPreview() {
		var pane = document.getElementById( 'qs-import-preview' );

		if ( pane && pane.scrollIntoView ) {
			pane.scrollIntoView( { block: 'start' } );
		}
	}

	function isIncluded( file, position ) {
		var chosen = state.includes[ file ];

		return ! chosen || chosen.indexOf( position ) !== -1;
	}

	function setIncluded( file, position, on ) {
		var chosen = ( state.includes[ file ] || [] ).filter( function ( item ) {
			return item !== position;
		} );

		if ( on ) {
			chosen.push( position );
			chosen.sort( function ( a, b ) {
				return a - b;
			} );
		}

		state.includes[ file ] = chosen;
	}

	function renderPreview() {
		var preview = state.preview;

		if ( ! preview ) {
			return null;
		}

		var wrap = el( 'div', { id: 'qs-import-preview', class: 'qs-import__compare-wrap', tabindex: '-1' } );

		if ( preview.loading ) {
			wrap.appendChild( el( 'p', { class: 'qs-import__pending', text: __( 'Converting the page…', 'qwerty-soft-signal' ) } ) );
			return wrap;
		}

		if ( preview.error ) {
			wrap.appendChild( el( 'p', { class: 'qs-import__error', text: preview.error } ) );
			return wrap;
		}

		var data = preview.data;
		var file = preview.file;
		var total = data.sections.length;
		var included = data.sections.filter( function ( section ) {
			return isIncluded( file, section.position );
		} ).length;

		var head = el( 'div', { class: 'qs-import__compare-head' }, [
			el( 'h3', {
				text: sprintf(
					/* translators: %s: page title. */
					__( 'Preview: %s', 'qwerty-soft-signal' ),
					data.title
				),
			} ),
			el( 'p', {
				class: 'qs-import__compare-count',
				role: 'status',
				text: sprintf(
					/* translators: 1: sections included, 2: sections on the page. */
					__( '%1$d of %2$d sections included', 'qwerty-soft-signal' ),
					included,
					total
				),
			} ),
			el( 'p', { class: 'qs-import__actions' }, [
				el( 'button', {
					type: 'button',
					class: 'button' + ( state.compare ? ' button-primary' : '' ),
					'aria-pressed': state.compare ? 'true' : 'false',
					text: __( 'Show the design beside it', 'qwerty-soft-signal' ),
					onClick: function () {
						state.compare = ! state.compare;
						render();
					},
				} ),
				el( 'button', {
					type: 'button',
					class: 'button',
					disabled: included === total ? 'disabled' : null,
					text: __( 'Include all', 'qwerty-soft-signal' ),
					onClick: function () {
						state.includes[ file ] = data.sections.map( function ( section ) {
							return section.position;
						} );
						render();
					},
				} ),
				el( 'button', {
					type: 'button',
					class: 'button',
					text: __( 'Close preview', 'qwerty-soft-signal' ),
					onClick: function () {
						state.preview = null;
						render();
					},
				} ),
			] ),
		] );

		wrap.appendChild( head );

		wrap.appendChild(
			el( 'p', {
				class: 'qs-import__hint',
				text: state.compare
					? __( 'Left: the design as uploaded. Right: the same section as blocks. Untick a section to leave it out of this page.', 'qwerty-soft-signal' )
					: __( 'Each section as the built page will show it, full width. Untick one to leave it out.', 'qwerty-soft-signal' ),
			} )
		);

		if ( data.notes && data.notes.length ) {
			wrap.appendChild( bullets( data.notes, 'qs-import__notes' ) );
		}

		var rows = el( 'ol', { class: 'qs-import__compare' } );

		data.sections.forEach( function ( section ) {
			rows.appendChild( renderCompareRow( file, section ) );
		} );

		wrap.appendChild( rows );

		return wrap;
	}

	function renderCompareRow( file, section ) {
		var id = 'qs-include-' + section.position;
		var on = isIncluded( file, section.position );

		var box = el( 'input', {
			type: 'checkbox',
			id: id,
			onChange: function ( event ) {
				setIncluded( file, section.position, !! event.target.checked );
				render();
			},
		} );

		box.checked = on;

		/*
		 * A pane is an iframe with the design's own stylesheet behind it.
		 *
		 * Without it the markup showed with none of its styling — a column of
		 * unstyled text that looked like a broken import rather than like the
		 * design — and the blocks pointed their classes at rules nothing had
		 * loaded. An iframe is what lets a whole stylesheet in without any of
		 * it reaching the admin page around it.
		 *
		 * What is shown is the section as the built page will show it, at the
		 * width it will have. The design beside it is a second opinion, not
		 * the default: two half-width panes made every section look wrong,
		 * because a layout written for a page is not a layout for a column.
		 */
		var cap = state.compare ? 640 : 1400;

		var blocks = section.preview_html
			? frame( section.preview_html, 'qs-import__preview', cap )
			: el( 'div', { class: 'qs-import__preview' }, [
				el( 'p', { class: 'qs-import__pending', text: __( 'Nothing here could become blocks.', 'qwerty-soft-signal' ) } ),
			] );

		var body = [
			el( 'div', { class: 'qs-import__compare-row-head' }, [
				el( 'span', { class: 'qs-import__choice' }, [
					box,
					el( 'label', { for: id, text: ' ' + __( 'Include', 'qwerty-soft-signal' ) } ),
				] ),
				el( 'strong', { text: section.label } ),
			] ),
		];

		if ( section.concerns && section.concerns.length ) {
			body.push( bullets( section.concerns, 'qs-import__concerns' ) );
		}

		if ( ! state.compare ) {
			body.push( el( 'div', { class: 'qs-import__compare-panes is-single' }, [ blocks ] ) );

			return el( 'li', { class: 'qs-import__compare-row' + ( on ? '' : ' is-excluded' ) }, body );
		}

		body.push(
			el( 'div', { class: 'qs-import__compare-panes' }, [
				el( 'div', { class: 'qs-import__compare-pane' }, [
					el( 'h4', { text: __( 'Design', 'qwerty-soft-signal' ) } ),
					frame( section.original_html, 'qs-import__preview qs-import__preview--design', cap ),
				] ),
				el( 'div', { class: 'qs-import__compare-pane' }, [
					el( 'h4', { text: __( 'Blocks', 'qwerty-soft-signal' ) } ),
					blocks,
				] ),
			] )
		);

		return el( 'li', { class: 'qs-import__compare-row' + ( on ? '' : ' is-excluded' ) }, body );
	}

	function choosePage( page ) {
		say( __( 'Reading the page…', 'qwerty-soft-signal' ) );

		apiFetch( {
			path:
				'/qwerty-soft-signal/v1/designs/' +
				encodeURIComponent( state.design.slug ) +
				'/sections?file=' +
				encodeURIComponent( page.file ),
		} )
			.then( function ( result ) {
				state.page = { file: page.file, title: result.title, lang: result.lang };
				state.sections = result.sections;
				state.savedPage = null;
				state.spend = result.spend || null;
				state.limit = result.limit || null;

				// Anything converted earlier for this page comes back with it,
				// so closing the tab mid-run costs the time it took to reopen
				// and nothing else.
				state.results = {};
				Object.keys( result.resume || {} ).forEach( function ( position ) {
					state.results[ position ] = result.resume[ position ];
				} );

				var resumed = Object.keys( state.results ).length;

				say(
					resumed
						? sprintf(
								/* translators: 1: number of sections on the page, 2: number already converted. */
								__( 'Found %1$d sections. %2$d were already converted earlier and have been brought back.', 'qwerty-soft-signal' ),
								result.sections.length,
								resumed
						  )
						: sprintf(
								/* translators: %d: number of sections. */
								__( 'Found %d sections on this page.', 'qwerty-soft-signal' ),
								result.sections.length
						  )
				);
				render();
			} )
			.catch( function ( error ) {
				say( errorText( error ), true );
			} );
	}

	/*
	 * The choice that puts a model in the loop, and everything the person
	 * pressing it deserves to know first: which route it will take, what it
	 * will cost, and how much longer it will take. Off unless chosen — the
	 * structural build stays the default, and stays free.
	 */
	function renderSmartChoice() {
		if ( state.modelChecking ) {
			return el( 'p', {
				class: 'qs-import__hint',
				text: __( 'Checking whether Claude can be reached from here…', 'qwerty-soft-signal' ),
			} );
		}

		if ( ! modelReady() ) {
			var why = ( state.model && state.model.reason ) || '';

			if ( ! why && ! cfg.hasKey && ! cfg.cliFound ) {
				why = __( 'Claude Code is not installed here and no API key has been saved.', 'qwerty-soft-signal' );
			}

			if ( ! why ) {
				return null;
			}

			return el( 'p', { class: 'qs-import__hint' }, [
				el( 'strong', { text: __( 'Correcting each section with Claude is not available yet. ', 'qwerty-soft-signal' ) } ),
				el( 'span', { text: why + ' ' } ),
				el( 'span', { text: __( 'Open Connection settings above to set it up. The build below works without it.', 'qwerty-soft-signal' ) } ),
			] );
		}

		if ( ! state.smart ) {
			return null;
		}

		/*
		 * What the chosen mode will cost and how long it will take. Shown
		 * only once a mode that reaches a model is picked: until then there
		 * is nothing to spend and nothing to wait for.
		 */
		var billed = 'api' === state.model.route;
		var calls = plannedSections() * ( state.refine ? 4 : 1 );

		var lines = [
			el( 'p', {
				class: 'qs-import__estimate',
				text: billed
					? sprintf(
						/* translators: 1: number of model calls, 2: estimated cost. */
						_n(
							'About %1$d call to the Anthropic API — roughly %2$s at list prices, billed to your key.',
							'About %1$d calls to the Anthropic API — roughly %2$s at list prices, billed to your key.',
							calls,
							'qwerty-soft-signal'
						),
						calls,
						money( state.refine ? plannedCost() : plannedCost() / 4 )
					)
					: sprintf(
						/* translators: %d: number of model calls. */
						_n(
							'About %d call through Claude Code on this machine, which uses the subscription it is signed in to. Nothing is billed.',
							'About %d calls through Claude Code on this machine, which uses the subscription it is signed in to. Nothing is billed.',
							calls,
							'qwerty-soft-signal'
						),
						calls
					),
			} ),
			el( 'p', {
				class: 'qs-import__hint',
				text: state.unattended
					? __( 'This runs on the server, page by page. Close the tab if you like — the account below is waiting when you come back.', 'qwerty-soft-signal' )
					: __( 'Expect minutes rather than seconds — a page is built section by section. Closing the tab stops it where it got to, and what it has already made stays.', 'qwerty-soft-signal' ),
			} ),
		];

		return el( 'div', { class: 'qs-import__smart' }, lines );
	}

	/*
	 * How careful the build is, as one choice rather than as three switches.
	 *
	 * The panel used to ask four yes/no questions — correct each section?
	 * check the result? run on the server? — which are not four questions. A
	 * person deciding how to import a design is deciding one thing: how much
	 * care to spend on it. The switches follow from that, so the screen makes
	 * the decision and sets them.
	 */
	/*
	 * Two, not three.
	 *
	 * The middle mode — convert, then have Claude correct each section but not
	 * check the result — was a distinction without a decision. It cost the same
	 * order of time and money as the full pass and gave up the one thing that
	 * pass is for, so nobody could say when to pick it. What people actually
	 * choose between is "now, free, offline" and "as close to the design as
	 * this gets, and it will take a while".
	 */
	var BUILD_MODES = [
		{
			key: 'fast',
			smart: false,
			refine: false,
			unattended: false,
		},
		{
			key: 'checked',
			smart: true,
			refine: true,
			unattended: true,
		},
	];

	function modeByKey( key ) {
		return BUILD_MODES.filter( function ( mode ) {
			return mode.key === key;
		} )[ 0 ] || BUILD_MODES[ 0 ];
	}

	function chooseMode( key ) {
		var mode = modeByKey( key );

		state.mode = mode.key;
		state.smart = mode.smart && modelReady();
		state.refine = mode.refine && modelReady();
		state.unattended = mode.unattended && modelReady();
	}

	/** The two ways to build, as cards you pick one of. */
	function renderModes( pages ) {
		var ready = modelReady();
		var sections = plannedSections();

		var copy = {
			fast: {
				title: __( 'Straight through', 'qwerty-soft-signal' ),
				line: __( 'The structural conversion only. Free, offline, and finished in seconds.', 'qwerty-soft-signal' ),
				cost: sprintf(
					/* translators: %d: number of pages. */
					_n( '%d page · seconds · no cost', '%d pages · seconds · no cost', pages, 'qwerty-soft-signal' ),
					pages
				),
			},
			checked: {
				title: __( 'Corrected and checked', 'qwerty-soft-signal' ),
				line: __( 'Every section is converted, then read by Claude and fixed where the conversion misread the design, then rendered and compared with the original until the two agree.', 'qwerty-soft-signal' ),
				cost: sprintf(
					/* translators: 1: number of sections, 2: how long it takes, 3: cost or "no cost". */
					__( '%1$d sections · %2$s · %3$s', 'qwerty-soft-signal' ),
					sections,
					plannedTime(),
					'cli' === ( state.model && state.model.route ) ? __( 'no cost', 'qwerty-soft-signal' ) : money( plannedCost() )
				),
			},
		};

		var list = el( 'ul', { class: 'qs-import__modes' } );

		BUILD_MODES.forEach( function ( mode ) {
			var disabled = mode.smart && ! ready;
			var chosen = state.mode === mode.key;

			var input = el( 'input', {
				type: 'radio',
				name: 'qs-import-mode',
				id: 'qs-import-mode-' + mode.key,
				value: mode.key,
				disabled: disabled ? 'disabled' : null,
				onChange: function () {
					chooseMode( mode.key );
					render();
				},
			} );

			input.checked = chosen;

			list.appendChild(
				el( 'li', { class: 'qs-import__mode' + ( chosen ? ' is-chosen' : '' ) + ( disabled ? ' is-unavailable' : '' ) }, [
					el( 'label', { for: 'qs-import-mode-' + mode.key }, [
						input,
						el( 'span', { class: 'qs-import__mode-body' }, [
							el( 'span', { class: 'qs-import__mode-title', text: copy[ mode.key ].title } ),
							el( 'span', { class: 'qs-import__mode-line', text: copy[ mode.key ].line } ),
							el( 'span', { class: 'qs-import__mode-cost', text: copy[ mode.key ].cost } ),
						] ),
					] ),
				] )
			);
		} );

		return list;
	}

	/*
	 * The one-press path. It uses the structural converter rather than a
	 * model, so it needs no key, costs nothing and can be undone and repeated
	 * — which is what makes it safe to offer as the first thing on the screen
	 * rather than an expert option buried at the bottom.
	 */
	function renderAuto( design ) {
		var choices = [];

		if ( design.languages && design.languages.length > 1 ) {
			design.languages.forEach( function ( code ) {
				choices.push( el( 'option', { value: code, text: code } ) );
			} );

			/*
			 * Offered, not assumed. Building every language is what a
			 * multilingual handoff is for, and it is also three times the work
			 * and three times the cost of building one — so it is a choice
			 * somebody makes rather than a default they discover afterwards.
			 */
			choices.push(
				el( 'option', {
					value: '',
					text: sprintf(
						/* translators: %d: how many languages the design ships. */
						__( 'All %d languages', 'qwerty-soft-signal' ),
						design.languages.length
					),
				} )
			);
		}

		var language = el(
			'select',
			{
				id: 'qs-import-language',
				class: 'qs-import__language',
				onChange: function ( event ) {
					state.language = event.target.value;
				},
			},
			choices
		);

		if ( state.language ) {
			language.value = state.language;
		}

		var publish = el( 'input', {
			type: 'checkbox',
			id: 'qs-import-publish',
			onChange: function ( event ) {
				state.publish = !! event.target.checked;
			},
		} );

		publish.checked = state.publish;

		var running = state.job && state.job.running;

		/*
		 * Nothing to build from is a state worth refusing in advance. A design
		 * whose every page is an empty app shell used to run the whole build,
		 * fail on each page in turn and leave a list of identical "nothing on
		 * this page could be converted" lines to read backwards.
		 */
		var nothingToBuild = design.readable === 0 && design.pages && design.pages.length > 0;

		/*
		 * How many pages "the whole site" is, written on the button itself.
		 *
		 * "Build the whole site" is a promise about the design, and the build
		 * is about the page list — which the language filter can quietly cut
		 * down to a fraction. Somebody who presses a button that says "the
		 * whole site" and gets three pages out of twelve has been misled by
		 * this screen, whatever the list above it said. So the number goes on
		 * the button, where it cannot be missed.
		 */
		var planned = plannedPages().length;

		var label = function ( n ) {
			if ( state.smart && modelReady() ) {
				return state.refine
					? sprintf(
							/* translators: %d: how many pages will be built. */
							_n(
								'Build the whole site, corrected and reviewed — %d page',
								'Build the whole site, corrected and reviewed — %d pages',
								n,
								'qwerty-soft-signal'
							),
							n
					  )
					: sprintf(
							/* translators: %d: how many pages will be built. */
							_n(
								'Build the whole site, corrected by Claude — %d page',
								'Build the whole site, corrected by Claude — %d pages',
								n,
								'qwerty-soft-signal'
							),
							n
					  );
			}

			return sprintf(
				/* translators: %d: how many pages will be built. */
				_n( 'Build the whole site — %d page', 'Build the whole site — %d pages', n, 'qwerty-soft-signal' ),
				n
			);
		};

		/*
		 * The one prerequisite worth refusing on, and refusing before the work
		 * rather than after it.
		 *
		 * A build turns every section into a block with editable fields, and
		 * ACF Pro is what provides those fields. Without it the pages come out
		 * correct and permanently uneditable: the editor calls every section
		 * "Unsupported" and the footer has no screen to be changed from. An
		 * hour of building is a poor way to find out a plugin is missing, so
		 * the button says so instead of running.
		 */
		var needsAcf = state.model && false === state.model.acf;

		var build = el( 'button', {
			type: 'button',
			class: 'button button-primary button-hero',
			text: label( planned ),
			disabled: running || nothingToBuild || needsAcf ? 'disabled' : null,
			title: nothingToBuild
				? __( 'There is no markup in this design to build from.', 'qwerty-soft-signal' )
				: null,
			onClick: function () {
				startBuild( choices.length ? language.value : '', publish.checked );
			},
		} );

		var body = [
			el( 'h3', { text: __( 'Build the site', 'qwerty-soft-signal' ) } ),
			el( 'p', {
				class: 'qs-import__hint',
				text: __( 'Every page as a draft, with its images, menu, header and footer. Undo it and run it again as often as you like — nothing here is one-way.', 'qwerty-soft-signal' ),
			} ),
		];

		/*
		 * Said at the top, where the decision is made, and said as a thing to
		 * do rather than as a thing that is wrong.
		 */
		if ( needsAcf ) {
			body.push(
				el( 'div', { class: 'qs-import__blocked', role: 'status' }, [
					el( 'p', {
						text: __( 'Install ACF Pro before building.', 'qwerty-soft-signal' ),
					} ),
					el( 'p', {
						class: 'qs-import__hint',
						text: __( 'Each section of the design becomes a block with editable fields, and ACF Pro is what provides those fields. Built without it, the pages would be correct and could never be edited — every section would read "Unsupported" in the editor. Activate the plugin and this button turns on by itself.', 'qwerty-soft-signal' ),
					} ),
					el( 'p', { class: 'qs-import__actions' }, [
						el( 'a', {
							class: 'button',
							href: 'plugins.php',
							text: __( 'Go to Plugins', 'qwerty-soft-signal' ),
						} ),
					] ),
				] )
			);
		}

		/*
		 * The blocks a site has lost, and the one button that puts them back.
		 *
		 * A generated block is a file in the theme, and the theme is the part
		 * of a WordPress site that gets replaced wholesale — a redeploy, a
		 * database copied from somewhere else, a directory tidied by hand.
		 * When they go the pages stay perfectly intact and every section of
		 * every one of them reads "Unsupported", which looks like the content
		 * is gone. It is not, and rebuilding needs no model and takes about a
		 * second, so this is a button rather than another build.
		 */
		if ( state.model && state.model.missing_blocks > 0 ) {
			body.push(
				el( 'div', { class: 'qs-import__blocked', role: 'status' }, [
					el( 'p', {
						text: sprintf(
							/* translators: %d: how many blocks are missing. */
							_n(
								'%d block this site uses is missing from the theme.',
								'%d blocks this site uses are missing from the theme.',
								state.model.missing_blocks,
								'qwerty-soft-signal'
							),
							state.model.missing_blocks
						),
					} ),
					el( 'p', {
						class: 'qs-import__hint',
						text: __( 'Your pages are untouched — the sections read "Unsupported" only because the theme files behind them are gone. They can be written again from the design and from the pages themselves, without asking the model anything.', 'qwerty-soft-signal' ),
					} ),
					el( 'p', { class: 'qs-import__actions' }, [
						el( 'button', {
							type: 'button',
							class: 'button button-primary',
							text: __( 'Restore the missing blocks', 'qwerty-soft-signal' ),
							onClick: function ( event ) {
								repairBlocks( event.target );
							},
						} ),
					] ),
				] )
			);
		}

		body.push( renderModes( plannedPages().length ) );

		var smart = renderSmartChoice();

		if ( smart ) {
			body.push( smart );
		}

		/*
		 * The switches that are genuinely separate from how careful the build
		 * is: what language, whether the pages go live, whether the archive
		 * stays. Small, on one line, out of the way of the decision above.
		 */
		var options = [];

		if ( choices.length ) {
			options.push(
				el( 'span', { class: 'qs-import__option' }, [
					el( 'label', { for: 'qs-import-language', text: __( 'Language', 'qwerty-soft-signal' ) } ),
					language,
				] )
			);
		}

		options.push(
			el( 'span', { class: 'qs-import__option' }, [
				publish,
				el( 'label', {
					for: 'qs-import-publish',
					title: __( 'Leave this off to review each page as a draft first.', 'qwerty-soft-signal' ),
					text: __( 'Publish straight away', 'qwerty-soft-signal' ),
				} ),
			] )
		);

		/*
		 * Two switches used to live here and neither was a decision.
		 *
		 * "Keep the archive afterwards" defaulted to off, so a design was
		 * deleted the moment its build finished — and re-running it, or
		 * building the other two languages, meant uploading the ZIP again.
		 * The archive is now kept, and the clean-up panel above already has a
		 * button that removes it with its size on the label, which is a better
		 * place for that decision than a tickbox pressed an hour earlier.
		 *
		 * "Run on the server" contradicted the mode above it: the careful mode
		 * is an hour of model calls and is unattended by definition, the quick
		 * one finishes before the request does. Left as a switch, its only
		 * real use was to turn an hour-long build into one that dies with the
		 * tab.
		 */

		/*
		 * Offered only when the design has such screens. The list below folds
		 * them away and says "import them if you want them", which until now
		 * was an offer with nothing behind it: there was no way to put them in
		 * a build, only to convert them one at a time.
		 */
		var utilityCount = ( design.pages || [] ).filter( isUtility ).length;

		if ( utilityCount > 0 ) {
			var withUtility = el( 'input', {
				type: 'checkbox',
				id: 'qs-import-utility',
				onChange: function ( event ) {
					state.utility = !! event.target.checked;
					render();
				},
			} );

			withUtility.checked = state.utility;

			options.push(
				el( 'span', { class: 'qs-import__option' }, [
					withUtility,
					el( 'label', {
						for: 'qs-import-utility',
						title: __( 'Dashboards, queues, upload forms and download areas the design ships for running the site.', 'qwerty-soft-signal' ),
						text: sprintf(
							/* translators: %d: how many admin and utility screens the design ships. */
							_n(
								'Build the %d admin screen too',
								'Build the %d admin and utility screens too',
								utilityCount,
								'qwerty-soft-signal'
							),
							utilityCount
						),
					} ),
				] )
			);
		}

		body.push( el( 'p', { class: 'qs-import__options' }, options ) );

		/*
		 * The pages this build will leave out, and why, said before it starts.
		 *
		 * Two different filters cut the list down — the language folder and
		 * the tooling test — and lumping them together was worse than saying
		 * nothing: a handoff with three pages in three languages and eleven
		 * admin screens was told its other seventeen pages were "filed under
		 * another language", which is true of six of them. Each reason is
		 * counted separately and only mentioned when it applies.
		 */
		var everything = design.pages ? design.pages.length : 0;

		if ( everything > planned ) {
			var utilityPages = ( design.pages || [] ).filter( isUtility ).length;

			/*
			 * Pages of the site itself that this language leaves out. Counted
			 * against the language-filtered list rather than by subtraction,
			 * so the two filters cannot be charged for each other's pages.
			 */
			var otherLanguages = Math.max(
				0,
				everything - utilityPages - pagesInLanguage().filter( function ( page ) {
					return ! isUtility( page );
				} ).length
			);

			var reasons = [];

			if ( otherLanguages > 0 ) {
				reasons.push(
					sprintf(
						/* translators: %d: pages that exist only in the other languages. */
						_n(
							'%d is the same page in another language — set Language to “All” above to build it too.',
							'%d are the same pages in the other languages — set Language to “All” above to build them too.',
							otherLanguages,
							'qwerty-soft-signal'
						),
						otherLanguages
					)
				);
			}

			if ( ! state.utility && utilityPages > 0 ) {
				reasons.push(
					sprintf(
						/* translators: %d: admin and utility screens left out. */
						_n(
							'%d is an admin screen, listed below — tick the box to build it too.',
							'%d are admin and utility screens, listed below — tick the box to build them too.',
							utilityPages,
							'qwerty-soft-signal'
						),
						utilityPages
					)
				);
			}

			body.push(
				el( 'p', { class: 'qs-import__notice-line' }, [
					el( 'strong', {
						text: sprintf(
							/* translators: 1: pages that will be built, 2: pages in the design. */
							__( 'Building %1$d of the %2$d pages in this design.', 'qwerty-soft-signal' ),
							planned,
							everything
						),
					} ),
					el( 'span', { text: reasons.length ? ' ' + reasons.join( ' ' ) : '' } ),
				] )
			);
		}

		/*
		 * Which pages, by name and by the address each will get.
		 *
		 * A number on a button is not a list. "Build the whole site — 3 pages"
		 * looks perfectly reasonable next to a design of fourteen, and the
		 * only way anybody found out which three was to spend the hour and
		 * read the result. This is that hour, moved to before the press.
		 */
		var manifest = renderPlanned();

		if ( manifest ) {
			body.push( manifest );
		}

		body.push(
			el( 'p', { class: 'qs-import__actions' }, [
				build,
				el( 'button', {
					type: 'button',
					class: 'button qs-import__danger',
					text: __( 'Delete everything and start over', 'qwerty-soft-signal' ),
					title: __( 'Removes only what an import created. Your own pages are left alone.', 'qwerty-soft-signal' ),
					onClick: function () {
						state.confirmingReset = 'auto';
						state.confirmingPurge = false;
						render();
						focusConfirm();
					},
				} ),
			] )
		);

		// Every confirmation appears under the button that asked for it.
		if ( 'auto' === state.confirmingReset ) {
			body.push( renderResetConfirm() );
		}

		if ( state.job ) {
			body.push( renderProgress( state.job ) );
		}

		body.push( renderRoutes() );

		return el( 'div', { class: 'qs-import__auto' }, body );
	}

	/*
	 * How far the build has got.
	 *
	 * A bar and a sentence answer "how long" and nothing else. What a person
	 * watching an hour-long build actually wants is the list: which pages are
	 * already made, which one is being made now and how long it has been at
	 * it, which failed and why, and what is still to come. The list is that,
	 * one row per step, and the bar stays because assistive tech reads a real
	 * <progress> as one.
	 */
	function renderProgress( job ) {
		var body = [];

		body.push( renderProgressHead( job ) );

		if ( job.total ) {
			body.push(
				el( 'progress', {
					class: 'qs-import__bar',
					max: String( job.total ),
					value: String( job.done ),
					'aria-describedby': 'qs-import-progress-text',
				} )
			);
		}

		body.push(
			el( 'p', {
				id: 'qs-import-progress-text',
				class: 'qs-import__progress-text',
				text: progressText( job ),
			} )
		);

		var beat = renderBeat( job );

		if ( beat ) {
			body.push( beat );
		}

		var stall = renderStall( job );

		if ( stall ) {
			body.push( stall );
		}

		var list = renderStepList( job );

		if ( list ) {
			body.push( list );
		}

		var actions = [];

		if ( job.running ) {
			actions.push(
				el( 'button', {
					type: 'button',
					class: 'button',
					text: __( 'Stop', 'qwerty-soft-signal' ),
					disabled: state.stopping ? 'disabled' : null,
					onClick: function ( event ) {
						stopBuild( event.target );
					},
				} )
			);
		} else if ( job.stopped ) {
			/*
			 * Stopped is not cancelled, and the difference is the whole point
			 * of the button beside it: nothing was undone, and the next press
			 * carries on from the step it reached rather than from the start.
			 */
			actions.push(
				el( 'button', {
					type: 'button',
					class: 'button button-primary',
					text: __( 'Continue the build', 'qwerty-soft-signal' ),
					disabled: state.resuming ? 'disabled' : null,
					onClick: function ( event ) {
						resumeBuild( event.target );
					},
				} )
			);
		} else if ( job.cancelled || ( job.errors && job.errors.length ) ) {
			/*
			 * The confirmation is rendered here, beside the button that asked
			 * for it. It used to live only in the clean-up panel at the top of
			 * the screen — and that panel hides itself when the import created
			 * nothing, which is exactly the state a failed build leaves. So
			 * the button set a flag, the flag rendered nothing anywhere, and
			 * pressing "Delete what was built so far" did visibly nothing.
			 */
			if ( 'progress' === state.confirmingReset ) {
				body.push( renderResetConfirm() );

				return el( 'div', { class: progressClass( job ), role: 'group', 'aria-label': __( 'Build progress', 'qwerty-soft-signal' ) }, body );
			}

			actions.push(
				el( 'button', {
					type: 'button',
					class: 'button qs-import__danger',
					text: __( 'Delete what was built so far', 'qwerty-soft-signal' ),
					onClick: function () {
						state.confirmingReset = 'progress';
						state.confirmingPurge = false;
						render();
						focusConfirm();
					},
				} )
			);
			actions.push(
				el( 'button', {
					type: 'button',
					class: 'button',
					text: __( 'Dismiss', 'qwerty-soft-signal' ),
					onClick: function () {
						state.job = null;
						render();
					},
				} )
			);
		}

		if ( actions.length ) {
			body.push( el( 'p', { class: 'qs-import__actions' }, actions ) );
		}

		return el( 'div', { class: progressClass( job ), role: 'group', 'aria-label': __( 'Build progress', 'qwerty-soft-signal' ) }, body );
	}

	/** Which state the whole panel is in, said in a class so CSS can colour it. */
	function progressClass( job ) {
		var name = 'qs-import__progress';

		if ( job.finished ) {
			return name + ' is-done';
		}

		if ( job.cancelled ) {
			return name + ' is-stopped';
		}

		if ( job.errors && job.errors.length ) {
			return name + ' is-failing';
		}

		// A stopped build still counts as running; it is the panel that has to look different.
		if ( job.stall && ! state.resuming ) {
			return name + ' is-stalled';
		}

		return job.running ? name + ' is-running' : name;
	}

	/*
	 * The one line worth reading from across the room: which step of how many,
	 * and how far through that is. Everything else on this panel elaborates
	 * on it.
	 */
	function renderProgressHead( job ) {
		var count;

		if ( job.finished ) {
			count = sprintf(
				/* translators: %d: steps in total. */
				__( 'All %d steps done', 'qwerty-soft-signal' ),
				job.total
			);
		} else if ( job.total ) {
			/*
			 * "Step 7 of 18" is arithmetic, not information: it counts two
			 * preparation steps, fourteen pages, the menu and the front page
			 * as one undifferentiated eighteen. What somebody watching wants
			 * is which page — so while the build is on pages, that is what it
			 * says, and the step count stays for the parts that are not pages.
			 */
			var pages = ( job.steps || [] ).filter( function ( step ) {
				return 'page' === step.key;
			} );

			var at = -1;

			pages.forEach( function ( step, index ) {
				if ( stepKey( step ) === job.currentKey ) {
					at = index;
				}
			} );

			count = at > -1
				? sprintf(
						/* translators: 1: the page being built, 2: pages in total. */
						__( 'Page %1$d of %2$d', 'qwerty-soft-signal' ),
						at + 1,
						pages.length
				  )
				: sprintf(
						/* translators: 1: the step being worked on, 2: steps in total. */
						__( 'Step %1$d of %2$d', 'qwerty-soft-signal' ),
						Math.min( job.total, job.done + 1 ),
						job.total
				  );
		} else {
			count = __( 'Starting…', 'qwerty-soft-signal' );
		}

		var head = [ el( 'strong', { class: 'qs-import__progress-count', text: count } ) ];

		if ( job.total ) {
			head.push(
				el( 'span', {
					class: 'qs-import__progress-percent',
					/* translators: %d: how far through the build is, as a percentage. */
					text: sprintf( __( '%d%%', 'qwerty-soft-signal' ), Math.round( ( job.done / job.total ) * 100 ) ),
				} )
			);
		}

		/*
		 * A spinner over a build that has stopped is the one thing this panel
		 * must never show: it is exactly the lie that made a stalled import
		 * indistinguishable from a slow one.
		 */
		var stalled = !! job.stall && ! state.resuming;

		if ( job.running && ! stalled ) {
			head.push( el( 'span', { class: 'qs-import__spinner', 'aria-hidden': 'true' } ) );
		}

		if ( job.unattended && job.running ) {
			head.push(
				el( 'span', {
					class: 'qs-import__tag' + ( stalled ? ' is-stalled' : '' ),
					text: stalled
						? __( 'Stopped', 'qwerty-soft-signal' )
						: __( 'Running on the server', 'qwerty-soft-signal' ),
				} )
			);
		}

		return el( 'p', { class: 'qs-import__progress-head' }, head );
	}

	/*
	 * Proof of life: the last thing the build said, and how long ago.
	 *
	 * "Step 4 of 7, 43%" is the one thing this screen used to show for a
	 * quarter of an hour at a time, and a number that does not move cannot
	 * tell a build that is working from a build that has died. The counter
	 * beside this line ticks every second whether anything else changes or
	 * not, so the answer to "is it stuck?" is on the screen rather than in a
	 * log panel somebody has to think to open.
	 */
	function renderBeat( job ) {
		if ( ! job.running || job.finished || job.cancelled || ! job.beat ) {
			return null;
		}

		// A stopped build has its own, louder panel; two of them would be noise.
		if ( job.stall && ! state.resuming ) {
			return null;
		}

		var last = state.log.lines.length ? state.log.lines[ state.log.lines.length - 1 ] : null;

		return el( 'p', { class: 'qs-import__beat' }, [
			el( 'span', {
				class: 'qs-import__beat-text',
				text: last && last.message ? last.message : __( 'Working…', 'qwerty-soft-signal' ),
			} ),
			el( 'span', {
				class: 'qs-import__beat-time',
				'data-qs-elapsed': String( job.beat ),
				text: elapsed( job.beat ),
			} ),
		] );
	}

	/*
	 * A build that has stopped, said out loud, with the way out beside it.
	 *
	 * The worst version of this screen is the one that shows a spinner over a
	 * number that has not moved for an hour. A background build can genuinely
	 * stop — the process running a step dies, or WP-Cron never runs on this
	 * site at all — and neither of those announces itself. So the server says
	 * when a build has been silent for longer than a step is allowed to be,
	 * and this turns that into a sentence a person can act on and one button
	 * that acts on it, by running the next step in this request rather than
	 * waiting for a scheduler that may never come.
	 */
	function renderStall( job ) {
		if ( state.resuming ) {
			return el( 'div', { class: 'qs-import__stall is-working', role: 'status' }, [
				el( 'p', {}, [
					el( 'span', { class: 'qs-import__spinner', 'aria-hidden': 'true' } ),
					el( 'span', {
						text: __(
							'Continuing the build. This step runs in this tab, so leave the page open until it finishes.',
							'qwerty-soft-signal'
						),
					} ),
				] ),
			] );
		}

		if ( ! job.stall ) {
			return null;
		}

		var stall = job.stall;

		var body = [
			el( 'p', { class: 'qs-import__stall-head' }, [
				el( 'strong', { text: __( 'The build has stopped.', 'qwerty-soft-signal' ) } ),
			] ),
			el( 'p', {
				text: stall.between
					? sprintf(
							/* translators: 1: how long ago the last step finished, 2: the step that should be next. */
							__(
								'The last step finished %1$s ago and the next one, %2$s, has not started.',
								'qwerty-soft-signal'
							),
							elapsed( 0, stall.quiet ),
							stall.label
					  )
					: sprintf(
							/* translators: 1: what was being built, 2: how long it has been silent. */
							__( 'Nothing has happened on %1$s for %2$s.', 'qwerty-soft-signal' ),
							stall.label,
							elapsed( 0, stall.quiet )
					  ),
			} ),
			el( 'p', { text: stallReason( stall ) } ),
		];

		/*
		 * How much rope this step has left. A page is given up on after three
		 * goes and set aside for one more attempt at the end, which is worth
		 * knowing before pressing the button for the third time. Only for a
		 * step that actually died: a step that has not started yet has spent
		 * nothing.
		 */
		if ( ! stall.between && stall.attempt > 0 ) {
			body.push(
				el( 'p', {
					text: stall.left > 0
						? sprintf(
								/* translators: 1: attempts made, 2: attempts allowed. */
								__(
									'It has been started %1$d of %2$d times. After that the build sets it aside, carries on with the rest and comes back to it at the end.',
									'qwerty-soft-signal'
								),
								stall.attempt,
								stall.max
						  )
						: __(
								'It has used every attempt. Continuing now sets this step aside and moves the build on to the rest; it gets one more go once everything else is built.',
								'qwerty-soft-signal'
						  ),
				} )
			);
		}

		body.push(
			el( 'p', { class: 'qs-import__actions' }, [
				el( 'button', {
					type: 'button',
					class: 'button button-primary',
					text: __( 'Continue the build', 'qwerty-soft-signal' ),
					onClick: function ( event ) {
						resumeBuild( event.target );
					},
				} ),
			] )
		);

		return el( 'div', { class: 'qs-import__stall', role: 'status' }, body );
	}

	/*
	 * Why the build stopped, in the one sentence that is actually true of it.
	 *
	 * A booking that nothing has run means the scheduler; no booking at all
	 * means the step took the process with it. Saying the wrong one sends
	 * somebody to fix a page that was never the problem.
	 */
	function stallReason( stall ) {
		if ( stall.scheduled ) {
			return __(
				'The next step is booked, but nothing on this site has run it. That is usually WP-Cron: it only fires when somebody makes a request, so a build left alone on a quiet site waits instead of working.',
				'qwerty-soft-signal'
			);
		}

		if ( stall.between ) {
			return __( 'Nothing is booked to start it, so the build is waiting where it stands.', 'qwerty-soft-signal' );
		}

		return __(
			'The step ended without finishing and left nothing behind to carry the build on.',
			'qwerty-soft-signal'
		);
	}

	/*
	 * Carry a stopped build on by hand.
	 *
	 * The step runs inside this request rather than on cron, which is the
	 * whole point: the site whose build stopped is usually the site whose cron
	 * is the reason. It books its own next tick before it starts, so pressing
	 * this on a site with a working scheduler restarts the build for good, and
	 * on a site without one advances it a step at a time.
	 */
	function resumeBuild( button ) {
		if ( state.resuming ) {
			return;
		}

		state.resuming = true;
		state.log.busy = true;

		if ( button ) {
			button.disabled = true;
		}

		say( __( 'Continuing the build…', 'qwerty-soft-signal' ) );
		render();
		watchLog();

		apiFetch( { path: '/qwerty-soft-signal/v1/build/resume', method: 'POST' } )
			.then( function ( result ) {
				if ( state.job ) {
					state.job.done = result.done;
					state.job.total = result.total;
					state.job.stall = null;

					if ( result.finished ) {
						state.job.running = false;
						state.job.finished = true;
					}
				}

				say(
					result.finished
						? __( 'The build is complete.', 'qwerty-soft-signal' )
						: __( 'That step is done and the build has moved on.', 'qwerty-soft-signal' )
				);
			} )
			.catch( function ( error ) {
				say( errorText( error ), true );
			} )
			.then( function () {
				state.resuming = false;
				state.log.busy = false;
				render();
				watchLog();
			} );
	}

	/**
	 * Write the missing block files again, from the design and from the pages.
	 *
	 * No model, no rebuild, nothing overwritten: only the blocks the site asks
	 * for and does not have. What each field is called comes out of the pages
	 * themselves, which is why the content that is already there comes back
	 * with them rather than reappearing as a section of empty boxes.
	 *
	 * @param {HTMLElement} button The button pressed, disabled while it works.
	 */
	function repairBlocks( button ) {
		if ( state.repairing ) {
			return;
		}

		state.repairing = true;

		if ( button ) {
			button.disabled = true;
		}

		say( __( 'Writing the missing blocks…', 'qwerty-soft-signal' ) );

		apiFetch( { path: '/qwerty-soft-signal/v1/blocks/repair', method: 'POST' } )
			.then( function ( report ) {
				if ( state.model ) {
					state.model.missing_blocks = ( report.missing || [] ).length;
				}

				say(
					sprintf(
						/* translators: 1: how many blocks were written, 2: how many pages they appear on. */
						_n(
							'%1$d block written. Your pages draw the design again — reload one to see it.',
							'%1$d blocks written, across %2$d pages. Your pages draw the design again — reload one to see it.',
							report.written || 0,
							'qwerty-soft-signal'
						),
						report.written || 0,
						report.pages || 0
					)
				);
			} )
			.catch( function ( error ) {
				say( errorText( error ), true );
			} )
			.then( function () {
				state.repairing = false;
				render();
			} );
	}

	/**
	 * Stop the build on the server, not only in this tab.
	 *
	 * The button used to set a flag in the tab's own state and draw a stopped
	 * panel while the build carried on running on cron. Somebody who pressed
	 * it and then pressed Build again got two workers; somebody who closed the
	 * tab never learned it was still going. Nothing it said was true.
	 *
	 * @param {HTMLElement} button The button pressed, disabled while it works.
	 */
	function stopBuild( button ) {
		if ( state.stopping ) {
			return;
		}

		state.stopping = true;

		if ( button ) {
			button.disabled = true;
		}

		say( __( 'Stopping the build…', 'qwerty-soft-signal' ) );

		apiFetch( { path: '/qwerty-soft-signal/v1/build/stop', method: 'POST' } )
			.then( function () {
				if ( state.job ) {
					state.job.running = false;
					state.job.stopped = true;
				}

				say(
					__(
						'Build stopped. Everything it made is on the site, and Continue picks up where it left off.',
						'qwerty-soft-signal'
					)
				);
			} )
			.catch( function ( error ) {
				say( errorText( error ), true );
			} )
			.then( function () {
				state.stopping = false;
				render();
				watchLog();
			} );
	}

	/*
	 * Every step of the build, in order, each saying which of four states it
	 * is in. The two preparation steps are on the list because they are in
	 * the total: without them a build opens on "2 of 9 done" with seven rows
	 * underneath, and the arithmetic looks broken when it is not.
	 */
	function renderStepList( job ) {
		var steps = ( job.prep || [] ).concat( job.steps || [] );

		if ( ! steps.length ) {
			return null;
		}

		var list = el( 'ol', { class: 'qs-import__steps' } );

		steps.forEach( function ( step ) {
			list.appendChild( renderStepRow( job, step ) );
		} );

		return list;
	}

	function renderStepRow( job, step ) {
		var key = stepKey( step );
		var failed = job.failed ? job.failed[ key ] : '';
		var done = ! failed && isStepDone( job, step );
		var running = ! done && ! failed && !! job.running && key === job.currentKey;

		var row = [
			el( 'span', { class: 'qs-import__step-mark', 'aria-hidden': 'true' } ),
			el( 'span', { class: 'qs-import__step-name', text: stepLabel( step ) } ),
		];

		/*
		 * A time on the row that is working, and on the rows that finished.
		 * The running one counts up in place rather than on the next render,
		 * because the next render can be a minute away and a number that has
		 * not moved for a minute reads as a build that has stopped.
		 */
		if ( running && job.stepStarted ) {
			row.push(
				el( 'span', {
					class: 'qs-import__step-time',
					'data-qs-elapsed': String( job.stepStarted ),
					text: elapsed( job.stepStarted ),
				} )
			);
		} else if ( job.times && job.times[ key ] ) {
			row.push( el( 'span', { class: 'qs-import__step-time', text: elapsed( 0, job.times[ key ] ) } ) );
		}

		if ( failed ) {
			row.push( el( 'span', { class: 'qs-import__step-why', text: failed } ) );
		}

		/*
		 * A page that is finished is a page on the site, so the row that says
		 * so should be the way to it. Waiting for the whole build to end made
		 * an hour of work feel like an hour of nothing, when the home page had
		 * been readable since the fourth minute.
		 */
		var made = job.made ? job.made[ key ] : null;

		if ( done && made ) {
			row.push(
				el( 'span', { class: 'qs-import__step-links' }, [
					el( 'a', {
						class: 'qs-import__step-link',
						href: made.link,
						target: '_blank',
						rel: 'noopener',
						text: __( 'View', 'qwerty-soft-signal' ),
					} ),
					el( 'a', {
						class: 'qs-import__step-link',
						href: made.edit_link,
						text: __( 'Edit', 'qwerty-soft-signal' ),
					} ),
				] )
			);
		}

		var className = 'qs-import__step-row';

		if ( failed ) {
			className += ' is-failed';
		} else if ( running ) {
			className += ' is-running';
		} else if ( done ) {
			className += ' is-done';
		} else {
			className += ' is-waiting';
		}

		return el( 'li', { class: className }, row );
	}

	/** The key a step is recorded under, on the server and here. */
	function stepKey( step ) {
		return 'page' === step.key ? 'page:' + step.file : step.key;
	}

	function isStepDone( job, step ) {
		// The preparation steps are behind us the moment there is a job at all.
		if ( 'tokens' === step.key || 'media' === step.key ) {
			return ! job.starting;
		}

		return !! ( job.completed && job.completed[ stepKey( step ) ] );
	}

	/**
	 * A duration, written the way a stopwatch would.
	 *
	 * @param {number} since   Milliseconds since the epoch at which the step began.
	 * @param {number} seconds A duration already known, in seconds.
	 * @return {string} Minutes and seconds, or seconds alone under a minute.
	 */
	function elapsed( since, seconds ) {
		var total = undefined === seconds
			? Math.max( 0, Math.round( ( Date.now() - ( Number( since ) || Date.now() ) ) / 1000 ) )
			: Math.max( 0, Math.round( Number( seconds ) || 0 ) );

		if ( total < 60 ) {
			/* translators: %d: a number of seconds. */
			return sprintf( __( '%ds', 'qwerty-soft-signal' ), total );
		}

		return sprintf(
			/* translators: 1: minutes, 2: seconds, always two digits. */
			__( '%1$d:%2$s', 'qwerty-soft-signal' ),
			Math.floor( total / 60 ),
			String( total % 60 ).length < 2 ? '0' + ( total % 60 ) : String( total % 60 )
		);
	}

	/*
	 * The clock on the running step.
	 *
	 * A guided page is a model call per section and can sit on one row for
	 * minutes. Re-rendering the screen every second to move a number would
	 * fight every field and every scroll position on it, so this writes into
	 * the one text node that changes and touches nothing else.
	 */
	function startClock() {
		if ( state.clock ) {
			return;
		}

		state.clock = window.setInterval( function () {
			var job = state.job;

			if ( ! job || ! job.running ) {
				window.clearInterval( state.clock );
				state.clock = null;

				return;
			}

			var nodes = document.querySelectorAll( '[data-qs-elapsed]' );

			Array.prototype.forEach.call( nodes, function ( node ) {
				node.textContent = elapsed( Number( node.getAttribute( 'data-qs-elapsed' ) ) );
			} );
		}, 1000 );
	}

	function progressText( job ) {
		if ( job.finished ) {
			return __( 'The build is complete.', 'qwerty-soft-signal' );
		}

		if ( job.cancelled ) {
			/* translators: 1: steps done, 2: steps in total. */
			return sprintf( __( 'Cancelled after %1$d of %2$d steps.', 'qwerty-soft-signal' ), job.done, job.total );
		}

		if ( job.starting ) {
			return __( 'Reading the design, importing fonts and pictures…', 'qwerty-soft-signal' );
		}

		var step = job.current;

		if ( ! step ) {
			/* translators: 1: steps done, 2: steps in total. */
			return sprintf( __( '%1$d of %2$d steps done.', 'qwerty-soft-signal' ), job.done, job.total );
		}

		if ( job.smart && 'page' === step.key ) {
			return sprintf(
				/* translators: %s: what is being built. */
				__( 'Building %s with Claude, section by section — this one takes a while.', 'qwerty-soft-signal' ),
				stepLabel( step )
			);
		}

		/* translators: %s: what is being built. */
		return sprintf( __( 'Building %s.', 'qwerty-soft-signal' ), stepLabel( step ) );
	}

	function stepLabel( step ) {
		if ( 'page' === step.key ) {
			return step.title || step.file;
		}

		if ( 'chrome' === step.key ) {
			return __( 'the menu, header and footer', 'qwerty-soft-signal' );
		}

		if ( 'finish' === step.key ) {
			return __( 'the front page and links', 'qwerty-soft-signal' );
		}

		// A preparation step carries its own wording from the server.
		return step.title || step.key;
	}

	/*
	 * All three ways of converting, named in one place. The subscription route
	 * lives further down the screen, and someone who presses the big button
	 * first would never scroll far enough to learn it exists.
	 */
	function renderRoutes() {
		var rows = [
			[
				__( 'Structure only — the button above', 'qwerty-soft-signal' ),
				__( 'Free, instant, no account. Headings, lists, cards and images become blocks.', 'qwerty-soft-signal' ),
			],
			[
				__( 'Your Claude subscription', 'qwerty-soft-signal' ),
				__( 'Free with any plan. Pick a page below, copy the brief into your Claude chat, paste the reply back. Better judgement than the button.', 'qwerty-soft-signal' ),
			],
			[
				__( 'An Anthropic API key', 'qwerty-soft-signal' ),
				__( 'One click per page, no copying. Roughly one to three dollars for a whole site. Set the key in Connection settings above.', 'qwerty-soft-signal' ),
			],
			[
				__( 'Claude Code on this machine', 'qwerty-soft-signal' ),
				__( 'The same automatic route, run through the claude command instead of the API — so it uses the subscription that command is signed in to and adds nothing to a bill. Only possible where the binary is installed and PHP may start it, which usually means your own machine rather than a client\'s hosting.', 'qwerty-soft-signal' ),
			],
		];

		var list = el( 'dl', { class: 'qs-import__routes' } );

		rows.forEach( function ( row ) {
			list.appendChild( el( 'dt', { text: row[ 0 ] } ) );
			list.appendChild( el( 'dd', { text: row[ 1 ] } ) );
		} );

		return el( 'details', { class: 'qs-import__routes-wrap' }, [
			el( 'summary', { text: __( 'Four ways to convert — which should I use?', 'qwerty-soft-signal' ) } ),
			list,
		] );
	}

	/*
	 * What the build made, with somewhere to go from each row. A list of links
	 * left the editor to find the pages again under Pages; this puts view,
	 * edit and publish on the row, and the template parts beside them.
	 */
	function renderBuilt( report ) {
		var drafts = report.pages.filter( function ( page ) {
			return 'publish' !== page.status;
		} );

		var table = el( 'table', { class: 'widefat striped qs-import__table' }, [
			el( 'thead', {}, [
				el( 'tr', {}, [
					el( 'th', { scope: 'col', text: __( 'Page', 'qwerty-soft-signal' ) } ),
					el( 'th', { scope: 'col', text: __( 'Status', 'qwerty-soft-signal' ) } ),
					el( 'th', { scope: 'col', text: __( 'Actions', 'qwerty-soft-signal' ) } ),
				] ),
			] ),
		] );

		var tbody = el( 'tbody', {} );

		report.pages.forEach( function ( page ) {
			var published = 'publish' === page.status;
			var actions = [
				el( 'a', { class: 'button button-small', href: page.link || page.url, target: '_blank', rel: 'noopener', text: __( 'View', 'qwerty-soft-signal' ) } ),
				el( 'a', { class: 'button button-small', href: page.edit_link, text: __( 'Edit', 'qwerty-soft-signal' ) } ),
			];

			if ( ! published ) {
				actions.push(
					el( 'button', {
						type: 'button',
						class: 'button button-small button-primary',
						text: __( 'Publish', 'qwerty-soft-signal' ),
						onClick: function ( event ) {
							publishPages( [ page.id ], event.target );
						},
					} )
				);
			}

			tbody.appendChild(
				el( 'tr', {}, [
					el( 'td', {}, [
						el( 'strong', { text: page.title } ),

						/*
						 * A row read back off the site knows the page but not
						 * how many sections it took to make, and "(0 sections)"
						 * beside a finished page reads as a failure.
						 */
						page.sections
							? el( 'span', {
									class: 'qs-import__section-meta',
									text: ' ' + sprintf(
										/* translators: %d: number of sections. */
										__( '(%d sections)', 'qwerty-soft-signal' ),
										page.sections
									),
							  } )
							: null,
						/*
						 * Two different claims, kept apart. A wrapped section
						 * had no model call at all; reporting it as one Claude
						 * corrected would tell an editor that a review they may
						 * be paying for happened when it did not.
						 */
						page.wrapped
							? el( 'span', {
									class: 'qs-import__section-meta',
									text:
										' ' +
										sprintf(
											/* translators: %d: number of sections kept as the design wrote them. */
											_n(
												'%d became a block of its own, markup and styles as the design wrote them.',
												'%d became blocks of their own, markup and styles as the design wrote them.',
												page.wrapped,
												'qwerty-soft-signal'
											),
											page.wrapped
										),
							  } )
							: null,
						page.improved
							? el( 'span', {
									class: 'qs-import__section-meta',
									text:
										' ' +
										sprintf(
											/* translators: %d: number of sections Claude changed. */
											_n(
												'Claude corrected %d of them.',
												'Claude corrected %d of them.',
												page.improved,
												'qwerty-soft-signal'
											),
											page.improved
										),
							  } )
							: null,
						page.changed && page.changed.length
							? el( 'details', { class: 'qs-import__changes' }, [
									el( 'summary', { text: __( 'What Claude changed', 'qwerty-soft-signal' ) } ),
									foldedBullets( page.changed, 'qs-import__concerns' ),
							  ] )
							: null,
						page.concerns && page.concerns.length ? foldedBullets( page.concerns, 'qs-import__concerns' ) : null,
					] ),
					el( 'td', {}, [
						el( 'span', {
							class: 'qs-import__badge ' + ( published ? 'is-on' : 'is-off' ),
							text: published ? __( 'Published', 'qwerty-soft-signal' ) : __( 'Draft', 'qwerty-soft-signal' ),
						} ),
					] ),
					el( 'td', {}, [ el( 'span', { class: 'qs-import__row-actions' }, actions ) ] ),
				] )
			);
		} );

		table.appendChild( tbody );

		var out = [
			el( 'p', { class: 'qs-import__saved' }, [ el( 'strong', { text: __( 'The site is built.', 'qwerty-soft-signal' ) } ) ] ),
		];

		if ( report.ai && report.ai.calls ) {
			out.push(
				el( 'p', {
					class: 'qs-import__estimate',
					text:
						'cli' === report.ai.transport
							? sprintf(
									/* translators: 1: number of calls, 2: tokens in, 3: tokens out, 4: what the same work would cost through the API. */
									__(
										'%1$d calls through Claude Code on this machine — %2$s tokens in, %3$s out. Nothing was billed; the same work through the API would have cost about %4$s.',
										'qwerty-soft-signal'
									),
									report.ai.calls,
									tokens( report.ai.input ),
									tokens( report.ai.output ),
									money( report.ai.notional )
							  )
							: sprintf(
									/* translators: 1: number of calls, 2: tokens in, 3: tokens out, 4: cost. */
									__(
										'%1$d calls to the Anthropic API — %2$s tokens in, %3$s out, about %4$s.',
										'qwerty-soft-signal'
									),
									report.ai.calls,
									tokens( report.ai.input ),
									tokens( report.ai.output ),
									money( report.ai.cost )
							  ),
				} )
			);
		}

		if ( report.ai && report.ai.discarded ) {
			out.push(
				el( 'p', {
					class: 'qs-import__estimate',
					text: sprintf(
						/* translators: %d: number of replies that were thrown away. */
						_n(
							'%d reply came back as markup the editor would have rejected, so that section kept its structural conversion. The section says so in its own notes.',
							'%d replies came back as markup the editor would have rejected, so those sections kept their structural conversions. Each section says so in its own notes.',
							report.ai.discarded,
							'qwerty-soft-signal'
						),
						report.ai.discarded
					),
				} )
			);
		}

		out.push( el( 'div', { class: 'qs-import__table-wrap' }, [ table ] ) );

		if ( drafts.length ) {
			out.push(
				el( 'p', { class: 'qs-import__actions' }, [
					el( 'button', {
						type: 'button',
						class: 'button button-primary',
						text: sprintf(
							/* translators: %d: number of draft pages. */
							_n( 'Publish %d draft page', 'Publish all %d draft pages', drafts.length, 'qwerty-soft-signal' ),
							drafts.length
						),
						onClick: function ( event ) {
							publishPages(
								drafts.map( function ( page ) {
									return page.id;
								} ),
								event.target
							);
						},
					} ),
				] )
			);
		}

		var chrome = [];

		( report.parts_detail || [] ).forEach( function ( part ) {
			chrome.push(
				el( 'li', {}, [
					el( 'span', { text: part.title + ' — ' } ),
					el( 'a', { href: part.edit_link, text: __( 'Edit in the Site Editor', 'qwerty-soft-signal' ) } ),
				] )
			);
		} );

		if ( report.menu_link ) {
			chrome.push(
				el( 'li', {}, [
					el( 'span', { text: __( 'Main navigation', 'qwerty-soft-signal' ) + ' — ' } ),
					el( 'a', { href: report.menu_link, text: __( 'Edit in the Site Editor', 'qwerty-soft-signal' ) } ),
				] )
			);
		}

		if ( chrome.length ) {
			out.push( el( 'p', { class: 'qs-import__label-inline', text: __( 'Header, footer and menu:', 'qwerty-soft-signal' ) } ) );
			out.push( el( 'ul', { class: 'qs-import__built' }, chrome ) );
		}

		if ( report.concerns && report.concerns.length ) {
			out.push( el( 'p', { class: 'qs-import__label-inline', text: __( 'Worth checking:', 'qwerty-soft-signal' ) } ) );
			out.push( bullets( report.concerns, 'qs-import__concerns' ) );
		}

		return el( 'section', { class: 'qs-import__step qs-import__result', 'aria-label': __( 'What the build made', 'qwerty-soft-signal' ) }, [
			el( 'h2', { text: __( 'Pages built', 'qwerty-soft-signal' ) } ),
		].concat( out ) );
	}

	function publishPages( ids, button ) {
		button.disabled = true;
		say( __( 'Publishing…', 'qwerty-soft-signal' ) );

		apiFetch( {
			path: '/qwerty-soft-signal/v1/build/publish',
			method: 'POST',
			data: { ids: ids },
		} )
			.then( function ( result ) {
				var rows = result.pages || [];

				if ( state.built ) {
					state.built.pages = state.built.pages.map( function ( page ) {
						var fresh = rows.filter( function ( row ) {
							return row.id === page.id;
						} )[ 0 ];

						// The row from the server knows its new status; the rest is ours.
						return fresh ? Object.assign( {}, page, { status: fresh.status, link: fresh.link, url: fresh.url } ) : page;
					} );
				}

				say(
					sprintf(
						/* translators: %d: number of pages published. */
						_n( 'Published %d page.', 'Published %d pages.', rows.length, 'qwerty-soft-signal' ),
						rows.length
					)
				);
				render();
			} )
			.catch( function ( error ) {
				button.disabled = false;
				say( errorText( error ), true );
			} );
	}

	/*
	 * The build, one request per step. The server does the same work the
	 * single request used to; the difference is that a twelve-page design no
	 * longer has to fit inside one PHP timeout, and the screen can say which
	 * page it is on.
	 */
	function startBuild( language, publish ) {
		if ( state.job && state.job.running ) {
			return;
		}

		var includes = {};

		Object.keys( state.includes ).forEach( function ( file ) {
			includes[ file ] = state.includes[ file ];
		} );

		state.job = { starting: true, running: true, done: 0, total: 0, errors: [] };
		state.built = null;
		render();
		watchLog();
		say( __( 'Starting the build — reading the design, importing fonts and images…', 'qwerty-soft-signal' ) );

		apiFetch( {
			path: '/qwerty-soft-signal/v1/build/start',
			method: 'POST',
			data: {
				slug: state.design.slug,
				language: language,
				publish: !! publish,
				keep_archive: !! state.keepArchive,
				includes: includes,
				utility: !! state.utility,

				/*
				 * The versions this build is not taking. Sent as files rather
				 * than as a rule, so the server builds exactly the list the
				 * screen showed and the two cannot drift apart.
				 */
				exclude: excludedFiles(),
				smart: !! ( state.smart && modelReady() ),
				refine: !! ( state.smart && state.refine && modelReady() ),
				unattended: !! state.unattended,
			},
		} )
			.then( function ( start ) {
				state.job = {
					id: start.job,
					steps: start.steps,
					index: 0,
					done: start.done,
					total: start.total,
					running: true,
					errors: [],
					publish: !! publish,
					smart: !! start.smart,
					refine: !! start.refine,
					unattended: !! start.unattended,
				};
				render();

				/*
				 * A build the server is running needs nothing from this tab
				 * but attention: the log says what is happening, and closing
				 * the tab does not stop it.
				 */
				if ( start.unattended ) {
					say( __( 'The build is running on the server. You can close this tab — it will carry on, and this screen picks it up again when you come back.', 'qwerty-soft-signal' ) );

					return;
				}

				nextStep();
			} )
			.catch( function ( error ) {
				state.job = null;
				say( errorText( error ), true );
				render();
			} );
	}

	function nextStep() {
		var job = state.job;

		if ( ! job || ! job.running || job.cancelled ) {
			return;
		}

		var step = job.steps[ job.index ];

		if ( ! step ) {
			job.running = false;
			render();
			return;
		}

		job.current = step;
		render();
		say( progressText( job ) );

		apiFetch( {
			path: '/qwerty-soft-signal/v1/build/step',
			method: 'POST',
			data: { job: job.id, key: step.key, file: step.file || '' },
		} )
			.then( function ( result ) {
				job.done = result.done;

				if ( 'finish' === step.key ) {
					finishBuild( job, result );
				}
			} )
			.catch( function ( error ) {
				if ( error && error.data && error.data.done ) {
					job.done = error.data.done;
				}

				job.errors.push( { label: stepLabel( step ), message: errorText( error ) } );

				// A job the server no longer has cannot be continued; say so once.
				if ( error && 'qwerty_soft_no_job' === error.code ) {
					job.cancelled = true;
				}

				say( stepLabel( step ) + ' — ' + errorText( error ), true );
			} )
			.then( function () {
				job.index += 1;
				job.current = null;

				if ( 'finish' === step.key || job.cancelled ) {
					job.running = false;
					render();
					return;
				}

				nextStep();
			} );
	}

	function finishBuild( job, result ) {
		var report = result.result;

		job.finished = true;
		state.built = report;

		if ( result.summary ) {
			state.summary = result.summary;
			state.archive = result.summary.archive || state.archive;
		}

		var message = job.publish
			? sprintf(
				/* translators: 1: pages built, 2: images imported. */
				__( 'Built and published %1$d pages and imported %2$d images. Look through them, then edit anything you like.', 'qwerty-soft-signal' ),
				report.pages.length,
				report.media
			)
			: sprintf(
				/* translators: 1: pages built, 2: images imported. */
				__( 'Built %1$d draft pages and imported %2$d images. Nothing is public yet — review each page and publish it when it is ready.', 'qwerty-soft-signal' ),
				report.pages.length,
				report.media
			);

		if ( result.archive_removed ) {
			message += ' ' + __( 'The uploaded design has been removed from uploads.', 'qwerty-soft-signal' );

			var gone = state.design ? state.design.slug : '';

			state.designs = state.designs.filter( function ( design ) {
				return design.slug !== gone;
			} );
			state.design = null;
			state.page = null;
			state.preview = null;
			state.includes = {};
		}

		say( message );
	}

	function undoBuild( button ) {
		button.disabled = true;
		say( __( 'Undoing…', 'qwerty-soft-signal' ) );

		apiFetch( { path: '/qwerty-soft-signal/v1/reset', method: 'POST' } )
			.then( function ( counts ) {
				state.built = null;
				state.job = null;
				state.confirmingReset = false;
				var removed =
					( counts.pages || 0 ) +
					( counts.parts || 0 ) +
					( counts.menus || 0 ) +
					( counts.media || 0 );

				/*
				 * Nothing removed is a finding, not a success. Undo deletes
				 * only what carries the import's ownership mark, so a site
				 * built by a path that forgot to set it reported "the site is
				 * back to how it was" while every imported page stayed
				 * published. Saying zero out loud is what makes that visible
				 * instead of leaving the owner to discover it on the front
				 * page.
				 */
				if ( ! removed ) {
					say(
						__(
							'Nothing was removed: no page, menu or image on this site carries the import mark. Anything you see was made another way and has to go from Pages, in the trash sense.',
							'qwerty-soft-signal'
						),
						true
					);
				} else {
					say(
						sprintf(
							/* translators: 1: pages removed, 2: images removed. */
							__( 'Removed %1$d pages and %2$d images. The site is back to how it was.', 'qwerty-soft-signal' ),
							counts.pages,
							counts.media
						)
					);
				}

				return refreshSummary();
			} )
			.catch( function ( error ) {
				say( errorText( error ), true );
				button.disabled = false;
			} );
	}

	// --------------------------------------------------------------- clean up

	/*
	 * What a previous import left on the site, whether or not a design is
	 * loaded right now. The undo button used to live only inside the build
	 * panel, which meant a reload — or deleting the archive — left the pages,
	 * parts and menus with no way back.
	 */
	function summaryTotal( summary ) {
		if ( ! summary ) {
			return 0;
		}

		return [ 'pages', 'parts', 'menus', 'media', 'fonts' ].reduce( function ( total, key ) {
			return total + ( parseInt( summary[ key ], 10 ) || 0 );
		}, 0 );
	}

	function refreshSummary() {
		return apiFetch( { path: '/qwerty-soft-signal/v1/summary' } )
			.then( function ( summary ) {
				state.summary = summary;
			} )
			.catch( function () {
				// A failed count only means the panel may be stale; not worth an error.
			} )
			.then( function () {
				render();
			} );
	}

	function focusConfirm() {
		var field = document.getElementById( 'qs-import-confirm' );

		if ( field ) {
			field.focus();
		}
	}

	function renderResetConfirm() {
		return renderConfirm( {
			run: undoBuild,
			hint: __( 'This cannot be undone. Only content this import created is removed; pages, menus and images you made yourself stay exactly as they are.', 'qwerty-soft-signal' ),
			cancel: function () {
				state.confirmingReset = false;
				render();
			},
		} );
	}

	function renderPurgeConfirm() {
		return renderConfirm( {
			run: purgeDesigns,
			hint: __( 'This removes the unpacked design files from uploads. Pages, images and fonts already imported are not affected — only the source archive goes, and it can be uploaded again.', 'qwerty-soft-signal' ),
			cancel: function () {
				state.confirmingPurge = false;
				render();
			},
		} );
	}

	/*
	 * A typed confirmation for anything that deletes. `run` is handed the
	 * button so it can disable it while the request is out.
	 */
	function renderConfirm( options ) {
		var word = 'DELETE';

		var field = el( 'input', {
			type: 'text',
			id: 'qs-import-confirm',
			class: 'qs-import__field qs-import__confirm-field',
			autocomplete: 'off',
			spellcheck: 'false',
			'aria-describedby': 'qs-import-confirm-hint',
			onInput: function ( event ) {
				go.disabled = event.target.value.trim() !== word;
			},
			onKeydown: function ( event ) {
				if ( 'Enter' === event.key && ! go.disabled ) {
					event.preventDefault();
					options.run( go );
				}
			},
		} );

		var go = el( 'button', {
			type: 'button',
			class: 'button qs-import__danger',
			disabled: 'disabled',
			text: __( 'Delete now', 'qwerty-soft-signal' ),
			onClick: function ( event ) {
				options.run( event.target );
			},
		} );

		var cancel = el( 'button', {
			type: 'button',
			class: 'button',
			text: __( 'Cancel', 'qwerty-soft-signal' ),
			onClick: options.cancel,
		} );

		return el( 'div', { class: 'qs-import__confirm', role: 'group', 'aria-labelledby': 'qs-import-confirm-label' }, [
			el( 'label', {
				id: 'qs-import-confirm-label',
				for: 'qs-import-confirm',
				text: sprintf(
					/* translators: %s: the word to type, in capitals. */
					__( 'Type %s to confirm', 'qwerty-soft-signal' ),
					word
				),
			} ),
			el( 'p', { class: 'qs-import__actions' }, [ field, go, cancel ] ),
			el( 'p', {
				id: 'qs-import-confirm-hint',
				class: 'qs-import__hint',
				text: options.hint,
			} ),
		] );
	}

	function purgeDesigns( button ) {
		button.disabled = true;
		say( __( 'Removing the uploaded designs…', 'qwerty-soft-signal' ) );

		apiFetch( { path: '/qwerty-soft-signal/v1/designs', method: 'DELETE' } )
			.then( function ( result ) {
				state.confirmingPurge = false;
				state.designs = [];
				state.design = null;
				state.page = null;
				state.preview = null;
				state.includes = {};
				state.archive = result.archive || { count: 0, bytes: 0 };

				if ( state.summary ) {
					state.summary.archive = state.archive;
				}

				/*
				 * A folder the server could not delete is the whole reason
				 * this button appeared to do nothing: it reported how many
				 * designs went and said nothing about the ones that stayed,
				 * so an undeletable folder read as success and came back on
				 * the next reload.
				 */
				if ( result.failed && result.failed.length ) {
					say(
						sprintf(
							/* translators: %s: comma-separated folder names. */
							__(
								'Could not remove: %s. The folder is still in uploads — the web server may not have permission to delete it, or a file inside is open. Delete it by hand over FTP or in the file manager.',
								'qwerty-soft-signal'
							),
							result.failed.join( ', ' )
						),
						true
					);
				} else {
					say(
						sprintf(
							/* translators: %d: number of designs removed. */
							_n( 'Removed %d uploaded design.', 'Removed %d uploaded designs.', result.removed, 'qwerty-soft-signal' ),
							result.removed
						)
					);
				}

				render();
				loadDesigns();
			} )
			.catch( function ( error ) {
				button.disabled = false;
				say( errorText( error ), true );
			} );
	}

	function renderCleanup() {
		var summary = state.summary;
		var archive = state.archive || ( summary && summary.archive ) || null;
		var hasArchive = archive && archive.count > 0;

		if ( ! summaryTotal( summary ) && ! hasArchive ) {
			return null;
		}

		var body = [ el( 'h3', { text: __( 'Clean up', 'qwerty-soft-signal' ) } ) ];

		if ( summaryTotal( summary ) ) {
			body.push(
				el( 'p', {
					text: sprintf(
						/* translators: 1: pages, 2: template parts, 3: menus, 4: images, 5: fonts, 6: section blocks. */
						__( 'This import created %1$d pages, %2$d template parts, %3$d menus, %4$d images, %5$d fonts, %6$d section blocks.', 'qwerty-soft-signal' ),
						summary.pages || 0,
						summary.parts || 0,
						summary.menus || 0,
						summary.media || 0,
						summary.fonts || 0,
						summary.blocks || 0
					),
				} )
			);
			body.push(
				el( 'p', {
					class: 'qs-import__hint',
					text: __( 'Your own content is untouched — only what the import added is removed.', 'qwerty-soft-signal' ),
				} )
			);

			if ( 'cleanup' === state.confirmingReset ) {
				body.push( renderResetConfirm() );
			} else {
				body.push(
					el( 'p', { class: 'qs-import__actions' }, [
						el( 'button', {
							type: 'button',
							class: 'button qs-import__danger',
							text: __( 'Delete everything this import added', 'qwerty-soft-signal' ),
							onClick: function () {
								state.confirmingReset = 'cleanup';
								state.confirmingPurge = false;
								render();
								focusConfirm();
							},
						} ),
					] )
				);
			}
		}

		if ( hasArchive ) {
			body.push(
				el( 'p', {
					text: sprintf(
						/* translators: 1: number of uploaded designs, 2: their size on disk. */
						_n( '%1$d uploaded design is still unpacked in uploads, taking %2$s.', '%1$d uploaded designs are still unpacked in uploads, taking %2$s.', archive.count, 'qwerty-soft-signal' ),
						archive.count,
						bytesText( archive.bytes )
					),
				} )
			);

			if ( state.confirmingPurge ) {
				body.push( renderPurgeConfirm() );
			} else {
				body.push(
					el( 'p', { class: 'qs-import__actions' }, [
						el( 'button', {
							type: 'button',
							class: 'button qs-import__danger',
							text: sprintf(
								/* translators: %s: size on disk. */
								__( 'Remove uploaded designs (%s)', 'qwerty-soft-signal' ),
								bytesText( archive.bytes )
							),
							onClick: function () {
								state.confirmingPurge = true;
								state.confirmingReset = false;
								render();
								focusConfirm();
							},
						} ),
					] )
				);
			}
		}

		return el( 'section', { class: 'qs-import__cleanup', 'aria-label': __( 'Clean up a previous import', 'qwerty-soft-signal' ) }, body );
	}

	// -------------------------------------------------------------- sections

	/*
	 * What this has cost and what the next press would cost.
	 *
	 * The API returns its token counts on every reply and the screen used to
	 * throw them away, which left the person deciding whether to convert
	 * fourteen sections with no idea whether that was forty cents or four
	 * dollars. Both figures are estimates from published list prices and say so.
	 */
	function renderMeter() {
		var pending = outstandingCount();
		var readouts = [];

		/*
		 * Nothing here is billed when the work goes through Claude Code on this
		 * machine: it spends the subscription that command is signed in to. The
		 * build modes above have said so for a while; this panel went on
		 * quoting list prices beside them, so the same screen answered "how
		 * much" two different ways within one scroll.
		 */
		var billed = 'cli' !== ( state.model && state.model.route );

		if ( pending > 0 ) {
			readouts.push(
				el( 'span', { class: 'qs-import__meter-figure' }, [
					el( 'strong', {
						text: billed ? money( outstandingEstimate() ) : __( 'No cost', 'qwerty-soft-signal' ),
					} ),
					el( 'span', {
						text: ' ' + sprintf(
							/* translators: %d: number of sections not yet converted. */
							_n( 'to convert %d remaining section', 'to convert %d remaining sections', pending, 'qwerty-soft-signal' ),
							pending
						),
					} ),
				] )
			);
		}

		if ( state.spend && state.spend.conversions > 0 ) {
			readouts.push(
				el( 'span', { class: 'qs-import__meter-figure' }, [
					el( 'strong', {
						text: billed ? money( state.spend.cost ) : __( 'Nothing billed', 'qwerty-soft-signal' ),
					} ),
					el( 'span', {
						text: ' ' + sprintf(
							/* translators: 1: number of conversions run, 2: input tokens, 3: output tokens. */
							__( 'so far · %1$d conversions · %2$s in, %3$s out', 'qwerty-soft-signal' ),
							state.spend.conversions,
							tokens( state.spend.input ),
							tokens( state.spend.output )
						),
					} ),
				] )
			);
		}

		if ( state.limit && state.limit.remaining <= 20 ) {
			readouts.push(
				el( 'span', { class: 'qs-import__meter-figure is-warning' }, [
					el( 'strong', { text: String( state.limit.remaining ) } ),
					el( 'span', {
						text: ' ' + sprintf(
							/* translators: %d: minutes until the hourly limit resets. */
							__( 'conversions left this hour · resets in %d min', 'qwerty-soft-signal' ),
							minutes( state.limit.resets_in )
						),
					} ),
				] )
			);
		}

		if ( ! readouts.length ) {
			return null;
		}

		readouts.push(
			el( 'span', {
				class: 'qs-import__meter-note',
				text: billed
					? __( 'Estimated from published list prices — not a bill.', 'qwerty-soft-signal' )
					: __( 'Run through Claude Code on this machine, on its signed-in subscription. The token counts are what it used, not what it charged.', 'qwerty-soft-signal' ),
			} )
		);

		return el( 'p', { class: 'qs-import__meter' }, readouts );
	}

	function renderSections() {
		if ( ! state.page ) {
			return null;
		}

		var list = el( 'ol', { class: 'qs-import__sections' } );

		state.sections.forEach( function ( section ) {
			list.appendChild( renderSection( section ) );
		} );

		var pending = outstandingCount();

		return el( 'section', { class: 'qs-import__step' }, [
			el( 'h2', { text: __( '3. Convert each section', 'qwerty-soft-signal' ) } ),
			el( 'p', {
				class: 'qs-import__hint',
				text: __( 'Convert a section, read what it says, then keep it. Nothing reaches your site until you press Keep.', 'qwerty-soft-signal' ),
			} ),
			renderMeter(),
			el( 'p', { class: 'qs-import__actions' }, [
				el( 'button', {
					type: 'button',
					class: 'button',
					text: pending
						? sprintf(
								/* translators: 1: number of sections, 2: estimated cost. */
								_n( 'Convert %1$d section — about %2$s', 'Convert %1$d sections — about %2$s', pending, 'qwerty-soft-signal' ),
								pending,
								money( outstandingEstimate() )
						  )
						: __( 'Every section is converted', 'qwerty-soft-signal' ),
					disabled: pending ? null : 'disabled',
					onClick: convertAll,
				} ),
				el( 'button', {
					type: 'button',
					class: 'button button-primary',
					text: __( 'Keep the whole page as a draft', 'qwerty-soft-signal' ),
					onClick: savePage,
				} ),
			] ),
			state.savedPage
				? el( 'p', { class: 'qs-import__saved' }, [
						el( 'span', { text: __( 'Draft page created.', 'qwerty-soft-signal' ) + ' ' } ),
						el( 'a', {
							href: state.savedPage.edit,
							text: __( 'Open it in the editor', 'qwerty-soft-signal' ),
						} ),
				  ] )
				: null,
			renderBridge(),
			list,
		] );
	}

	/*
	 * The subscription route. The theme never calls the API here: it hands the
	 * site owner a brief to paste into their own Claude conversation and takes
	 * the reply back. Everything pasted goes through the same validator as an
	 * API reply, so this costs nothing and trusts nothing.
	 */
	function renderBridge() {
		var paste = el( 'textarea', {
			id: 'qs-import-paste',
			class: 'qs-import__paste',
			rows: '6',
			spellcheck: 'false',
			placeholder: __( 'Paste the whole reply here, including the opening [ and closing ]', 'qwerty-soft-signal' ),
		} );

		/*
		 * The manual way out. Clipboard access can be refused for reasons the
		 * person cannot do anything about — an unfocused window, a locked-down
		 * browser, a site served over plain http — and "copy failed" with no
		 * alternative would strand them. The brief lands here instead, ready
		 * to select by hand.
		 */
		var briefBox = el( 'textarea', {
			id: 'qs-import-brief',
			class: 'qs-import__paste',
			rows: '6',
			readonly: 'readonly',
			spellcheck: 'false',
			hidden: 'hidden',
		} );

		var briefLabel = el( 'label', {
			class: 'qs-import__label',
			for: 'qs-import-brief',
			text: __( 'The brief — select it all and copy', 'qwerty-soft-signal' ),
			hidden: 'hidden',
		} );

		var copy = el( 'button', {
			type: 'button',
			class: 'button button-primary',
			text: __( 'Copy the brief', 'qwerty-soft-signal' ),
			onClick: function ( event ) {
				copyBrief( event.target, briefBox, briefLabel );
			},
		} );

		return el( 'details', { class: 'qs-import__bridge' }, [
			el( 'summary', { text: __( 'No API key? Use your own Claude subscription instead', 'qwerty-soft-signal' ) } ),
			el( 'ol', { class: 'qs-import__bridge-steps' }, [
				el( 'li', { text: __( 'Copy the brief for this page.', 'qwerty-soft-signal' ) } ),
				el( 'li', { text: __( 'Paste it into your Claude conversation and send it.', 'qwerty-soft-signal' ) } ),
				el( 'li', { text: __( 'Copy the whole reply and paste it below.', 'qwerty-soft-signal' ) } ),
			] ),
			el( 'p', {}, [ copy ] ),
			briefLabel,
			briefBox,
			el( 'label', {
				class: 'qs-import__label',
				for: 'qs-import-paste',
				text: __( 'Claude’s reply', 'qwerty-soft-signal' ),
			} ),
			paste,
			el( 'p', {}, [
				el( 'button', {
					type: 'button',
					class: 'button',
					text: __( 'Read the reply', 'qwerty-soft-signal' ),
					onClick: function () {
						acceptPaste( paste.value );
					},
				} ),
			] ),
		] );
	}

	function copyBrief( button, briefBox, briefLabel ) {
		var label = button.textContent;

		button.disabled = true;
		say( __( 'Building the brief…', 'qwerty-soft-signal' ) );

		apiFetch( {
			path:
				'/qwerty-soft-signal/v1/designs/' +
				encodeURIComponent( state.design.slug ) +
				'/brief?file=' +
				encodeURIComponent( state.page.file ),
		} )
			.then( function ( data ) {
				briefBox.value = data.brief;

				var size = sprintf(
					/* translators: 1: number of sections, 2: size in kilobytes. */
					__( 'Brief for %1$d sections, %2$d KB.', 'qwerty-soft-signal' ),
					data.sections,
					Math.round( data.bytes / 1024 )
				);

				return writeClipboard( data.brief ).then(
					function () {
						button.textContent = __( 'Copied — now paste it into Claude', 'qwerty-soft-signal' );
						say( size + ' ' + __( 'Copied. Paste it into Claude, then bring the reply back.', 'qwerty-soft-signal' ) );

						window.setTimeout( function () {
							button.textContent = label;
						}, 4000 );
					},
					function () {
						// Copying was refused; show it so it can be taken by hand.
						briefBox.removeAttribute( 'hidden' );
						briefLabel.removeAttribute( 'hidden' );
						briefBox.focus();
						briefBox.select();
						say(
							size +
								' ' +
								__( 'The browser would not copy it, so it is in the box below — select it all and copy it yourself.', 'qwerty-soft-signal' ),
							true
						);
					}
				);
			} )
			.catch( function ( error ) {
				say( errorText( error ), true );
			} )
			.then( function () {
				button.disabled = false;
			} );
	}

	/*
	 * navigator.clipboard needs a secure context, which a plain-http local
	 * install is not. Fall back to a hidden field and execCommand so the
	 * button still works there rather than failing silently.
	 */
	function writeClipboard( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}

		return new Promise( function ( resolve, reject ) {
			var field = document.createElement( 'textarea' );

			field.value = text;
			field.setAttribute( 'readonly', 'readonly' );
			field.style.position = 'fixed';
			field.style.opacity = '0';
			document.body.appendChild( field );
			field.select();

			var ok = document.execCommand( 'copy' );

			document.body.removeChild( field );

			if ( ok ) {
				resolve();
			} else {
				reject( new Error( __( 'The browser refused to copy.', 'qwerty-soft-signal' ) ) );
			}
		} );
	}

	function acceptPaste( reply ) {
		if ( ! reply.trim() ) {
			say( __( 'Paste the reply first.', 'qwerty-soft-signal' ), true );
			return;
		}

		say( __( 'Reading the reply…', 'qwerty-soft-signal' ) );

		apiFetch( {
			path: '/qwerty-soft-signal/v1/paste',
			method: 'POST',
			data: { slug: state.design.slug, file: state.page.file, reply: reply },
		} )
			.then( function ( data ) {
				data.results.forEach( function ( result ) {
					state.results[ result.position ] = result;
				} );

				render();

				if ( data.received !== data.expected ) {
					say(
						sprintf(
							/* translators: 1: sections received, 2: sections expected. */
							__( 'Read %1$d sections, but this page has %2$d. Check the reply was not cut off.', 'qwerty-soft-signal' ),
							data.received,
							data.expected
						),
						true
					);
					return;
				}

				say(
					sprintf(
						/* translators: %d: number of sections. */
						__( 'Read %d sections. Review each one before keeping it.', 'qwerty-soft-signal' ),
						data.received
					)
				);
			} )
			.catch( function ( error ) {
				say( errorText( error ), true );
			} );
	}

	function renderSection( section ) {
		var result = state.results[ section.position ];
		var body = [
			el( 'div', { class: 'qs-import__section-head' }, [
				el( 'strong', { text: section.label } ),
				el( 'span', {
					class: 'qs-import__section-meta',
					text: sprintf(
						/* translators: 1: element name, 2: word count, 3: image count. */
						__( '<%1$s> · %2$d words · %3$d images', 'qwerty-soft-signal' ),
						section.tag,
						section.words,
						section.images
					),
				} ),
			] ),
			el( 'p', { class: 'qs-import__excerpt', text: section.excerpt } ),
		];

		if ( ! result ) {
			body.push(
				el( 'button', {
					type: 'button',
					class: 'button',
					text: __( 'Convert this section', 'qwerty-soft-signal' ),
					onClick: function () {
						convert( section );
					},
				} )
			);
		} else if ( result.pending ) {
			body.push( el( 'p', { class: 'qs-import__pending', text: __( 'Converting…', 'qwerty-soft-signal' ) } ) );
		} else if ( result.error ) {
			body.push( el( 'p', { class: 'qs-import__error', text: result.error } ) );
			body.push(
				el( 'button', {
					type: 'button',
					class: 'button',
					text: __( 'Try again', 'qwerty-soft-signal' ),
					onClick: function () {
						convert( section );
					},
				} )
			);
		} else {
			body = body.concat( renderResult( section, result ) );
		}

		return el( 'li', { class: 'qs-import__section' }, body );
	}

	function renderResult( section, result ) {
		var out = [];

		if ( result.summary ) {
			out.push( el( 'p', { class: 'qs-import__summary', text: result.summary } ) );
		}

		if ( ! result.valid ) {
			out.push( el( 'p', { class: 'qs-import__error', text: __( 'This conversion was refused:', 'qwerty-soft-signal' ) } ) );
			out.push( bullets( result.errors, 'qs-import__error-list' ) );
			out.push(
				el( 'button', {
					type: 'button',
					class: 'button',
					text: __( 'Try again', 'qwerty-soft-signal' ),
					onClick: function () {
						convert( section );
					},
				} )
			);

			return out;
		}

		if ( result.editable && result.editable.length ) {
			out.push( el( 'p', { class: 'qs-import__label-inline', text: __( 'You will be able to edit:', 'qwerty-soft-signal' ) } ) );
			out.push( bullets( result.editable, 'qs-import__editable' ) );
		}

		if ( result.concerns && result.concerns.length ) {
			out.push( el( 'p', { class: 'qs-import__label-inline', text: __( 'Worth checking:', 'qwerty-soft-signal' ) } ) );
			out.push( bullets( result.concerns, 'qs-import__concerns' ) );
		}

		if ( result.notes && result.notes.length ) {
			out.push( el( 'p', { class: 'qs-import__label-inline', text: __( 'Quality notes:', 'qwerty-soft-signal' ) } ) );
			out.push( bullets( result.notes, 'qs-import__notes' ) );
		}

		var preview = el( 'div', { class: 'qs-import__preview' } );
		// Server-validated and kses-filtered; see Importer::preview().
		preview.innerHTML = result.preview;

		out.push(
			el( 'details', { class: 'qs-import__preview-wrap' }, [
				el( 'summary', { text: __( 'Preview', 'qwerty-soft-signal' ) } ),
				preview,
			] )
		);

		if ( result.saved ) {
			out.push(
				el( 'p', { class: 'qs-import__saved' }, [
					el( 'span', { text: __( 'Kept.', 'qwerty-soft-signal' ) + ' ' } ),
					el( 'a', { href: result.saved.edit, text: __( 'Open it in the editor', 'qwerty-soft-signal' ) } ),
				] )
			);

			return out;
		}

		out.push(
			el( 'div', { class: 'qs-import__actions' }, [
				el( 'button', {
					type: 'button',
					class: 'button button-primary',
					text: __( 'Keep as a reusable section', 'qwerty-soft-signal' ),
					onClick: function () {
						save( section, result, 'pattern' );
					},
				} ),
				el( 'button', {
					type: 'button',
					class: 'button',
					text: __( 'Keep as a draft page', 'qwerty-soft-signal' ),
					onClick: function () {
						save( section, result, 'page' );
					},
				} ),
			] )
		);

		return out;
	}

	function bullets( items, className ) {
		var ul = el( 'ul', { class: className } );

		items.forEach( function ( item ) {
			ul.appendChild( el( 'li', { text: item } ) );
		} );

		return ul;
	}

	/*
	 * The same note, said once, with a count.
	 *
	 * A page of ten sections produces ten notes about the kicker colour, ten
	 * about the heading font and ten about the section background, because
	 * each section really did hit each of them. Printed one per line that is
	 * two hundred bullets nobody reads, and the three notes that matter — a
	 * dropped chart, a form that does not subscribe anybody — are lost in it.
	 *
	 * Notes are matched on their shape rather than their text: the hex values
	 * and pixel sizes are taken out before comparing, so "the kicker #98772e
	 * maps to accent-ink" and "the kicker gold #d8bd72 maps to accent-2" are
	 * recognised as one recurring substitution rather than two facts.
	 */
	function foldNotes( items ) {
		var order = [];
		var groups = {};

		( items || [] ).forEach( function ( item ) {
			var text = String( item );

			var key = text
				.toLowerCase()
				.replace( /#[0-9a-f]{3,8}\b/g, '#' )
				.replace( /\b\d+(\.\d+)?(px|rem|em|%|ch|vw|vh)?\b/g, 'N' )
				.replace( /[^a-z#]+/g, ' ' )
				.trim();

			if ( ! groups[ key ] ) {
				groups[ key ] = { text: text, count: 0 };
				order.push( key );
			}

			groups[ key ].count++;
		} );

		return order.map( function ( key ) {
			return groups[ key ];
		} ).sort( function ( a, b ) {
			return b.count - a.count;
		} );
	}

	/** The folded notes as a list, each saying how many sections it covers. */
	function foldedBullets( items, className ) {
		var ul = el( 'ul', { class: className } );

		foldNotes( items ).forEach( function ( note ) {
			ul.appendChild(
				el( 'li', {}, [
					el( 'span', { text: note.text } ),
					note.count > 1
						? el( 'span', {
								class: 'qs-import__note-count',
								text: sprintf(
									/* translators: %d: how many sections the same note was made about. */
									__( '× %d sections', 'qwerty-soft-signal' ),
									note.count
								),
						  } )
						: null,
				] )
			);
		} );

		return ul;
	}

	// --------------------------------------------------------------- actions

	function convert( section ) {
		if ( ! cfg.hasKey ) {
			say( __( 'Add your Anthropic API key in the connection settings first.', 'qwerty-soft-signal' ), true );
			return;
		}

		state.results[ section.position ] = { pending: true };
		render();
		say( sprintf( /* translators: %s: section name. */ __( 'Converting “%s”…', 'qwerty-soft-signal' ), section.label ) );

		return apiFetch( {
			path: '/qwerty-soft-signal/v1/convert',
			method: 'POST',
			data: {
				slug: state.design.slug,
				file: state.page.file,
				position: section.position,
			},
		} )
			.then( function ( result ) {
				state.results[ section.position ] = result;

				// The reply carries what it cost and what is left of the
				// hourly allowance; both are shown rather than discarded.
				if ( result.spend ) {
					state.spend = result.spend;
				}

				if ( result.limit ) {
					state.limit = result.limit;
				}

				render();
				say(
					result.valid
						? sprintf( /* translators: %s: section name. */ __( '“%s” converted. Review it below.', 'qwerty-soft-signal' ), section.label )
						: sprintf( /* translators: %s: section name. */ __( '“%s” was refused — see the reason below.', 'qwerty-soft-signal' ), section.label ),
					! result.valid
				);
			} )
			.catch( function ( error ) {
				state.results[ section.position ] = { error: errorText( error ) };
				render();
				say( errorText( error ), true );
			} );
	}

	function convertAll() {
		if ( state.busy ) {
			return;
		}

		state.busy = true;

		/*
		 * Only what has not been converted. Re-running a section that already
		 * succeeded would spend money to produce the same thing twice — which
		 * matters now that a run can be resumed after a closed tab.
		 */
		var queue = state.sections.filter( function ( section ) {
			var done = state.results[ section.position ];

			return ! done || done.error;
		} );

		function next() {
			var section = queue.shift();

			if ( ! section ) {
				state.busy = false;
				say( __( 'All sections converted. Review each one before keeping it.', 'qwerty-soft-signal' ) );
				return;
			}

			// One at a time on purpose: parallel requests would hit the rate
			// limit and make failures much harder to attribute to a section.
			convert( section ).then( next, next );
		}

		next();
	}

	/*
	 * The finish the flow is actually for. Keeping sections one at a time
	 * leaves the editor with a pile of loose blocks and the job of ordering
	 * them; this stitches every converted section back into one page, in the
	 * order they appeared in the design.
	 */
	function savePage() {
		var ordered = state.sections
			.map( function ( section ) {
				return state.results[ section.position ];
			} )
			.filter( function ( result ) {
				return result && result.valid && result.markup;
			} );

		if ( ! ordered.length ) {
			say( __( 'Convert at least one section first.', 'qwerty-soft-signal' ), true );
			return;
		}

		var skipped = state.sections.length - ordered.length;

		say( __( 'Building the page…', 'qwerty-soft-signal' ) );

		apiFetch( {
			path: '/qwerty-soft-signal/v1/save',
			method: 'POST',
			data: {
				markup: ordered
					.map( function ( result ) {
						return result.markup;
					} )
					.join( '\n\n' ),
				title: state.page.title,
				as: 'page',
			},
		} )
			.then( function ( saved ) {
				state.savedPage = saved;
				render();

				if ( skipped ) {
					say(
						sprintf(
							/* translators: 1: sections kept, 2: sections left out. */
							__( 'Draft page created from %1$d sections. %2$d were left out because they are not converted or were refused.', 'qwerty-soft-signal' ),
							ordered.length,
							skipped
						),
						true
					);
					return;
				}

				say(
					sprintf(
						/* translators: %d: number of sections. */
						__( 'Draft page created from all %d sections.', 'qwerty-soft-signal' ),
						ordered.length
					)
				);
			} )
			.catch( function ( error ) {
				say( errorText( error ), true );
			} );
	}

	function save( section, result, as ) {
		var title = state.page.title + ' — ' + section.label;

		apiFetch( {
			path: '/qwerty-soft-signal/v1/save',
			method: 'POST',
			data: { markup: result.markup, title: title, as: as },
		} )
			.then( function ( saved ) {
				state.results[ section.position ].saved = saved;
				render();
				say( __( 'Saved.', 'qwerty-soft-signal' ) );
			} )
			.catch( function ( error ) {
				say( errorText( error ), true );
			} );
	}

	// ---------------------------------------------------------------- render

	function render() {
		// A side-by-side preview needs the whole window, not the 60rem column.
		var screen = app.closest( '.qs-import' );

		if ( screen ) {
			screen.classList.toggle( 'is-previewing', !! state.preview );
		}

		/*
		 * The live region is created once and kept across renders. Destroying
		 * and recreating it in the same task as say() would leave screen
		 * readers with nothing to announce.
		 */
		var status = document.getElementById( 'qs-import-status' );

		if ( ! status ) {
			status = el( 'p', {
				id: 'qs-import-status',
				class: 'qs-import__status',
				role: 'status',
				'aria-live': 'polite',
			} );
		}

		app.textContent = '';
		app.appendChild( status );

		// The panes about to be built are the only ones worth repainting.
		state.designCssWaiting = [];

		var log = renderLog();

		if ( log ) {
			app.appendChild( log );
		}

		var cleanup = renderCleanup();

		if ( cleanup ) {
			app.appendChild( cleanup );
		}

		app.appendChild( renderUpload() );

		var pages = renderPages();

		if ( pages ) {
			app.appendChild( pages );
		}

		/*
		 * Outside the design panel on purpose: a build that removed its own
		 * archive has no design left to hang the result on, and the pages it
		 * made are the one thing the editor needs to see next.
		 */
		if ( state.built ) {
			app.appendChild( renderBuilt( state.built ) );
		}

		var sections = renderSections();

		if ( sections ) {
			app.appendChild( sections );
		}
	}

	render();
	loadDesigns();
	loadModel();

	/*
	 * A build may have been started in another tab, or by this one before it
	 * was closed. The log says whether anything is running and the panel
	 * rejoins it rather than showing an idle screen over a working server.
	 */
	resumeWatching();
} )( window.wp );

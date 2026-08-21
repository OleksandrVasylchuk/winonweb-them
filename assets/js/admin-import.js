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

	var cfg = window.wowSignalImport || {};
	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var apiFetch = wp.apiFetch;

	apiFetch.use( apiFetch.createNonceMiddleware( cfg.nonce ) );

	var app = document.getElementById( 'wow-import-app' );

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
		keepArchive: false,
		// Whether the model corrects each section, and whether it also reviews its own work.
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
			return __( 'under $0.01', 'wow-signal' );
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
			return sprintf( __( '%s MB', 'wow-signal' ), ( value / 1048576 ).toFixed( 1 ) );
		}

		/* translators: %s: size in kilobytes. */
		return sprintf( __( '%s KB', 'wow-signal' ), String( Math.max( 1, Math.round( value / 1024 ) ) ) );
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

			return 0 === path.indexOf( prefix ) || path.indexOf( '/' + prefix ) > -1;
		} );

		// A design whose files are not filed by language is one site, not none.
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

		return total * ( state.refine ? 2 : 1 );
	}

	/** Whether a model can be reached from this machine at all. */
	function modelReady() {
		return !! ( state.model && state.model.ready );
	}

	/** Ask the server which route works from here. */
	function loadModel() {
		state.modelChecking = true;

		apiFetch( { path: '/wow-signal/v1/model' } )
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

	/** Announce progress and errors to everyone, including screen readers. */
	function say( message, isError ) {
		var live = document.getElementById( 'wow-import-status' );

		if ( live ) {
			live.textContent = message;
			live.className = 'wow-import__status' + ( isError ? ' is-error' : '' );
		}
	}

	function errorText( error ) {
		if ( ! error ) {
			return __( 'Something went wrong.', 'wow-signal' );
		}

		return error.message || String( error );
	}

	// ---------------------------------------------------------------- upload

	function renderUpload() {
		if ( ! archiveInput ) {
			archiveInput = el( 'input', {
				type: 'file',
				id: 'wow-archive',
				accept: '.zip,application/zip',
				class: 'wow-import__file',
			} );
		}

		var input = archiveInput;

		var button = el( 'button', {
			type: 'submit',
			class: 'button button-primary',
			text: __( 'Upload and read the design', 'wow-signal' ),
		} );

		var form = el( 'form', {
			class: 'wow-import__upload',
			onSubmit: function ( event ) {
				event.preventDefault();

				if ( ! input.files || ! input.files[ 0 ] ) {
					say( __( 'Choose a ZIP file first.', 'wow-signal' ), true );
					return;
				}

				var body = new FormData();
				body.append( 'archive', input.files[ 0 ] );

				button.disabled = true;
				say( __( 'Unpacking and reading the design…', 'wow-signal' ) );

				apiFetch( { path: '/wow-signal/v1/designs', method: 'POST', body: body } )
					.then( function ( result ) {
						button.disabled = false;
						say(
							sprintf(
								/* translators: 1: number of files, 2: number of pages. */
								__( 'Read %1$d files and found %2$d pages.', 'wow-signal' ),
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
			el( 'label', { for: 'wow-archive', class: 'wow-import__label', text: __( 'Design archive (.zip)', 'wow-signal' ) } ),
			input,
			button,
		] );

		return el( 'section', { class: 'wow-import__step' }, [
			el( 'h2', { text: __( '1. Upload the design', 'wow-signal' ) } ),
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

		var list = el( 'ul', { class: 'wow-import__designs' } );

		state.designs.forEach( function ( design ) {
			var active = state.design && state.design.slug === design.slug;

			list.appendChild(
				el( 'li', {}, [
					el( 'button', {
						type: 'button',
						class: 'wow-import__page' + ( active ? ' is-active' : '' ),
						'aria-pressed': active ? 'true' : 'false',
						onClick: function () {
							chooseDesign( design );
							render();
						},
					}, [
						el( 'span', { class: 'wow-import__page-title', text: design.slug } ),
						el( 'span', {
							class: 'wow-import__page-meta',
							text: sprintf(
								/* translators: 1: number of pages, 2: number of images. */
								__( '%1$d pages · %2$d images', 'wow-signal' ),
								design.pages.length,
								design.images
							),
						} ),
					] ),
				] )
			);
		} );

		return el( 'div', { class: 'wow-import__designs-wrap' }, [
			el( 'h3', { text: __( 'Already uploaded', 'wow-signal' ) } ),
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
	}

	function loadDesigns() {
		return apiFetch( { path: '/wow-signal/v1/designs' } )
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

	// ----------------------------------------------------------------- pages

	function renderPages() {
		if ( ! state.design ) {
			return null;
		}

		var design = state.design;
		var list = el( 'ul', { class: 'wow-import__pages' } );

		design.pages.forEach( function ( page ) {
			var active = state.page && state.page.file === page.file;
			var previewing = state.preview && state.preview.file === page.file;
			var chosen = state.includes[ page.file ];

			list.appendChild(
				el( 'li', { class: 'wow-import__page-item' }, [
					el( 'button', {
						type: 'button',
						class: 'wow-import__page' + ( active ? ' is-active' : '' ),
						'aria-pressed': active ? 'true' : 'false',
						onClick: function () {
							choosePage( page );
						},
					}, [
						el( 'span', { class: 'wow-import__page-title', text: page.title } ),
						el( 'span', {
							class: 'wow-import__page-meta',
							text: sprintf(
								/* translators: 1: file path, 2: number of sections. */
								__( '%1$s — %2$d sections', 'wow-signal' ),
								page.file,
								page.sections
							),
						} ),
						chosen
							? el( 'span', {
								class: 'wow-import__page-meta',
								text: sprintf(
									/* translators: %d: number of sections chosen for the build. */
									_n( '%d section chosen for the build', '%d sections chosen for the build', chosen.length, 'wow-signal' ),
									chosen.length
								),
							} )
							: null,
					] ),
					el( 'button', {
						type: 'button',
						class: 'button wow-import__page-preview',
						'aria-pressed': previewing ? 'true' : 'false',
						text: previewing ? __( 'Previewing', 'wow-signal' ) : __( 'Preview', 'wow-signal' ),
						onClick: function () {
							openPreview( page );
						},
					} ),
				] )
			);
		} );

		var children = [ el( 'h2', { text: __( '2. Pick a page', 'wow-signal' ) } ) ];

		if ( design.languages && design.languages.length > 1 ) {
			children.push(
				el( 'p', {
					class: 'wow-import__hint',
					text: sprintf(
						/* translators: %s: comma-separated language codes. */
						__( 'This design has %s. Import one language first; the others can be added later with a translation plugin.', 'wow-signal' ),
						design.languages.join( ', ' )
					),
				} )
			);
		}

		if ( design.skipped && design.skipped.length ) {
			var skipped = el( 'details', { class: 'wow-import__skipped' }, [
				el( 'summary', {
					text: sprintf(
						/* translators: %d: number of files. */
						__( '%d files were not unpacked', 'wow-signal' ),
						design.skipped.length
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
		children.push( list );
		children.push( renderPreview() );

		return el( 'section', { class: 'wow-import__step' }, children );
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
		render();
		say( sprintf( /* translators: %s: page title. */ __( 'Converting “%s” for the preview…', 'wow-signal' ), page.title ) );

		apiFetch( {
			path:
				'/wow-signal/v1/designs/' +
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
						__( '“%1$s” previewed: %2$d sections. Untick any you do not want built.', 'wow-signal' ),
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
		var pane = document.getElementById( 'wow-import-preview' );

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

		var wrap = el( 'div', { id: 'wow-import-preview', class: 'wow-import__compare-wrap', tabindex: '-1' } );

		if ( preview.loading ) {
			wrap.appendChild( el( 'p', { class: 'wow-import__pending', text: __( 'Converting the page…', 'wow-signal' ) } ) );
			return wrap;
		}

		if ( preview.error ) {
			wrap.appendChild( el( 'p', { class: 'wow-import__error', text: preview.error } ) );
			return wrap;
		}

		var data = preview.data;
		var file = preview.file;
		var total = data.sections.length;
		var included = data.sections.filter( function ( section ) {
			return isIncluded( file, section.position );
		} ).length;

		var head = el( 'div', { class: 'wow-import__compare-head' }, [
			el( 'h3', {
				text: sprintf(
					/* translators: %s: page title. */
					__( 'Preview: %s', 'wow-signal' ),
					data.title
				),
			} ),
			el( 'p', {
				class: 'wow-import__compare-count',
				role: 'status',
				text: sprintf(
					/* translators: 1: sections included, 2: sections on the page. */
					__( '%1$d of %2$d sections included', 'wow-signal' ),
					included,
					total
				),
			} ),
			el( 'p', { class: 'wow-import__actions' }, [
				el( 'button', {
					type: 'button',
					class: 'button',
					disabled: included === total ? 'disabled' : null,
					text: __( 'Include all', 'wow-signal' ),
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
					text: __( 'Close preview', 'wow-signal' ),
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
				class: 'wow-import__hint',
				text: __( 'Left: the design as uploaded. Right: the blocks the build will make of it. Untick a section to leave it out of this page.', 'wow-signal' ),
			} )
		);

		if ( data.notes && data.notes.length ) {
			wrap.appendChild( bullets( data.notes, 'wow-import__notes' ) );
		}

		var rows = el( 'ol', { class: 'wow-import__compare' } );

		data.sections.forEach( function ( section ) {
			rows.appendChild( renderCompareRow( file, section ) );
		} );

		wrap.appendChild( rows );

		return wrap;
	}

	function renderCompareRow( file, section ) {
		var id = 'wow-include-' + section.position;
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

		var design = el( 'div', { class: 'wow-import__preview wow-import__preview--design' } );
		// Server-side kses-filtered; see Importer::original_html().
		design.innerHTML = section.original_html;

		var blocks = el( 'div', { class: 'wow-import__preview' } );

		if ( section.preview_html ) {
			// Server-validated and kses-filtered; see Importer::preview().
			blocks.innerHTML = section.preview_html;
		} else {
			blocks.appendChild( el( 'p', { class: 'wow-import__pending', text: __( 'Nothing here could become blocks.', 'wow-signal' ) } ) );
		}

		var body = [
			el( 'div', { class: 'wow-import__compare-row-head' }, [
				el( 'span', { class: 'wow-import__choice' }, [
					box,
					el( 'label', { for: id, text: ' ' + __( 'Include', 'wow-signal' ) } ),
				] ),
				el( 'strong', { text: section.label } ),
			] ),
		];

		if ( section.concerns && section.concerns.length ) {
			body.push( bullets( section.concerns, 'wow-import__concerns' ) );
		}

		body.push(
			el( 'div', { class: 'wow-import__compare-panes' }, [
				el( 'div', { class: 'wow-import__compare-pane' }, [
					el( 'h4', { text: __( 'Design', 'wow-signal' ) } ),
					design,
				] ),
				el( 'div', { class: 'wow-import__compare-pane' }, [
					el( 'h4', { text: __( 'Blocks', 'wow-signal' ) } ),
					blocks,
				] ),
			] )
		);

		return el( 'li', { class: 'wow-import__compare-row' + ( on ? '' : ' is-excluded' ) }, body );
	}

	function choosePage( page ) {
		say( __( 'Reading the page…', 'wow-signal' ) );

		apiFetch( {
			path:
				'/wow-signal/v1/designs/' +
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
								__( 'Found %1$d sections. %2$d were already converted earlier and have been brought back.', 'wow-signal' ),
								result.sections.length,
								resumed
						  )
						: sprintf(
								/* translators: %d: number of sections. */
								__( 'Found %d sections on this page.', 'wow-signal' ),
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
				class: 'wow-import__hint',
				text: __( 'Checking whether Claude can be reached from here…', 'wow-signal' ),
			} );
		}

		if ( ! modelReady() ) {
			var why = ( state.model && state.model.reason ) || '';

			if ( ! why && ! cfg.hasKey && ! cfg.cliFound ) {
				why = __( 'Claude Code is not installed here and no API key has been saved.', 'wow-signal' );
			}

			if ( ! why ) {
				return null;
			}

			return el( 'p', { class: 'wow-import__hint' }, [
				el( 'strong', { text: __( 'Correcting each section with Claude is not available yet. ', 'wow-signal' ) } ),
				el( 'span', { text: why + ' ' } ),
				el( 'span', { text: __( 'Open Connection settings above to set it up. The build below works without it.', 'wow-signal' ) } ),
			] );
		}

		var smart = el( 'input', {
			type: 'checkbox',
			id: 'wow-import-smart',
			onChange: function ( event ) {
				state.smart = !! event.target.checked;

				if ( ! state.smart ) {
					state.refine = false;
				}

				render();
			},
		} );

		smart.checked = state.smart;

		var refine = el( 'input', {
			type: 'checkbox',
			id: 'wow-import-refine',
			disabled: state.smart ? null : 'disabled',
			onChange: function ( event ) {
				state.refine = !! event.target.checked;
				render();
			},
		} );

		refine.checked = state.refine;

		var billed = 'api' === state.model.route;
		var calls = plannedSections() * ( state.refine ? 2 : 1 );

		var lines = [
			el( 'p', { class: 'wow-import__choice' }, [
				smart,
				el( 'label', {
					for: 'wow-import-smart',
					text: ' ' + __( 'Let Claude correct each section', 'wow-signal' ),
				} ),
				el( 'span', {
					class: 'wow-import__hint',
					text:
						' ' +
						__(
							'The structural conversion is done first either way; Claude is shown that result, what the design\'s CSS resolves to, and a screenshot when the archive has one, and fixes what is wrong. A section it cannot improve is kept exactly as the structural conversion made it.',
							'wow-signal'
						),
				} ),
			] ),
			el( 'p', { class: 'wow-import__choice wow-import__choice--nested' }, [
				refine,
				el( 'label', {
					for: 'wow-import-refine',
					text: ' ' + __( 'and check the rendered result against the design', 'wow-signal' ),
				} ),
				el( 'span', {
					class: 'wow-import__hint',
					text:
						' ' +
						__(
							'A second pass per section: the blocks are rendered on the server and compared with the design. It catches what a conversion written blind cannot see, and doubles the time and the cost.',
							'wow-signal'
						),
				} ),
			] ),
		];

		if ( state.smart ) {
			var route = billed
				? sprintf(
						/* translators: 1: number of model calls, 2: estimated cost. */
						_n(
							'About %1$d call to the Anthropic API — roughly %2$s at list prices, billed to your key.',
							'About %1$d calls to the Anthropic API — roughly %2$s at list prices, billed to your key.',
							calls,
							'wow-signal'
						),
						calls,
						money( plannedCost() )
				  )
				: sprintf(
						/* translators: %d: number of model calls. */
						_n(
							'About %d call through Claude Code on this machine, which uses the subscription it is signed in to. Nothing is billed per conversion.',
							'About %d calls through Claude Code on this machine, which uses the subscription it is signed in to. Nothing is billed per conversion.',
							calls,
							'wow-signal'
						),
						calls
				  );

			lines.push( el( 'p', { class: 'wow-import__estimate', text: route } ) );
			lines.push(
				el( 'p', {
					class: 'wow-import__hint',
					text: __(
						'Expect this to take minutes rather than seconds — a page is built section by section. Leave the tab open; closing it stops the build where it got to, and what it has already made stays.',
						'wow-signal'
					),
				} )
			);
		}

		return el( 'div', { class: 'wow-import__smart' }, lines );
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
		}

		var language = el(
			'select',
			{
				id: 'wow-import-language',
				class: 'wow-import__language',
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
			id: 'wow-import-publish',
			onChange: function ( event ) {
				state.publish = !! event.target.checked;
			},
		} );

		publish.checked = state.publish;

		var keep = el( 'input', {
			type: 'checkbox',
			id: 'wow-import-keep',
			onChange: function ( event ) {
				state.keepArchive = !! event.target.checked;
			},
		} );

		keep.checked = state.keepArchive;

		var running = state.job && state.job.running;

		var build = el( 'button', {
			type: 'button',
			class: 'button button-primary button-hero',
			text: __( 'Build the whole site', 'wow-signal' ),
			disabled: running ? 'disabled' : null,
			onClick: function () {
				startBuild( choices.length ? language.value : '', publish.checked );
			},
		} );

		if ( state.smart && modelReady() ) {
			build.textContent = state.refine
				? __( 'Build the whole site, corrected and reviewed', 'wow-signal' )
				: __( 'Build the whole site, corrected by Claude', 'wow-signal' );
		}

		var body = [
			el( 'h3', { text: __( 'Build everything at once', 'wow-signal' ) } ),
			el( 'p', {
				class: 'wow-import__hint',
				text: __( 'Creates every page as a draft, imports the images, builds the menu and the header and footer, and sets the front page. No API key and no conversation needed. You can undo it and run it again. Press Preview on any page below to see what it will become and leave sections out.', 'wow-signal' ),
			} ),
			el( 'p', { class: 'wow-import__choice' }, [
				publish,
				el( 'label', {
					for: 'wow-import-publish',
					text: ' ' + __( 'Publish pages immediately', 'wow-signal' ),
				} ),
				el( 'span', {
					class: 'wow-import__hint',
					text: ' ' + __( 'Leave this off to review each page as a draft first.', 'wow-signal' ),
				} ),
			] ),
			el( 'p', { class: 'wow-import__choice' }, [
				keep,
				el( 'label', {
					for: 'wow-import-keep',
					text: ' ' + __( 'Keep the uploaded design for another run', 'wow-signal' ),
				} ),
				el( 'span', {
					class: 'wow-import__hint',
					text: ' ' + __( 'Otherwise the unpacked files are removed from uploads once the build is done.', 'wow-signal' ),
				} ),
			] ),
		];

		var smart = renderSmartChoice();

		if ( smart ) {
			body.push( smart );
		}

		if ( choices.length ) {
			body.push(
				el( 'p', {}, [
					el( 'label', { for: 'wow-import-language', text: __( 'Language to build:', 'wow-signal' ) + ' ' } ),
					language,
				] )
			);
		}

		body.push(
			el( 'p', {
				class: 'wow-import__hint',
				text: __( 'Changed your mind, or got a new version of the design? Delete everything and drop the new archive in — nothing here is one-way.', 'wow-signal' ),
			} )
		);

		body.push(
			el( 'p', { class: 'wow-import__actions' }, [
				build,
				el( 'button', {
					type: 'button',
					class: 'button wow-import__danger',
					text: __( 'Delete everything and start over', 'wow-signal' ),
					title: __( 'Removes only what an import created. Your own pages are left alone.', 'wow-signal' ),
					onClick: function () {
						state.confirmingReset = true;
						render();
						focusConfirm();
					},
				} ),
			] )
		);

		// With nothing counted yet the clean-up panel is hidden, so confirm here.
		if ( state.confirmingReset && ! summaryTotal( state.summary ) ) {
			body.push( renderResetConfirm() );
		}

		if ( state.job ) {
			body.push( renderProgress( state.job ) );
		}

		body.push( renderRoutes() );

		return el( 'div', { class: 'wow-import__auto' }, body );
	}

	/*
	 * How far the build has got, said in pages rather than percent. The bar
	 * is a real <progress> so assistive tech reads it as one; the sentence
	 * beside it is what the live region announces on every step.
	 */
	function renderProgress( job ) {
		var body = [];

		if ( job.starting ) {
			body.push( el( 'p', { class: 'wow-import__pending', text: __( 'Reading the design, importing fonts and images…', 'wow-signal' ) } ) );
		}

		if ( job.total ) {
			var bar = el( 'progress', {
				class: 'wow-import__bar',
				max: String( job.total ),
				value: String( job.done ),
				'aria-describedby': 'wow-import-progress-text',
			} );

			body.push( bar );
			body.push( el( 'p', { id: 'wow-import-progress-text', class: 'wow-import__progress-text', text: progressText( job ) } ) );
		}

		if ( job.errors && job.errors.length ) {
			body.push( el( 'p', { class: 'wow-import__label-inline', text: __( 'Steps that did not complete:', 'wow-signal' ) } ) );
			body.push(
				bullets(
					job.errors.map( function ( entry ) {
						return entry.label + ' — ' + entry.message;
					} ),
					'wow-import__error-list'
				)
			);
		}

		var actions = [];

		if ( job.running ) {
			actions.push(
				el( 'button', {
					type: 'button',
					class: 'button',
					text: __( 'Cancel', 'wow-signal' ),
					onClick: function () {
						job.cancelled = true;
						job.running = false;
						say( __( 'Build cancelled. The pages made so far are still on the site as drafts.', 'wow-signal' ) );
						render();
					},
				} )
			);
		} else if ( job.cancelled || ( job.errors && job.errors.length && ! job.finished ) ) {
			actions.push(
				el( 'button', {
					type: 'button',
					class: 'button wow-import__danger',
					text: __( 'Delete what was built so far', 'wow-signal' ),
					onClick: function () {
						state.confirmingReset = true;
						render();
						focusConfirm();
					},
				} )
			);
			actions.push(
				el( 'button', {
					type: 'button',
					class: 'button',
					text: __( 'Dismiss', 'wow-signal' ),
					onClick: function () {
						state.job = null;
						render();
					},
				} )
			);
		}

		if ( actions.length ) {
			body.push( el( 'p', { class: 'wow-import__actions' }, actions ) );
		}

		return el( 'div', { class: 'wow-import__progress', role: 'group', 'aria-label': __( 'Build progress', 'wow-signal' ) }, body );
	}

	function progressText( job ) {
		if ( job.finished ) {
			return __( 'The build is complete.', 'wow-signal' );
		}

		if ( job.cancelled ) {
			/* translators: 1: steps done, 2: steps in total. */
			return sprintf( __( 'Cancelled after %1$d of %2$d steps.', 'wow-signal' ), job.done, job.total );
		}

		var step = job.current;

		if ( ! step ) {
			/* translators: 1: steps done, 2: steps in total. */
			return sprintf( __( '%1$d of %2$d steps done.', 'wow-signal' ), job.done, job.total );
		}

		if ( job.smart && 'page' === step.key ) {
			return sprintf(
				/* translators: 1: what is being built, 2: steps done, 3: steps in total. */
				__( 'Building %1$s with Claude, section by section (%2$d of %3$d) — this one takes a while', 'wow-signal' ),
				stepLabel( step ),
				job.done + 1,
				job.total
			);
		}

		return sprintf(
			/* translators: 1: what is being built, 2: steps done, 3: steps in total. */
			__( 'Building %1$s (%2$d of %3$d)', 'wow-signal' ),
			stepLabel( step ),
			job.done + 1,
			job.total
		);
	}

	function stepLabel( step ) {
		if ( 'page' === step.key ) {
			return step.title || step.file;
		}

		if ( 'chrome' === step.key ) {
			return __( 'the menu, header and footer', 'wow-signal' );
		}

		return __( 'the front page and links', 'wow-signal' );
	}

	/*
	 * All three ways of converting, named in one place. The subscription route
	 * lives further down the screen, and someone who presses the big button
	 * first would never scroll far enough to learn it exists.
	 */
	function renderRoutes() {
		var rows = [
			[
				__( 'Structure only — the button above', 'wow-signal' ),
				__( 'Free, instant, no account. Headings, lists, cards and images become blocks.', 'wow-signal' ),
			],
			[
				__( 'Your Claude subscription', 'wow-signal' ),
				__( 'Free with any plan. Pick a page below, copy the brief into your Claude chat, paste the reply back. Better judgement than the button.', 'wow-signal' ),
			],
			[
				__( 'An Anthropic API key', 'wow-signal' ),
				__( 'One click per page, no copying. Roughly one to three dollars for a whole site. Set the key in Connection settings above.', 'wow-signal' ),
			],
			[
				__( 'Claude Code on this machine', 'wow-signal' ),
				__( 'The same automatic route, run through the claude command instead of the API — so it uses the subscription that command is signed in to and adds nothing to a bill. Only possible where the binary is installed and PHP may start it, which usually means your own machine rather than a client\'s hosting.', 'wow-signal' ),
			],
		];

		var list = el( 'dl', { class: 'wow-import__routes' } );

		rows.forEach( function ( row ) {
			list.appendChild( el( 'dt', { text: row[ 0 ] } ) );
			list.appendChild( el( 'dd', { text: row[ 1 ] } ) );
		} );

		return el( 'details', { class: 'wow-import__routes-wrap' }, [
			el( 'summary', { text: __( 'Four ways to convert — which should I use?', 'wow-signal' ) } ),
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

		var table = el( 'table', { class: 'widefat striped wow-import__table' }, [
			el( 'thead', {}, [
				el( 'tr', {}, [
					el( 'th', { scope: 'col', text: __( 'Page', 'wow-signal' ) } ),
					el( 'th', { scope: 'col', text: __( 'Status', 'wow-signal' ) } ),
					el( 'th', { scope: 'col', text: __( 'Actions', 'wow-signal' ) } ),
				] ),
			] ),
		] );

		var tbody = el( 'tbody', {} );

		report.pages.forEach( function ( page ) {
			var published = 'publish' === page.status;
			var actions = [
				el( 'a', { class: 'button button-small', href: page.link || page.url, target: '_blank', rel: 'noopener', text: __( 'View', 'wow-signal' ) } ),
				el( 'a', { class: 'button button-small', href: page.edit_link, text: __( 'Edit', 'wow-signal' ) } ),
			];

			if ( ! published ) {
				actions.push(
					el( 'button', {
						type: 'button',
						class: 'button button-small button-primary',
						text: __( 'Publish', 'wow-signal' ),
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
						el( 'span', {
							class: 'wow-import__section-meta',
							text: ' ' + sprintf(
								/* translators: %d: number of sections. */
								__( '(%d sections)', 'wow-signal' ),
								page.sections
							),
						} ),
						page.improved
							? el( 'span', {
									class: 'wow-import__section-meta',
									text:
										' ' +
										sprintf(
											/* translators: %d: number of sections Claude changed. */
											_n(
												'Claude corrected %d of them.',
												'Claude corrected %d of them.',
												page.improved,
												'wow-signal'
											),
											page.improved
										),
							  } )
							: null,
						page.changed && page.changed.length
							? el( 'details', { class: 'wow-import__changes' }, [
									el( 'summary', { text: __( 'What Claude changed', 'wow-signal' ) } ),
									bullets( page.changed, 'wow-import__concerns' ),
							  ] )
							: null,
						page.concerns && page.concerns.length ? bullets( page.concerns, 'wow-import__concerns' ) : null,
					] ),
					el( 'td', {}, [
						el( 'span', {
							class: 'wow-import__badge ' + ( published ? 'is-on' : 'is-off' ),
							text: published ? __( 'Published', 'wow-signal' ) : __( 'Draft', 'wow-signal' ),
						} ),
					] ),
					el( 'td', {}, [ el( 'span', { class: 'wow-import__row-actions' }, actions ) ] ),
				] )
			);
		} );

		table.appendChild( tbody );

		var out = [
			el( 'p', { class: 'wow-import__saved' }, [ el( 'strong', { text: __( 'The site is built.', 'wow-signal' ) } ) ] ),
		];

		if ( report.ai && report.ai.calls ) {
			out.push(
				el( 'p', {
					class: 'wow-import__estimate',
					text:
						'cli' === report.ai.transport
							? sprintf(
									/* translators: 1: number of calls, 2: tokens in, 3: tokens out, 4: what the same work would cost through the API. */
									__(
										'%1$d calls through Claude Code on this machine — %2$s tokens in, %3$s out. Nothing was billed; the same work through the API would have cost about %4$s.',
										'wow-signal'
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
										'wow-signal'
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
					class: 'wow-import__estimate',
					text: sprintf(
						/* translators: %d: number of replies that were thrown away. */
						_n(
							'%d reply came back as markup the editor would have rejected, so that section kept its structural conversion. The section says so in its own notes.',
							'%d replies came back as markup the editor would have rejected, so those sections kept their structural conversions. Each section says so in its own notes.',
							report.ai.discarded,
							'wow-signal'
						),
						report.ai.discarded
					),
				} )
			);
		}

		out.push( el( 'div', { class: 'wow-import__table-wrap' }, [ table ] ) );

		if ( drafts.length ) {
			out.push(
				el( 'p', { class: 'wow-import__actions' }, [
					el( 'button', {
						type: 'button',
						class: 'button button-primary',
						text: sprintf(
							/* translators: %d: number of draft pages. */
							_n( 'Publish %d draft page', 'Publish all %d draft pages', drafts.length, 'wow-signal' ),
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
					el( 'a', { href: part.edit_link, text: __( 'Edit in the Site Editor', 'wow-signal' ) } ),
				] )
			);
		} );

		if ( report.menu_link ) {
			chrome.push(
				el( 'li', {}, [
					el( 'span', { text: __( 'Main navigation', 'wow-signal' ) + ' — ' } ),
					el( 'a', { href: report.menu_link, text: __( 'Edit in the Site Editor', 'wow-signal' ) } ),
				] )
			);
		}

		if ( chrome.length ) {
			out.push( el( 'p', { class: 'wow-import__label-inline', text: __( 'Header, footer and menu:', 'wow-signal' ) } ) );
			out.push( el( 'ul', { class: 'wow-import__built' }, chrome ) );
		}

		if ( report.concerns && report.concerns.length ) {
			out.push( el( 'p', { class: 'wow-import__label-inline', text: __( 'Worth checking:', 'wow-signal' ) } ) );
			out.push( bullets( report.concerns, 'wow-import__concerns' ) );
		}

		return el( 'section', { class: 'wow-import__step wow-import__result', 'aria-label': __( 'What the build made', 'wow-signal' ) }, [
			el( 'h2', { text: __( 'Pages built', 'wow-signal' ) } ),
		].concat( out ) );
	}

	function publishPages( ids, button ) {
		button.disabled = true;
		say( __( 'Publishing…', 'wow-signal' ) );

		apiFetch( {
			path: '/wow-signal/v1/build/publish',
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
						_n( 'Published %d page.', 'Published %d pages.', rows.length, 'wow-signal' ),
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
		say( __( 'Starting the build — reading the design, importing fonts and images…', 'wow-signal' ) );

		apiFetch( {
			path: '/wow-signal/v1/build/start',
			method: 'POST',
			data: {
				slug: state.design.slug,
				language: language,
				publish: !! publish,
				keep_archive: !! state.keepArchive,
				includes: includes,
				smart: !! ( state.smart && modelReady() ),
				refine: !! ( state.smart && state.refine && modelReady() ),
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
				};
				render();
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
			path: '/wow-signal/v1/build/step',
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
				if ( error && 'wow_signal_no_job' === error.code ) {
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
				__( 'Built and published %1$d pages and imported %2$d images. Look through them, then edit anything you like.', 'wow-signal' ),
				report.pages.length,
				report.media
			)
			: sprintf(
				/* translators: 1: pages built, 2: images imported. */
				__( 'Built %1$d draft pages and imported %2$d images. Nothing is public yet — review each page and publish it when it is ready.', 'wow-signal' ),
				report.pages.length,
				report.media
			);

		if ( result.archive_removed ) {
			message += ' ' + __( 'The uploaded design has been removed from uploads.', 'wow-signal' );

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
		say( __( 'Undoing…', 'wow-signal' ) );

		apiFetch( { path: '/wow-signal/v1/reset', method: 'POST' } )
			.then( function ( counts ) {
				state.built = null;
				state.job = null;
				state.confirmingReset = false;
				say(
					sprintf(
						/* translators: 1: pages removed, 2: images removed. */
						__( 'Removed %1$d pages and %2$d images. The site is back to how it was.', 'wow-signal' ),
						counts.pages,
						counts.media
					)
				);

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
		return apiFetch( { path: '/wow-signal/v1/summary' } )
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
		var field = document.getElementById( 'wow-import-confirm' );

		if ( field ) {
			field.focus();
		}
	}

	function renderResetConfirm() {
		return renderConfirm( {
			run: undoBuild,
			hint: __( 'This cannot be undone. Only content this import created is removed; pages, menus and images you made yourself stay exactly as they are.', 'wow-signal' ),
			cancel: function () {
				state.confirmingReset = false;
				render();
			},
		} );
	}

	function renderPurgeConfirm() {
		return renderConfirm( {
			run: purgeDesigns,
			hint: __( 'This removes the unpacked design files from uploads. Pages, images and fonts already imported are not affected — only the source archive goes, and it can be uploaded again.', 'wow-signal' ),
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
			id: 'wow-import-confirm',
			class: 'wow-import__field wow-import__confirm-field',
			autocomplete: 'off',
			spellcheck: 'false',
			'aria-describedby': 'wow-import-confirm-hint',
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
			class: 'button wow-import__danger',
			disabled: 'disabled',
			text: __( 'Delete now', 'wow-signal' ),
			onClick: function ( event ) {
				options.run( event.target );
			},
		} );

		var cancel = el( 'button', {
			type: 'button',
			class: 'button',
			text: __( 'Cancel', 'wow-signal' ),
			onClick: options.cancel,
		} );

		return el( 'div', { class: 'wow-import__confirm', role: 'group', 'aria-labelledby': 'wow-import-confirm-label' }, [
			el( 'label', {
				id: 'wow-import-confirm-label',
				for: 'wow-import-confirm',
				text: sprintf(
					/* translators: %s: the word to type, in capitals. */
					__( 'Type %s to confirm', 'wow-signal' ),
					word
				),
			} ),
			el( 'p', { class: 'wow-import__actions' }, [ field, go, cancel ] ),
			el( 'p', {
				id: 'wow-import-confirm-hint',
				class: 'wow-import__hint',
				text: options.hint,
			} ),
		] );
	}

	function purgeDesigns( button ) {
		button.disabled = true;
		say( __( 'Removing the uploaded designs…', 'wow-signal' ) );

		apiFetch( { path: '/wow-signal/v1/designs', method: 'DELETE' } )
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

				say(
					sprintf(
						/* translators: %d: number of designs removed. */
						_n( 'Removed %d uploaded design.', 'Removed %d uploaded designs.', result.removed, 'wow-signal' ),
						result.removed
					)
				);
				render();
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

		var body = [ el( 'h3', { text: __( 'Clean up', 'wow-signal' ) } ) ];

		if ( summaryTotal( summary ) ) {
			body.push(
				el( 'p', {
					text: sprintf(
						/* translators: 1: pages, 2: template parts, 3: menus, 4: images, 5: fonts. */
						__( 'This import created %1$d pages, %2$d template parts, %3$d menus, %4$d images, %5$d fonts.', 'wow-signal' ),
						summary.pages || 0,
						summary.parts || 0,
						summary.menus || 0,
						summary.media || 0,
						summary.fonts || 0
					),
				} )
			);
			body.push(
				el( 'p', {
					class: 'wow-import__hint',
					text: __( 'Your own content is untouched — only what the import added is removed.', 'wow-signal' ),
				} )
			);

			if ( state.confirmingReset ) {
				body.push( renderResetConfirm() );
			} else {
				body.push(
					el( 'p', { class: 'wow-import__actions' }, [
						el( 'button', {
							type: 'button',
							class: 'button wow-import__danger',
							text: __( 'Delete everything this import added', 'wow-signal' ),
							onClick: function () {
								state.confirmingReset = true;
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
						_n( '%1$d uploaded design is still unpacked in uploads, taking %2$s.', '%1$d uploaded designs are still unpacked in uploads, taking %2$s.', archive.count, 'wow-signal' ),
						archive.count,
						bytesText( archive.bytes )
					),
				} )
			);

			if ( state.confirmingPurge ) {
				body.push( renderPurgeConfirm() );
			} else {
				body.push(
					el( 'p', { class: 'wow-import__actions' }, [
						el( 'button', {
							type: 'button',
							class: 'button wow-import__danger',
							text: sprintf(
								/* translators: %s: size on disk. */
								__( 'Remove uploaded designs (%s)', 'wow-signal' ),
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

		return el( 'section', { class: 'wow-import__cleanup', 'aria-label': __( 'Clean up a previous import', 'wow-signal' ) }, body );
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

		if ( pending > 0 ) {
			readouts.push(
				el( 'span', { class: 'wow-import__meter-figure' }, [
					el( 'strong', { text: money( outstandingEstimate() ) } ),
					el( 'span', {
						text: ' ' + sprintf(
							/* translators: %d: number of sections not yet converted. */
							_n( 'to convert %d remaining section', 'to convert %d remaining sections', pending, 'wow-signal' ),
							pending
						),
					} ),
				] )
			);
		}

		if ( state.spend && state.spend.conversions > 0 ) {
			readouts.push(
				el( 'span', { class: 'wow-import__meter-figure' }, [
					el( 'strong', { text: money( state.spend.cost ) } ),
					el( 'span', {
						text: ' ' + sprintf(
							/* translators: 1: number of conversions run, 2: input tokens, 3: output tokens. */
							__( 'spent so far · %1$d conversions · %2$s in, %3$s out', 'wow-signal' ),
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
				el( 'span', { class: 'wow-import__meter-figure is-warning' }, [
					el( 'strong', { text: String( state.limit.remaining ) } ),
					el( 'span', {
						text: ' ' + sprintf(
							/* translators: %d: minutes until the hourly limit resets. */
							__( 'conversions left this hour · resets in %d min', 'wow-signal' ),
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
				class: 'wow-import__meter-note',
				text: __( 'Estimated from published list prices — not a bill.', 'wow-signal' ),
			} )
		);

		return el( 'p', { class: 'wow-import__meter' }, readouts );
	}

	function renderSections() {
		if ( ! state.page ) {
			return null;
		}

		var list = el( 'ol', { class: 'wow-import__sections' } );

		state.sections.forEach( function ( section ) {
			list.appendChild( renderSection( section ) );
		} );

		var pending = outstandingCount();

		return el( 'section', { class: 'wow-import__step' }, [
			el( 'h2', { text: __( '3. Convert each section', 'wow-signal' ) } ),
			el( 'p', {
				class: 'wow-import__hint',
				text: __( 'Convert a section, read what it says, then keep it. Nothing reaches your site until you press Keep.', 'wow-signal' ),
			} ),
			renderMeter(),
			el( 'p', { class: 'wow-import__actions' }, [
				el( 'button', {
					type: 'button',
					class: 'button',
					text: pending
						? sprintf(
								/* translators: 1: number of sections, 2: estimated cost. */
								_n( 'Convert %1$d section — about %2$s', 'Convert %1$d sections — about %2$s', pending, 'wow-signal' ),
								pending,
								money( outstandingEstimate() )
						  )
						: __( 'Every section is converted', 'wow-signal' ),
					disabled: pending ? null : 'disabled',
					onClick: convertAll,
				} ),
				el( 'button', {
					type: 'button',
					class: 'button button-primary',
					text: __( 'Keep the whole page as a draft', 'wow-signal' ),
					onClick: savePage,
				} ),
			] ),
			state.savedPage
				? el( 'p', { class: 'wow-import__saved' }, [
						el( 'span', { text: __( 'Draft page created.', 'wow-signal' ) + ' ' } ),
						el( 'a', {
							href: state.savedPage.edit,
							text: __( 'Open it in the editor', 'wow-signal' ),
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
			id: 'wow-import-paste',
			class: 'wow-import__paste',
			rows: '6',
			spellcheck: 'false',
			placeholder: __( 'Paste the whole reply here, including the opening [ and closing ]', 'wow-signal' ),
		} );

		/*
		 * The manual way out. Clipboard access can be refused for reasons the
		 * person cannot do anything about — an unfocused window, a locked-down
		 * browser, a site served over plain http — and "copy failed" with no
		 * alternative would strand them. The brief lands here instead, ready
		 * to select by hand.
		 */
		var briefBox = el( 'textarea', {
			id: 'wow-import-brief',
			class: 'wow-import__paste',
			rows: '6',
			readonly: 'readonly',
			spellcheck: 'false',
			hidden: 'hidden',
		} );

		var briefLabel = el( 'label', {
			class: 'wow-import__label',
			for: 'wow-import-brief',
			text: __( 'The brief — select it all and copy', 'wow-signal' ),
			hidden: 'hidden',
		} );

		var copy = el( 'button', {
			type: 'button',
			class: 'button button-primary',
			text: __( 'Copy the brief', 'wow-signal' ),
			onClick: function ( event ) {
				copyBrief( event.target, briefBox, briefLabel );
			},
		} );

		return el( 'details', { class: 'wow-import__bridge' }, [
			el( 'summary', { text: __( 'No API key? Use your own Claude subscription instead', 'wow-signal' ) } ),
			el( 'ol', { class: 'wow-import__bridge-steps' }, [
				el( 'li', { text: __( 'Copy the brief for this page.', 'wow-signal' ) } ),
				el( 'li', { text: __( 'Paste it into your Claude conversation and send it.', 'wow-signal' ) } ),
				el( 'li', { text: __( 'Copy the whole reply and paste it below.', 'wow-signal' ) } ),
			] ),
			el( 'p', {}, [ copy ] ),
			briefLabel,
			briefBox,
			el( 'label', {
				class: 'wow-import__label',
				for: 'wow-import-paste',
				text: __( 'Claude’s reply', 'wow-signal' ),
			} ),
			paste,
			el( 'p', {}, [
				el( 'button', {
					type: 'button',
					class: 'button',
					text: __( 'Read the reply', 'wow-signal' ),
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
		say( __( 'Building the brief…', 'wow-signal' ) );

		apiFetch( {
			path:
				'/wow-signal/v1/designs/' +
				encodeURIComponent( state.design.slug ) +
				'/brief?file=' +
				encodeURIComponent( state.page.file ),
		} )
			.then( function ( data ) {
				briefBox.value = data.brief;

				var size = sprintf(
					/* translators: 1: number of sections, 2: size in kilobytes. */
					__( 'Brief for %1$d sections, %2$d KB.', 'wow-signal' ),
					data.sections,
					Math.round( data.bytes / 1024 )
				);

				return writeClipboard( data.brief ).then(
					function () {
						button.textContent = __( 'Copied — now paste it into Claude', 'wow-signal' );
						say( size + ' ' + __( 'Copied. Paste it into Claude, then bring the reply back.', 'wow-signal' ) );

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
								__( 'The browser would not copy it, so it is in the box below — select it all and copy it yourself.', 'wow-signal' ),
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
				reject( new Error( __( 'The browser refused to copy.', 'wow-signal' ) ) );
			}
		} );
	}

	function acceptPaste( reply ) {
		if ( ! reply.trim() ) {
			say( __( 'Paste the reply first.', 'wow-signal' ), true );
			return;
		}

		say( __( 'Reading the reply…', 'wow-signal' ) );

		apiFetch( {
			path: '/wow-signal/v1/paste',
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
							__( 'Read %1$d sections, but this page has %2$d. Check the reply was not cut off.', 'wow-signal' ),
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
						__( 'Read %d sections. Review each one before keeping it.', 'wow-signal' ),
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
			el( 'div', { class: 'wow-import__section-head' }, [
				el( 'strong', { text: section.label } ),
				el( 'span', {
					class: 'wow-import__section-meta',
					text: sprintf(
						/* translators: 1: element name, 2: word count, 3: image count. */
						__( '<%1$s> · %2$d words · %3$d images', 'wow-signal' ),
						section.tag,
						section.words,
						section.images
					),
				} ),
			] ),
			el( 'p', { class: 'wow-import__excerpt', text: section.excerpt } ),
		];

		if ( ! result ) {
			body.push(
				el( 'button', {
					type: 'button',
					class: 'button',
					text: __( 'Convert this section', 'wow-signal' ),
					onClick: function () {
						convert( section );
					},
				} )
			);
		} else if ( result.pending ) {
			body.push( el( 'p', { class: 'wow-import__pending', text: __( 'Converting…', 'wow-signal' ) } ) );
		} else if ( result.error ) {
			body.push( el( 'p', { class: 'wow-import__error', text: result.error } ) );
			body.push(
				el( 'button', {
					type: 'button',
					class: 'button',
					text: __( 'Try again', 'wow-signal' ),
					onClick: function () {
						convert( section );
					},
				} )
			);
		} else {
			body = body.concat( renderResult( section, result ) );
		}

		return el( 'li', { class: 'wow-import__section' }, body );
	}

	function renderResult( section, result ) {
		var out = [];

		if ( result.summary ) {
			out.push( el( 'p', { class: 'wow-import__summary', text: result.summary } ) );
		}

		if ( ! result.valid ) {
			out.push( el( 'p', { class: 'wow-import__error', text: __( 'This conversion was refused:', 'wow-signal' ) } ) );
			out.push( bullets( result.errors, 'wow-import__error-list' ) );
			out.push(
				el( 'button', {
					type: 'button',
					class: 'button',
					text: __( 'Try again', 'wow-signal' ),
					onClick: function () {
						convert( section );
					},
				} )
			);

			return out;
		}

		if ( result.editable && result.editable.length ) {
			out.push( el( 'p', { class: 'wow-import__label-inline', text: __( 'You will be able to edit:', 'wow-signal' ) } ) );
			out.push( bullets( result.editable, 'wow-import__editable' ) );
		}

		if ( result.concerns && result.concerns.length ) {
			out.push( el( 'p', { class: 'wow-import__label-inline', text: __( 'Worth checking:', 'wow-signal' ) } ) );
			out.push( bullets( result.concerns, 'wow-import__concerns' ) );
		}

		if ( result.notes && result.notes.length ) {
			out.push( el( 'p', { class: 'wow-import__label-inline', text: __( 'Quality notes:', 'wow-signal' ) } ) );
			out.push( bullets( result.notes, 'wow-import__notes' ) );
		}

		var preview = el( 'div', { class: 'wow-import__preview' } );
		// Server-validated and kses-filtered; see Importer::preview().
		preview.innerHTML = result.preview;

		out.push(
			el( 'details', { class: 'wow-import__preview-wrap' }, [
				el( 'summary', { text: __( 'Preview', 'wow-signal' ) } ),
				preview,
			] )
		);

		if ( result.saved ) {
			out.push(
				el( 'p', { class: 'wow-import__saved' }, [
					el( 'span', { text: __( 'Kept.', 'wow-signal' ) + ' ' } ),
					el( 'a', { href: result.saved.edit, text: __( 'Open it in the editor', 'wow-signal' ) } ),
				] )
			);

			return out;
		}

		out.push(
			el( 'div', { class: 'wow-import__actions' }, [
				el( 'button', {
					type: 'button',
					class: 'button button-primary',
					text: __( 'Keep as a reusable section', 'wow-signal' ),
					onClick: function () {
						save( section, result, 'pattern' );
					},
				} ),
				el( 'button', {
					type: 'button',
					class: 'button',
					text: __( 'Keep as a draft page', 'wow-signal' ),
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

	// --------------------------------------------------------------- actions

	function convert( section ) {
		if ( ! cfg.hasKey ) {
			say( __( 'Add your Anthropic API key in the connection settings first.', 'wow-signal' ), true );
			return;
		}

		state.results[ section.position ] = { pending: true };
		render();
		say( sprintf( /* translators: %s: section name. */ __( 'Converting “%s”…', 'wow-signal' ), section.label ) );

		return apiFetch( {
			path: '/wow-signal/v1/convert',
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
						? sprintf( /* translators: %s: section name. */ __( '“%s” converted. Review it below.', 'wow-signal' ), section.label )
						: sprintf( /* translators: %s: section name. */ __( '“%s” was refused — see the reason below.', 'wow-signal' ), section.label ),
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
				say( __( 'All sections converted. Review each one before keeping it.', 'wow-signal' ) );
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
			say( __( 'Convert at least one section first.', 'wow-signal' ), true );
			return;
		}

		var skipped = state.sections.length - ordered.length;

		say( __( 'Building the page…', 'wow-signal' ) );

		apiFetch( {
			path: '/wow-signal/v1/save',
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
							__( 'Draft page created from %1$d sections. %2$d were left out because they are not converted or were refused.', 'wow-signal' ),
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
						__( 'Draft page created from all %d sections.', 'wow-signal' ),
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
			path: '/wow-signal/v1/save',
			method: 'POST',
			data: { markup: result.markup, title: title, as: as },
		} )
			.then( function ( saved ) {
				state.results[ section.position ].saved = saved;
				render();
				say( __( 'Saved.', 'wow-signal' ) );
			} )
			.catch( function ( error ) {
				say( errorText( error ), true );
			} );
	}

	// ---------------------------------------------------------------- render

	function render() {
		// A side-by-side preview needs the whole window, not the 60rem column.
		var screen = app.closest( '.wow-import' );

		if ( screen ) {
			screen.classList.toggle( 'is-previewing', !! state.preview );
		}

		/*
		 * The live region is created once and kept across renders. Destroying
		 * and recreating it in the same task as say() would leave screen
		 * readers with nothing to announce.
		 */
		var status = document.getElementById( 'wow-import-status' );

		if ( ! status ) {
			status = el( 'p', {
				id: 'wow-import-status',
				class: 'wow-import__status',
				role: 'status',
				'aria-live': 'polite',
			} );
		}

		app.textContent = '';
		app.appendChild( status );

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
} )( window.wp );

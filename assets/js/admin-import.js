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
	};

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
		var input = el( 'input', {
			type: 'file',
			id: 'wow-archive',
			accept: '.zip,application/zip',
			class: 'wow-import__file',
		} );

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
						state.design = result;
						render();
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
		] );
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

			list.appendChild(
				el( 'li', {}, [
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
					] ),
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

		return el( 'section', { class: 'wow-import__step' }, children );
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
				state.results = {};
				state.savedPage = null;
				say(
					sprintf(
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
			{ id: 'wow-import-language', class: 'wow-import__language' },
			choices
		);

		var build = el( 'button', {
			type: 'button',
			class: 'button button-primary button-hero',
			text: __( 'Build the whole site', 'wow-signal' ),
			onClick: function ( event ) {
				buildSite( event.target, choices.length ? language.value : '' );
			},
		} );

		var body = [
			el( 'h3', { text: __( 'Build everything at once', 'wow-signal' ) } ),
			el( 'p', {
				class: 'wow-import__hint',
				text: __( 'Creates every page, imports the images, builds the menu and the header and footer, and sets the front page. No API key and no conversation needed. You can undo it and run it again.', 'wow-signal' ),
			} ),
		];

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
					onClick: function ( event ) {
						undoBuild( event.target );
					},
				} ),
			] )
		);

		if ( state.built ) {
			body.push( renderBuilt( state.built ) );
		}

		body.push( renderRoutes() );

		return el( 'div', { class: 'wow-import__auto' }, body );
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
		];

		var list = el( 'dl', { class: 'wow-import__routes' } );

		rows.forEach( function ( row ) {
			list.appendChild( el( 'dt', { text: row[ 0 ] } ) );
			list.appendChild( el( 'dd', { text: row[ 1 ] } ) );
		} );

		return el( 'details', { class: 'wow-import__routes-wrap' }, [
			el( 'summary', { text: __( 'Three ways to convert — which should I use?', 'wow-signal' ) } ),
			list,
		] );
	}

	function renderBuilt( report ) {
		var list = el( 'ul', { class: 'wow-import__built' } );

		report.pages.forEach( function ( page ) {
			list.appendChild(
				el( 'li', {}, [
					el( 'a', { href: page.url, text: page.title } ),
					el( 'span', {
						class: 'wow-import__section-meta',
						text: sprintf(
							/* translators: %d: number of sections. */
							' ' + __( '(%d sections)', 'wow-signal' ),
							page.sections
						),
					} ),
				] )
			);
		} );

		var out = [ el( 'p', { class: 'wow-import__saved' }, [ el( 'strong', { text: __( 'The site is built.', 'wow-signal' ) } ) ] ), list ];

		if ( report.concerns && report.concerns.length ) {
			out.push( el( 'p', { class: 'wow-import__label-inline', text: __( 'Worth checking:', 'wow-signal' ) } ) );
			out.push( bullets( report.concerns, 'wow-import__concerns' ) );
		}

		return el( 'div', {}, out );
	}

	function buildSite( button, language ) {
		button.disabled = true;
		say( __( 'Building the site — this takes a moment…', 'wow-signal' ) );

		apiFetch( {
			path: '/wow-signal/v1/build',
			method: 'POST',
			data: { slug: state.design.slug, language: language, publish: true },
		} )
			.then( function ( report ) {
				state.built = report;
				render();
				say(
					sprintf(
						/* translators: 1: pages built, 2: images imported. */
						__( 'Built %1$d pages and imported %2$d images. Look through them, then edit anything you like.', 'wow-signal' ),
						report.pages.length,
						report.media
					)
				);
			} )
			.catch( function ( error ) {
				say( errorText( error ), true );
			} )
			.then( function () {
				button.disabled = false;
			} );
	}

	function undoBuild( button ) {
		button.disabled = true;
		say( __( 'Undoing…', 'wow-signal' ) );

		apiFetch( { path: '/wow-signal/v1/reset', method: 'POST' } )
			.then( function ( counts ) {
				state.built = null;
				render();
				say(
					sprintf(
						/* translators: 1: pages removed, 2: images removed. */
						__( 'Removed %1$d pages and %2$d images. The site is back to how it was.', 'wow-signal' ),
						counts.pages,
						counts.media
					)
				);
			} )
			.catch( function ( error ) {
				say( errorText( error ), true );
			} )
			.then( function () {
				button.disabled = false;
			} );
	}

	// -------------------------------------------------------------- sections

	function renderSections() {
		if ( ! state.page ) {
			return null;
		}

		var list = el( 'ol', { class: 'wow-import__sections' } );

		state.sections.forEach( function ( section ) {
			list.appendChild( renderSection( section ) );
		} );

		return el( 'section', { class: 'wow-import__step' }, [
			el( 'h2', { text: __( '3. Convert each section', 'wow-signal' ) } ),
			el( 'p', {
				class: 'wow-import__hint',
				text: __( 'Convert a section, read what it says, then keep it. Nothing reaches your site until you press Keep.', 'wow-signal' ),
			} ),
			el( 'p', { class: 'wow-import__actions' }, [
				el( 'button', {
					type: 'button',
					class: 'button',
					text: __( 'Convert every section', 'wow-signal' ),
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

		var queue = state.sections.slice();

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
		var status = document.getElementById( 'wow-import-status' );
		var text = status ? status.textContent : '';
		var isError = status ? status.className.indexOf( 'is-error' ) > -1 : false;

		app.textContent = '';

		app.appendChild(
			el( 'p', {
				id: 'wow-import-status',
				class: 'wow-import__status' + ( isError ? ' is-error' : '' ),
				role: 'status',
				'aria-live': 'polite',
				text: text,
			} )
		);

		app.appendChild( renderUpload() );

		var pages = renderPages();

		if ( pages ) {
			app.appendChild( pages );
		}

		var sections = renderSections();

		if ( sections ) {
			app.appendChild( sections );
		}
	}

	render();
} )( window.wp );

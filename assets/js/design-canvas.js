/**
 * Editing a generated design block where it is drawn.
 *
 * ACF Pro draws a generated block as its preview and, inside the iframed
 * editor every supported WordPress now uses, never as its form — so the
 * fields sit in the sidebar and the section on the canvas is a picture.
 * The block writer marks every field's element with `data-qs-field`, and
 * this reads those marks after each preview render and makes the elements
 * themselves editable: click a heading and type, click a picture and choose
 * another, click a button and change its words or its address, add or
 * remove a row of a repeated list. A "Fields" button on each section opens
 * a panel of every field beside the canvas. What is typed is written to the
 * same block data the sidebar edits, so both stay in step.
 *
 * Two spellings of that data exist and both are handled. The importer
 * writes a block's data by field name, flat — `heading`, `items` (a count),
 * `items_0_point`. Once the editor has loaded the block, ACF rewrites it by
 * field key — `field_qs_…_heading`, and a repeater as an object of rows,
 * `{ "row-0": { "field_qs_…_row_point": "…" } }`. Writing by name into the
 * keyed spelling is silently ignored, which is how the first version of
 * this file edited nothing at all.
 *
 * Hand-written against the global `wp` and `acf` objects. No build step.
 *
 * @package Qwerty\Soft
 */

( function ( wp, acf, $ ) {
	'use strict';

	if ( ! wp || ! wp.data || ! acf || 'function' !== typeof acf.addAction ) {
		return;
	}

	var settings = window.qsDesignCanvas || {};
	var words    = settings.i18n || {};
	var allowed  = Array.isArray( settings.tags ) ? settings.tags : [ 'br', 'span', 'strong', 'b', 'em', 'i', 'small', 'sup', 'sub', 'mark', 'u', 's', 'code', 'a', 'time', 'abbr' ];
	var select   = wp.data.select;
	var dispatch = wp.data.dispatch;

	var EDITING  = 'qs-canvas-editing';
	var BAR      = 'qs-canvas-bar';
	var REPEATER = 'items';

	/* ------------------------------------------------------------------ */
	/* The block and its data                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * The block a preview element belongs to.
	 *
	 * @param {Element} el Any element inside the preview.
	 * @return {string|null} Client id.
	 */
	function clientIdOf( el ) {
		var wrapper = el.closest( '[data-block]' );

		return wrapper ? wrapper.getAttribute( 'data-block' ) : null;
	}

	/**
	 * What the field group says about this block's fields.
	 *
	 * @param {Object} attributes Block attributes.
	 * @return {{fields: Object, rows: Object, items: string, itemsKey: string}}
	 */
	function metaOf( attributes ) {
		var known = ( settings.blocks || {} )[ attributes.name ] || {};

		return {
			fields: known.fields || {},
			rows: known.rows || {},
			items: known.items || '',
			itemsKey: known.itemsKey || '',
		};
	}

	/**
	 * A deep copy of the block's ACF data.
	 *
	 * @param {string} clientId Block.
	 * @return {Object|null}
	 */
	function dataOf( clientId ) {
		var block = select( 'core/block-editor' ).getBlock( clientId );

		if ( ! block ) {
			return null;
		}

		return JSON.parse( JSON.stringify( block.attributes.data || {} ) );
	}

	/**
	 * Whether the data is spelled by field key (ACF, in the editor) or by name (the importer).
	 *
	 * @param {Object} data Block data.
	 * @return {boolean}
	 */
	function keyed( data ) {
		var names = Object.keys( data );

		for ( var i = 0; i < names.length; i++ ) {
			if ( 0 === names[ i ].indexOf( 'field_' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The field key of a field, from the group or from the data's own `_name` note.
	 *
	 * @param {Object}  attributes Block attributes.
	 * @param {Object}  data       Block data.
	 * @param {string}  name       Field name.
	 * @param {boolean} inRow      Whether it is a repeater sub-field.
	 * @return {string}
	 */
	function keyOf( attributes, data, name, inRow ) {
		var meta = metaOf( attributes );
		var map  = inRow ? meta.rows : meta.fields;

		if ( map[ name ] && map[ name ].key ) {
			return map[ name ].key;
		}

		if ( ! inRow && data[ '_' + name ] ) {
			return data[ '_' + name ];
		}

		if ( inRow && data[ '_' + REPEATER + '_0_' + name ] ) {
			return data[ '_' + REPEATER + '_0_' + name ];
		}

		return '';
	}

	/**
	 * The repeater's own key.
	 *
	 * @param {Object} attributes Block attributes.
	 * @param {Object} data       Block data.
	 * @return {string}
	 */
	function repeaterKey( attributes, data ) {
		return metaOf( attributes ).itemsKey || data[ '_' + REPEATER ] || '';
	}

	/**
	 * The rows of the repeater, whichever spelling, as a list of {name: value}.
	 *
	 * @param {Object} attributes Block attributes.
	 * @param {Object} data       Block data.
	 * @return {Array<Object>}
	 */
	function rowsOf( attributes, data ) {
		var meta = metaOf( attributes );
		var rows = [];

		if ( keyed( data ) ) {
			var stored = data[ repeaterKey( attributes, data ) ];

			if ( ! stored || 'object' !== typeof stored ) {
				return rows;
			}

			var ids = Object.keys( stored ).filter( function ( id ) {
				return /^row-\d+$/.test( id );
			} ).sort( function ( a, b ) {
				return parseInt( a.slice( 4 ), 10 ) - parseInt( b.slice( 4 ), 10 );
			} );

			$.each( ids, function ( i, id ) {
				var row = {};

				$.each( stored[ id ] || {}, function ( key, value ) {
					var name = key;

					$.each( meta.rows, function ( candidate, info ) {
						if ( info.key === key ) {
							name = candidate;
						}
					} );

					row[ name ] = value;
				} );

				rows.push( row );
			} );

			return rows;
		}

		var count = parseInt( data[ REPEATER ] || '0', 10 );

		for ( var index = 0; index < count; index++ ) {
			var flat   = {};
			var prefix = REPEATER + '_' + index + '_';

			$.each( data, function ( key, value ) {
				if ( 0 === key.indexOf( prefix ) ) {
					flat[ key.substring( prefix.length ) ] = value;
				}
			} );

			rows.push( flat );
		}

		return rows;
	}

	/**
	 * Read one field's value.
	 *
	 * @param {Object} attributes Block attributes.
	 * @param {Object} data       Block data.
	 * @param {string} name       Field name.
	 * @param {number} index      Row, or -1 for a field of the block.
	 * @return {*}
	 */
	function getValue( attributes, data, name, index ) {
		if ( index >= 0 ) {
			var rows = rowsOf( attributes, data );

			return rows[ index ] ? rows[ index ][ name ] : undefined;
		}

		if ( keyed( data ) ) {
			return data[ keyOf( attributes, data, name, false ) ];
		}

		return data[ name ];
	}

	/**
	 * Write one field's value into a copy of the data.
	 *
	 * @param {Object} attributes Block attributes.
	 * @param {Object} data       Block data, changed in place.
	 * @param {string} name       Field name.
	 * @param {number} index      Row, or -1 for a field of the block.
	 * @param {*}      value      New value.
	 * @return {void}
	 */
	function setValue( attributes, data, name, index, value ) {
		if ( keyed( data ) ) {
			if ( index < 0 ) {
				data[ keyOf( attributes, data, name, false ) ] = value;

				return;
			}

			var rk = repeaterKey( attributes, data );

			if ( ! data[ rk ] || 'object' !== typeof data[ rk ] ) {
				data[ rk ] = {};
			}

			if ( ! data[ rk ][ 'row-' + index ] ) {
				data[ rk ][ 'row-' + index ] = {};
			}

			data[ rk ][ 'row-' + index ][ keyOf( attributes, data, name, true ) ] = value;

			return;
		}

		if ( index < 0 ) {
			data[ name ] = value;

			return;
		}

		data[ REPEATER + '_' + index + '_' + name ] = value;

		if ( ! data[ '_' + REPEATER + '_' + index + '_' + name ] ) {
			data[ '_' + REPEATER + '_' + index + '_' + name ] = keyOf( attributes, data, name, true );
		}
	}

	/**
	 * Replace the repeater's rows in a copy of the data.
	 *
	 * @param {Object}        attributes Block attributes.
	 * @param {Object}        data       Block data, changed in place.
	 * @param {Array<Object>} rows       Rows as {name: value}.
	 * @return {void}
	 */
	function setRows( attributes, data, rows ) {
		if ( keyed( data ) ) {
			var stored = {};

			$.each( rows, function ( index, row ) {
				var out = {};

				$.each( row, function ( name, value ) {
					out[ keyOf( attributes, data, name, true ) || name ] = value;
				} );

				stored[ 'row-' + index ] = out;
			} );

			data[ repeaterKey( attributes, data ) ] = stored;

			return;
		}

		var keys = {};

		$.each( Object.keys( data ), function ( i, key ) {
			if ( 0 === key.indexOf( '_' + REPEATER + '_' ) ) {
				keys[ key.replace( /^_items_\d+_/, '' ) ] = data[ key ];
			}

			if ( 0 === key.indexOf( REPEATER + '_' ) || 0 === key.indexOf( '_' + REPEATER + '_' ) ) {
				delete data[ key ];
			}
		} );

		data[ REPEATER ] = rows.length;

		$.each( rows, function ( index, row ) {
			$.each( row, function ( name, value ) {
				data[ REPEATER + '_' + index + '_' + name ] = value;

				var key = keys[ name ] || keyOf( attributes, data, name, true );

				if ( key ) {
					data[ '_' + REPEATER + '_' + index + '_' + name ] = key;
				}
			} );
		} );
	}

	/**
	 * Write to the block, and to the sidebar's form if it is showing this block.
	 *
	 * @param {string}        clientId   Block.
	 * @param {Object}        attributes Block attributes.
	 * @param {Array<Object>} patches    [{name, index, value}].
	 * @return {void}
	 */
	function write( clientId, attributes, patches ) {
		var data = dataOf( clientId );

		if ( ! data ) {
			return;
		}

		$.each( patches, function ( i, patch ) {
			setValue( attributes, data, patch.name, patch.index, patch.value );
			syncSidebar( keyOf( attributes, data, patch.name, patch.index >= 0 ), patch.index, patch.value );
		} );

		dispatch( 'core/block-editor' ).updateBlockAttributes( clientId, { data: data } );
	}

	/**
	 * Set the matching input in ACF's sidebar form quietly, so a later change
	 * there does not write the old value back over the canvas's.
	 *
	 * @param {string} key   Field key.
	 * @param {number} index Row, or -1.
	 * @param {*}      value Value.
	 * @return {void}
	 */
	function syncSidebar( key, index, value ) {
		if ( ! key || 'function' !== typeof acf.getFields ) {
			return;
		}

		$.each( acf.getFields( { key: key } ), function ( i, field ) {
			if ( index >= 0 ) {
				var row = field.$el.closest( '.acf-row' );

				if ( ! row.length || row.attr( 'data-id' ) !== 'row-' + index ) {
					return;
				}
			}

			if ( 'function' === typeof field.val ) {
				try {
					field.val( value, true );
				} catch ( e ) {
					// A field type that cannot take this shape leaves the block's own data as the truth.
				}
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Values from the canvas                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Where a marked element's value lives.
	 *
	 * @param {Element} el Marked element.
	 * @return {{name: string, index: number}}
	 */
	function addressOf( el ) {
		var name = el.getAttribute( 'data-qs-field' );
		var row  = el.closest( '[data-qs-row]' );

		return {
			name: name,
			index: row ? parseInt( row.getAttribute( 'data-qs-index' ) || '0', 10 ) : -1,
		};
	}

	/**
	 * Markup a rich field may keep, and nothing else.
	 *
	 * @param {string} html What the element holds after editing.
	 * @return {string}
	 */
	function clean( html ) {
		var box = document.createElement( 'div' );

		box.innerHTML = html;

		var walk = function ( node ) {
			$.each( Array.prototype.slice.call( node.childNodes ), function ( i, child ) {
				if ( 1 !== child.nodeType ) {
					return;
				}

				var tag = child.tagName.toLowerCase();

				if ( -1 === allowed.indexOf( tag ) ) {
					while ( child.firstChild ) {
						node.insertBefore( child.firstChild, child );
					}

					node.removeChild( child );

					return;
				}

				$.each( Array.prototype.slice.call( child.attributes ), function ( j, attribute ) {
					var name = attribute.name.toLowerCase();
					var keep = [ 'class', 'id', 'title', 'lang', 'dir' ];

					if ( 'a' === tag ) {
						keep = keep.concat( [ 'href', 'target', 'rel' ] );
					}

					if ( 'time' === tag ) {
						keep.push( 'datetime' );
					}

					if ( -1 === keep.indexOf( name ) || /^\s*javascript:/i.test( attribute.value ) ) {
						child.removeAttribute( attribute.name );
					}
				} );

				walk( child );
			} );
		};

		walk( box );

		return box.innerHTML.replace( /<div><br><\/div>/g, '<br>' ).replace( /<\/?div>/g, '' );
	}

	/**
	 * What an edited element now says, in the shape its field stores.
	 *
	 * @param {Element} el   Element.
	 * @param {string}  type Field type.
	 * @return {string}
	 */
	function valueOf( el, type ) {
		if ( 'rich' === type ) {
			return clean( el.innerHTML ).trim();
		}

		return el.textContent.replace( / /g, ' ' ).trim();
	}

	/**
	 * Keep the editor's own shortcuts out of a field somebody is typing in.
	 *
	 * @param {Event} event Any keyboard or input event.
	 * @return {void}
	 */
	function contain( event ) {
		event.stopPropagation();
	}

	/* ------------------------------------------------------------------ */
	/* In-place editing                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Make a text element editable in place.
	 *
	 * @param {Element} el         Marked element.
	 * @param {string}  clientId   Block.
	 * @param {Object}  attributes Block attributes.
	 * @return {void}
	 */
	function bindText( el, clientId, attributes ) {
		var type    = el.getAttribute( 'data-qs-type' );
		var address = addressOf( el );
		var before  = null;

		el.setAttribute( 'contenteditable', 'true' );
		el.setAttribute( 'spellcheck', 'true' );
		el.setAttribute( 'title', words.edit || '' );

		if ( 'a' === el.tagName.toLowerCase() ) {
			el.setAttribute( 'draggable', 'false' );
		}

		$.each( [ 'keydown', 'keypress', 'keyup', 'input', 'beforeinput', 'paste', 'cut', 'copy', 'compositionstart', 'compositionend' ], function ( i, name ) {
			el.addEventListener( name, contain );
		} );

		// Select the block on the first click, so its toolbar and sidebar follow the caret.
		el.addEventListener( 'mousedown', function () {
			if ( select( 'core/block-editor' ).getSelectedBlockClientId() !== clientId ) {
				dispatch( 'core/block-editor' ).selectBlock( clientId );
			}
		} );

		el.addEventListener( 'focus', function () {
			before = el.innerHTML;
			el.classList.add( EDITING );

			if ( 'link' === type ) {
				showLinkBar( el, clientId, attributes );
			}
		} );

		el.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				el.innerHTML = before;
				el.blur();

				return;
			}

			if ( 'Enter' === event.key ) {
				if ( 'rich' === type && event.shiftKey ) {
					event.preventDefault();
					el.ownerDocument.execCommand( 'insertHTML', false, '<br>' );

					return;
				}

				event.preventDefault();
				el.blur();
			}
		} );

		el.addEventListener( 'paste', function ( event ) {
			var text = ( event.clipboardData || window.clipboardData ).getData( 'text/plain' );

			event.preventDefault();
			el.ownerDocument.execCommand( 'insertText', false, text );
		} );

		el.addEventListener( 'blur', function () {
			el.classList.remove( EDITING );

			if ( null === before || el.innerHTML === before ) {
				return;
			}

			var value = valueOf( el, type );

			if ( 'link' === type ) {
				var data    = dataOf( clientId ) || {};
				var current = $.extend( { url: '', title: '', target: '' }, getValue( attributes, data, address.name, address.index ) || {} );

				current.title = value;
				value         = current;
			}

			write( clientId, attributes, [ { name: address.name, index: address.index, value: value } ] );
		} );
	}

	/**
	 * Open the Media Library for a picture and write what was chosen.
	 *
	 * @param {Function} chosen Called with the attachment as JSON.
	 * @return {void}
	 */
	function pickImage( chosen ) {
		if ( ! wp.media ) {
			return;
		}

		var frame = wp.media( {
			title: words.choose || '',
			button: { text: words.use || '' },
			library: { type: 'image' },
			multiple: false,
		} );

		frame.on( 'select', function () {
			var first = frame.state().get( 'selection' ).first();

			if ( first ) {
				chosen( first.toJSON() );
			}
		} );

		frame.open();
	}

	/**
	 * Let a picture be replaced from the Media Library.
	 *
	 * @param {Element} el         The `<img>`.
	 * @param {string}  clientId   Block.
	 * @param {Object}  attributes Block attributes.
	 * @return {void}
	 */
	function bindImage( el, clientId, attributes ) {
		var address = addressOf( el );

		el.setAttribute( 'title', words.image || '' );
		el.classList.add( 'qs-canvas-image' );

		el.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			event.stopPropagation();

			dispatch( 'core/block-editor' ).selectBlock( clientId );

			pickImage( function ( picked ) {
				el.src = picked.url;
				write( clientId, attributes, [ { name: address.name, index: address.index, value: picked.id } ] );
			} );
		} );
	}

	/**
	 * A small bar under a link for its address, shown while its words are being edited.
	 *
	 * @param {Element} el         The `<a>`.
	 * @param {string}  clientId   Block.
	 * @param {Object}  attributes Block attributes.
	 * @return {void}
	 */
	function showLinkBar( el, clientId, attributes ) {
		var doc     = el.ownerDocument;
		var address = addressOf( el );
		var data    = dataOf( clientId ) || {};
		var current = $.extend( { url: '', title: '', target: '' }, getValue( attributes, data, address.name, address.index ) || {} );

		hideBars( doc, BAR + '--link' );

		var bar = doc.createElement( 'div' );

		bar.className = BAR + ' ' + BAR + '--link';
		bar.setAttribute( 'contenteditable', 'false' );

		var url = doc.createElement( 'input' );

		url.type        = 'url';
		url.value       = current.url || el.getAttribute( 'href' ) || '';
		url.placeholder = words.link || 'https://';
		url.setAttribute( 'aria-label', words.link || '' );

		var label = doc.createElement( 'label' );
		var tab   = doc.createElement( 'input' );

		tab.type    = 'checkbox';
		tab.checked = '_blank' === current.target;
		label.appendChild( tab );
		label.appendChild( doc.createTextNode( ' ' + ( words.newTab || '' ) ) );

		var apply = doc.createElement( 'button' );

		apply.type        = 'button';
		apply.textContent = words.apply || 'OK';

		bar.appendChild( url );
		bar.appendChild( label );
		bar.appendChild( apply );

		$.each( [ 'keydown', 'keypress', 'keyup', 'input', 'click' ], function ( i, name ) {
			bar.addEventListener( name, contain );
		} );

		// Keep the link's own blur from firing while the bar is being used.
		bar.addEventListener( 'mousedown', function ( event ) {
			event.stopPropagation();

			if ( event.target !== url ) {
				event.preventDefault();
			}
		} );

		var commit = function () {
			var value = $.extend( {}, current );

			value.url    = url.value.trim();
			value.target = tab.checked ? '_blank' : '';
			value.title  = valueOf( el, 'text' ) || value.title;

			el.setAttribute( 'href', value.url );
			write( clientId, attributes, [ { name: address.name, index: address.index, value: value } ] );
			hideBars( doc, BAR + '--link' );
		};

		apply.addEventListener( 'click', commit );
		url.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				commit();
			}

			if ( 'Escape' === event.key ) {
				hideBars( doc, BAR + '--link' );
			}
		} );

		place( bar, el, doc, false );
	}

	/**
	 * Row controls beside a repeated row while the pointer is over it.
	 *
	 * @param {Element} row        The row.
	 * @param {string}  clientId   Block.
	 * @param {Object}  attributes Block attributes.
	 * @return {void}
	 */
	function showRowBar( row, clientId, attributes ) {
		var doc   = row.ownerDocument;
		var index = parseInt( row.getAttribute( 'data-qs-index' ) || '0', 10 );

		hideBars( doc, BAR + '--row' );

		var bar = doc.createElement( 'div' );

		bar.className = BAR + ' ' + BAR + '--row';
		bar.setAttribute( 'contenteditable', 'false' );

		var add = doc.createElement( 'button' );

		add.type        = 'button';
		add.textContent = '+';
		add.title       = words.addRow || '';

		var remove = doc.createElement( 'button' );

		remove.type        = 'button';
		remove.textContent = '×';
		remove.title       = words.removeRow || '';

		bar.appendChild( add );
		bar.appendChild( remove );

		$.each( [ 'mousedown', 'click' ], function ( i, name ) {
			bar.addEventListener( name, contain );
		} );

		add.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			reshapeRows( clientId, attributes, index, 'add' );
		} );

		remove.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			reshapeRows( clientId, attributes, index, 'remove' );
		} );

		place( bar, row, doc, true );
	}

	/**
	 * Add a row after one, or take one out.
	 *
	 * @param {string} clientId   Block.
	 * @param {Object} attributes Block attributes.
	 * @param {number} index      Row.
	 * @param {string} how        'add' or 'remove'.
	 * @return {void}
	 */
	function reshapeRows( clientId, attributes, index, how ) {
		var data = dataOf( clientId );

		if ( ! data ) {
			return;
		}

		var rows = rowsOf( attributes, data );

		if ( 'add' === how ) {
			rows.splice( index + 1, 0, $.extend( {}, rows[ index ] || rows[ rows.length - 1 ] || {} ) );
		} else if ( rows.length > 1 ) {
			rows.splice( index, 1 );
		} else {
			return;
		}

		setRows( attributes, data, rows );
		dispatch( 'core/block-editor' ).updateBlockAttributes( clientId, { data: data } );

		/*
		 * The sidebar form, if open, was drawn for the old row count and
		 * would write it back on its next change. Reselecting the block
		 * makes ACF draw the form again from the data just written.
		 */
		dispatch( 'core/block-editor' ).clearSelectedBlock();
		window.setTimeout( function () {
			dispatch( 'core/block-editor' ).selectBlock( clientId );
		}, 50 );
	}

	/**
	 * Put a bar just outside an element, without touching the element's layout.
	 *
	 * @param {Element}  bar   The bar.
	 * @param {Element}  el    What it belongs to.
	 * @param {Document} doc   The canvas document.
	 * @param {boolean}  above Whether to sit above rather than below.
	 * @return {void}
	 */
	function place( bar, el, doc, above ) {
		var box = el.getBoundingClientRect();

		bar.style.position = 'fixed';
		bar.style.left     = Math.max( 4, box.left ) + 'px';
		bar.style.top      = ( above ? Math.max( 4, box.top - 30 ) : box.bottom + 4 ) + 'px';
		bar.style.zIndex   = '99999';

		doc.body.appendChild( bar );
	}

	/**
	 * Take bars down.
	 *
	 * @param {Document} doc  The canvas document.
	 * @param {string}   only Only bars with this class, when given.
	 * @return {void}
	 */
	function hideBars( doc, only ) {
		$.each( Array.prototype.slice.call( doc.querySelectorAll( '.' + ( only || BAR ) ) ), function ( i, bar ) {
			if ( bar.parentNode ) {
				bar.parentNode.removeChild( bar );
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* The panel of fields                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * A human name for a field, from the field group where there is one.
	 *
	 * @param {Object}  attributes Block attributes.
	 * @param {string}  name       Field name.
	 * @param {boolean} inRow      Whether it is a repeater sub-field.
	 * @return {string}
	 */
	function labelOf( attributes, name, inRow ) {
		var meta = metaOf( attributes );
		var map  = inRow ? meta.rows : meta.fields;

		if ( map[ name ] && map[ name ].label ) {
			return map[ name ].label;
		}

		return name.replace( /_/g, ' ' ).replace( /^\w/, function ( c ) {
			return c.toUpperCase();
		} );
	}

	/**
	 * The fields a preview draws, in the order they appear, top-level and per row.
	 *
	 * @param {Element} root       The preview.
	 * @param {Object}  attributes Block attributes.
	 * @return {{fields: Array, rowFields: Array, repeats: boolean}}
	 */
	function shapeOf( root, attributes ) {
		var fields    = [];
		var rowFields = [];
		var seen      = {};
		var seenRow   = {};
		var meta      = metaOf( attributes );

		$.each( root.querySelectorAll( '[data-qs-field]' ), function ( i, el ) {
			var name = el.getAttribute( 'data-qs-field' );
			var type = el.getAttribute( 'data-qs-type' );

			if ( el.closest( '[data-qs-row]' ) ) {
				if ( ! seenRow[ name ] ) {
					seenRow[ name ] = true;
					rowFields.push( { name: name, type: type } );
				}

				return;
			}

			if ( ! seen[ name ] ) {
				seen[ name ] = true;
				fields.push( { name: name, type: type } );
			}
		} );

		// Fields the markup does not mark — an attribute written back into a row — still belong on the panel.
		$.each( meta.rows, function ( name, info ) {
			if ( ! seenRow[ name ] ) {
				seenRow[ name ] = true;
				rowFields.push( { name: name, type: 'textarea' === info.type ? 'textarea' : 'text' } );
			}
		} );

		$.each( meta.fields, function ( name, info ) {
			if ( ! seen[ name ] && 'repeater' !== info.type ) {
				seen[ name ] = true;
				fields.push( { name: name, type: 'image' === info.type ? 'image' : ( 'link' === info.type ? 'link' : ( 'textarea' === info.type ? 'textarea' : 'text' ) ) } );
			}
		} );

		return { fields: fields, rowFields: rowFields, repeats: !! ( meta.itemsKey || root.querySelector( '[data-qs-row]' ) ) };
	}

	/**
	 * One control for one field, wired to the block's data.
	 *
	 * @param {Document} doc        The canvas document.
	 * @param {string}   clientId   Block.
	 * @param {Object}   attributes Block attributes.
	 * @param {Object}   field      {name, type}.
	 * @param {number}   index      Row, or -1.
	 * @param {Element}  root       The preview, for the current picture.
	 * @return {Element}
	 */
	function control( doc, clientId, attributes, field, index, root ) {
		var data  = dataOf( clientId ) || {};
		var value = getValue( attributes, data, field.name, index );
		var wrap  = doc.createElement( 'div' );
		var label = doc.createElement( 'label' );
		var timer = null;

		wrap.className    = 'qs-canvas-panel__field';
		label.textContent = labelOf( attributes, field.name, index >= 0 );
		wrap.appendChild( label );

		var later = function ( next ) {
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				write( clientId, attributes, [ { name: field.name, index: index, value: next } ] );
			}, 600 );
		};

		if ( 'image' === field.type ) {
			var picture = root.querySelector( '[data-qs-field="' + field.name + '"]' );
			var thumb   = doc.createElement( 'img' );
			var replace = doc.createElement( 'button' );

			thumb.className = 'qs-canvas-panel__thumb';
			thumb.src       = picture && picture.src ? picture.src : ( value && value.url ? value.url : '' );
			thumb.alt       = '';
			replace.type        = 'button';
			replace.textContent = words.replace || 'Replace';

			replace.addEventListener( 'click', function () {
				pickImage( function ( picked ) {
					thumb.src = picked.url;
					write( clientId, attributes, [ { name: field.name, index: index, value: picked.id } ] );
				} );
			} );

			wrap.appendChild( thumb );
			wrap.appendChild( replace );

			return wrap;
		}

		if ( 'link' === field.type ) {
			var link  = $.extend( { url: '', title: '', target: '' }, value || {} );
			var url   = doc.createElement( 'input' );
			var text  = doc.createElement( 'input' );
			var tab   = doc.createElement( 'input' );
			var tabLb = doc.createElement( 'label' );

			url.type         = 'url';
			url.value        = link.url;
			url.placeholder  = words.link || 'https://';
			text.type        = 'text';
			text.value       = link.title;
			text.placeholder = words.linkText || '';
			tab.type         = 'checkbox';
			tab.checked      = '_blank' === link.target;
			tabLb.className  = 'qs-canvas-panel__check';
			tabLb.appendChild( tab );
			tabLb.appendChild( doc.createTextNode( ' ' + ( words.newTab || '' ) ) );

			var commit = function () {
				later( { url: url.value.trim(), title: text.value, target: tab.checked ? '_blank' : '' } );
			};

			url.addEventListener( 'input', commit );
			text.addEventListener( 'input', commit );
			tab.addEventListener( 'change', commit );

			wrap.appendChild( text );
			wrap.appendChild( url );
			wrap.appendChild( tabLb );

			return wrap;
		}

		var input = doc.createElement( 'text' === field.type ? 'input' : 'textarea' );

		if ( 'text' === field.type ) {
			input.type = 'text';
		} else {
			input.rows = 'rich' === field.type ? 3 : 4;
		}

		input.value = 'string' === typeof value || 'number' === typeof value ? String( value ) : '';

		input.addEventListener( 'input', function () {
			later( 'rich' === field.type ? clean( input.value ) : input.value );
		} );

		wrap.appendChild( input );

		return wrap;
	}

	/**
	 * Draw the panel of fields for one block beside the canvas.
	 *
	 * The panel lives in the canvas document's body rather than inside the
	 * preview, so ACF drawing the preview again — which it does after every
	 * change — does not take the panel with it.
	 *
	 * @param {Element} root       The preview.
	 * @param {string}  clientId   Block.
	 * @param {Object}  attributes Block attributes.
	 * @return {void}
	 */
	function openPanel( root, clientId, attributes ) {
		var doc   = root.ownerDocument;
		var shape = shapeOf( root, attributes );
		var data  = dataOf( clientId ) || {};
		var meta  = metaOf( attributes );

		closePanel( doc );

		var panel = doc.createElement( 'aside' );

		panel.className = 'qs-canvas-panel';
		panel.setAttribute( 'data-qs-panel', clientId );
		panel.setAttribute( 'contenteditable', 'false' );

		panel.className += ' ' + sizeClass();

		var head  = doc.createElement( 'header' );
		var title = doc.createElement( 'strong' );
		var tools = doc.createElement( 'div' );
		var size  = doc.createElement( 'button' );
		var close = doc.createElement( 'button' );
		var block = root.closest( '[data-block]' );

		title.textContent = ( block && block.getAttribute( 'data-title' ) ) || ( words.fields || 'Fields' );

		tools.className = 'qs-canvas-panel__tools';

		/*
		 * Three widths rather than two. Half the canvas suits most sections,
		 * the whole of it suits one with a repeat, and a narrow panel is what
		 * somebody wants when they are reading the section behind it — so the
		 * button cycles, and the choice is remembered for the next section.
		 */
		size.type      = 'button';
		size.className = 'qs-canvas-panel__size';
		paintSize( size, panel );
		size.addEventListener( 'click', function () {
			var next = nextSize();

			panel.className = 'qs-canvas-panel ' + next;

			// A dragged width is the widths' own business again once one is chosen.
			panel.style.width = '';
			paintSize( size, panel );
		} );

		close.type        = 'button';
		close.textContent = '×';
		close.title       = words.close || '';
		close.addEventListener( 'click', function () {
			closePanel( doc );
		} );

		tools.appendChild( size );
		tools.appendChild( close );
		head.appendChild( title );
		head.appendChild( tools );
		panel.appendChild( head );

		var body = doc.createElement( 'div' );

		body.className = 'qs-canvas-panel__body';

		if ( ! shape.fields.length && ! shape.repeats ) {
			var none = doc.createElement( 'p' );

			none.textContent = words.noFields || '';
			body.appendChild( none );
		}

		$.each( shape.fields, function ( i, field ) {
			body.appendChild( control( doc, clientId, attributes, field, -1, root ) );
		} );

		if ( shape.repeats ) {
			var rows    = rowsOf( attributes, data );
			var section = doc.createElement( 'div' );
			var heading = doc.createElement( 'h4' );

			section.className   = 'qs-canvas-panel__rows';
			heading.textContent = meta.items || labelOf( attributes, REPEATER, false );
			section.appendChild( heading );

			$.each( rows, function ( at ) {
				var row    = doc.createElement( 'fieldset' );
				var legend = doc.createElement( 'legend' );
				var remove = doc.createElement( 'button' );

				legend.textContent = ( words.row || 'Row' ) + ' ' + ( at + 1 );
				remove.type        = 'button';
				remove.textContent = '×';
				remove.title       = words.removeRow || '';
				remove.addEventListener( 'click', function () {
					reshapeRows( clientId, attributes, at, 'remove' );
					window.setTimeout( function () {
						openPanel( root, clientId, attributes );
					}, 150 );
				} );
				legend.appendChild( remove );
				row.appendChild( legend );

				$.each( shape.rowFields, function ( j, field ) {
					row.appendChild( control( doc, clientId, attributes, field, at, root ) );
				} );

				section.appendChild( row );
			} );

			var add = doc.createElement( 'button' );

			add.type        = 'button';
			add.className   = 'qs-canvas-panel__add';
			add.textContent = '+ ' + ( words.addRowEnd || 'Add row' );
			add.addEventListener( 'click', function () {
				reshapeRows( clientId, attributes, Math.max( 0, rows.length - 1 ), 'add' );
				window.setTimeout( function () {
					openPanel( root, clientId, attributes );
				}, 150 );
			} );
			section.appendChild( add );
			body.appendChild( section );
		}

		var hint = doc.createElement( 'p' );

		hint.className   = 'qs-canvas-panel__hint';
		hint.textContent = words.hint || '';
		body.appendChild( hint );
		panel.appendChild( body );

		$.each( [ 'keydown', 'keypress', 'keyup', 'input', 'paste', 'mousedown', 'click' ], function ( i, name ) {
			panel.addEventListener( name, contain );
		} );

		doc.body.appendChild( panel );
	}

	/**
	 * The three widths the panel is offered at, in the order the button
	 * cycles them.
	 *
	 * @type {string[]}
	 */
	var SIZES = [ 'qs-canvas-panel--half', 'qs-canvas-panel--wide', 'qs-canvas-panel--narrow' ];

	/**
	 * Where the chosen width is kept between sections and between sessions.
	 *
	 * @type {string}
	 */
	var SIZE_KEY = 'qsDesignCanvasPanelSize';

	/**
	 * The width class the panel should open at.
	 *
	 * Half is the default and is the bare class, because the stylesheet's own
	 * rule is the half one; a stored value that is not one of the three is a
	 * value from an older version of this file and is ignored.
	 *
	 * @return {string} A class name, or empty for the default.
	 */
	function sizeClass() {
		var stored = '';

		try {
			stored = window.localStorage.getItem( SIZE_KEY ) || '';
		} catch ( e ) {
			stored = '';
		}

		return SIZES.indexOf( stored ) > 0 ? stored : '';
	}

	/**
	 * The next width in the cycle, stored as it is chosen.
	 *
	 * @return {string} A class name, or empty for the default.
	 */
	function nextSize() {
		var at   = SIZES.indexOf( sizeClass() || SIZES[ 0 ] );
		var next = SIZES[ ( at + 1 ) % SIZES.length ];

		try {
			window.localStorage.setItem( SIZE_KEY, next );
		} catch ( e ) {
			// A browser that refuses storage still gets the width, just not the memory of it.
		}

		return SIZES[ 0 ] === next ? '' : next;
	}

	/**
	 * Put the right arrow on the size button for what pressing it will do.
	 *
	 * @param {HTMLElement} button The button.
	 * @param {HTMLElement} panel  The panel it sizes.
	 * @return {void}
	 */
	function paintSize( button, panel ) {
		var wide = panel.classList.contains( 'qs-canvas-panel--wide' );

		button.textContent = wide ? '⇥' : '⇤';
		button.title       = ( wide ? words.narrower : words.wider ) || '';
	}

	/**
	 * Take the panel down.
	 *
	 * @param {Document} doc The canvas document.
	 * @return {void}
	 */
	function closePanel( doc ) {
		$.each( Array.prototype.slice.call( doc.querySelectorAll( '.qs-canvas-panel' ) ), function ( i, panel ) {
			panel.parentNode.removeChild( panel );
		} );
	}

	/**
	 * The button on a section that opens its fields.
	 *
	 * @param {Element} root       The preview.
	 * @param {string}  clientId   Block.
	 * @param {Object}  attributes Block attributes.
	 * @return {void}
	 */
	function addPanelButton( root, clientId, attributes ) {
		var doc     = root.ownerDocument;
		var section = root.querySelector( '.qs-design' ) || root.firstElementChild;

		if ( ! section || section.querySelector( ':scope > .qs-canvas-open' ) ) {
			return;
		}

		if ( 'static' === doc.defaultView.getComputedStyle( section ).position ) {
			section.style.position = 'relative';
		}

		var button = doc.createElement( 'button' );

		button.type        = 'button';
		button.className   = 'qs-canvas-open';
		button.textContent = '✎ ' + ( words.fields || 'Fields' );
		button.setAttribute( 'contenteditable', 'false' );
		button.addEventListener( 'mousedown', contain );
		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			event.stopPropagation();

			dispatch( 'core/block-editor' ).selectBlock( clientId );

			if ( doc.querySelector( '.qs-canvas-panel[data-qs-panel="' + clientId + '"]' ) ) {
				closePanel( doc );
			} else {
				openPanel( root, clientId, attributes );
			}
		} );

		section.insertBefore( button, section.firstChild );
	}

	/* ------------------------------------------------------------------ */
	/* Binding                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Bind everything marked inside one freshly drawn preview.
	 *
	 * @param {jQuery} $el        The preview.
	 * @param {Object} attributes The block's attributes.
	 * @return {void}
	 */
	function bind( $el, attributes ) {
		if ( ! $el || ! $el.length || ! attributes || ! attributes.name || 0 !== attributes.name.indexOf( 'qs/design-' ) ) {
			return;
		}

		var root     = $el.get( 0 );
		var clientId = clientIdOf( root );

		if ( ! clientId ) {
			return;
		}

		$.each( root.querySelectorAll( '[data-qs-field]' ), function ( i, el ) {
			if ( el.getAttribute( 'data-qs-bound' ) ) {
				return;
			}

			el.setAttribute( 'data-qs-bound', '1' );

			if ( 'image' === el.getAttribute( 'data-qs-type' ) ) {
				bindImage( el, clientId, attributes );
			} else {
				bindText( el, clientId, attributes );
			}
		} );

		addPanelButton( root, clientId, attributes );

		// A link inside the preview must never take the canvas somewhere else.
		$.each( root.querySelectorAll( 'a' ), function ( i, a ) {
			a.addEventListener( 'click', function ( event ) {
				event.preventDefault();
			} );
		} );

		$.each( root.querySelectorAll( '[data-qs-row]' ), function ( i, row ) {
			row.addEventListener( 'mouseenter', function () {
				showRowBar( row, clientId, attributes );
			} );

			row.addEventListener( 'mouseleave', function ( event ) {
				var to = event.relatedTarget;

				if ( to && to.closest && to.closest( '.' + BAR + '--row' ) ) {
					return;
				}

				hideBars( row.ownerDocument, BAR + '--row' );
			} );
		} );

		if ( ! root.ownerDocument.body.getAttribute( 'data-qs-canvas' ) ) {
			root.ownerDocument.body.setAttribute( 'data-qs-canvas', '1' );
			root.ownerDocument.addEventListener( 'mousedown', function ( event ) {
				if ( ! event.target.closest || ! event.target.closest( '.' + BAR ) ) {
					hideBars( root.ownerDocument, BAR + '--link' );
				}
			} );
		}
	}

	acf.addAction( 'render_block_preview', bind );
} )( window.wp, window.acf, window.jQuery );

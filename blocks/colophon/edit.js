/**
 * Editor UI for qs/colophon.
 *
 * Written against the global `wp` object rather than ES modules so the theme
 * needs no bundler: what ships is what runs.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;

	wp.blocks.registerBlockType( 'qs/colophon', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps( { className: 'qs-colophon' } );

			var preview = [ attributes.prefix + ' ' + new Date().getFullYear() ];

			if ( attributes.showSiteName ) {
				preview.push( __( 'Site name', 'qwerty-soft-signal' ) );
			}

			if ( attributes.extraText ) {
				preview.push( attributes.extraText );
			}

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Colophon', 'qwerty-soft-signal' ) },
						el( TextControl, {
							label: __( 'Symbol before the year', 'qwerty-soft-signal' ),
							help: __( 'Usually ©. Leave empty to show the year on its own.', 'qwerty-soft-signal' ),
							value: attributes.prefix,
							onChange: function ( value ) {
								setAttributes( { prefix: value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Show the site name', 'qwerty-soft-signal' ),
							checked: !! attributes.showSiteName,
							onChange: function ( value ) {
								setAttributes( { showSiteName: value } );
							},
						} ),
						el( TextControl, {
							label: __( 'Extra text', 'qwerty-soft-signal' ),
							help: __( 'Optional. Shown after the site name, for example "All rights reserved".', 'qwerty-soft-signal' ),
							value: attributes.extraText,
							onChange: function ( value ) {
								setAttributes( { extraText: value } );
							},
						} )
					)
				),
				el(
					'p',
					blockProps,
					el( 'span', { className: 'qs-colophon__line' }, preview.join( ' · ' ) )
				)
			);
		},

		// Dynamic block: the markup comes from render.php on every request so
		// the year can never go stale in saved post content.
		save: function () {
			return null;
		},
	} );
} )( window.wp );

/**
 * Editor UI for qs/metric.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var RichText = wp.blockEditor.RichText;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;

	// Mirrors render.php and view.js: digits, optional thousands spaces, dot decimal.
	var COUNTABLE = /^\d[\d\s]*(\.\d+)?$/;

	wp.blocks.registerBlockType( 'qs/metric', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps( { className: 'qs-metric' } );

			var isNumeric = COUNTABLE.test( String( attributes.value || '' ).trim() );

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Metric', 'qwerty-soft-signal' ) },
						el( TextControl, {
							label: __( 'Number', 'qwerty-soft-signal' ),
							help: __( 'For example 98, 4.9 or 150. Text like "24/7" also works, it simply will not count up.', 'qwerty-soft-signal' ),
							value: attributes.value,
							onChange: function ( value ) {
								setAttributes( { value: value } );
							},
						} ),
						el( TextControl, {
							label: __( 'Before the number', 'qwerty-soft-signal' ),
							help: __( 'Optional, for example a currency sign.', 'qwerty-soft-signal' ),
							value: attributes.prefix,
							onChange: function ( value ) {
								setAttributes( { prefix: value } );
							},
						} ),
						el( TextControl, {
							label: __( 'After the number', 'qwerty-soft-signal' ),
							help: __( 'Optional, for example % or +.', 'qwerty-soft-signal' ),
							value: attributes.suffix,
							onChange: function ( value ) {
								setAttributes( { suffix: value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Count up when it scrolls into view', 'qwerty-soft-signal' ),
							help: isNumeric
								? __( 'Visitors who ask their device for reduced motion always see the final number straight away.', 'qwerty-soft-signal' )
								: __( 'This number is not a plain figure, so it will be shown without counting.', 'qwerty-soft-signal' ),
							checked: !! attributes.animate,
							disabled: ! isNumeric,
							onChange: function ( value ) {
								setAttributes( { animate: value } );
							},
						} )
					)
				),
				el(
					'div',
					blockProps,
					el(
						'p',
						{ className: 'qs-metric__value' },
						el(
							'span',
							{ className: 'qs-metric__figure' },
							attributes.prefix
								? el( 'span', { className: 'qs-metric__affix' }, attributes.prefix )
								: null,
							el(
								'span',
								{ className: 'qs-metric__number' },
								attributes.value || '00'
							),
							attributes.suffix
								? el( 'span', { className: 'qs-metric__affix' }, attributes.suffix )
								: null
						)
					),
					el( RichText, {
						tagName: 'p',
						className: 'qs-metric__label',
						value: attributes.label,
						placeholder: __( 'What the number means…', 'qwerty-soft-signal' ),
						allowedFormats: [ 'core/bold', 'core/italic' ],
						onChange: function ( value ) {
							setAttributes( { label: value } );
						},
					} )
				)
			);
		},

		save: function () {
			return null;
		},
	} );
} )( window.wp );

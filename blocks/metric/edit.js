/**
 * Editor UI for wow/metric.
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

	wp.blocks.registerBlockType( 'wow/metric', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps( { className: 'wow-metric' } );

			var isNumeric =
				'' !== attributes.value &&
				! isNaN( parseFloat( String( attributes.value ).replace( /[\s,]/g, '' ) ) );

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Metric', 'wow-signal' ) },
						el( TextControl, {
							label: __( 'Number', 'wow-signal' ),
							help: __( 'For example 98, 4.9 or 150. Text like "24/7" also works, it simply will not count up.', 'wow-signal' ),
							value: attributes.value,
							onChange: function ( value ) {
								setAttributes( { value: value } );
							},
						} ),
						el( TextControl, {
							label: __( 'Before the number', 'wow-signal' ),
							help: __( 'Optional, for example a currency sign.', 'wow-signal' ),
							value: attributes.prefix,
							onChange: function ( value ) {
								setAttributes( { prefix: value } );
							},
						} ),
						el( TextControl, {
							label: __( 'After the number', 'wow-signal' ),
							help: __( 'Optional, for example % or +.', 'wow-signal' ),
							value: attributes.suffix,
							onChange: function ( value ) {
								setAttributes( { suffix: value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Count up when it scrolls into view', 'wow-signal' ),
							help: isNumeric
								? __( 'Visitors who ask their device for reduced motion always see the final number straight away.', 'wow-signal' )
								: __( 'This number is not a plain figure, so it will be shown without counting.', 'wow-signal' ),
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
						{ className: 'wow-metric__value' },
						el(
							'span',
							{ className: 'wow-metric__figure' },
							attributes.prefix
								? el( 'span', { className: 'wow-metric__affix' }, attributes.prefix )
								: null,
							el(
								'span',
								{ className: 'wow-metric__number' },
								attributes.value || __( '00', 'wow-signal' )
							),
							attributes.suffix
								? el( 'span', { className: 'wow-metric__affix' }, attributes.suffix )
								: null
						)
					),
					el( RichText, {
						tagName: 'p',
						className: 'wow-metric__label',
						value: attributes.label,
						placeholder: __( 'What the number means…', 'wow-signal' ),
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

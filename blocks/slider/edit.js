/**
 * Editor UI for wow/slider.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var useInnerBlocksProps = wp.blockEditor.useInnerBlocksProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var SelectControl = wp.components.SelectControl;

	var TEMPLATE = [
		[ 'core/group', { className: 'is-style-card' }, [
			[ 'core/heading', { level: 3, placeholder: __( 'Case name', 'wow-signal' ) } ],
			[ 'core/paragraph', { placeholder: __( 'What changed, and by how much.', 'wow-signal' ) } ],
		] ],
	];

	wp.blocks.registerBlockType( 'wow/slider', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			var blockProps = useBlockProps( {
				className: 'wow-slider is-editor',
				style: { '--wow-slide-width': attributes.slideWidth },
			} );

			var innerProps = useInnerBlocksProps(
				{ className: 'wow-slider__track' },
				{ template: TEMPLATE, templateLock: false, orientation: 'horizontal' }
			);

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Slider', 'wow-signal' ) },
						el( TextControl, {
							label: __( 'What is in this slider', 'wow-signal' ),
							help: __( 'A short description read aloud by screen readers, for example "Client case studies".', 'wow-signal' ),
							value: attributes.label,
							onChange: function ( value ) {
								setAttributes( { label: value } );
							},
						} ),
						el( SelectControl, {
							label: __( 'Card width', 'wow-signal' ),
							value: attributes.slideWidth,
							options: [
								{ label: __( 'Narrow', 'wow-signal' ), value: '18rem' },
								{ label: __( 'Medium', 'wow-signal' ), value: '22rem' },
								{ label: __( 'Wide', 'wow-signal' ), value: '28rem' },
							],
							onChange: function ( value ) {
								setAttributes( { slideWidth: value } );
							},
						} )
					)
				),
				el( 'div', blockProps, el( 'div', innerProps ) )
			);
		},

		save: function () {
			return null;
		},
	} );
} )( window.wp );

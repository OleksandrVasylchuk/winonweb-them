/**
 * Editor UI for qs/slider.
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

	// Widths are theme.json tokens so the slider follows the design system.
	var WIDTH_NARROW = 'var(--wp--custom--slider--slide-width-narrow)';
	var WIDTH_MEDIUM = 'var(--wp--custom--slider--slide-width)';
	var WIDTH_WIDE = 'var(--wp--custom--slider--slide-width-wide)';

	// Content saved before the tokens existed stored literal rem values.
	var LEGACY_WIDTHS = { '18rem': WIDTH_NARROW, '22rem': WIDTH_MEDIUM, '28rem': WIDTH_WIDE };

	var TEMPLATE = [
		[ 'core/group', { className: 'is-style-card' }, [
			[ 'core/heading', { level: 3, placeholder: __( 'Case name', 'qwerty-soft-signal' ) } ],
			[ 'core/paragraph', { placeholder: __( 'What changed, and by how much.', 'qwerty-soft-signal' ) } ],
		] ],
	];

	wp.blocks.registerBlockType( 'qs/slider', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			var slideWidth = LEGACY_WIDTHS[ attributes.slideWidth ] || attributes.slideWidth || WIDTH_MEDIUM;

			var blockProps = useBlockProps( {
				className: 'qs-slider is-editor',
				style: { '--qs-slide-width': slideWidth },
				'data-editor-hint': __( 'Cards wrap here in the editor; visitors scroll them sideways.', 'qwerty-soft-signal' ),
			} );

			var innerProps = useInnerBlocksProps(
				{ className: 'qs-slider__track' },
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
						{ title: __( 'Slider', 'qwerty-soft-signal' ) },
						el( TextControl, {
							label: __( 'What is in this slider', 'qwerty-soft-signal' ),
							help: __( 'A short description read aloud by screen readers, for example "Client case studies".', 'qwerty-soft-signal' ),
							value: attributes.label,
							onChange: function ( value ) {
								setAttributes( { label: value } );
							},
						} ),
						el( SelectControl, {
							label: __( 'Card width', 'qwerty-soft-signal' ),
							value: slideWidth,
							options: [
								{ label: __( 'Narrow', 'qwerty-soft-signal' ), value: WIDTH_NARROW },
								{ label: __( 'Medium', 'qwerty-soft-signal' ), value: WIDTH_MEDIUM },
								{ label: __( 'Wide', 'qwerty-soft-signal' ), value: WIDTH_WIDE },
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

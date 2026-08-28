/**
 * Editor UI for qs/faq.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var useInnerBlocksProps = wp.blockEditor.useInnerBlocksProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var ToggleControl = wp.components.ToggleControl;

	var TEMPLATE = [ [ 'qs/faq-item' ], [ 'qs/faq-item' ], [ 'qs/faq-item' ] ];

	wp.blocks.registerBlockType( 'qs/faq', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			var blockProps = useBlockProps( {
				className: 'qs-faq',
				'data-empty-hint': __( 'Add a question to start the FAQ.', 'qwerty-soft-signal' ),
			} );
			var innerProps = useInnerBlocksProps( blockProps, {
				allowedBlocks: [ 'qs/faq-item' ],
				template: TEMPLATE,
				templateLock: false,
				renderAppender: wp.blockEditor.InnerBlocks.ButtonBlockAppender,
			} );

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'FAQ settings', 'qwerty-soft-signal' ) },
						el( ToggleControl, {
							label: __( 'Publish as an FAQ to search engines', 'qwerty-soft-signal' ),
							help: __( 'Adds hidden FAQ data so Google can show these questions in results. Turn this off if another plugin already does it, or if the answers are not genuine questions.', 'qwerty-soft-signal' ),
							checked: !! attributes.emitSchema,
							onChange: function ( value ) {
								setAttributes( { emitSchema: value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Close other answers when one opens', 'qwerty-soft-signal' ),
							help: __( 'Keeps only one answer open at a time. Visitors can still open every answer if JavaScript is unavailable.', 'qwerty-soft-signal' ),
							checked: !! attributes.exclusive,
							onChange: function ( value ) {
								setAttributes( { exclusive: value } );
							},
						} )
					)
				),
				el( 'div', innerProps )
			);
		},

		save: function () {
			return null;
		},
	} );
} )( window.wp );

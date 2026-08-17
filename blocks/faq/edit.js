/**
 * Editor UI for wow/faq.
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

	var TEMPLATE = [ [ 'wow/faq-item' ], [ 'wow/faq-item' ], [ 'wow/faq-item' ] ];

	wp.blocks.registerBlockType( 'wow/faq', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			var blockProps = useBlockProps( { className: 'wow-faq' } );
			var innerProps = useInnerBlocksProps( blockProps, {
				allowedBlocks: [ 'wow/faq-item' ],
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
						{ title: __( 'FAQ settings', 'wow-signal' ) },
						el( ToggleControl, {
							label: __( 'Publish as an FAQ to search engines', 'wow-signal' ),
							help: __( 'Adds hidden FAQ data so Google can show these questions in results. Turn this off if another plugin already does it, or if the answers are not genuine questions.', 'wow-signal' ),
							checked: !! attributes.emitSchema,
							onChange: function ( value ) {
								setAttributes( { emitSchema: value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Close other answers when one opens', 'wow-signal' ),
							help: __( 'Keeps only one answer open at a time. Visitors can still open every answer if JavaScript is unavailable.', 'wow-signal' ),
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

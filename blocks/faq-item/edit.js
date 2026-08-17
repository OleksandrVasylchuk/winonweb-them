/**
 * Editor UI for wow/faq-item.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var useInnerBlocksProps = wp.blockEditor.useInnerBlocksProps;
	var RichText = wp.blockEditor.RichText;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var ToggleControl = wp.components.ToggleControl;

	var ANSWER_TEMPLATE = [
		[
			'core/paragraph',
			{ placeholder: __( 'Answer the question in a sentence or two…', 'wow-signal' ) },
		],
	];

	wp.blocks.registerBlockType( 'wow/faq-item', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			var blockProps = useBlockProps( { className: 'wow-faq__item is-editor' } );
			var innerProps = useInnerBlocksProps(
				{ className: 'wow-faq__answer' },
				{ template: ANSWER_TEMPLATE, templateLock: false }
			);

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Question', 'wow-signal' ) },
						el( ToggleControl, {
							label: __( 'Open when the page loads', 'wow-signal' ),
							help: __( 'Use this for the one question most visitors ask.', 'wow-signal' ),
							checked: !! attributes.startOpen,
							onChange: function ( value ) {
								setAttributes( { startOpen: value } );
							},
						} )
					)
				),
				el(
					'div',
					blockProps,
					el(
						'div',
						{ className: 'wow-faq__question' },
						el( RichText, {
							tagName: 'span',
							className: 'wow-faq__question-text',
							value: attributes.question,
							placeholder: __( 'Type the question…', 'wow-signal' ),
							allowedFormats: [ 'core/bold', 'core/italic', 'core/code' ],
							onChange: function ( value ) {
								setAttributes( { question: value } );
							},
						} )
					),
					el( 'div', innerProps )
				)
			);
		},

		save: function () {
			return null;
		},
	} );
} )( window.wp );

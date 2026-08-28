/**
 * Editor UI for qs/faq-item.
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
			{ placeholder: __( 'Answer the question in a sentence or two…', 'qwerty-soft-signal' ) },
		],
	];

	wp.blocks.registerBlockType( 'qs/faq-item', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			var blockProps = useBlockProps( { className: 'qs-faq__item is-editor' } );
			var innerProps = useInnerBlocksProps(
				{ className: 'qs-faq__answer' },
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
						{ title: __( 'Question', 'qwerty-soft-signal' ) },
						el( ToggleControl, {
							label: __( 'Open when the page loads', 'qwerty-soft-signal' ),
							help: __( 'Use this for the one question most visitors ask.', 'qwerty-soft-signal' ),
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
						{ className: 'qs-faq__question' },
						el( RichText, {
							tagName: 'span',
							className: 'qs-faq__question-text',
							value: attributes.question,
							placeholder: __( 'Type the question…', 'qwerty-soft-signal' ),
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

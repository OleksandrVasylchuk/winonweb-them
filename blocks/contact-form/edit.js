/**
 * Editor UI for wow/contact-form.
 *
 * The canvas shows a non-functional preview of the real markup. It is not a
 * live form on purpose: an editor should never be able to fire a submission
 * from inside wp-admin.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var ToggleControl = wp.components.ToggleControl;
	var Notice = wp.components.Notice;

	function field( label, hint, type ) {
		return el(
			'div',
			{ className: 'wow-contact__field' },
			el( 'span', { className: 'wow-contact__label' }, label ),
			hint ? el( 'p', { className: 'wow-contact__hint' }, hint ) : null,
			el( 'div', {
				className:
					'wow-contact__input' +
					( 'textarea' === type ? ' wow-contact__textarea' : '' ),
				'aria-hidden': 'true',
			} )
		);
	}

	wp.blocks.registerBlockType( 'wow/contact-form', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps( { className: 'wow-contact is-editor' } );

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Form', 'wow-signal' ) },
						el(
							Notice,
							{ status: 'info', isDismissible: false },
							__( 'Messages go to the site administrator email set under Settings → General. A developer can route a form elsewhere with the wow_signal/contact_recipient filter.', 'wow-signal' )
						),
						el( TextControl, {
							label: __( 'Form name', 'wow-signal' ),
							help: __( 'Used to tell forms apart if the site has more than one, for example "careers".', 'wow-signal' ),
							value: attributes.formId,
							onChange: function ( value ) {
								setAttributes( { formId: value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Ask for a subject', 'wow-signal' ),
							help: __( 'Adds an optional subject line above the message.', 'wow-signal' ),
							checked: !! attributes.showSubject,
							onChange: function ( value ) {
								setAttributes( { showSubject: value } );
							},
						} ),
						el( TextControl, {
							label: __( 'Button text', 'wow-signal' ),
							placeholder: __( 'Send message', 'wow-signal' ),
							value: attributes.submitLabel,
							onChange: function ( value ) {
								setAttributes( { submitLabel: value } );
							},
						} ),
						el( TextareaControl, {
							label: __( 'Thank-you message', 'wow-signal' ),
							help: __( 'Shown on the page after the message is sent.', 'wow-signal' ),
							value: attributes.successMessage,
							onChange: function ( value ) {
								setAttributes( { successMessage: value } );
							},
						} ),
						el( TextareaControl, {
							label: __( 'Small print under the button', 'wow-signal' ),
							help: __( 'Optional. Use it for a privacy note, for example how long you keep enquiries.', 'wow-signal' ),
							value: attributes.consentText,
							onChange: function ( value ) {
								setAttributes( { consentText: value } );
							},
						} )
					)
				),
				el(
					'div',
					blockProps,
					el(
						'div',
						{ className: 'wow-contact__form' },
						field( __( 'Your name', 'wow-signal' ), '', 'text' ),
						field(
							__( 'Email address', 'wow-signal' ),
							__( 'We reply to this address and never share it.', 'wow-signal' ),
							'email'
						),
						attributes.showSubject
							? field( __( 'Subject', 'wow-signal' ), '', 'text' )
							: null,
						field( __( 'How can we help?', 'wow-signal' ), '', 'textarea' ),
						attributes.consentText
							? el( 'p', { className: 'wow-contact__consent' }, attributes.consentText )
							: null,
						el(
							'span',
							{ className: 'wow-contact__submit wp-block-button__link wp-element-button' },
							attributes.submitLabel || __( 'Send message', 'wow-signal' )
						)
					)
				)
			);
		},

		save: function () {
			return null;
		},
	} );
} )( window.wp );

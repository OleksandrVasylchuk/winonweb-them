/**
 * Editor UI for qs/contact-form.
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
			{ className: 'qs-contact__field' },
			el( 'span', { className: 'qs-contact__label' }, label ),
			hint ? el( 'p', { className: 'qs-contact__hint' }, hint ) : null,
			el( 'div', {
				className:
					'qs-contact__input' +
					( 'textarea' === type ? ' qs-contact__textarea' : '' ),
				'aria-hidden': 'true',
			} )
		);
	}

	wp.blocks.registerBlockType( 'qs/contact-form', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps( { className: 'qs-contact is-editor' } );

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Form', 'qwerty-soft-signal' ) },
						el(
							Notice,
							{ status: 'info', isDismissible: false },
							__( 'Messages go to the site administrator email set under Settings → General. A developer can route a form elsewhere with the qwerty_soft/contact_recipient filter.', 'qwerty-soft-signal' )
						),
						el( TextControl, {
							label: __( 'Form name', 'qwerty-soft-signal' ),
							help: __( 'Used to tell forms apart if the site has more than one, for example "careers".', 'qwerty-soft-signal' ),
							value: attributes.formId,
							onChange: function ( value ) {
								setAttributes( { formId: value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Ask for a subject', 'qwerty-soft-signal' ),
							help: __( 'Adds an optional subject line above the message.', 'qwerty-soft-signal' ),
							checked: !! attributes.showSubject,
							onChange: function ( value ) {
								setAttributes( { showSubject: value } );
							},
						} ),
						el( TextControl, {
							label: __( 'Button text', 'qwerty-soft-signal' ),
							placeholder: __( 'Send message', 'qwerty-soft-signal' ),
							value: attributes.submitLabel,
							onChange: function ( value ) {
								setAttributes( { submitLabel: value } );
							},
						} ),
						el( TextareaControl, {
							label: __( 'Thank-you message', 'qwerty-soft-signal' ),
							help: __( 'Shown on the page after the message is sent.', 'qwerty-soft-signal' ),
							value: attributes.successMessage,
							onChange: function ( value ) {
								setAttributes( { successMessage: value } );
							},
						} ),
						el( TextareaControl, {
							label: __( 'Small print under the button', 'qwerty-soft-signal' ),
							help: __( 'Optional. Use it for a privacy note, for example how long you keep enquiries.', 'qwerty-soft-signal' ),
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
						{ className: 'qs-contact__form' },
						field( __( 'Your name', 'qwerty-soft-signal' ), '', 'text' ),
						field(
							__( 'Email address', 'qwerty-soft-signal' ),
							__( 'We reply to this address and never share it.', 'qwerty-soft-signal' ),
							'email'
						),
						attributes.showSubject
							? field( __( 'Subject', 'qwerty-soft-signal' ), '', 'text' )
							: null,
						field( __( 'How can we help?', 'qwerty-soft-signal' ), '', 'textarea' ),
						attributes.consentText
							? el( 'p', { className: 'qs-contact__consent' }, attributes.consentText )
							: null,
						el(
							'span',
							{ className: 'qs-contact__submit wp-block-button__link wp-element-button' },
							attributes.submitLabel || __( 'Send message', 'qwerty-soft-signal' )
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

<?php
/**
 * The server half of a form the design drew.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Support;

use Qwerty\Soft\Modules\ContactForm;

defined( 'ABSPATH' ) || exit;

/**
 * What a wrapped section's <form> needs in order to actually send something.
 *
 * A design hands over a form as markup: three inputs, a button, and an
 * `action="#"` that goes nowhere, because in the design's own world a script
 * was going to catch the submit. Imported as-is, the section looked finished
 * and silently threw every message away.
 *
 * The answer is not a forms plugin. The theme already ships the server side —
 * qs/contact-form: a plain POST to admin-post.php, a honeypot, a time trap, a
 * per-IP rate limit, and a recipient that is never read from the request. So
 * BlockWriter keeps the design's own markup, down to the class names, and
 * points it here; this adds the hidden fields that handler expects and the
 * message it sends back afterwards.
 *
 * Everything it prints is the theme's, not the design's — which is why it
 * carries no classes worth styling. The design's form keeps its own looks.
 */
final class DesignForm {

	/**
	 * Where an imported form posts.
	 *
	 * @return string
	 */
	public static function action(): string {
		return admin_url( 'admin-post.php' );
	}

	/**
	 * The hidden fields the handler expects, and the answer to the last send.
	 *
	 * Printed as the form's first children, so the result of a submission is
	 * read before the fields it came from.
	 *
	 * @param string $id Which form this is, for the qwerty_soft/contact_recipient filter.
	 * @return void
	 */
	public static function fields( string $id = 'design' ): void {
		$id = sanitize_key( $id );
		$id = '' !== $id ? $id : 'design';

		self::answer();

		wp_nonce_field( ContactForm::ACTION, ContactForm::NONCE_FIELD );

		printf(
			'<input type="hidden" name="action" value="%s">',
			esc_attr( ContactForm::ACTION )
		);

		printf(
			'<input type="hidden" name="qwerty_soft_form" value="%s">',
			esc_attr( $id )
		);

		printf(
			'<input type="hidden" name="qsoft_rendered_at" value="%s">',
			esc_attr( (string) time() )
		);

		/*
		 * The honeypot, off-screen rather than display:none — some bots skip
		 * hidden inputs. The position is inline because this markup carries no
		 * stylesheet of its own: the design's sheet styles the design's form,
		 * and the theme's contact-form styles do not load on a design block.
		 * It is a mechanism, not a design decision, so no token applies.
		 */
		printf(
			'<div aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden"><label for="%1$s">%2$s</label><input type="text" id="%1$s" name="qsoft_website" value="" tabindex="-1" autocomplete="off"></div>',
			esc_attr( wp_unique_id( 'qs-design-trap-' ) ),
			esc_html__( 'Leave this field empty', 'qwerty-soft-signal' )
		);
	}

	/**
	 * What happened to the last submission, if this page load is the one after it.
	 *
	 * Announced rather than merely shown: the redirect lands on a fresh page,
	 * and a visitor using a screen reader has no other way to know the message
	 * went. Nothing is printed when there is nothing to say.
	 *
	 * @return void
	 */
	private static function answer(): void {
		if ( ContactForm::is_success() ) {
			printf(
				'<p role="status" tabindex="-1">%s</p>',
				esc_html__( 'Thank you — your message is on its way.', 'qwerty-soft-signal' )
			);

			return;
		}

		$feedback = ContactForm::consume_feedback();
		$errors   = (array) ( $feedback['errors'] ?? array() );

		if ( array() === $errors ) {
			return;
		}

		echo '<div role="alert" tabindex="-1"><p>' . esc_html__( 'Your message was not sent.', 'qwerty-soft-signal' ) . '</p><ul>';

		foreach ( $errors as $message ) {
			echo '<li>' . esc_html( (string) $message ) . '</li>';
		}

		echo '</ul></div>';
	}
}

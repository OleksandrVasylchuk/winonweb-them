<?php
/**
 * Server render for wow/contact-form.
 *
 * Accessibility contract implemented here:
 *
 * - every control has a real <label for> bound to a unique id (WCAG 1.3.1, 3.3.2);
 * - required fields carry `required` plus aria-required, so both native
 *   validation and assistive technology agree (WCAG 3.3.2);
 * - a field that failed validation gets aria-invalid and is described by its
 *   own message through aria-describedby (WCAG 3.3.1);
 * - the summary is a role="alert" region, announced on the page it lands on
 *   after the redirect, and it links to each field that needs attention
 *   (WCAG 3.3.3);
 * - the success message is role="status", so it is announced without
 *   interrupting whatever the visitor is doing (WCAG 4.1.3).
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks (unused).
 * @var WP_Block             $block      Block instance (unused).
 */

declare( strict_types = 1 );

use Wow\Signal\Modules\ContactForm;

defined( 'ABSPATH' ) || exit;

$wow_form_id      = isset( $attributes['formId'] ) ? sanitize_key( (string) $attributes['formId'] ) : 'default';
$wow_form_id      = '' !== $wow_form_id ? $wow_form_id : 'default';
$wow_show_subject = isset( $attributes['showSubject'] ) && (bool) $attributes['showSubject'];
$wow_submit       = isset( $attributes['submitLabel'] ) ? trim( (string) $attributes['submitLabel'] ) : '';
$wow_success      = isset( $attributes['successMessage'] ) ? trim( (string) $attributes['successMessage'] ) : '';
$wow_consent      = isset( $attributes['consentText'] ) ? trim( (string) $attributes['consentText'] ) : '';

$wow_submit  = '' !== $wow_submit ? $wow_submit : __( 'Send message', 'wow-signal' );
$wow_success = '' !== $wow_success ? $wow_success : __( 'Thank you — your message is on its way. We reply within one working day.', 'wow-signal' );

$wow_feedback = ContactForm::consume_feedback();
$wow_errors   = $wow_feedback['errors'];
$wow_values   = $wow_feedback['values'];
$wow_sent     = ContactForm::is_success();

// Unique per instance so two forms on one page never share an id.
$wow_uid = wp_unique_id( 'wow-contact-' );

/**
 * Build the id for one field.
 *
 * @param string $uid   Instance prefix.
 * @param string $field Field name.
 * @return string
 */
$wow_field_id = static function ( string $uid, string $field ): string {
	return $uid . '-' . $field;
};

$wow_wrapper = get_block_wrapper_attributes( array( 'class' => 'wow-contact' ) );

$wow_fields = array(
	'name'    => array(
		'label'        => __( 'Your name', 'wow-signal' ),
		'type'         => 'text',
		'autocomplete' => 'name',
		'required'     => true,
		'hint'         => '',
	),
	'email'   => array(
		'label'        => __( 'Email address', 'wow-signal' ),
		'type'         => 'email',
		'autocomplete' => 'email',
		'required'     => true,
		'hint'         => __( 'We reply to this address and never share it.', 'wow-signal' ),
	),
	'subject' => array(
		'label'        => __( 'Subject', 'wow-signal' ),
		'type'         => 'text',
		'autocomplete' => 'off',
		'required'     => false,
		'hint'         => '',
	),
);

if ( ! $wow_show_subject ) {
	unset( $wow_fields['subject'] );
}
?>
<div <?php echo $wow_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>>

	<?php if ( $wow_sent ) : ?>
		<?php
		/*
		 * The success redirect lands on this id, so the thank-you line is in
		 * view even on a phone. tabindex="-1" lets view.js move focus here:
		 * a role="status" region that is already present at load is not
		 * announced on its own.
		 */
		?>
		<p
			class="wow-contact__notice wow-contact__notice--success"
			id="<?php echo esc_attr( ContactForm::ACTION ); ?>-status"
			role="status"
			tabindex="-1"
			data-wow-contact-status
		>
			<?php echo esc_html( $wow_success ); ?>
		</p>
	<?php endif; ?>

	<?php if ( array() !== $wow_errors ) : ?>
		<div class="wow-contact__notice wow-contact__notice--error" role="alert" tabindex="-1" data-wow-contact-summary>
			<p class="wow-contact__notice-title">
				<?php esc_html_e( 'Your message was not sent.', 'wow-signal' ); ?>
			</p>
			<ul class="wow-contact__notice-list">
				<?php foreach ( $wow_errors as $wow_key => $wow_message ) : ?>
					<li>
						<?php if ( '_form' === $wow_key ) : ?>
							<?php echo esc_html( $wow_message ); ?>
						<?php else : ?>
							<a href="#<?php echo esc_attr( $wow_field_id( $wow_uid, (string) $wow_key ) ); ?>">
								<?php echo esc_html( $wow_message ); ?>
							</a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php
	/*
	 * The form carries the anchor the handler redirects back to. The block
	 * declares supports.multiple = false, so this id stays unique on a page.
	 */
	?>
	<form
		class="wow-contact__form"
		id="<?php echo esc_attr( ContactForm::ACTION ); ?>"
		action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
		method="post"
	>
		<?php wp_nonce_field( ContactForm::ACTION, ContactForm::NONCE_FIELD ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( ContactForm::ACTION ); ?>">
		<input type="hidden" name="wow_signal_form" value="<?php echo esc_attr( $wow_form_id ); ?>">
		<input type="hidden" name="wow_rendered_at" value="<?php echo esc_attr( (string) time() ); ?>">

		<?php
		/*
		 * Honeypot. It is off-screen rather than display:none — some bots skip
		 * hidden inputs — and it is removed from the tab order and from the
		 * accessibility tree so no human ever encounters it.
		 */
		?>
		<div class="wow-contact__trap" aria-hidden="true">
			<label for="<?php echo esc_attr( $wow_uid ); ?>-website">
				<?php esc_html_e( 'Leave this field empty', 'wow-signal' ); ?>
			</label>
			<input
				type="text"
				id="<?php echo esc_attr( $wow_uid ); ?>-website"
				name="wow_website"
				value=""
				tabindex="-1"
				autocomplete="off"
			>
		</div>

		<?php foreach ( $wow_fields as $wow_name => $wow_field ) : ?>
			<?php
			$wow_id       = $wow_field_id( $wow_uid, (string) $wow_name );
			$wow_error    = $wow_errors[ $wow_name ] ?? '';
			$wow_has_hint = '' !== $wow_field['hint'];

			$wow_described = array();

			if ( '' !== $wow_error ) {
				$wow_described[] = $wow_id . '-error';
			}

			if ( $wow_has_hint ) {
				$wow_described[] = $wow_id . '-hint';
			}

			$wow_field_class = 'wow-contact__field' . ( '' !== $wow_error ? ' has-error' : '' );
			?>
			<div class="<?php echo esc_attr( $wow_field_class ); ?>">
				<label class="wow-contact__label" for="<?php echo esc_attr( $wow_id ); ?>">
					<?php echo esc_html( (string) $wow_field['label'] ); ?>
					<?php if ( $wow_field['required'] ) : ?>
						<span class="wow-contact__required">
							<span aria-hidden="true">*</span>
							<span class="screen-reader-text"><?php esc_html_e( '(required)', 'wow-signal' ); ?></span>
						</span>
					<?php endif; ?>
				</label>

				<?php if ( $wow_has_hint ) : ?>
					<p class="wow-contact__hint" id="<?php echo esc_attr( $wow_id ); ?>-hint">
						<?php echo esc_html( (string) $wow_field['hint'] ); ?>
					</p>
				<?php endif; ?>

				<?php if ( '' !== $wow_error ) : ?>
					<p class="wow-contact__error" id="<?php echo esc_attr( $wow_id ); ?>-error">
						<span class="screen-reader-text"><?php esc_html_e( 'Error:', 'wow-signal' ); ?></span>
						<?php echo esc_html( $wow_error ); ?>
					</p>
				<?php endif; ?>

				<input
					class="wow-contact__input"
					type="<?php echo esc_attr( (string) $wow_field['type'] ); ?>"
					id="<?php echo esc_attr( $wow_id ); ?>"
					name="wow_<?php echo esc_attr( (string) $wow_name ); ?>"
					value="<?php echo esc_attr( $wow_values[ $wow_name ] ?? '' ); ?>"
					autocomplete="<?php echo esc_attr( (string) $wow_field['autocomplete'] ); ?>"
					<?php if ( $wow_field['required'] ) : ?>
						required aria-required="true"
					<?php endif; ?>
					<?php if ( '' !== $wow_error ) : ?>
						aria-invalid="true"
					<?php endif; ?>
					<?php if ( array() !== $wow_described ) : ?>
						aria-describedby="<?php echo esc_attr( implode( ' ', $wow_described ) ); ?>"
					<?php endif; ?>
				>
			</div>
		<?php endforeach; ?>

		<?php
		$wow_message_id    = $wow_field_id( $wow_uid, 'message' );
		$wow_message_error = $wow_errors['message'] ?? '';
		$wow_message_class = 'wow-contact__field' . ( '' !== $wow_message_error ? ' has-error' : '' );
		?>
		<div class="<?php echo esc_attr( $wow_message_class ); ?>">
			<label class="wow-contact__label" for="<?php echo esc_attr( $wow_message_id ); ?>">
				<?php esc_html_e( 'How can we help?', 'wow-signal' ); ?>
				<span class="wow-contact__required">
					<span aria-hidden="true">*</span>
					<span class="screen-reader-text"><?php esc_html_e( '(required)', 'wow-signal' ); ?></span>
				</span>
			</label>

			<?php if ( '' !== $wow_message_error ) : ?>
				<p class="wow-contact__error" id="<?php echo esc_attr( $wow_message_id ); ?>-error">
					<span class="screen-reader-text"><?php esc_html_e( 'Error:', 'wow-signal' ); ?></span>
					<?php echo esc_html( $wow_message_error ); ?>
				</p>
			<?php endif; ?>

			<textarea
				class="wow-contact__input wow-contact__textarea"
				id="<?php echo esc_attr( $wow_message_id ); ?>"
				name="wow_message"
				rows="6"
				maxlength="5000"
				required
				aria-required="true"
				<?php if ( '' !== $wow_message_error ) : ?>
					aria-invalid="true" aria-describedby="<?php echo esc_attr( $wow_message_id ); ?>-error"
				<?php endif; ?>
			><?php echo esc_textarea( $wow_values['message'] ?? '' ); ?></textarea>
		</div>

		<?php if ( '' !== $wow_consent ) : ?>
			<p class="wow-contact__consent"><?php echo esc_html( $wow_consent ); ?></p>
		<?php endif; ?>

		<button type="submit" class="wow-contact__submit wp-block-button__link wp-element-button">
			<?php echo esc_html( $wow_submit ); ?>
		</button>
	</form>
</div>

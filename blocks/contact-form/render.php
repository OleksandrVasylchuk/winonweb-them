<?php
/**
 * Server render for qs/contact-form.
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
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks (unused).
 * @var WP_Block             $block      Block instance (unused).
 */

declare( strict_types = 1 );

use Qwerty\Soft\Modules\ContactForm;

defined( 'ABSPATH' ) || exit;

$qsoft_form_id      = isset( $attributes['formId'] ) ? sanitize_key( (string) $attributes['formId'] ) : 'default';
$qsoft_form_id      = '' !== $qsoft_form_id ? $qsoft_form_id : 'default';
$qsoft_show_subject = isset( $attributes['showSubject'] ) && (bool) $attributes['showSubject'];
$qsoft_submit       = isset( $attributes['submitLabel'] ) ? trim( (string) $attributes['submitLabel'] ) : '';
$qsoft_success      = isset( $attributes['successMessage'] ) ? trim( (string) $attributes['successMessage'] ) : '';
$qsoft_consent      = isset( $attributes['consentText'] ) ? trim( (string) $attributes['consentText'] ) : '';

$qsoft_submit  = '' !== $qsoft_submit ? $qsoft_submit : __( 'Send message', 'qwerty-soft-signal' );
$qsoft_success = '' !== $qsoft_success ? $qsoft_success : __( 'Thank you — your message is on its way. We reply within one working day.', 'qwerty-soft-signal' );

$qsoft_feedback = ContactForm::consume_feedback();
$qsoft_errors   = $qsoft_feedback['errors'];
$qsoft_values   = $qsoft_feedback['values'];
$qsoft_sent     = ContactForm::is_success();

// Unique per instance so two forms on one page never share an id.
$qsoft_uid = wp_unique_id( 'qs-contact-' );

/**
 * Build the id for one field.
 *
 * @param string $uid   Instance prefix.
 * @param string $field Field name.
 * @return string
 */
$qsoft_field_id = static function ( string $uid, string $field ): string {
	return $uid . '-' . $field;
};

$qsoft_wrapper = get_block_wrapper_attributes( array( 'class' => 'qs-contact' ) );

$qsoft_fields = array(
	'name'    => array(
		'label'        => __( 'Your name', 'qwerty-soft-signal' ),
		'type'         => 'text',
		'autocomplete' => 'name',
		'required'     => true,
		'hint'         => '',
	),
	'email'   => array(
		'label'        => __( 'Email address', 'qwerty-soft-signal' ),
		'type'         => 'email',
		'autocomplete' => 'email',
		'required'     => true,
		'hint'         => __( 'We reply to this address and never share it.', 'qwerty-soft-signal' ),
	),
	'subject' => array(
		'label'        => __( 'Subject', 'qwerty-soft-signal' ),
		'type'         => 'text',
		'autocomplete' => 'off',
		'required'     => false,
		'hint'         => '',
	),
);

if ( ! $qsoft_show_subject ) {
	unset( $qsoft_fields['subject'] );
}
?>
<div <?php echo $qsoft_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>>

	<?php if ( $qsoft_sent ) : ?>
		<?php
		/*
		 * The success redirect lands on this id, so the thank-you line is in
		 * view even on a phone. tabindex="-1" lets view.js move focus here:
		 * a role="status" region that is already present at load is not
		 * announced on its own.
		 */
		?>
		<p
			class="qs-contact__notice qs-contact__notice--success"
			id="<?php echo esc_attr( ContactForm::ACTION ); ?>-status"
			role="status"
			tabindex="-1"
			data-qs-contact-status
		>
			<?php echo esc_html( $qsoft_success ); ?>
		</p>
	<?php endif; ?>

	<?php if ( array() !== $qsoft_errors ) : ?>
		<div class="qs-contact__notice qs-contact__notice--error" role="alert" tabindex="-1" data-qs-contact-summary>
			<p class="qs-contact__notice-title">
				<?php esc_html_e( 'Your message was not sent.', 'qwerty-soft-signal' ); ?>
			</p>
			<ul class="qs-contact__notice-list">
				<?php foreach ( $qsoft_errors as $qsoft_key => $qsoft_message ) : ?>
					<li>
						<?php if ( '_form' === $qsoft_key ) : ?>
							<?php echo esc_html( $qsoft_message ); ?>
						<?php else : ?>
							<a href="#<?php echo esc_attr( $qsoft_field_id( $qsoft_uid, (string) $qsoft_key ) ); ?>">
								<?php echo esc_html( $qsoft_message ); ?>
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
		class="qs-contact__form"
		id="<?php echo esc_attr( ContactForm::ACTION ); ?>"
		action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
		method="post"
	>
		<?php wp_nonce_field( ContactForm::ACTION, ContactForm::NONCE_FIELD ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( ContactForm::ACTION ); ?>">
		<input type="hidden" name="qwerty_soft_form" value="<?php echo esc_attr( $qsoft_form_id ); ?>">
		<input type="hidden" name="qsoft_rendered_at" value="<?php echo esc_attr( (string) time() ); ?>">

		<?php
		/*
		 * Honeypot. It is off-screen rather than display:none — some bots skip
		 * hidden inputs — and it is removed from the tab order and from the
		 * accessibility tree so no human ever encounters it.
		 */
		?>
		<div class="qs-contact__trap" aria-hidden="true">
			<label for="<?php echo esc_attr( $qsoft_uid ); ?>-website">
				<?php esc_html_e( 'Leave this field empty', 'qwerty-soft-signal' ); ?>
			</label>
			<input
				type="text"
				id="<?php echo esc_attr( $qsoft_uid ); ?>-website"
				name="qsoft_website"
				value=""
				tabindex="-1"
				autocomplete="off"
			>
		</div>

		<?php foreach ( $qsoft_fields as $qsoft_name => $qsoft_field ) : ?>
			<?php
			$qsoft_id       = $qsoft_field_id( $qsoft_uid, (string) $qsoft_name );
			$qsoft_error    = $qsoft_errors[ $qsoft_name ] ?? '';
			$qsoft_has_hint = '' !== $qsoft_field['hint'];

			$qsoft_described = array();

			if ( '' !== $qsoft_error ) {
				$qsoft_described[] = $qsoft_id . '-error';
			}

			if ( $qsoft_has_hint ) {
				$qsoft_described[] = $qsoft_id . '-hint';
			}

			$qsoft_field_class = 'qs-contact__field' . ( '' !== $qsoft_error ? ' has-error' : '' );
			?>
			<div class="<?php echo esc_attr( $qsoft_field_class ); ?>">
				<label class="qs-contact__label" for="<?php echo esc_attr( $qsoft_id ); ?>">
					<?php echo esc_html( (string) $qsoft_field['label'] ); ?>
					<?php if ( $qsoft_field['required'] ) : ?>
						<span class="qs-contact__required">
							<span aria-hidden="true">*</span>
							<span class="screen-reader-text"><?php esc_html_e( '(required)', 'qwerty-soft-signal' ); ?></span>
						</span>
					<?php endif; ?>
				</label>

				<?php if ( $qsoft_has_hint ) : ?>
					<p class="qs-contact__hint" id="<?php echo esc_attr( $qsoft_id ); ?>-hint">
						<?php echo esc_html( (string) $qsoft_field['hint'] ); ?>
					</p>
				<?php endif; ?>

				<?php if ( '' !== $qsoft_error ) : ?>
					<p class="qs-contact__error" id="<?php echo esc_attr( $qsoft_id ); ?>-error">
						<span class="screen-reader-text"><?php esc_html_e( 'Error:', 'qwerty-soft-signal' ); ?></span>
						<?php echo esc_html( $qsoft_error ); ?>
					</p>
				<?php endif; ?>

				<input
					class="qs-contact__input"
					type="<?php echo esc_attr( (string) $qsoft_field['type'] ); ?>"
					id="<?php echo esc_attr( $qsoft_id ); ?>"
					name="qsoft_<?php echo esc_attr( (string) $qsoft_name ); ?>"
					value="<?php echo esc_attr( $qsoft_values[ $qsoft_name ] ?? '' ); ?>"
					autocomplete="<?php echo esc_attr( (string) $qsoft_field['autocomplete'] ); ?>"
					<?php if ( $qsoft_field['required'] ) : ?>
						required aria-required="true"
					<?php endif; ?>
					<?php if ( '' !== $qsoft_error ) : ?>
						aria-invalid="true"
					<?php endif; ?>
					<?php if ( array() !== $qsoft_described ) : ?>
						aria-describedby="<?php echo esc_attr( implode( ' ', $qsoft_described ) ); ?>"
					<?php endif; ?>
				>
			</div>
		<?php endforeach; ?>

		<?php
		$qsoft_message_id    = $qsoft_field_id( $qsoft_uid, 'message' );
		$qsoft_message_error = $qsoft_errors['message'] ?? '';
		$qsoft_message_class = 'qs-contact__field' . ( '' !== $qsoft_message_error ? ' has-error' : '' );
		?>
		<div class="<?php echo esc_attr( $qsoft_message_class ); ?>">
			<label class="qs-contact__label" for="<?php echo esc_attr( $qsoft_message_id ); ?>">
				<?php esc_html_e( 'How can we help?', 'qwerty-soft-signal' ); ?>
				<span class="qs-contact__required">
					<span aria-hidden="true">*</span>
					<span class="screen-reader-text"><?php esc_html_e( '(required)', 'qwerty-soft-signal' ); ?></span>
				</span>
			</label>

			<?php if ( '' !== $qsoft_message_error ) : ?>
				<p class="qs-contact__error" id="<?php echo esc_attr( $qsoft_message_id ); ?>-error">
					<span class="screen-reader-text"><?php esc_html_e( 'Error:', 'qwerty-soft-signal' ); ?></span>
					<?php echo esc_html( $qsoft_message_error ); ?>
				</p>
			<?php endif; ?>

			<textarea
				class="qs-contact__input qs-contact__textarea"
				id="<?php echo esc_attr( $qsoft_message_id ); ?>"
				name="qsoft_message"
				rows="6"
				maxlength="5000"
				required
				aria-required="true"
				<?php if ( '' !== $qsoft_message_error ) : ?>
					aria-invalid="true" aria-describedby="<?php echo esc_attr( $qsoft_message_id ); ?>-error"
				<?php endif; ?>
			><?php echo esc_textarea( $qsoft_values['message'] ?? '' ); ?></textarea>
		</div>

		<?php if ( '' !== $qsoft_consent ) : ?>
			<p class="qs-contact__consent"><?php echo esc_html( $qsoft_consent ); ?></p>
		<?php endif; ?>

		<button type="submit" class="qs-contact__submit wp-block-button__link wp-element-button">
			<?php echo esc_html( $qsoft_submit ); ?>
		</button>
	</form>
</div>

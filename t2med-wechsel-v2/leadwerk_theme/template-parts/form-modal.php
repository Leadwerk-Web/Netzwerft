<?php
/**
 * Single WPForms modal.
 *
 * @package Leadwerk_T2med
 */
$leadwerk_page_id = get_queried_object_id();
$form_id          = absint( leadwerk_theme_option( 'wpforms_form_id', 0 ) );
?>
<div class="modal" id="funnel-modal" role="dialog" aria-modal="true" aria-labelledby="funnel-heading" aria-hidden="true">
	<div class="modal__backdrop" data-modal-close></div>
	<div class="modal__dialog">
		<button class="modal__close" type="button" aria-label="Dialog schließen" data-modal-close>&times;</button>
		<span class="modal__eyebrow"><?php echo esc_html( leadwerk_theme_field( 'modal_eyebrow', $leadwerk_page_id, 'T2med-Wechsel' ) ); ?></span>
		<h2 id="funnel-heading"><?php echo esc_html( leadwerk_theme_field( 'modal_title', $leadwerk_page_id, 'Ihre Angaben für ein Erstgespräch mit Substanz.' ) ); ?></h2>
		<p class="modal__sub"><?php echo esc_html( leadwerk_theme_field( 'modal_intro', $leadwerk_page_id, '' ) ); ?></p>
		<div class="leadwerk-wpforms">
			<?php if ( $form_id && shortcode_exists( 'wpforms' ) ) : ?>
				<?php echo do_shortcode( '[wpforms id="' . $form_id . '" title="false" description="false" ajax="true"]' ); ?>
			<?php else : ?>
				<?php $fallback_email = sanitize_email( leadwerk_theme_option( 'company_email', 'info@dienetzwerft.de' ) ); ?>
				<p>Das Kontaktformular ist noch nicht eingerichtet. Bitte führen Sie den T2med-Importer aus oder schreiben Sie an <a href="mailto:<?php echo esc_attr( $fallback_email ); ?>"><?php echo esc_html( antispambot( $fallback_email ) ); ?></a>.</p>
			<?php endif; ?>
		</div>
	</div>
</div>

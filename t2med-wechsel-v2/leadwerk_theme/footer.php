<?php
/**
 * Site footer.
 *
 * @package Leadwerk_T2med
 */
$company = leadwerk_theme_option( 'company_name', 'die netzwerft GmbH' );
$phone   = leadwerk_theme_option( 'company_phone', '07243 350600' );
$email   = leadwerk_theme_option( 'company_email', 'info@dienetzwerft.de' );
?>
<footer class="site-footer">
	<div class="container">
		<div class="footer__grid">
			<div class="footer__brand">
				<?php
				$logo_id = absint( leadwerk_theme_option( 'brand_logo', 0 ) );
				if ( $logo_id ) {
					echo wp_get_attachment_image(
						$logo_id,
						'full',
						false,
						array(
							'class' => 'brand__logo brand__logo--footer',
							'alt'   => 'die netzwerft',
						)
					);
				}
				?>
				<p class="footer__desc"><?php echo esc_html( leadwerk_theme_option( 'footer_description', '' ) ); ?></p>
			</div>
			<nav class="footer__col" aria-label="Footer-Navigation">
				<h3>Navigation</h3>
				<ul class="footer__list">
					<?php foreach ( (array) leadwerk_theme_option( 'footer_menu', array() ) as $item ) : ?>
						<?php if ( ! empty( $item['label'] ) ) : ?>
							<li><a href="<?php echo esc_url( leadwerk_page_reference_url( $item['target'] ?? array() ) ); ?>"><?php echo esc_html( $item['label'] ); ?></a></li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
			</nav>
			<div class="footer__col">
				<h3>Kontakt</h3>
				<address class="footer__contact">
					<strong><?php echo esc_html( $company ); ?></strong>
					<span><?php echo nl2br( esc_html( leadwerk_theme_option( 'company_address', '' ) ) ); ?></span>
					<a href="mailto:<?php echo esc_attr( sanitize_email( $email ) ); ?>"><?php echo esc_html( antispambot( $email ) ); ?></a>
					<a href="tel:<?php echo esc_attr( preg_replace( '/[^+0-9]/', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a>
				</address>
			</div>
		</div>
		<div class="footer__bottom">
			<span>&copy; <span id="footer-year"><?php echo esc_html( gmdate( 'Y' ) ); ?></span> <?php echo esc_html( $company ); ?></span>
			<nav class="footer__legal" aria-label="Rechtliches">
				<a href="<?php echo esc_url( leadwerk_get_page_url( 'nw-impressum-v1' ) ); ?>">Impressum</a>
				<a href="<?php echo esc_url( leadwerk_get_page_url( 'nw-datenschutz-v1' ) ); ?>">Datenschutz</a>
			</nav>
		</div>
	</div>
</footer>
<div class="sticky-corner" aria-label="Schnellaktionen">
	<button type="button" class="btn btn--primary btn--lg back-to-top" data-back-to-top aria-label="Nach oben scrollen" hidden>Nach oben</button>
</div>
<?php if ( is_front_page() ) : ?>
	<div class="sticky-cta" data-sticky-cta>
		<button class="btn btn--primary" type="button" data-cta="lp-sticky-mobile" data-conversion="appointment_start"><?php echo esc_html( leadwerk_theme_field( 'qualify_cta_label', get_queried_object_id(), 'T2med-Wechsel vorprüfen lassen' ) ); ?></button>
	</div>
	<?php get_template_part( 'template-parts/form-modal' ); ?>
<?php endif; ?>
<?php wp_footer(); ?>
</body>
</html>

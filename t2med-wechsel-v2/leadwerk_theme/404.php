<?php
/**
 * Real HTTP 404 page.
 *
 * @package Leadwerk_T2med
 */
status_header( 404 );
nocache_headers();
$content_id = leadwerk_theme_find_page( 'nw-404-v1' );
get_header();
?>
<main id="hauptinhalt" class="special-page special-page--404">
	<div class="special-page__bg" aria-hidden="true">
		<div class="lp-hero__blur"></div>
		<canvas class="lp-hero__particles" id="hero-particles"></canvas>
	</div>
	<div class="container">
		<article class="special-page__card" data-animate>
			<span class="eyebrow"><?php echo esc_html( leadwerk_theme_field( 'page_eyebrow', $content_id, '404 · Seite nicht gefunden' ) ); ?></span>
			<h1><?php echo esc_html( leadwerk_theme_field( 'page_headline', $content_id, 'Diese Seite ist vom Kurs abgekommen.' ) ); ?></h1>
			<p class="special-page__lead"><?php echo esc_html( leadwerk_theme_field( 'page_text', $content_id, 'Die aufgerufene Adresse existiert nicht oder wurde verschoben.' ) ); ?></p>
			<div class="cta-actions">
				<a class="btn btn--light btn--lg" href="<?php echo esc_url( leadwerk_page_reference_url( leadwerk_theme_field( 'page_cta_target', $content_id, array() ), home_url( '/' ) ) ); ?>"><?php echo esc_html( leadwerk_theme_field( 'page_cta_label', $content_id, 'Zur Startseite' ) ); ?></a>
				<a class="btn btn--primary btn--lg" href="<?php echo esc_url( leadwerk_page_reference_url( leadwerk_theme_field( 'page_secondary_target', $content_id, array() ), leadwerk_get_page_url( 'nw-t2med-home-v2', 'vorpruefung' ) ) ); ?>"><?php echo esc_html( leadwerk_theme_field( 'page_secondary_label', $content_id, 'T2med-Wechsel vorprüfen' ) ); ?></a>
			</div>
		</article>
	</div>
</main>
<?php get_footer(); ?>

<?php
/**
 * Structured page renderer.
 *
 * @package Leadwerk_T2med
 */
get_header();
while ( have_posts() ) :
	the_post();
	$source_key = (string) get_post_meta( get_the_ID(), 'leadwerk_source_key', true );
	$is_legal   = in_array( $source_key, array( 'nw-impressum-v1', 'nw-datenschutz-v1' ), true );
	?>
	<main id="hauptinhalt" class="special-page <?php echo $is_legal ? 'special-page--legal' : 'special-page--message'; ?>">
		<div class="special-page__bg" aria-hidden="true"><div class="lp-hero__blur"></div></div>
		<div class="container">
			<article class="special-page__card">
				<?php if ( leadwerk_theme_field( 'page_eyebrow', get_the_ID() ) ) : ?>
					<span class="eyebrow"><?php echo esc_html( leadwerk_theme_field( 'page_eyebrow', get_the_ID() ) ); ?></span>
				<?php endif; ?>
				<h1><?php echo esc_html( leadwerk_theme_field( 'page_headline', get_the_ID(), get_the_title() ) ); ?></h1>
				<?php if ( $is_legal ) : ?>
					<div class="legal-content"><?php echo wp_kses_post( leadwerk_theme_field( 'page_content', get_the_ID(), get_the_content() ) ); ?></div>
				<?php else : ?>
					<p class="special-page__lead"><?php echo esc_html( leadwerk_theme_field( 'page_text', get_the_ID() ) ); ?></p>
					<a class="btn btn--primary btn--lg" href="<?php echo esc_url( leadwerk_page_reference_url( leadwerk_theme_field( 'page_cta_target', get_the_ID(), array() ) ) ); ?>"><?php echo esc_html( leadwerk_theme_field( 'page_cta_label', get_the_ID(), 'Zur Startseite' ) ); ?></a>
				<?php endif; ?>
			</article>
		</div>
	</main>
	<?php
endwhile;
get_footer();


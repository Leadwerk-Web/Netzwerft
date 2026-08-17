<?php
/**
 * Generic fallback.
 *
 * @package Leadwerk_T2med
 */
get_header();
?>
<main id="hauptinhalt" class="section section--white">
	<div class="container legal-content">
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<h1><?php the_title(); ?></h1>
			<?php the_content(); ?>
		<?php endwhile; ?>
	</div>
</main>
<?php get_footer(); ?>

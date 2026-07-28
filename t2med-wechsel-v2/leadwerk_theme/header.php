<?php
/**
 * Site header.
 *
 * @package Leadwerk_T2med
 */
$solid_header = ! is_front_page();
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="theme-color" content="#004562">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'lp' ); ?>>
<?php wp_body_open(); ?>
<a class="skip-link" href="#hauptinhalt">Zum Inhalt springen</a>
<header class="<?php echo esc_attr( $solid_header ? 'site-header is-scrolled' : 'site-header' ); ?>" id="site-header" data-solid-header="<?php echo esc_attr( $solid_header ? 'true' : 'false' ); ?>">
	<div class="site-header__inner">
		<a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="die netzwerft – zur Startseite">
			<?php
			$logo_id = absint( leadwerk_theme_option( 'brand_logo', 0 ) );
			if ( $logo_id ) {
				echo wp_get_attachment_image(
					$logo_id,
					'full',
					false,
					array(
						'class' => 'brand__logo',
						'alt'   => 'die netzwerft',
					)
				);
			} else {
				echo '<span class="brand__text">die netzwerft</span>';
			}
			?>
		</a>
		<nav class="site-nav" id="site-nav" aria-label="Hauptnavigation">
			<ul class="site-nav__list">
				<?php foreach ( (array) leadwerk_theme_option( 'header_menu', array() ) as $item ) : ?>
					<?php if ( ! empty( $item['label'] ) ) : ?>
						<li><a class="site-nav__link" href="<?php echo esc_url( leadwerk_page_reference_url( $item['target'] ?? array() ) ); ?>"><?php echo esc_html( $item['label'] ); ?></a></li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
			<div class="site-nav__cta">
				<button class="btn btn--primary" type="button" data-cta="lp-nav" data-conversion="appointment_start">Erstgespräch</button>
			</div>
		</nav>
		<button class="nav-toggle" type="button" aria-label="Menü öffnen" aria-expanded="false" aria-controls="site-nav">
			<span class="nav-toggle__bar"></span><span class="nav-toggle__bar"></span><span class="nav-toggle__bar"></span>
		</button>
	</div>
</header>
<div class="nav-backdrop" aria-hidden="true"></div>

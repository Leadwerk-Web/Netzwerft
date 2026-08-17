<?php
/**
 * Structured T2med landing page.
 *
 * @package Leadwerk_T2med
 */
$leadwerk_page_id = get_queried_object_id();
$field            = static fn( $name, $default = '' ) => leadwerk_theme_field( $name, $leadwerk_page_id, $default );
$rows             = static function ( $name ) use ( $field ) {
	$value = $field( $name, array() );
	return is_array( $value ) ? array_values( $value ) : array();
};
$heading          = static function ( $normal, $bold ) {
	echo esc_html( $normal );
	if ( '' !== trim( (string) $bold ) ) {
		echo ' <span class="headline-bold">' . esc_html( $bold ) . '</span>';
	}
};
?>
<main id="hauptinhalt">
	<span id="top"></span>
	<section class="lp-hero" id="hero" aria-labelledby="lp-hero-title">
		<div class="lp-hero__bg" aria-hidden="true"><div class="lp-hero__blur"></div><canvas class="lp-hero__particles" id="hero-particles"></canvas></div>
		<div class="container">
			<div class="lp-hero__content" data-animate>
				<span class="eyebrow lp-hero__eyebrow"><?php echo esc_html( $field( 'hero_eyebrow' ) ); ?></span>
				<h1 class="lp-hero__title" id="lp-hero-title"><?php $heading( $field( 'hero_title' ), $field( 'hero_title_bold' ) ); ?></h1>
				<p class="lp-hero__sub"><?php echo esc_html( $field( 'hero_text' ) ); ?></p>
				<div class="lp-hero__actions">
					<button class="btn btn--light btn--lg" type="button" data-cta="lp-hero" data-conversion="appointment_start"><?php echo esc_html( $field( 'hero_cta_label' ) ); ?></button>
				</div>
				<p class="lp-hero__trust">
					<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns SVG from a fixed allowlist. ?>
					<?php echo leadwerk_theme_icon( 'security' ); ?>
					<?php echo esc_html( $field( 'hero_trust' ) ); ?>
				</p>
			</div>
		</div>
		<figure class="t2med__visual" style="--leadwerk-image-focus:<?php echo esc_attr( $field( 'hero_image_focus', '50% 50%' ) ); ?>">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress generates and escapes attachment markup.
			echo leadwerk_theme_image(
				'hero_image',
				'full',
				$field( 'hero_image_alt' ),
				$leadwerk_page_id,
				array(
					'fetchpriority' => 'high',
					'decoding'      => 'async',
				)
			);
			?>
		</figure>
	</section>

	<section class="section section--white" id="warum-wechseln" aria-labelledby="lp-problem-title">
		<div class="container">
			<div class="section__head section__head--center" data-animate>
				<span class="eyebrow"><?php echo esc_html( $field( 'problem_eyebrow' ) ); ?></span>
				<h2 class="section__title" id="lp-problem-title"><?php $heading( $field( 'problem_title' ), $field( 'problem_title_bold' ) ); ?></h2>
				<p class="section__intro"><?php echo esc_html( $field( 'problem_intro' ) ); ?></p>
			</div>
			<div class="pain-focus" data-pain-focus data-animate>
				<div class="pain-focus__nav" role="tablist" aria-label="Typische Probleme im Praxisalltag">
					<?php foreach ( $rows( 'problem_items' ) as $index => $item ) : ?>
						<button type="button" class="pain-focus__tab<?php echo 0 === $index ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo 0 === $index ? 'true' : 'false'; ?>" aria-controls="pain-focus-<?php echo esc_attr( $index + 1 ); ?>" id="pain-focus-tab-<?php echo esc_attr( $index + 1 ); ?>" data-pain-focus-tab="<?php echo esc_attr( $index ); ?>"<?php echo 0 === $index ? '' : ' tabindex="-1"'; ?>>
							<span class="pain-focus__num"><?php echo esc_html( str_pad( (string) ( $index + 1 ), 2, '0', STR_PAD_LEFT ) ); ?></span>
							<span class="pain-focus__label"><?php echo esc_html( $item['title'] ?? '' ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>
				<div class="pain-focus__stage">
					<?php foreach ( $rows( 'problem_items' ) as $index => $item ) : ?>
						<article class="pain-focus__panel<?php echo 0 === $index ? ' is-active' : ''; ?>" role="tabpanel" id="pain-focus-<?php echo esc_attr( $index + 1 ); ?>" aria-labelledby="pain-focus-tab-<?php echo esc_attr( $index + 1 ); ?>" data-pain-focus-panel="<?php echo esc_attr( $index ); ?>"<?php echo 0 === $index ? '' : ' hidden'; ?>>
							<span class="pain-focus__stage-num" aria-hidden="true"><?php echo esc_html( str_pad( (string) ( $index + 1 ), 2, '0', STR_PAD_LEFT ) ); ?></span>
							<h3 class="pain-focus__title"><?php echo esc_html( $item['title'] ?? '' ); ?></h3>
							<p class="pain-focus__text"><?php echo esc_html( $item['text'] ?? '' ); ?></p>
						</article>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</section>

	<section class="section section--surface" aria-labelledby="lp-solution-title">
		<div class="container lp-solution">
			<div class="lp-solution__intro" data-animate>
				<span class="eyebrow"><?php echo esc_html( $field( 'solution_eyebrow' ) ); ?></span>
				<h2 class="section__title" id="lp-solution-title"><?php $heading( $field( 'solution_title' ), $field( 'solution_title_bold' ) ); ?></h2>
				<p class="section__intro"><?php echo esc_html( $field( 'solution_intro' ) ); ?></p>
				<button class="btn btn--primary btn--lg" type="button" data-cta="lp-solution" data-conversion="appointment_start"><?php echo esc_html( $field( 'solution_cta_label' ) ); ?></button>
			</div>
			<ul class="t2med__list lp-solution__list" data-animate>
				<?php foreach ( $rows( 'solution_items' ) as $item ) : ?>
					<li><strong><?php echo esc_html( $item['title'] ?? '' ); ?></strong> – <?php echo esc_html( $item['text'] ?? '' ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</section>

	<section class="section section--white video-feature" aria-labelledby="lp-software-title">
		<div class="container video-feature__grid">
			<div class="video-feature__head" data-animate>
				<span class="eyebrow"><?php echo esc_html( $field( 'video_eyebrow' ) ); ?></span>
				<h2 class="section__title" id="lp-software-title"><?php $heading( $field( 'video_title' ), $field( 'video_title_bold' ) ); ?></h2>
			</div>
			<div class="video-feature__text" data-animate>
				<p class="section__intro"><?php echo esc_html( $field( 'video_intro' ) ); ?></p>
				<ul class="t2med__list">
					<?php foreach ( $rows( 'video_items' ) as $item ) : ?>
						<li><?php echo esc_html( $item['text'] ?? '' ); ?></li>
					<?php endforeach; ?>
				</ul>
				<button class="btn btn--primary btn--lg" type="button" data-cta="lp-software" data-conversion="appointment_start"><?php echo esc_html( $field( 'video_cta_label' ) ); ?></button>
			</div>
			<div class="video-feature__media" data-animate>
				<div class="video-embed" data-video-id="<?php echo esc_attr( preg_replace( '/[^A-Za-z0-9_-]/', '', $field( 'video_youtube_id' ) ) ); ?>" data-video-title="<?php echo esc_attr( $field( 'video_title_attr' ) ); ?>">
					<button class="video-embed__trigger" type="button" aria-label="<?php echo esc_attr( 'Video abspielen: ' . $field( 'video_title_attr' ) ); ?>">
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress generates and escapes attachment markup.
						echo leadwerk_theme_image(
							'video_poster',
							'full',
							$field( 'video_poster_alt' ),
							$leadwerk_page_id,
							array(
								'class'    => 'video-embed__poster',
								'loading'  => 'lazy',
								'decoding' => 'async',
							)
						);
						?>
						<span class="video-embed__overlay" aria-hidden="true"></span>
						<span class="video-embed__play" aria-hidden="true"><?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns SVG from a fixed allowlist. ?><?php echo leadwerk_theme_icon( 'check' ); ?></span>
						<span class="video-embed__hint">Video ansehen · T2med in der Praxis</span>
					</button>
					<p class="video-embed__note">
						Beim Klick wird YouTube im erweiterten Datenschutzmodus geladen. Hinweise stehen im <a href="<?php echo esc_url( leadwerk_get_page_url( 'nw-datenschutz-v1' ) ); ?>">Datenschutz</a>.
						<?php if ( $field( 'video_external_label' ) && $field( 'video_external_url' ) ) : ?>
							<a href="<?php echo esc_url( $field( 'video_external_url' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $field( 'video_external_label' ) ); ?></a>.
						<?php endif; ?>
					</p>
				</div>
			</div>
		</div>
	</section>

	<section class="section section--dark" id="leistungen" aria-labelledby="lp-services-title">
		<div class="container">
			<div class="section__head section__head--center" data-animate>
				<span class="eyebrow"><?php echo esc_html( $field( 'services_eyebrow' ) ); ?></span>
				<h2 class="section__title" id="lp-services-title"><?php $heading( $field( 'services_title' ), $field( 'services_title_bold' ) ); ?></h2>
				<p class="section__intro"><?php echo esc_html( $field( 'services_intro' ) ); ?></p>
			</div>
			<div class="grid grid--3">
				<?php foreach ( $rows( 'services_items' ) as $item ) : ?>
					<article class="card" data-animate>
						<span class="card__icon" aria-hidden="true"><?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns SVG from a fixed allowlist. ?><?php echo leadwerk_theme_icon( $item['icon_key'] ?? 'check' ); ?></span>
						<h3 class="card__title"><?php echo esc_html( $item['title'] ?? '' ); ?></h3>
						<p class="card__text"><?php echo esc_html( $item['text'] ?? '' ); ?></p>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<section class="section section--white" id="ablauf" aria-labelledby="lp-process-title">
		<div class="container">
			<div class="section__head section__head--center" data-animate>
				<span class="eyebrow"><?php echo esc_html( $field( 'process_eyebrow' ) ); ?></span>
				<h2 class="section__title" id="lp-process-title"><?php $heading( $field( 'process_title' ), $field( 'process_title_bold' ) ); ?></h2>
				<p class="section__intro"><?php echo esc_html( $field( 'process_intro' ) ); ?></p>
			</div>
			<div class="steps lp-steps">
				<?php foreach ( $rows( 'process_items' ) as $index => $item ) : ?>
					<div class="step" data-animate>
						<span class="step__num"><?php echo esc_html( $item['number'] ?? ( $index + 1 ) ); ?></span>
						<h3 class="step__title"><?php echo esc_html( $item['title'] ?? '' ); ?></h3>
						<p class="step__text"><?php echo esc_html( $item['text'] ?? '' ); ?></p>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<section class="section section--surface" aria-labelledby="lp-trust-title">
		<div class="container">
			<div class="section__head section__head--center" data-animate>
				<span class="eyebrow"><?php echo esc_html( $field( 'trust_eyebrow' ) ); ?></span>
				<h2 class="section__title" id="lp-trust-title"><?php $heading( $field( 'trust_title' ), $field( 'trust_title_bold' ) ); ?></h2>
				<p class="section__intro"><?php echo esc_html( $field( 'trust_intro' ) ); ?></p>
			</div>
			<div class="grid grid--3">
				<?php foreach ( $rows( 'trust_items' ) as $item ) : ?>
					<article class="card" data-animate>
						<span class="card__icon" aria-hidden="true"><?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns SVG from a fixed allowlist. ?><?php echo leadwerk_theme_icon( $item['icon_key'] ?? 'check' ); ?></span>
						<h3 class="card__title"><?php echo esc_html( $item['title'] ?? '' ); ?></h3>
						<p class="card__text"><?php echo esc_html( $item['text'] ?? '' ); ?></p>
					</article>
				<?php endforeach; ?>
			</div>
			<div class="stat-row lp-proof__stats">
				<?php foreach ( $rows( 'trust_stats' ) as $item ) : ?>
					<div class="stat" data-animate><div class="stat__num"><?php echo esc_html( $item['value'] ?? '' ); ?></div><div class="stat__label"><?php echo esc_html( $item['label'] ?? '' ); ?></div></div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<section class="section section--dark" id="vorpruefung" aria-labelledby="lp-qualify-title">
		<div class="container">
			<div class="lp-qualify" data-animate>
				<div class="lp-qualify__head">
					<span class="eyebrow"><?php echo esc_html( $field( 'qualify_eyebrow' ) ); ?></span>
					<h2 class="section__title" id="lp-qualify-title"><?php $heading( $field( 'qualify_title' ), $field( 'qualify_title_bold' ) ); ?></h2>
					<p class="section__intro"><?php echo esc_html( $field( 'qualify_intro' ) ); ?></p>
				</div>
				<div class="lp-qualify__form" id="lp-qualify-form" data-prequalifier>
					<div class="funnel-step">
						<span class="funnel-step__label" id="lp-q1-label"><?php echo esc_html( $field( 'qualify_situation_label' ) ); ?></span>
						<div class="funnel-options" data-prequal-group="situation" role="group" aria-labelledby="lp-q1-label">
							<?php foreach ( $rows( 'qualify_situation_items' ) as $item ) : ?>
								<button type="button" class="funnel-chip" aria-pressed="false" data-value="<?php echo esc_attr( $item['label'] ?? '' ); ?>"><?php echo esc_html( $item['label'] ?? '' ); ?></button>
							<?php endforeach; ?>
						</div>
					</div>
					<div class="funnel-step">
						<label class="funnel-step__label" for="lp-q2-plz"><?php echo esc_html( $field( 'qualify_location_label' ) ); ?></label>
						<input class="funnel-input" id="lp-q2-plz" data-prequal-location type="text" autocomplete="postal-code" placeholder="<?php echo esc_attr( $field( 'qualify_location_holder' ) ); ?>">
					</div>
					<div class="funnel-step">
						<span class="funnel-step__label" id="lp-q3-label"><?php echo esc_html( $field( 'qualify_start_label' ) ); ?></span>
						<div class="funnel-options" data-prequal-group="start" role="group" aria-labelledby="lp-q3-label">
							<?php foreach ( $rows( 'qualify_start_items' ) as $item ) : ?>
								<button type="button" class="funnel-chip" aria-pressed="false" data-value="<?php echo esc_attr( $item['label'] ?? '' ); ?>"><?php echo esc_html( $item['label'] ?? '' ); ?></button>
							<?php endforeach; ?>
						</div>
					</div>
					<div class="funnel-step">
						<span class="funnel-step__label" id="lp-q4-label"><?php echo esc_html( $field( 'qualify_scope_label' ) ); ?></span>
						<div class="funnel-options" data-prequal-group="scope" role="group" aria-labelledby="lp-q4-label">
							<?php foreach ( $rows( 'qualify_scope_items' ) as $item ) : ?>
								<button type="button" class="funnel-chip" aria-pressed="false" data-value="<?php echo esc_attr( $item['label'] ?? '' ); ?>"><?php echo esc_html( $item['label'] ?? '' ); ?></button>
							<?php endforeach; ?>
						</div>
					</div>
					<button type="button" class="btn btn--light btn--lg btn--block" data-cta="lp-qualify-submit" data-conversion="appointment_start"><?php echo esc_html( $field( 'qualify_cta_label' ) ); ?></button>
					<p class="lp-qualify__note"><?php echo esc_html( $field( 'qualify_note' ) ); ?></p>
				</div>
			</div>
		</div>
	</section>

	<section class="section section--surface" id="faq" aria-labelledby="lp-faq-title">
		<div class="container">
			<div class="section__head section__head--center" data-animate>
				<span class="eyebrow"><?php echo esc_html( $field( 'faq_eyebrow' ) ); ?></span>
				<h2 class="section__title" id="lp-faq-title"><?php $heading( $field( 'faq_title' ), $field( 'faq_title_bold' ) ); ?></h2>
			</div>
			<div class="faq" data-animate>
				<?php foreach ( $rows( 'faq_items' ) as $index => $item ) : ?>
					<div class="faq__item">
						<button class="faq__trigger" type="button" aria-expanded="false" aria-controls="lp-faq-<?php echo esc_attr( $index + 1 ); ?>" id="lp-faq-<?php echo esc_attr( $index + 1 ); ?>-btn">
							<?php echo esc_html( $item['question'] ?? '' ); ?><span class="faq__icon" aria-hidden="true"></span>
						</button>
						<div class="faq__panel" id="lp-faq-<?php echo esc_attr( $index + 1 ); ?>" role="region" aria-labelledby="lp-faq-<?php echo esc_attr( $index + 1 ); ?>-btn"><div class="faq__panel-inner"><?php echo esc_html( $item['answer'] ?? '' ); ?></div></div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<section class="section section--surface final-cta" id="kontakt" aria-labelledby="lp-final-title">
		<div class="container">
			<div class="final-cta__card" data-animate>
				<h2 id="lp-final-title"><?php $heading( $field( 'final_title' ), $field( 'final_title_bold' ) ); ?></h2>
				<p><?php echo esc_html( $field( 'final_text' ) ); ?></p>
				<div class="cta-actions"><button class="btn btn--light btn--lg" type="button" data-cta="lp-final" data-conversion="appointment_start"><?php echo esc_html( $field( 'final_cta_label' ) ); ?></button></div>
				<p class="cta-note"><?php echo esc_html( $field( 'final_contact_prefix' ) ); ?>
					<a href="tel:<?php echo esc_attr( preg_replace( '/[^+0-9]/', '', leadwerk_theme_option( 'company_phone', '07243 350600' ) ) ); ?>"><?php echo esc_html( leadwerk_theme_option( 'company_phone', '07243 350600' ) ); ?></a>
					· <a href="mailto:<?php echo esc_attr( sanitize_email( leadwerk_theme_option( 'company_email', 'info@dienetzwerft.de' ) ) ); ?>"><?php echo esc_html( antispambot( leadwerk_theme_option( 'company_email', 'info@dienetzwerft.de' ) ) ); ?></a>
				</p>
			</div>
		</div>
	</section>
</main>

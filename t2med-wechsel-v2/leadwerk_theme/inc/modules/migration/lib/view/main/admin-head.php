<?php
/**
 * Leadwerk Migration icon font bootstrap.
 *
 * Generic icon glyphs are retained from the GPL-licensed upstream UI assets.
 * Copyright (C) 2014-2025 ServMask Inc.
 * Modifications Copyright (C) 2026 Leadwerk.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access is not allowed.' );
}
?>
<style type="text/css" media="all">
	@font-face {
		font-family: 'leadwerk-migration-icons';
		src: url('<?php echo esc_url( wp_make_link_relative( LEADWERK_MIGRATION_URL ) . '/lib/view/assets/font/servmask.eot?v=' . LEADWERK_MIGRATION_VERSION ); ?>');
		src: url('<?php echo esc_url( wp_make_link_relative( LEADWERK_MIGRATION_URL ) . '/lib/view/assets/font/servmask.eot?v=' . LEADWERK_MIGRATION_VERSION ); ?>#iefix') format('embedded-opentype'),
		url('<?php echo esc_url( wp_make_link_relative( LEADWERK_MIGRATION_URL ) . '/lib/view/assets/font/servmask.woff?v=' . LEADWERK_MIGRATION_VERSION ); ?>') format('woff'),
		url('<?php echo esc_url( wp_make_link_relative( LEADWERK_MIGRATION_URL ) . '/lib/view/assets/font/servmask.ttf?v=' . LEADWERK_MIGRATION_VERSION ); ?>') format('truetype'),
		url('<?php echo esc_url( wp_make_link_relative( LEADWERK_MIGRATION_URL ) . '/lib/view/assets/font/servmask.svg?v=' . LEADWERK_MIGRATION_VERSION ); ?>#servmask') format('svg');
		font-weight: normal;
		font-style: normal;
	}

	[class^="leadwerk_migration-icon-"], [class*=" leadwerk_migration-icon-"] {
		font-family: 'leadwerk-migration-icons';
		speak: none;
		font-style: normal;
		font-weight: normal;
		font-variant: normal;
		text-transform: none;
		line-height: 1;
		-webkit-font-smoothing: antialiased;
		-moz-osx-font-smoothing: grayscale;
	}

	.leadwerk_migration-menu-count {
		display: inline-block;
		vertical-align: top;
		box-sizing: border-box;
		margin: 1px 0 -1px 2px;
		padding: 0 5px;
		min-width: 18px;
		height: 18px;
		border-radius: 9px;
		background-color: #d63638;
		color: #fff;
		font-size: 11px;
		line-height: 1.6;
		text-align: center;
	}

	.leadwerk_migration-menu-count.leadwerk_migration-menu-hide { display: none; }

	/* Replace upstream bitmap branding with a neutral activity indicator. */
	.leadwerk_migration-loader,
	.leadwerk_migration-modal-container section h1 .leadwerk_migration-loader {
		box-sizing: border-box;
		width: 34px;
		height: 34px;
		border: 3px solid rgba(91, 76, 240, .18);
		border-top-color: #5b4cf0;
		border-radius: 50%;
		background: none !important;
	}
</style>

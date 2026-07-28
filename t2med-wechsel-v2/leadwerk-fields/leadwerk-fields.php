<?php
/**
 * Plugin Name: Leadwerk Fields
 * Description: Sichere, ACF-kompatible Feldverwaltung für die T2med-Landingpage.
 * Version: 2.0.1
 * Author: Leadwerk
 * Text Domain: leadwerk-fields
 * Requires at least: 6.9
 * Requires PHP: 8.1
 *
 * @package Leadwerk_Fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LEADWERK_FIELDS_VERSION', '2.0.1' );
define( 'LEADWERK_FIELDS_PATH', plugin_dir_path( __FILE__ ) );
define( 'LEADWERK_FIELDS_URL', plugin_dir_url( __FILE__ ) );

require_once LEADWERK_FIELDS_PATH . 'includes/class-leadwerk-content-schema.php';
require_once LEADWERK_FIELDS_PATH . 'includes/class-leadwerk-fields-api.php';
require_once LEADWERK_FIELDS_PATH . 'includes/leadwerk-fields-functions.php';
require_once LEADWERK_FIELDS_PATH . 'includes/class-leadwerk-fields-metabox.php';

Leadwerk_Fields_API::init();
Leadwerk_Fields_Metabox::init();

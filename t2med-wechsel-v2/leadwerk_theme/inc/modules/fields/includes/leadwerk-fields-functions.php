<?php
/**
 * Public field helpers.
 *
 * @package Leadwerk_Fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function leadwerk_get_field( $name, $post_id = null, $default = null ) {
	$value = Leadwerk_Fields_API::get_field( $name, $post_id );
	return ( null === $value || '' === $value ) ? $default : $value;
}

function leadwerk_update_field( $name, $value, $post_id = null ) {
	return Leadwerk_Fields_API::update_field( $name, $value, $post_id );
}

function leadwerk_get_option( $name, $default = null ) {
	return leadwerk_get_field( $name, 'option', $default );
}

if ( ! function_exists( 'get_field' ) ) {
	function get_field( $name, $post_id = null ) {
		return Leadwerk_Fields_API::get_field( $name, $post_id );
	}
}

if ( ! function_exists( 'update_field' ) ) {
	function update_field( $name, $value, $post_id = null ) {
		return Leadwerk_Fields_API::update_field( $name, $value, $post_id );
	}
}

if ( ! function_exists( 'the_field' ) ) {
	function the_field( $name, $post_id = null ) {
		echo esc_html( (string) get_field( $name, $post_id ) );
	}
}

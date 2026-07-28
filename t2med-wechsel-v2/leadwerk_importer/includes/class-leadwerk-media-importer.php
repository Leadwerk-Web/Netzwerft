<?php
/**
 * Path+SHA-256 based media import with controlled SVG sanitizing.
 *
 * @package Leadwerk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Media_Importer {
	public static function discover_references( $html_file, $css_dir ) {
		$references = array();
		$html       = is_file( $html_file ) ? (string) file_get_contents( $html_file ) : '';

		preg_match_all( "~(?:src|poster)\\s*=\\s*[\"']([^\"']+)[\"']~i", $html, $single );
		$references = array_merge( $references, $single[1] ?? array() );
		preg_match_all( "~srcset\\s*=\\s*[\"']([^\"']+)[\"']~i", $html, $sets );
		foreach ( $sets[1] ?? array() as $set ) {
			foreach ( explode( ',', $set ) as $candidate ) {
				$references[] = preg_split( '/\\s+/', trim( $candidate ) )[0] ?? '';
			}
		}
		preg_match_all( "~background(?:-image)?\\s*:\\s*[^;]*url\\([\"']?([^)\"']+)~i", $html, $inline );
		$references = array_merge( $references, $inline[1] ?? array() );

		$css_files = glob( trailingslashit( $css_dir ) . '*.css' );
		foreach ( $css_files ? $css_files : array() as $css_file ) {
			$css = (string) file_get_contents( $css_file );
			preg_match_all( "~url\\([\"']?([^)\"']+)~i", $css, $matches );
			$references = array_merge( $references, $matches[1] ?? array() );
		}

		$found   = array();
		$missing = array();
		foreach ( array_unique( array_filter( $references ) ) as $reference ) {
			$path = strtok( $reference, '?#' );
			if ( preg_match( '#^(?:https?:|data:|//)#i', $path ) || ! preg_match( '/\\.(?:png|jpe?g|gif|webp|svg)$/i', $path ) ) {
				continue;
			}
			$candidate = LEADWERK_IMPORTER_PATH . 'source_assets/media/' . basename( $path );
			if ( is_file( $candidate ) ) {
				$found[] = $path;
			} else {
				$missing[] = $path;
			}
		}
		return array(
			'found'   => array_values( array_unique( $found ) ),
			'missing' => array_values( array_unique( $missing ) ),
		);
	}

	public static function import_manifest( $media_items, &$journal ) {
		$media_items = self::with_discovered_media( $media_items );
		$result      = array();
		foreach ( $media_items as $item ) {
			$result[ $item['key'] ] = self::import_one( $item, $journal );
		}
		return $result;
	}

	/**
	 * Add image references found in HTML/CSS even when a future package update
	 * forgot to list them explicitly in the manifest.
	 *
	 * Manifest entries keep their semantic keys (hero_image, brand_logo, ...).
	 * Discovered-only files receive stable path-derived keys and are imported to
	 * the Media Library so CSS background, poster and srcset assets cannot vanish.
	 */
	private static function with_discovered_media( $media_items ) {
		$items       = is_array( $media_items ) ? array_values( $media_items ) : array();
		$known_paths = array();
		foreach ( $items as $item ) {
			$known_paths[] = ltrim( (string) ( $item['path'] ?? '' ), '/' );
		}

		$source    = LEADWERK_IMPORTER_PATH . 'source_assets/t2med-wechsel-v2.html';
		$theme_css = trailingslashit( get_template_directory() ) . 'assets/css';
		$discovery = self::discover_references( $source, $theme_css );
		foreach ( $discovery['found'] as $reference ) {
			$relative = 'source_assets/media/' . basename( strtok( $reference, '?#' ) );
			if ( in_array( $relative, $known_paths, true ) ) {
				continue;
			}
			$file = LEADWERK_IMPORTER_PATH . $relative;
			if ( ! is_file( $file ) ) {
				continue;
			}
			$items[]       = array(
				'key'    => 'discovered_' . substr( hash( 'sha256', $relative ), 0, 16 ),
				'path'   => $relative,
				'sha256' => hash_file( 'sha256', $file ),
				'alt'    => '',
			);
			$known_paths[] = $relative;
		}
		return $items;
	}

	private static function import_one( $item, &$journal ) {
		$relative = ltrim( (string) $item['path'], '/' );
		$source   = LEADWERK_IMPORTER_PATH . $relative;
		$hash     = hash_file( 'sha256', $source );
		$existing = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_leadwerk_source_path',
						'value' => $relative,
					),
					array(
						'key'   => '_leadwerk_source_sha256',
						'value' => $hash,
					),
				),
			)
		);
		if ( ! empty( $existing ) ) {
			return (int) $existing[0];
		}

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			throw new RuntimeException( $upload['error'] );
		}
		$filename = wp_unique_filename( $upload['path'], sanitize_file_name( basename( $source ) ) );
		$target   = trailingslashit( $upload['path'] ) . $filename;
		$content  = (string) file_get_contents( $source );

		if ( 'svg' === strtolower( pathinfo( $source, PATHINFO_EXTENSION ) ) ) {
			$content = self::sanitize_svg( $content );
		}
		if ( false === file_put_contents( $target, $content, LOCK_EX ) ) {
			throw new RuntimeException( 'Medium konnte nicht in das Upload-Verzeichnis kopiert werden: ' . $filename );
		}

		$filetype = wp_check_filetype( $filename );
		if ( '' === (string) $filetype['type'] && str_ends_with( strtolower( $filename ), '.svg' ) ) {
			$filetype = array(
				'type' => 'image/svg+xml',
				'ext'  => 'svg',
			);
		}
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $filetype['type'],
				'post_title'     => sanitize_text_field( pathinfo( $filename, PATHINFO_FILENAME ) ),
				'post_status'    => 'inherit',
			),
			$target
		);
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $target );
			throw new RuntimeException( $attachment_id->get_error_message() );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $target );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}
		update_post_meta( $attachment_id, '_leadwerk_source_path', $relative );
		update_post_meta( $attachment_id, '_leadwerk_source_sha256', $hash );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $item['alt'] ?? '' ) );
		$journal['created_attachments'][] = (int) $attachment_id;
		return (int) $attachment_id;
	}

	private static function sanitize_svg( $svg ) {
		if ( false !== stripos( $svg, '<!DOCTYPE' ) || false !== stripos( $svg, '<!ENTITY' ) ) {
			throw new RuntimeException( 'Unsicheres SVG: DOCTYPE/ENTITY ist nicht erlaubt.' );
		}
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $dom->loadXML( $svg, LIBXML_NONET | LIBXML_NOBLANKS );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || 'svg' !== strtolower( $dom->documentElement->localName ) ) {
			throw new RuntimeException( 'Ungültiges SVG.' );
		}
		$xpath = new DOMXPath( $dom );
		foreach ( $xpath->query( '//*[local-name()="script" or local-name()="foreignObject" or local-name()="iframe" or local-name()="object"]' ) as $node ) {
			$node->parentNode->removeChild( $node );
		}
		foreach ( $xpath->query( '//@*' ) as $attribute ) {
			$name  = strtolower( $attribute->nodeName );
			$value = trim( $attribute->nodeValue );
			if ( str_starts_with( $name, 'on' ) || preg_match( '#^\\s*(?:javascript|data:text/html):#i', $value ) ) {
				$attribute->ownerElement->removeAttributeNode( $attribute );
			}
		}
		return $dom->saveXML( $dom->documentElement );
	}
}

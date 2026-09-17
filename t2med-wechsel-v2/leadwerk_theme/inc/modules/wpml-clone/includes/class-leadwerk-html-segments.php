<?php
/** Extract and apply stable translation segments in safe HTML fragments. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_HTML_Segments {
	private static $skip_tags = array( 'script', 'style', 'svg', 'path', 'circle', 'source', 'code', 'pre' );
	private static $attributes = array( 'alt', 'aria-label', 'title', 'placeholder', 'data-title', 'data-body' );

	public static function extract( $html ) {
		$root = self::load_fragment( $html );
		if ( ! $root ) {
			return array();
		}
		$segments = array();
		self::walk( $root, 'root[1]', $segments, false, false );
		return $segments;
	}

	public static function apply( $html, $translations ) {
		$root = self::load_fragment( $html );
		if ( ! $root ) {
			return $html;
		}
		$translations = is_array( $translations ) ? $translations : array();
		self::walk( $root, 'root[1]', $translations, true, false );
		$out = '';
		foreach ( $root->childNodes as $child ) {
			$out .= $root->ownerDocument->saveHTML( $child );
		}
		return $out;
	}

	private static function walk( $node, $path, &$collector, $apply, $skip ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$source = (string) $node->nodeValue;
			if ( $skip || ! self::is_translatable( $source ) ) {
				return;
			}
			if ( $apply ) {
				if ( isset( $collector[ $path ] ) && '' !== trim( (string) $collector[ $path ] ) ) {
					preg_match( '/^(\s*)(.*?)(\s*)$/us', $source, $matches );
					$node->nodeValue = ( $matches[1] ?? '' ) . (string) $collector[ $path ] . ( $matches[3] ?? '' );
				}
			} else {
				$collector[ $path ] = array( 'key' => $path, 'type' => 'text', 'source' => trim( $source ), 'source_hash' => md5( trim( $source ) ) );
			}
			return;
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return;
		}
		$skip = $skip || in_array( strtolower( $node->nodeName ), self::$skip_tags, true ) || 'true' === $node->getAttribute( 'aria-hidden' ) || $node->hasAttribute( 'data-no-translate' );
		if ( $skip ) {
			return;
		}
		foreach ( self::$attributes as $attribute ) {
			if ( ! $node->hasAttribute( $attribute ) || ! self::is_translatable( $node->getAttribute( $attribute ) ) ) {
				continue;
			}
			$key = $path . '/@' . $attribute;
			if ( $apply ) {
				if ( isset( $collector[ $key ] ) && '' !== trim( (string) $collector[ $key ] ) ) {
					$node->setAttribute( $attribute, (string) $collector[ $key ] );
				}
			} else {
				$value             = (string) $node->getAttribute( $attribute );
				$collector[ $key ] = array( 'key' => $key, 'type' => 'attribute', 'attribute' => $attribute, 'source' => $value, 'source_hash' => md5( $value ) );
			}
		}
		$elements = array();
		$texts    = 0;
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$texts++;
				self::walk( $child, $path . '/text[' . $texts . ']', $collector, $apply, false );
			} elseif ( XML_ELEMENT_NODE === $child->nodeType ) {
				$tag = strtolower( $child->nodeName );
				$elements[ $tag ] = ( $elements[ $tag ] ?? 0 ) + 1;
				self::walk( $child, $path . '/' . $tag . '[' . $elements[ $tag ] . ']', $collector, $apply, false );
			}
		}
	}

	private static function is_translatable( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || false !== strpos( $value, '@' ) || preg_match( '#^(https?:)?//#i', $value ) ) {
			return false;
		}
		return (bool) preg_match( '/[[:alpha:]\x{00C0}-\x{024F}]/u', $value );
	}

	private static function load_fragment( $html ) {
		if ( '' === trim( (string) $html ) || ! class_exists( 'DOMDocument' ) ) {
			return null;
		}
		$dom = new DOMDocument( '1.0', 'UTF-8' );
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?><div id="leadwerk-html-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		$root = $dom->getElementById( 'leadwerk-html-root' );
		return $root instanceof DOMElement ? $root : null;
	}
}

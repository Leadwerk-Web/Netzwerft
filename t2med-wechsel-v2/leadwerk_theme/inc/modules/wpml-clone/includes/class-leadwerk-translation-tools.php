<?php
/** Read-only diagnostics and conservative metadata repair. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_Translation_Tools {
	public static function report() {
		$ids = get_posts( array(
			'post_type' => 'page', 'post_status' => array( 'publish', 'draft', 'private', 'pending', 'future', 'trash' ),
			'posts_per_page' => -1, 'fields' => 'ids',
		) );
		$groups = array();
		$slugs  = array();
		$issues = array();
		foreach ( $ids as $post_id ) {
			$lang  = Leadwerk_Translation_API::language_of( $post_id );
			$group = Leadwerk_Translation_API::group_of( $post_id );
			$groups[ $group ][ $lang ][] = absint( $post_id );
			if ( 'en' === $lang ) {
				$slug = sanitize_title( (string) get_post_meta( $post_id, Leadwerk_Translation_API::META_PUBLIC_SLUG, true ) );
				if ( $slug ) {
					$slugs[ $slug ][] = absint( $post_id );
				}
			}
		}
		foreach ( $groups as $group => $languages ) {
			foreach ( $languages as $lang => $post_ids ) {
				if ( count( $post_ids ) > 1 ) {
					$issues[] = array( 'type' => 'duplicate_pair', 'label' => 'Doppelte ' . strtoupper( $lang ) . '-Zuordnung', 'detail' => $group . ': #' . implode( ', #', $post_ids ), 'repairable' => false );
				}
			}
			if ( empty( $languages['de'] ) && ! empty( $languages['en'] ) ) {
				$issues[] = array( 'type' => 'orphan_en', 'label' => 'EN-Seite ohne DE-Quelle', 'detail' => $group . ': #' . implode( ', #', $languages['en'] ), 'repairable' => false );
			}
		}
		foreach ( $slugs as $slug => $post_ids ) {
			if ( count( $post_ids ) > 1 ) {
				$issues[] = array( 'type' => 'duplicate_slug', 'label' => 'Doppelter öffentlicher EN-Slug', 'detail' => $slug . ': #' . implode( ', #', $post_ids ), 'repairable' => true );
			}
		}
		return array( 'pages' => count( $ids ), 'groups' => count( $groups ), 'issues' => $issues );
	}

	public static function repair() {
		$ids = get_posts( array(
			'post_type' => 'page', 'post_status' => array( 'publish', 'draft', 'private', 'pending', 'future' ),
			'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC',
		) );
		$fixed = 0;
		$used  = array();
		foreach ( $ids as $post_id ) {
			$lang = Leadwerk_Translation_API::language_of( $post_id );
			$before_lang  = (string) get_post_meta( $post_id, Leadwerk_Translation_API::META_LANG, true );
			$before_group = (string) get_post_meta( $post_id, Leadwerk_Translation_API::META_GROUP, true );
			Leadwerk_Translation_API::ensure_identity( $post_id, $lang );
			if ( $before_lang !== (string) get_post_meta( $post_id, Leadwerk_Translation_API::META_LANG, true ) || $before_group !== (string) get_post_meta( $post_id, Leadwerk_Translation_API::META_GROUP, true ) ) {
				$fixed++;
			}
			if ( 'en' !== $lang ) {
				continue;
			}
			$slug = sanitize_title( (string) get_post_meta( $post_id, Leadwerk_Translation_API::META_PUBLIC_SLUG, true ) );
			$slug = $slug ?: sanitize_title( preg_replace( '/-en$/', '', (string) get_post_field( 'post_name', $post_id ) ) );
			$base = $slug ?: 'page';
			$i    = 2;
			while ( isset( $used[ $slug ] ) ) {
				$slug = $base . '-' . $i++;
			}
			$used[ $slug ] = true;
			if ( $slug !== (string) get_post_meta( $post_id, Leadwerk_Translation_API::META_PUBLIC_SLUG, true ) ) {
				update_post_meta( $post_id, Leadwerk_Translation_API::META_PUBLIC_SLUG, $slug );
				$fixed++;
			}
		}
		delete_transient( 'leadwerk_translation_tools_report' );
		return $fixed;
	}
}

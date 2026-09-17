<?php
/**
 * Keep WP Mail SMTP settings intact across backup/import.
 *
 * Settings live in `wp_mail_smtp` and the Sodium key in `wp_mail_smtp_mail_key`.
 * Both names start with `wp_` but are plugin keys, not table-prefix columns.
 * URL/email replacement also mutates the encrypted password. The backup writes
 * a sidecar outside SQL; import reapplies it after the options table is replaced.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

class Leadwerk_Migration_Mail_Smtp {

	const SNAPSHOT_NAME        = 'mail-smtp-options.json';
	const TARGET_SNAPSHOT_NAME = 'mail-smtp-target.json';
	const OPTION_PREFIX        = 'wp_mail_smtp';
	const KEY_OPTION           = 'wp_mail_smtp_mail_key';

	/**
	 * Column prefixes that must not be rewritten during database export.
	 *
	 * @return string[]
	 */
	public static function reserved_column_prefixes() {
		return array(
			'wp_force_deactivated_plugins',
			'wp_page_for_privacy_policy',
			self::OPTION_PREFIX,
			self::KEY_OPTION,
			'wp_rocket_settings',
			'wp_rocket_dismiss_imagify_notice',
			'wp_rocket_no_licence',
			'wp_rocket_rocketcdn_old_url',
			'wp_rocket_hide_deactivation_form',
		);
	}

	/**
	 * True when an INSERT still contains a WP Mail SMTP option name.
	 *
	 * @param string $input SQL statement.
	 * @return boolean
	 */
	public static function is_insert_query( $input ) {
		if ( ! is_string( $input ) || $input === '' ) {
			return false;
		}

		if ( preg_match( "/'wp_mail_smtp(?:_[^']*)?'/", $input ) ) {
			return true;
		}

		$placeholder = defined( 'LEADWERK_MIGRATION_TABLE_PREFIX' ) ? LEADWERK_MIGRATION_TABLE_PREFIX : 'SERVMASK_PREFIX_';
		return (bool) preg_match( '/\'' . preg_quote( $placeholder, '/' ) . 'mail_smtp(?:_[^\']*)?\'/', $input );
	}

	/**
	 * Write the source SMTP sidecar that is packed into the backup archive.
	 *
	 * @param array $params Export parameters.
	 * @return string Absolute path of the sidecar, or empty string on failure.
	 */
	public static function write_export_sidecar( $params ) {
		return self::write_snapshot_file( self::sidecar_path( $params, self::SNAPSHOT_NAME ), self::load_option_rows() );
	}

	/**
	 * Persist destination SMTP options before the options table is replaced.
	 *
	 * @param array $params Import parameters.
	 * @return void
	 */
	public static function capture_target( $params ) {
		self::write_snapshot_file( self::sidecar_path( $params, self::TARGET_SNAPSHOT_NAME ), self::load_option_rows() );
	}

	/**
	 * Re-apply SMTP options after the options table has been replaced.
	 *
	 * @param array $params Import parameters.
	 * @return void
	 */
	public static function preserve_imported_settings( $params ) {
		self::repair_option_names();

		$source = self::read_snapshot_file( self::sidecar_path( $params, self::SNAPSHOT_NAME ) );
		if ( self::rows_usable( $source ) ) {
			self::write_option_rows( $source );
			return;
		}

		$target = self::read_snapshot_file( self::sidecar_path( $params, self::TARGET_SNAPSHOT_NAME ) );
		if ( self::rows_usable( $target ) ) {
			self::write_option_rows( $target );
		}
	}

	/**
	 * @param array $params Pipeline parameters.
	 * @return string
	 */
	public static function export_sidecar_path( $params ) {
		return self::sidecar_path( $params, self::SNAPSHOT_NAME );
	}

	/**
	 * @param array  $params Pipeline parameters.
	 * @param string $name   File name.
	 * @return string
	 */
	private static function sidecar_path( $params, $name ) {
		return leadwerk_migration_storage_path( $params ) . DIRECTORY_SEPARATOR . $name;
	}

	/**
	 * @param string                               $path Destination file.
	 * @param array<int, array<string, string>>    $rows Option rows.
	 * @return string
	 */
	private static function write_snapshot_file( $path, $rows ) {
		if ( ! is_string( $path ) || $path === '' ) {
			return '';
		}

		$payload = array(
			'version' => 2,
			'rows'    => array(),
		);
		foreach ( $rows as $row ) {
			if ( empty( $row['option_name'] ) || ! is_string( $row['option_name'] ) ) {
				continue;
			}
			$payload['rows'][] = array(
				'option_name'  => $row['option_name'],
				'option_value' => base64_encode( isset( $row['option_value'] ) ? (string) $row['option_value'] : '' ),
				'autoload'     => isset( $row['autoload'] ) ? (string) $row['autoload'] : 'no',
				'encoding'     => 'base64',
			);
		}

		$encoded = wp_json_encode( $payload );
		if ( ! is_string( $encoded ) || @file_put_contents( $path, $encoded, LOCK_EX ) !== strlen( $encoded ) ) {
			return '';
		}

		@chmod( $path, 0600 );
		return $path;
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private static function load_option_rows() {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->options ) ) {
			return array();
		}

		$like = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value, autoload FROM `{$wpdb->options}` WHERE option_name LIKE %s",
				$like
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rename `{prefix}mail_smtp*` leftovers back to `wp_mail_smtp*`.
	 *
	 * @return void
	 */
	private static function repair_option_names() {
		global $wpdb;

		$prefix = function_exists( 'leadwerk_migration_table_prefix' ) ? leadwerk_migration_table_prefix() : '';
		if ( ! is_string( $prefix ) || $prefix === '' || $prefix === 'wp_' || ! isset( $wpdb ) || empty( $wpdb->options ) ) {
			return;
		}

		$like = $wpdb->esc_like( $prefix . 'mail_smtp' ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM `{$wpdb->options}` WHERE option_name LIKE %s",
				$like
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			if ( empty( $row['option_name'] ) || 0 !== strpos( $row['option_name'], $prefix . 'mail_smtp' ) ) {
				continue;
			}

			$canonical = 'wp_' . substr( $row['option_name'], strlen( $prefix ) );
			$existing  = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_id FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 1",
					$canonical
				)
			);

			if ( $existing ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $row['option_name'] ) );
				continue;
			}

			$wpdb->update(
				$wpdb->options,
				array( 'option_name' => $canonical ),
				array( 'option_name' => $row['option_name'] )
			);
		}
	}

	/**
	 * @param array<int, array<string, string>> $rows Snapshot rows.
	 * @return boolean
	 */
	private static function rows_usable( $rows ) {
		if ( ! is_array( $rows ) || $rows === array() ) {
			return false;
		}

		$options = false;
		$key     = false;
		foreach ( $rows as $row ) {
			if ( empty( $row['option_name'] ) ) {
				continue;
			}
			if ( self::OPTION_PREFIX === $row['option_name'] ) {
				$options = maybe_unserialize( $row['option_value'] );
			}
			if ( self::KEY_OPTION === $row['option_name'] ) {
				$key = $row['option_value'];
			}
		}

		if ( ! is_array( $options ) || $options === array() ) {
			return false;
		}

		$mailer = isset( $options['mail']['mailer'] ) && is_string( $options['mail']['mailer'] ) ? $options['mail']['mailer'] : '';
		$host   = isset( $options['smtp']['host'] ) && is_string( $options['smtp']['host'] ) ? $options['smtp']['host'] : '';
		$from   = isset( $options['mail']['from_email'] ) && is_string( $options['mail']['from_email'] ) ? $options['mail']['from_email'] : '';
		if ( $mailer === '' && $host === '' && $from === '' ) {
			return false;
		}

		if ( in_array( $mailer, array( 'smtp', 'gmail', 'outlook', 'mailgun', 'sendgrid', 'sendinblue', 'smtpcom', 'amazonses', 'postmark', 'sparkpost', 'smtp2go' ), true ) && ( ! is_string( $key ) || $key === '' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @param string $path Snapshot file.
	 * @return array<int, array<string, string>>
	 */
	private static function read_snapshot_file( $path ) {
		if ( ! is_string( $path ) || ! is_file( $path ) ) {
			return array();
		}

		$handle   = leadwerk_migration_open( $path, 'r' );
		$contents = leadwerk_migration_read( $handle, filesize( $path ) );
		leadwerk_migration_close( $handle );
		$decoded  = is_string( $contents ) ? json_decode( $contents, true ) : null;
		if ( ! is_array( $decoded ) || empty( $decoded['rows'] ) || ! is_array( $decoded['rows'] ) ) {
			return array();
		}

		$rows = array();
		foreach ( $decoded['rows'] as $row ) {
			if ( empty( $row['option_name'] ) || ! is_string( $row['option_name'] ) ) {
				continue;
			}
			$value = isset( $row['option_value'] ) ? (string) $row['option_value'] : '';
			if ( isset( $row['encoding'] ) && 'base64' === $row['encoding'] ) {
				$decoded_value = base64_decode( $value, true );
				$value         = false === $decoded_value ? $value : $decoded_value;
			}
			$rows[] = array(
				'option_name'  => $row['option_name'],
				'option_value' => $value,
				'autoload'     => isset( $row['autoload'] ) ? (string) $row['autoload'] : 'no',
			);
		}

		return $rows;
	}

	/**
	 * @param array<int, array<string, string>> $rows Snapshot rows.
	 * @return void
	 */
	private static function write_option_rows( $rows ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || empty( $wpdb->options ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			if ( empty( $row['option_name'] ) || ! is_string( $row['option_name'] ) || 0 !== strpos( $row['option_name'], self::OPTION_PREFIX ) ) {
				continue;
			}

			$autoload = isset( $row['autoload'] ) && is_string( $row['autoload'] ) ? $row['autoload'] : 'no';
			$wpdb->replace(
				$wpdb->options,
				array(
					'option_name'  => $row['option_name'],
					'option_value' => isset( $row['option_value'] ) ? $row['option_value'] : '',
					'autoload'     => $autoload,
				)
			);
		}
	}
}

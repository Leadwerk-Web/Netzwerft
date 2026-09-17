<?php
/**
 * Standalone smoke checks for WP Mail SMTP backup/import preservation.
 * Run with: php tests/mail-smtp-import-smoke.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'LEADWERK_MIGRATION_TABLE_PREFIX', 'SERVMASK_PREFIX_' );

function __( $message ) {
	return $message;
}

function leadwerk_mail_smtp_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

require_once dirname( __DIR__ ) . '/lib/model/class-leadwerk_migration-mail-smtp.php';

leadwerk_mail_smtp_assert(
	Leadwerk_Migration_Mail_Smtp::SNAPSHOT_NAME !== Leadwerk_Migration_Mail_Smtp::TARGET_SNAPSHOT_NAME,
	'source sidecar and destination snapshot must use different filenames'
);
leadwerk_mail_smtp_assert(
	Leadwerk_Migration_Mail_Smtp::is_insert_query( "INSERT INTO `SERVMASK_PREFIX_options` VALUES (12,'wp_mail_smtp','a:1:{s:4:\"mail\";a:0:{}}');" ),
	'canonical WP Mail SMTP option rows must be detected before prefix rewrite'
);
leadwerk_mail_smtp_assert(
	Leadwerk_Migration_Mail_Smtp::is_insert_query( "INSERT INTO `SERVMASK_PREFIX_options` VALUES (13,'wp_mail_smtp_mail_key','QUFBQUFBQUE=');" ),
	'the Sodium encryption key option must be treated as a mail SMTP row'
);
leadwerk_mail_smtp_assert(
	Leadwerk_Migration_Mail_Smtp::is_insert_query( "INSERT INTO `SERVMASK_PREFIX_options` VALUES (14,'SERVMASK_PREFIX_mail_smtp','a:1:{s:4:\"mail\";a:0:{}}');" ),
	'legacy prefix-rewritten WP Mail SMTP option names must still be detected'
);
leadwerk_mail_smtp_assert(
	! Leadwerk_Migration_Mail_Smtp::is_insert_query( "INSERT INTO `SERVMASK_PREFIX_options` VALUES (15,'siteurl','https://example.com');" ),
	'unrelated options must still receive URL replacement'
);

$reserved = Leadwerk_Migration_Mail_Smtp::reserved_column_prefixes();
leadwerk_mail_smtp_assert( in_array( 'wp_mail_smtp', $reserved, true ), 'export must reserve the WP Mail SMTP option prefix' );
leadwerk_mail_smtp_assert( in_array( 'wp_mail_smtp_mail_key', $reserved, true ), 'export must reserve the WP Mail SMTP encryption key option' );

$database_source = file_get_contents( dirname( __DIR__ ) . '/lib/vendor/servmask/database/class-leadwerk_migration-database.php' );
$import_source   = file_get_contents( dirname( __DIR__ ) . '/lib/model/import/class-leadwerk_migration-import-database.php' );
$import_file     = file_get_contents( dirname( __DIR__ ) . '/lib/model/import/class-leadwerk_migration-import-database-file.php' );
$export_source   = file_get_contents( dirname( __DIR__ ) . '/lib/model/export/class-leadwerk_migration-export-database.php' );
$export_file     = file_get_contents( dirname( __DIR__ ) . '/lib/model/export/class-leadwerk_migration-export-database-file.php' );
$loader_source   = file_get_contents( dirname( __DIR__ ) . '/loader.php' );
$helper_source   = file_get_contents( dirname( __DIR__ ) . '/lib/model/class-leadwerk_migration-mail-smtp.php' );

leadwerk_mail_smtp_assert(
	is_string( $database_source ) && strpos( $database_source, 'Leadwerk_Migration_Mail_Smtp::is_insert_query' ) !== false && strpos( $database_source, 'empty( $preserve_mail_smtp )' ) !== false,
	'import must skip URL/email replacement for WP Mail SMTP INSERTs'
);
leadwerk_mail_smtp_assert(
	is_string( $database_source ) && strpos( $database_source, 'replace_smtp_insert_table_identifier' ) !== false,
	'SMTP INSERTs must rewrite only the table identifier, not values that may contain wp_'
);
leadwerk_mail_smtp_assert(
	is_string( $import_source ) && strpos( $import_source, 'Leadwerk_Migration_Mail_Smtp::capture_target' ) !== false && strpos( $import_source, 'Leadwerk_Migration_Mail_Smtp::preserve_imported_settings' ) !== false,
	'import must snapshot destination SMTP options and restore them after the database is replaced'
);
leadwerk_mail_smtp_assert(
	is_string( $export_source ) && strpos( $export_source, 'Leadwerk_Migration_Mail_Smtp::reserved_column_prefixes' ) !== false,
	'export must use the shared reserved-prefix list'
);
leadwerk_mail_smtp_assert(
	is_string( $export_source ) && strpos( $export_source, 'Leadwerk_Migration_Mail_Smtp::write_export_sidecar' ) !== false && strpos( $export_source, "`option_name` NOT LIKE 'wp_mail_smtp%%'" ) !== false,
	'export must write a sidecar and omit WP Mail SMTP rows from SQL'
);
leadwerk_mail_smtp_assert(
	is_string( $export_file ) && strpos( $export_file, 'Leadwerk_Migration_Mail_Smtp::SNAPSHOT_NAME' ) !== false,
	'the backup archive must include the SMTP sidecar next to database.sql'
);
leadwerk_mail_smtp_assert(
	is_string( $import_file ) && strpos( $import_file, 'Leadwerk_Migration_Mail_Smtp::SNAPSHOT_NAME' ) !== false,
	'import must extract the SMTP sidecar with database.sql'
);
leadwerk_mail_smtp_assert(
	is_string( $helper_source ) && strpos( $helper_source, 'TARGET_SNAPSHOT_NAME' ) !== false && strpos( $helper_source, "'encoding'     => 'base64'" ) !== false,
	'SMTP snapshots must be stored as base64 so JSON cannot alter the ciphertext'
);
leadwerk_mail_smtp_assert(
	is_string( $loader_source ) && strpos( $loader_source, 'class-leadwerk_migration-mail-smtp.php' ) !== false,
	'the SMTP helper class must be loaded with the plugin'
);

fwrite( STDOUT, "Leadwerk WP Mail SMTP import smoke tests passed.\n" );

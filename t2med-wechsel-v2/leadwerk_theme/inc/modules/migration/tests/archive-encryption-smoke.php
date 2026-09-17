<?php
/**
 * Standalone archive encryption regression test.
 *
 * Run: php tests/archive-encryption-smoke.php
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
define( 'LEADWERK_MIGRATION_CIPHER_NAME', 'AES-256-CBC' );
define( 'LEADWERK_MIGRATION_MAX_CHUNK_SIZE', 5 * 1024 * 1024 );
define( 'LEADWERK_MIGRATION_ENCRYPTION_FORMAT', 'leadwerk-aes-256-gcm-pbkdf2-aad-v3' );
define( 'LEADWERK_MIGRATION_ENCRYPTION_FRAME_HEADER_SIZE', 54 );
define( 'LEADWERK_MIGRATION_SIGN_TEXT', 'leadwerk-smoke-signature' );
define( 'LEADWERK_MIGRATION_PACKAGE_NAME', 'package.json' );
define( 'LEADWERK_MIGRATION_MULTISITE_NAME', 'multisite.json' );
define( 'LEADWERK_MIGRATION_AUTH_MANIFEST_NAME', 'leadwerk-auth.json' );
define( 'LEADWERK_MIGRATION_BACKUPS_PATH', __DIR__ );
define( 'LEADWERK_MIGRATION_BACKUPS_NAME', 'backups' );
define( 'LEADWERK_MIGRATION_DATABASE_NAME', 'database.sql' );
define( 'LEADWERK_MIGRATION_W3TC_CONFIG_FILE', 'advanced-cache.php' );

function __( $message ) {
	return $message;
}

function esc_html__( $message ) {
	return $message;
}

function apply_filters( $hook, $value ) {
	return $value;
}

function do_action() {}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function path_is_absolute( $path ) {
	return is_string( $path ) && ( substr( $path, 0, 1 ) === '/' || preg_match( '/\A[A-Za-z]:[\\\\\/]/', $path ) );
}

function validate_file( $file ) {
	return strpos( str_replace( '\\', '/', (string) $file ), '../' ) !== false ? 1 : 0;
}

$root = dirname( __DIR__ );
require_once $root . '/exceptions.php';
require_once $root . '/functions.php';
require_once $root . '/lib/vendor/servmask/checksum/class-leadwerk_migration-crc.php';
require_once $root . '/lib/vendor/servmask/archiver/class-leadwerk_migration-archiver.php';
require_once $root . '/lib/vendor/servmask/archiver/class-leadwerk_migration-compressor.php';
require_once $root . '/lib/vendor/servmask/archiver/class-leadwerk_migration-extractor.php';

function lwm_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function lwm_remove_tree( $path ) {
	if ( ! is_dir( $path ) || strpos( basename( $path ), 'lwm-crypto-smoke-' ) !== 0 ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
	}
	@rmdir( $path );
}

function lwm_payload( $size ) {
	$seed = hash( 'sha256', 'Leadwerk archive smoke test', true );
	return substr( str_repeat( $seed, (int) ceil( max( 1, $size ) / strlen( $seed ) ) ), 0, $size );
}

function lwm_seal_archive( $archive_path, $password, $archive_id, $temp_dir ) {
	$manifest = leadwerk_migration_create_encrypted_archive_manifest( $archive_path, $password, $archive_id );
	$temp     = $temp_dir . '/manifest-' . bin2hex( random_bytes( 4 ) );
	file_put_contents( $temp, $manifest );
	$archive = new Leadwerk_Migration_Compressor( $archive_path );
	$read = $offset = $written = 0;
	$crc  = null;
	lwm_assert( $archive->add_file( $temp, LEADWERK_MIGRATION_AUTH_MANIFEST_NAME, $read, $offset, $written, $crc ), 'Manifest append did not complete.' );
	$archive->close( true );
	@unlink( $temp );
}

function lwm_create_gcm_archive( $source, $archive_path, $compression, $password, $archive_id, $temp_dir ) {
	$archive = new Leadwerk_Migration_Compressor( $archive_path, $password, $compression, $archive_id );
	$read = $offset = $written = 0;
	$crc  = null;
	lwm_assert( $archive->add_file( $source, 'payload.bin', $read, $offset, $written, $crc ), 'GCM archive write did not complete.' );
	$archive->close();
	lwm_seal_archive( $archive_path, $password, $archive_id, $temp_dir );
}

function lwm_legacy_encrypt( $plaintext, $password ) {
	$iv_length = openssl_cipher_iv_length( LEADWERK_MIGRATION_CIPHER_NAME );
	$key       = substr( sha1( $password, true ), 0, $iv_length );
	$iv        = random_bytes( $iv_length );
	$cipher    = openssl_encrypt( $plaintext, LEADWERK_MIGRATION_CIPHER_NAME, $key, OPENSSL_RAW_DATA, $iv );
	lwm_assert( is_string( $cipher ), 'Legacy fixture encryption failed.' );
	return $iv . $cipher;
}

function lwm_create_legacy_archive( $plaintext, $archive_path, $compression, $password ) {
	$content = '';
	$offset  = 0;
	$length  = strlen( $plaintext );
	while ( $offset < $length ) {
		$chunk = substr( $plaintext, $offset, Leadwerk_Migration_Archiver::READ_CHUNK_SIZE );
		$offset += strlen( $chunk );
		if ( $compression === 'gzip' ) {
			$chunk = gzcompress( $chunk, 9 );
		}
		$chunk = lwm_legacy_encrypt( $chunk, $password );
		$content .= $compression === 'gzip' ? pack( 'N', strlen( $chunk ) ) . $chunk : $chunk;
	}
	$header = pack( 'a255a14a12a4088a8', 'payload.bin', strlen( $content ), time(), '.', hash( 'crc32b', $plaintext ) );
	file_put_contents( $archive_path, $header . $content . str_repeat( "\0", Leadwerk_Migration_Archiver::HEADER_SIZE ) );
}

function lwm_extract( $archive_path, $destination, $password, $compression, $format = null, $archive_id = null ) {
	@mkdir( $destination, 0700, true );
	$archive = new Leadwerk_Migration_Extractor( $archive_path, $password, $compression, $format, $archive_id );
	$read = $offset = $written = 0;
	$result = $archive->extract_by_files_array( $destination, array( 'payload.bin' ), array(), array(), $read, $offset, $written );
	$archive->close();
	lwm_assert( $result, 'Archive extraction did not complete.' );
	return file_get_contents( $destination . '/payload.bin' );
}

$temp_dir = sys_get_temp_dir() . '/lwm-crypto-smoke-' . bin2hex( random_bytes( 8 ) );
mkdir( $temp_dir, 0700, true );

try {
	lwm_assert( leadwerk_migration_can_encrypt() && leadwerk_migration_can_decrypt(), 'Required OpenSSL primitives are unavailable.' );
	$password   = 'correct horse battery staple';
	$archive_id = bin2hex( random_bytes( 16 ) );
	$sizes      = array( 0, 1, 511952, 511953, 512000, 512001, 1024007 );

	foreach ( $sizes as $size ) {
		$plaintext = lwm_payload( $size );
		$source    = $temp_dir . '/source-' . $size;
		file_put_contents( $source, $plaintext );

		foreach ( array( null, 'gzip' ) as $compression ) {
			$label       = $compression ?: 'plain';
			$gcm_archive = $temp_dir . '/gcm-' . $label . '-' . $size . '.wpress';
			$gcm_output  = $temp_dir . '/out-gcm-' . $label . '-' . $size;
			lwm_create_gcm_archive( $source, $gcm_archive, $compression, $password, $archive_id, $temp_dir );
			$package = array( 'EncryptionFormat' => LEADWERK_MIGRATION_ENCRYPTION_FORMAT, 'EncryptionArchiveId' => $archive_id );
			lwm_assert( leadwerk_migration_verify_encrypted_archive_manifest( $gcm_archive, $package, $password ), 'Whole-archive manifest verification failed.' );
			lwm_assert( hash_equals( hash( 'sha256', $plaintext ), hash( 'sha256', lwm_extract( $gcm_archive, $gcm_output, $password, $compression, LEADWERK_MIGRATION_ENCRYPTION_FORMAT, $archive_id ) ) ), 'GCM round-trip mismatch at size ' . $size . ' (' . $label . ').' );

			$legacy_archive = $temp_dir . '/legacy-' . $label . '-' . $size . '.wpress';
			$legacy_output  = $temp_dir . '/out-legacy-' . $label . '-' . $size;
			lwm_create_legacy_archive( $plaintext, $legacy_archive, $compression, $password );
			lwm_assert( hash_equals( hash( 'sha256', $plaintext ), hash( 'sha256', lwm_extract( $legacy_archive, $legacy_output, $password, $compression ) ) ), 'Legacy CBC round-trip mismatch at size ' . $size . ' (' . $label . ').' );
		}
	}

	$signature = base64_encode( leadwerk_migration_encrypt_string( LEADWERK_MIGRATION_SIGN_TEXT . "\0" . $archive_id, $password, 'signature|' . $archive_id ) );
	lwm_assert( leadwerk_migration_is_decryption_password_valid( $signature, $password, LEADWERK_MIGRATION_ENCRYPTION_FORMAT, $archive_id ), 'V3 signature validation failed.' );
	lwm_assert( ! leadwerk_migration_is_decryption_password_valid( $signature, $password, LEADWERK_MIGRATION_ENCRYPTION_FORMAT, str_repeat( '0', 32 ) ), 'Archive-ID substitution was accepted.' );

	$source  = $temp_dir . '/tamper-source';
	$archive = $temp_dir . '/tamper.wpress';
	file_put_contents( $source, lwm_payload( 700000 ) );
	lwm_create_gcm_archive( $source, $archive, null, $password, $archive_id, $temp_dir );
	$handle = fopen( $archive, 'c+b' );
	fseek( $handle, Leadwerk_Migration_Archiver::HEADER_SIZE + LEADWERK_MIGRATION_ENCRYPTION_FRAME_HEADER_SIZE + 32 );
	$byte = fread( $handle, 1 );
	fseek( $handle, -1, SEEK_CUR );
	fwrite( $handle, chr( ord( $byte ) ^ 1 ) );
	fclose( $handle );
	$failed = false;
	try {
		lwm_extract( $archive, $temp_dir . '/out-tamper', $password, null, LEADWERK_MIGRATION_ENCRYPTION_FORMAT, $archive_id );
	} catch ( Leadwerk_Migration_Not_Decryptable_Exception $error ) {
		$failed = true;
	}
	lwm_assert( $failed, 'A modified GCM tag was accepted.' );

	$header_archive = $temp_dir . '/header-tamper.wpress';
	lwm_create_gcm_archive( $source, $header_archive, null, $password, $archive_id, $temp_dir );
	$handle = fopen( $header_archive, 'c+b' );
	fwrite( $handle, str_pad( 'relocated.php', 255, "\0" ) );
	fclose( $handle );
	$failed = false;
	try {
		leadwerk_migration_verify_encrypted_archive_manifest( $header_archive, array( 'EncryptionFormat' => LEADWERK_MIGRATION_ENCRYPTION_FORMAT, 'EncryptionArchiveId' => $archive_id ), $password );
	} catch ( Leadwerk_Migration_Not_Decryptable_Exception $error ) {
		$failed = true;
	}
	lwm_assert( $failed, 'A modified archive path passed whole-archive authentication.' );

	echo "Leadwerk archive encryption smoke test passed (" . count( $sizes ) . " boundary sizes, GCM/CBC, compressed/uncompressed, tamper checks).\n";
} finally {
	lwm_remove_tree( $temp_dir );
}

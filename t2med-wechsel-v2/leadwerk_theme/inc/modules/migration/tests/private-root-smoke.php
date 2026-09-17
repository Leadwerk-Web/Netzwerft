<?php
/**
 * Standalone smoke test for Leadwerk private-root web/CLI consistency.
 * Run with: php tests/private-root-smoke.php
 */

define( 'ABSPATH', '/srv/www/site/' );
define( 'LEADWERK_MIGRATION_PRIVATE_ROOT_OPTION', 'leadwerk_migration_private_root_resolved' );
$_SERVER['DOCUMENT_ROOT'] = '/srv/www';
$GLOBALS['leadwerk_private_root_options'] = array();
$GLOBALS['leadwerk_private_root_updates'] = array();

function get_option( $name, $default = false ) {
	if ( array_key_exists( $name, $GLOBALS['leadwerk_private_root_options'] ) ) {
		return $GLOBALS['leadwerk_private_root_options'][ $name ];
	}
	return $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['leadwerk_private_root_options'][ $name ] = $value;
	$GLOBALS['leadwerk_private_root_updates'][]        = array(
		'name'  => $name,
		'value' => $value,
	);
	return true;
}

function leadwerk_private_root_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

/**
 * Mirror of the constants.php selection policy for isolated assertions.
 *
 * @param string|false $document_root Live DOCUMENT_ROOT realpath or false.
 * @param string       $abspath       WordPress ABSPATH.
 * @param string|false $sibling_real  realpath of the WordPress parent.
 * @param string|false $temp_real     realpath of sys_get_temp_dir().
 * @param mixed        $saved_value   Option payload.
 * @return array{path:string,validated_document_root:string,persist:bool}
 */
function leadwerk_resolve_private_root_for_test( $document_root, $abspath, $sibling_real, $temp_real, $saved_value ) {
	$saved_path          = '';
	$saved_document_root = false;
	if ( is_array( $saved_value ) && isset( $saved_value['version'], $saved_value['path'] ) && (int) $saved_value['version'] === 1 ) {
		$saved_path = is_string( $saved_value['path'] ) ? $saved_value['path'] : '';
		if ( isset( $saved_value['validated_document_root'] ) && is_string( $saved_value['validated_document_root'] ) && $saved_value['validated_document_root'] !== '' ) {
			$saved_document_root = $saved_value['validated_document_root'];
		}
	} elseif ( is_string( $saved_value ) ) {
		$saved_path = $saved_value;
	}

	$saved_real              = $saved_path !== '' ? $saved_path : false;
	$effective_document_root = $document_root !== false ? $document_root : $saved_document_root;

	$is_outside_web = function ( $candidate ) use ( $effective_document_root, $abspath ) {
		if ( $candidate === false ) {
			return false;
		}
		$candidate = rtrim( str_replace( '\\', '/', $candidate ), '/' ) . '/';
		$roots     = array( rtrim( str_replace( '\\', '/', $abspath ), '/' ) . '/' );
		if ( $effective_document_root !== false ) {
			$roots[] = rtrim( str_replace( '\\', '/', $effective_document_root ), '/' ) . '/';
		} else {
			$roots[] = rtrim( str_replace( '\\', '/', dirname( rtrim( $abspath, "/\\" ) ) ), '/' ) . '/';
		}
		foreach ( $roots as $root ) {
			if ( strpos( $candidate, $root ) === 0 ) {
				return false;
			}
		}
		return true;
	};

	$is_temp_path = function ( $candidate ) use ( $temp_real ) {
		return $candidate !== false && $temp_real !== false && rtrim( $candidate, '/' ) === rtrim( $temp_real, '/' );
	};

	$persist = true;
	if ( $document_root !== false && $sibling_real !== false && $is_outside_web( $sibling_real ) ) {
		$private_root            = $sibling_real;
		$validated_document_root = $document_root;
	} elseif (
		$saved_real !== false &&
		$is_outside_web( $saved_real ) &&
		! (
			$is_temp_path( $saved_real ) &&
			$document_root !== false &&
			$sibling_real !== false &&
			$is_outside_web( $sibling_real )
		)
	) {
		$private_root            = $saved_real;
		$validated_document_root = $effective_document_root;
	} else {
		$private_root            = $temp_real !== false ? $temp_real : dirname( rtrim( $abspath, "/\\" ) );
		$validated_document_root = $document_root;
		if ( $document_root === false ) {
			$persist = false;
		}
	}

	return array(
		'path'                    => rtrim( $private_root, "/\\" ),
		'validated_document_root' => $validated_document_root !== false ? rtrim( $validated_document_root, "/\\" ) : '',
		'persist'                 => $persist,
	);
}

$sibling = '/var/www';
$temp    = '/tmp';
$web     = leadwerk_resolve_private_root_for_test( '/srv/www', '/srv/www/site/', $sibling, $temp, '' );
leadwerk_private_root_assert( $web['path'] === $sibling, 'web request should prefer the durable sibling root' );
leadwerk_private_root_assert( $web['validated_document_root'] === '/srv/www', 'web request should persist the validating document root' );
leadwerk_private_root_assert( $web['persist'] === true, 'web-validated sibling should be persisted' );

$cli_after_web = leadwerk_resolve_private_root_for_test(
	false,
	'/srv/www/site/',
	$sibling,
	$temp,
	array(
		'version'                 => 1,
		'path'                    => $sibling,
		'validated_document_root' => '/srv/www',
	)
);
leadwerk_private_root_assert( $cli_after_web['path'] === $sibling, 'CLI should reuse the web-validated sibling root' );
leadwerk_private_root_assert( $cli_after_web['persist'] === true, 'CLI reuse of a durable root may refresh the option' );

$cli_first = leadwerk_resolve_private_root_for_test( false, '/srv/www/site/', $sibling, $temp, '' );
leadwerk_private_root_assert( $cli_first['path'] === $temp, 'CLI without evidence may use the temporary directory for the request' );
leadwerk_private_root_assert( $cli_first['persist'] === false, 'CLI must not permanently demote the private root to temp' );

$web_upgrade = leadwerk_resolve_private_root_for_test(
	'/srv/www',
	'/srv/www/site/',
	$sibling,
	$temp,
	array(
		'version'                 => 1,
		'path'                    => $temp,
		'validated_document_root' => '',
	)
);
leadwerk_private_root_assert( $web_upgrade['path'] === $sibling, 'later web request must upgrade a temp fallback to the sibling root' );
leadwerk_private_root_assert( $web_upgrade['persist'] === true, 'upgraded sibling root must be persisted' );

fwrite( STDOUT, "Leadwerk private-root smoke test passed.\n" );

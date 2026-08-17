<?php
declare(strict_types=1);

$package = dirname( __DIR__ );
$root    = dirname( $package );
$errors  = array();

$assert = static function ( bool $condition, string $message ) use ( &$errors ): void {
	if ( ! $condition ) {
		$errors[] = $message;
	}
};

$manifest_file = $package . '/leadwerk_importer/manifest/import-manifest.json';
$manifest      = json_decode( (string) file_get_contents( $manifest_file ), true );
$assert( is_array( $manifest ) && JSON_ERROR_NONE === json_last_error(), 'Manifest JSON is invalid.' );

if ( is_array( $manifest ) ) {
	$source = $package . '/leadwerk_importer/' . $manifest['source']['path'];
	$assert( is_file( $source ), 'Source snapshot is missing.' );
	$assert( is_file( $source ) && hash_equals( $manifest['source']['sha256'], hash_file( 'sha256', $source ) ), 'Source checksum mismatch.' );
	foreach ( $manifest['media'] ?? array() as $item ) {
		$file = $package . '/leadwerk_importer/' . $item['path'];
		$assert( is_file( $file ), 'Missing media: ' . $item['path'] );
		$assert( is_file( $file ) && hash_equals( $item['sha256'], hash_file( 'sha256', $file ) ), 'Media checksum mismatch: ' . $item['path'] );
	}
	$expected = array( 'nw-t2med-home-v2', 'nw-danke-v1', 'nw-impressum-v1', 'nw-datenschutz-v1', 'nw-404-v1' );
	$assert( $expected === array_column( $manifest['pages'] ?? array(), 'source_key' ), 'Unexpected source keys or page order.' );
	$assert( 'de' === ( $manifest['language'] ?? '' ), 'Initial manifest language must be DE.' );
}

$forbidden = array( 'acm_news', 'debug-5279b9', '127.0.0.1:7345', 'source_shells', 'fonts.googleapis.com', 'fonts.gstatic.com' );
$iterator  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $package, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	if ( ! $file->isFile() || ! preg_match( '/\\.(?:php|js|css|json|html)$/i', $file->getFilename() ) ) {
		continue;
	}
	if ( str_contains( $file->getPathname(), DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR ) ) {
		continue;
	}
	$content = (string) file_get_contents( $file->getPathname() );
	foreach ( $forbidden as $needle ) {
		$assert( false === stripos( $content, $needle ), $needle . ' remains in ' . $file->getPathname() );
	}
}

$html_files = array(
	$root . '/t2med-wechsel-v2.html',
	$root . '/danke.html',
	$root . '/404.html',
	$root . '/impressum.html',
	$root . '/datenschutz.html',
);

foreach ( $html_files as $html_file ) {
	$assert( is_file( $html_file ), 'Missing static preview: ' . basename( $html_file ) );
	if ( ! is_file( $html_file ) ) {
		continue;
	}
	$html = (string) file_get_contents( $html_file );
	$assert( ! preg_match( "~href=[\"'][^\"']*\\.html(?:[#?][^\"']*)?[\"']~i", $html ), 'Internal .html link in ' . basename( $html_file ) );
	$assert( ! preg_match( "~href=[\"']#[\"']~i", $html ), 'Empty fragment link in ' . basename( $html_file ) );
	$assert( false === stripos( $html, 'fonts.googleapis.com' ), 'External Google Font in ' . basename( $html_file ) );

	$dom      = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	$loaded   = $dom->loadHTML( $html, LIBXML_NONET );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	$assert( $loaded, 'HTML parse failed: ' . basename( $html_file ) );
	if ( $loaded ) {
		$ids = array();
		foreach ( ( new DOMXPath( $dom ) )->query( '//*[@id]' ) as $node ) {
			$id = $node->getAttribute( 'id' );
			$assert( ! isset( $ids[ $id ] ), 'Duplicate ID ' . $id . ' in ' . basename( $html_file ) );
			$ids[ $id ] = true;
		}
	}
}

$template = (string) file_get_contents( $package . '/leadwerk_theme/template-parts/page-t2med.php' );
foreach ( array( 'hero', 'warum-wechseln', 'leistungen', 'ablauf', 'vorpruefung', 'faq', 'kontakt' ) as $anchor ) {
	$assert( false !== strpos( $template, 'id="' . $anchor . '"' ), 'Theme anchor missing: ' . $anchor );
}
$assert( 1 === substr_count( (string) file_get_contents( $package . '/leadwerk_theme/footer.php' ), "get_template_part( 'template-parts/form-modal' )" ), 'WPForms modal must be rendered once.' );
$assert( false === strpos( $template, '.html' ), 'Theme template contains an .html reference.' );

if ( $errors ) {
	fwrite( STDERR, implode( PHP_EOL, array_map( static fn( string $error ): string => 'FAIL: ' . $error, $errors ) ) . PHP_EOL );
	exit( 1 );
}

echo "Leadwerk T2med package validation passed.\n";

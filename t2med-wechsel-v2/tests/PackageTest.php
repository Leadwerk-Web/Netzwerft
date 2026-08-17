<?php

use PHPUnit\Framework\TestCase;

final class PackageTest extends TestCase {
	private $package;
	private $manifest;

	protected function setUp(): void {
		$this->package  = dirname( __DIR__ );
		$this->manifest = json_decode(
			file_get_contents( $this->package . '/leadwerk_importer/manifest/import-manifest.json' ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
	}

	public function test_manifest_contains_only_expected_pages(): void {
		$expected = array(
			'nw-t2med-home-v2',
			'nw-danke-v1',
			'nw-impressum-v1',
			'nw-datenschutz-v1',
			'nw-404-v1',
		);
		$this->assertSame( $expected, array_column( $this->manifest['pages'], 'source_key' ) );
		$this->assertSame( 'de', $this->manifest['language'] );
	}

	public function test_manifest_checksums_match(): void {
		$source = $this->package . '/leadwerk_importer/' . $this->manifest['source']['path'];
		$this->assertSame( $this->manifest['source']['sha256'], hash_file( 'sha256', $source ) );
		foreach ( $this->manifest['media'] as $item ) {
			$this->assertSame(
				$item['sha256'],
				hash_file( 'sha256', $this->package . '/leadwerk_importer/' . $item['path'] ),
				$item['path']
			);
		}
	}

	public function test_legacy_acm_assets_are_absent(): void {
		$this->assertDirectoryDoesNotExist( $this->package . '/leadwerk_importer/source_assets/news' );
		$this->assertDirectoryDoesNotExist( $this->package . '/leadwerk_theme/source_shells' );
		$this->assertFileDoesNotExist( $this->package . '/leadwerk-wpml-clone/debug-5279b9.log' );
	}
}


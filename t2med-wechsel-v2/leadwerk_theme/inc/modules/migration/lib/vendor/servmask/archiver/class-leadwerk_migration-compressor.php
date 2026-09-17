<?php
/**
 * Copyright (C) 2014-2025 ServMask Inc.
 * Modifications Copyright (C) 2026 Leadwerk.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Upstream attribution: This file derives from the All-in-One WP Migration plugin, developed by
 *
 * ███████╗███████╗██████╗ ██╗   ██╗███╗   ███╗ █████╗ ███████╗██╗  ██╗
 * ██╔════╝██╔════╝██╔══██╗██║   ██║████╗ ████║██╔══██╗██╔════╝██║ ██╔╝
 * ███████╗█████╗  ██████╔╝██║   ██║██╔████╔██║███████║███████╗█████╔╝
 * ╚════██║██╔══╝  ██╔══██╗╚██╗ ██╔╝██║╚██╔╝██║██╔══██║╚════██║██╔═██╗
 * ███████║███████╗██║  ██║ ╚████╔╝ ██║ ╚═╝ ██║██║  ██║███████║██║  ██╗
 * ╚══════╝╚══════╝╚═╝  ╚═╝  ╚═══╝  ╚═╝     ╚═╝╚═╝  ╚═╝╚══════╝╚═╝  ╚═╝
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

class Leadwerk_Migration_Compressor extends Leadwerk_Migration_Archiver {

	/** @type string|null Random per-export ID used in authenticated chunk AAD. */
	protected $file_encryption_archive_id = null;

	/**
	 * Overloaded constructor that opens the passed file for writing
	 *
	 * @param string $file_name        File to use as archive
	 * @param string $file_password    File password string
	 * @param string $file_compression         File compression type
	 * @param string $file_encryption_archive_id Random per-export encryption ID
	 */
	public function __construct( $file_name, $file_password = null, $file_compression = null, $file_encryption_archive_id = null ) {
		if ( ! empty( $file_password ) ) {
			if ( ! is_string( $file_encryption_archive_id ) || ! preg_match( '/\A[a-f0-9]{32}\z/D', $file_encryption_archive_id ) ) {
				throw new Leadwerk_Migration_Not_Encryptable_Exception( esc_html__( 'Authenticated archive encryption requires a valid per-export identifier.', 'leadwerk-migration' ) );
			}
			$this->file_encryption_archive_id = $file_encryption_archive_id;
		}

		// Call parent, to initialize variables
		parent::__construct( $file_name, $file_password, $file_compression, true );
	}

	/**
	 * Add a file to the archive
	 *
	 * @param string      $file_name          File to add to the archive
	 * @param string      $new_file_name      Write the file with a different name
	 * @param int         $file_bytes_read    Amount of the bytes we read
	 * @param int         $file_bytes_offset  File bytes offset
	 * @param int         $file_bytes_written Amount of the bytes we wrote
	 * @param string|null $file_crc           File CRC32 checksum (passed by reference, optional)
	 *
	 * @throws \Leadwerk_Migration_Not_Seekable_Exception
	 * @throws \Leadwerk_Migration_Not_Writable_Exception
	 * @throws \Leadwerk_Migration_Quota_Exceeded_Exception
	 *
	 * @return bool
	 */
	public function add_file( $file_name, $new_file_name = '', &$file_bytes_read = 0, &$file_bytes_offset = 0, &$file_bytes_written = 0, &$file_crc = null ) {
		// Replace forward slash with current directory separator in file name
		$file_name = leadwerk_migration_replace_forward_slash_with_directory_separator( $file_name );

		// Escape Windows directory separator in file name
		$file_name = leadwerk_migration_escape_windows_directory_separator( $file_name );

		// Flag to hold if file data has been processed
		$completed = true;

		// Start time
		$start = microtime( true );
		$archive_file_name = ! empty( $new_file_name ) ? $new_file_name : $file_name;
		$archive_file_name = leadwerk_migration_replace_directory_separator_with_forward_slash( $archive_file_name );
		$source_total_size = @filesize( $file_name );
		if ( $source_total_size === false ) {
			throw new Leadwerk_Migration_Not_Readable_Exception( sprintf( __( 'Could not read file size. File: %s', 'leadwerk-migration' ), $file_name ) );
		}

		// Open the file for reading in binary mode (fopen may return null for quarantined files)
		if ( ( $file_handle = @fopen( $file_name, 'rb' ) ) ) {

			// Start native hash for current chunk
			$hash_ctx = Leadwerk_Migration_Crc::init_crc32();

			// Get header block with empty CRC placeholder
			if ( ( $block = $this->get_file_block( $file_name, $new_file_name, '' ) ) ) {

				// Write header block
				if ( $file_bytes_offset === 0 ) {
					if ( ( $file_bytes = @fwrite( $this->file_handle, $block ) ) !== false ) {
						if ( strlen( $block ) !== $file_bytes ) {
							throw new Leadwerk_Migration_Quota_Exceeded_Exception( sprintf( __( 'Out of disk space. Could not write header to file. File: %s', 'leadwerk-migration' ), $this->file_name ) );
						}
					} else {
						throw new Leadwerk_Migration_Not_Writable_Exception( sprintf( __( 'Could not write header to file. File: %s', 'leadwerk-migration' ), $this->file_name ) );
					}
				}

				// Set file offset
				if ( @fseek( $file_handle, $file_bytes_offset, SEEK_SET ) !== -1 ) {
					$file_bytes_read = 0;

					// Cache config file check outside the loop
					$should_process_file = ! in_array( $new_file_name, leadwerk_migration_config_filters() );

					// Read the file in 512KB chunks
					while ( false === @feof( $file_handle ) ) {
						if ( ( $file_content = @fread( $file_handle, static::READ_CHUNK_SIZE ) ) !== false ) {

							// Empty read indicates EOF
							if ( strlen( $file_content ) === 0 ) {
								break;
							}

							$plain_offset = $file_bytes_offset + $file_bytes_read;
							$plain_length = strlen( $file_content );

							// Add the amount of bytes we read
							$file_bytes_read += $plain_length;

							// Update CRC with original content (BEFORE compression/encryption)
							Leadwerk_Migration_Crc::update_crc32( $hash_ctx, $file_content );

							// Do not encrypt or compress config files
							if ( $should_process_file === true ) {

								// Add chunk data compression
								if ( ! empty( $this->file_compression ) ) {
									switch ( $this->file_compression ) {
										case 'gzip':
											$file_content = gzcompress( $file_content, 9 );
											break;

										case 'bzip2':
											$file_content = bzcompress( $file_content, 9 );
											break;
									}
								}

								// Add chunk data encryption
								if ( ! empty( $this->file_password ) ) {
									$aad = leadwerk_migration_encryption_chunk_aad(
										$this->file_encryption_archive_id,
										$archive_file_name,
										$source_total_size,
										$plain_offset,
										$plain_length
									);
									$file_content = leadwerk_migration_encrypt_string( $file_content, $this->file_password, $aad );
								}

								// Authenticated chunks carry bounded metadata that is also covered
								// by AAD. Legacy unencrypted compression retains its 4-byte length.
								if ( ! empty( $this->file_password ) ) {
									$file_content = leadwerk_migration_encryption_frame_header(
										strlen( $file_content ),
										$source_total_size,
										$plain_offset,
										$plain_length
									) . $file_content;
								} elseif ( ! empty( $this->file_compression ) ) {
									$file_content = pack( 'N', strlen( $file_content ) ) . $file_content;
								}
							}

							if ( ( $file_bytes = @fwrite( $this->file_handle, $file_content ) ) !== false ) {
								if ( strlen( $file_content ) !== $file_bytes ) {
									throw new Leadwerk_Migration_Quota_Exceeded_Exception( sprintf( __( 'Out of disk space. Could not write content to file. File: %s', 'leadwerk-migration' ), $this->file_name ) );
								}
							} else {
								throw new Leadwerk_Migration_Not_Writable_Exception( sprintf( __( 'Could not write content to file. File: %s', 'leadwerk-migration' ), $this->file_name ) );
							}

							// Add the amount of bytes we wrote
							$file_bytes_written += $file_bytes;
						}

						// Time elapsed
						if ( ( $timeout = apply_filters( 'leadwerk_migration_completed_timeout', 10 ) ) ) {
							if ( ( microtime( true ) - $start ) > $timeout ) {
								$completed = false;
								break;
							}
						}
					}

					// Empty encrypted files still need an authenticated frame so their
					// archive path and zero length cannot be rewritten undetected.
					if ( $source_total_size === 0 && $should_process_file === true && ! empty( $this->file_password ) && $file_bytes_written === 0 ) {
						$aad = leadwerk_migration_encryption_chunk_aad( $this->file_encryption_archive_id, $archive_file_name, 0, 0, 0 );
						$file_content = '';
						if ( $this->file_compression === 'gzip' ) {
							$file_content = gzcompress( $file_content, 9 );
						} elseif ( $this->file_compression === 'bzip2' ) {
							$file_content = bzcompress( $file_content, 9 );
						}
						$file_content = leadwerk_migration_encrypt_string( $file_content, $this->file_password, $aad );
						$file_content = leadwerk_migration_encryption_frame_header( strlen( $file_content ), 0, 0, 0 ) . $file_content;
						$file_bytes = @fwrite( $this->file_handle, $file_content );
						if ( $file_bytes === false || $file_bytes !== strlen( $file_content ) ) {
							throw new Leadwerk_Migration_Not_Writable_Exception( sprintf( __( 'Could not write authenticated empty content. File: %s', 'leadwerk-migration' ), $this->file_name ) );
						}
						$file_bytes_written += $file_bytes;
					}

					// Add the amount of bytes we read
					$file_bytes_offset += $file_bytes_read;
				}

				// Combine and finalize CRC
				if ( empty( $file_crc ) ) {
					$file_crc = Leadwerk_Migration_Crc::finalize_crc32( $hash_ctx );
				} else {
					$file_crc = Leadwerk_Migration_Crc::combine_crc32( $file_crc, Leadwerk_Migration_Crc::finalize_crc32( $hash_ctx ), $file_bytes_read );
				}

				// Write file size to file header
				if ( ( $file_size_block = $this->get_file_size_block( $file_bytes_written ) ) ) {

					// Seek to beginning of file size (back over: content + crc32(8) + path(4088) + mtime(12) + size(14))
					if ( @fseek( $this->file_handle, - $file_bytes_written - 8 - 4088 - 12 - 14, SEEK_CUR ) === -1 ) {
						throw new Leadwerk_Migration_Not_Seekable_Exception( __( 'Your PHP is 32-bit. In order to export your file, please change your PHP version to 64-bit and try again. Review the Leadwerk Migration health report for technical details.', 'leadwerk-migration' ) );
					}

					// Write file size to file header
					if ( ( $file_bytes = @fwrite( $this->file_handle, $file_size_block ) ) !== false ) {
						if ( strlen( $file_size_block ) !== $file_bytes ) {
							throw new Leadwerk_Migration_Quota_Exceeded_Exception( sprintf( __( 'Out of disk space. Could not write size to file. File: %s', 'leadwerk-migration' ), $this->file_name ) );
						}
					} else {
						throw new Leadwerk_Migration_Not_Writable_Exception( sprintf( __( 'Could not write size to file. File: %s', 'leadwerk-migration' ), $this->file_name ) );
					}

					// Seek to beginning of file CRC (forward over: mtime(12) + path(4088))
					if ( @fseek( $this->file_handle, + 12 + 4088, SEEK_CUR ) === -1 ) {
						throw new Leadwerk_Migration_Not_Seekable_Exception( __( 'Your PHP is 32-bit. In order to export your file, please change your PHP version to 64-bit and try again. Review the Leadwerk Migration health report for technical details.', 'leadwerk-migration' ) );
					}

					// Write file CRC to file header
					if ( ( $file_crc_block = $this->get_file_crc_block( $file_crc ) ) ) {
						if ( ( $file_bytes = @fwrite( $this->file_handle, $file_crc_block ) ) !== false ) {
							if ( strlen( $file_crc_block ) !== $file_bytes ) {
								throw new Leadwerk_Migration_Quota_Exceeded_Exception( sprintf( __( 'Out of disk space. Could not write CRC to file. File: %s', 'leadwerk-migration' ), $this->file_name ) );
							}
						}
					}

					// Seek to end of file content (forward over: content)
					if ( @fseek( $this->file_handle, + $file_bytes_written, SEEK_CUR ) === -1 ) {
						throw new Leadwerk_Migration_Not_Seekable_Exception( __( 'Your PHP is 32-bit. In order to export your file, please change your PHP version to 64-bit and try again. Review the Leadwerk Migration health report for technical details.', 'leadwerk-migration' ) );
					}
				}
			}

			// Close the handle
			@fclose( $file_handle );
		}

		return $completed;
	}

	/**
	 * Generate binary block header for a file
	 *
	 * @param string      $file_name     Filename to generate block header for
	 * @param string      $new_file_name Write the file with a different name
	 * @param string|null $crc32         CRC32 checksum (optional)
	 *
	 * @return string
	 */
	private function get_file_block( $file_name, $new_file_name = '', $crc32 = null ) {
		$block = '';

		// Get stats about the file
		if ( ( $stat = @stat( $file_name ) ) !== false ) {

			// Filename of the file we are accessing
			if ( empty( $new_file_name ) ) {
				$name = leadwerk_migration_basename( $file_name );
			} else {
				$name = leadwerk_migration_basename( $new_file_name );
			}

			// Size in bytes of the file
			$size = $stat['size'];

			// Last time the file was modified
			$date = $stat['mtime'];

			// Replace current directory separator with backward slash in file path
			if ( empty( $new_file_name ) ) {
				$path = leadwerk_migration_replace_directory_separator_with_forward_slash( leadwerk_migration_dirname( $file_name ) );
			} else {
				$path = leadwerk_migration_replace_directory_separator_with_forward_slash( leadwerk_migration_dirname( $new_file_name ) );
			}

			// Only calculate CRC if not provided
			if ( empty( $crc32 ) ) {
				$crc32 = Leadwerk_Migration_Crc::calculate_file_crc32( $file_name );
			}

			// Concatenate block format parts
			$format = implode( '', $this->block_format );

			// Pack file data into binary string
			$block = pack( $format, $name, $size, $date, $path, $crc32 );
		}

		return $block;
	}

	/**
	 * Generate file size binary block header for a file
	 *
	 * @param int $file_size File size
	 *
	 * @return string
	 */
	public function get_file_size_block( $file_size ) {
		$block = '';

		// Pack file data into binary string
		if ( isset( $this->block_format[1] ) ) {
			$block = pack( $this->block_format[1], $file_size );
		}

		return $block;
	}

	/**
	 * Generate file CRC binary block header for a file
	 *
	 * @param int $file_crc File CRC
	 *
	 * @return string
	 */
	public function get_file_crc_block( $file_crc ) {
		$block = '';

		// Pack file data into binary string
		if ( isset( $this->block_format[4] ) ) {
			$block = pack( $this->block_format[4], $file_crc );
		}

		return $block;
	}
}

<?php

declare(strict_types=1);

namespace DDMaintenance\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-restore.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-file-security.php';

final class FileSecurityTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/dd-file-security-phpunit-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root, 0755, true );
	}

	protected function tearDown(): void {
		$this->removeTree( $this->root );
	}

	public function testTraversalAndAbsolutePathsAreRejected(): void {
		$this->assertInstanceOf( \WP_Error::class, \DD_Maintenance_File_Security::normalize_relative_path( '../escape.txt' ) );
		$this->assertInstanceOf( \WP_Error::class, \DD_Maintenance_File_Security::normalize_relative_path( '/absolute.txt' ) );
		$this->assertSame( 'safe/file.txt', \DD_Maintenance_File_Security::normalize_relative_path( 'safe\\file.txt' ) );
	}

	public function testExistingFileTargetRemainsInsideAuthorizedRoot(): void {
		mkdir( $this->root . '/nested', 0755, true );
		file_put_contents( $this->root . '/nested/existing.txt', 'old' );

		$target = \DD_Maintenance_File_Security::safe_child_path( $this->root, 'nested/existing.txt' );

		$this->assertSame( $this->root . '/nested/existing.txt', $target );
		$this->assertFileExists( $target );
	}

	public function testRestoreCopyReplacesExistingTargetWithoutUsingUninitializedPath(): void {
		$source_dir = $this->root . '/source';
		$dest_dir   = $this->root . '/destination';
		mkdir( $source_dir, 0755, true );
		mkdir( $dest_dir, 0755, true );
		file_put_contents( $source_dir . '/existing.txt', 'new content' );
		file_put_contents( $dest_dir . '/existing.txt', 'old content' );

		$files  = new \DD_Maintenance_Restore_Files_Service();
		$result = $files->copy_directory( $source_dir, $dest_dir );

		$this->assertSame( 1, $result );
		$this->assertSame( 'new content', file_get_contents( $dest_dir . '/existing.txt' ) );
	}

	public function testSymlinkedParentIsRejected(): void {
		$outside = $this->root . '-outside';
		mkdir( $outside, 0755, true );
		symlink( $outside, $this->root . '/linked' );

		$result = \DD_Maintenance_File_Security::safe_child_path( $this->root, 'linked/escape.txt', true );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'dd_path_parent_unsafe', $result->get_error_code() );
		$this->removeTree( $outside );
	}
	public function testSymlinkedRootAndDestinationCannotEscapeAuthorizedRoot(): void {
		$outside = $this->root . '-outside';
		mkdir( $outside, 0755, true );
		file_put_contents( $outside . '/sentinel.txt', 'outside' );
		symlink( $outside, $this->root . '/linked' );
		symlink( $outside, $this->root . '/root-link' );
		file_put_contents( $this->root . '/target.txt', 'original' );
		symlink( $outside . '/sentinel.txt', $this->root . '/target-link.txt' );

		$this->assertInstanceOf( \WP_Error::class, \DD_Maintenance_File_Security::safe_child_path( $this->root, 'linked/escape.txt', true ) );
		$this->assertInstanceOf( \WP_Error::class, \DD_Maintenance_File_Security::safe_child_path( $this->root . '/root-link', 'escape.txt', true ) );
		$this->assertInstanceOf( \WP_Error::class, \DD_Maintenance_File_Security::copy_to_root( $this->root . '/target.txt', $this->root, 'target-link.txt' ) );
		$this->assertSame( 'outside', file_get_contents( $outside . '/sentinel.txt' ) );
		$this->removeTree( $outside );
	}

	public function testArchiveTraversalAndSymlinkEntriesAreRejected(): void {
		$archive_path = $this->root . '/malicious.zip';
		$archive      = new \ZipArchive();
		$this->assertSame( true, $archive->open( $archive_path, \ZipArchive::CREATE ) );
		$archive->addFromString( '../escape.txt', 'escape' );
		$archive->addFromString( 'safe.txt', 'safe' );
		$archive->close();

		$archive = new \ZipArchive();
		$this->assertSame( true, $archive->open( $archive_path ) );
		mkdir( $this->root . '/extract', 0755, true );
		$result = \DD_Maintenance_File_Security::extract_archive( $archive, $this->root . '/extract' );
		$archive->close();
		$this->assertInstanceOf( \WP_Error::class, $result );

		$symlink_archive_path = $this->root . '/symlink.zip';
		$symlink_archive      = new \ZipArchive();
		$this->assertSame( true, $symlink_archive->open( $symlink_archive_path, \ZipArchive::CREATE ) );
		$symlink_archive->addFromString( 'linked.txt', 'outside link' );
		if ( method_exists( $symlink_archive, 'setExternalAttributesIndex' ) ) {
			$symlink_archive->setExternalAttributesIndex( 0, \ZipArchive::OPSYS_UNIX, ( 0120000 | 0777 ) << 16 );
		}
		$symlink_archive->close();

		$symlink_archive = new \ZipArchive();
		$this->assertSame( true, $symlink_archive->open( $symlink_archive_path ) );
		$symlink_result = \DD_Maintenance_File_Security::extract_archive( $symlink_archive, $this->root . '/extract' );
		$symlink_archive->close();
		$this->assertInstanceOf( \WP_Error::class, $symlink_result );
		$this->assertFileDoesNotExist( $this->root . '/escape.txt' );
	}

	private function removeTree( string $path ): void {
		if ( ! is_dir( $path ) || is_link( $path ) ) {
			if ( is_link( $path ) || file_exists( $path ) ) {
				unlink( $path );
			}
			return;
		}
		$entries = scandir( $path );
		foreach ( $entries ?: array() as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->removeTree( $path . '/' . $entry );
			}
		}
		rmdir( $path );
	}
}

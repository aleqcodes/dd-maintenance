<?php
declare(strict_types=1);

class WP_Error {
	private $code;
	public function __construct( string $code ) { $this->code = $code; }
	public function get_error_code(): string { return $this->code; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function __( string $text ): string { return $text; }
function wp_normalize_path( string $path ): string { return str_replace( '\\', '/', $path ); }
function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0755, true ); }

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../includes/class-dd-maintenance-file-security.php';

$root = sys_get_temp_dir() . '/dd-file-security-' . bin2hex( random_bytes( 4 ) );
$outside = sys_get_temp_dir() . '/dd-file-security-outside-' . bin2hex( random_bytes( 4 ) );
wp_mkdir_p( $root );
wp_mkdir_p( $outside );
assert( is_wp_error( DD_Maintenance_File_Security::normalize_relative_path( '../escape.txt' ) ), 'traversal deve ser rejeitado' );
assert( is_wp_error( DD_Maintenance_File_Security::normalize_relative_path( '/absolute.txt' ) ), 'caminho absoluto deve ser rejeitado' );
assert( is_wp_error( DD_Maintenance_File_Security::normalize_relative_path( '%2e%2e/escape.txt' ) ), 'traversal codificado deve ser rejeitado' );
assert( 'safe/file.txt' === DD_Maintenance_File_Security::normalize_relative_path( 'safe\\file.txt' ), 'separadores devem ser normalizados' );

file_put_contents( $outside . '/sentinel.txt', 'outside' );
symlink( $outside, $root . '/linked' );
assert( is_wp_error( DD_Maintenance_File_Security::safe_child_path( $root, 'linked/escape.txt', true ) ), 'pai simbólico deve ser rejeitado' );
symlink( $outside, $root . '/root-link' );
assert( is_wp_error( DD_Maintenance_File_Security::safe_child_path( $root . '/root-link', 'escape.txt', true ) ), 'raiz simbólica deve ser rejeitada' );
file_put_contents( $root . '/target.txt', 'original' );
symlink( $outside . '/sentinel.txt', $root . '/target-link.txt' );
assert( is_wp_error( DD_Maintenance_File_Security::copy_to_root( $root . '/target.txt', $root, 'target-link.txt' ) ), 'destino simbólico deve ser rejeitado' );
assert( 'outside' === file_get_contents( $outside . '/sentinel.txt' ), 'nenhuma cópia pode escapar da raiz' );

$zip_path = $root . '/malicious.zip';
$zip = new ZipArchive();
assert( true === $zip->open( $zip_path, ZipArchive::CREATE ), 'ZIP de teste deve abrir' );
$zip->addFromString( '../escape.txt', 'escape' );
$zip->addFromString( 'safe.txt', 'safe' );
$zip->close();
$zip = new ZipArchive();
assert( true === $zip->open( $zip_path ), 'ZIP de teste deve reabrir' );
wp_mkdir_p( $root . '/extract' );
$extract = DD_Maintenance_File_Security::extract_archive( $zip, $root . '/extract' );
$zip->close();
assert( is_wp_error( $extract ), 'entrada ZIP traversal deve interromper a extração' );

$symlink_zip_path = $root . '/symlink.zip';
$symlink_zip = new ZipArchive();
assert( true === $symlink_zip->open( $symlink_zip_path, ZipArchive::CREATE ), 'ZIP simbólico deve abrir' );
$symlink_zip->addFromString( 'linked.txt', 'outside link' );
if ( method_exists( $symlink_zip, 'setExternalAttributesIndex' ) ) {
	$symlink_zip->setExternalAttributesIndex( 0, ZipArchive::OPSYS_UNIX, ( 0120000 | 0777 ) << 16 );
}
$symlink_zip->close();
$symlink_zip = new ZipArchive();
assert( true === $symlink_zip->open( $symlink_zip_path ), 'ZIP simbólico deve reabrir' );
$symlink_result = DD_Maintenance_File_Security::extract_archive( $symlink_zip, $root . '/extract' );
$symlink_zip->close();
assert( is_wp_error( $symlink_result ), 'entrada ZIP symlink deve ser rejeitada' );
assert( ! file_exists( $root . '/escape.txt' ) && ! file_exists( $outside . '/escape.txt' ), 'ZIP inválido não pode criar artefatos' );

$delete = static function ( string $path ) use ( &$delete ): void {
	if ( is_dir( $path ) && ! is_link( $path ) ) {
		foreach ( scandir( $path ) ?: array() as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) $delete( $path . '/' . $entry );
		}
		rmdir( $path );
	} elseif ( file_exists( $path ) || is_link( $path ) ) {
		unlink( $path );
	}
};
$delete( $root );
$delete( $outside );
echo "File security paths validated successfully!\n";

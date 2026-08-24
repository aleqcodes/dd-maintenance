<?php
/**
 * Teste unitário para download e listagem de backups locais no DD Maintenance.
 */

declare(strict_types=1);

// Mock básico do ambiente WordPress para testes isolados
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/dd_test_wp_' . uniqid() . '/' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}

function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', (string) $path );
}

function wp_mkdir_p( $path ) {
	return is_dir( $path ) || mkdir( $path, 0777, true );
}

function sanitize_file_name( $name ) {
	return preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $name );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $key ) );
}

function wp_create_nonce( $action ) {
	return 'nonce_' . md5( (string) $action );
}

function wp_verify_nonce( $nonce, $action ) {
	return $nonce === 'nonce_' . md5( (string) $action );
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

function add_query_arg( $args, $url ) {
	$query = http_build_query( $args );
	return $url . ( strpos( $url, '?' ) !== false ? '&' : '?' ) . $query;
}

function get_date_from_gmt( $date, $format ) {
	return date( $format, strtotime( $date ) );
}

function size_format( $bytes, $decimals = 0 ) {
	$bytes = (int) $bytes;
	if ( $bytes >= 1048576 ) {
		return round( $bytes / 1048576, 1 ) . ' MB';
	} elseif ( $bytes >= 1024 ) {
		return round( $bytes / 1024, 1 ) . ' KB';
	}
	return $bytes . ' B';
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html__( $text, $domain = 'default' ) {
	return $text;
}

function esc_attr__( $text, $domain = 'default' ) {
	return $text;
}

function get_option( $key, $default = array() ) {
	return array();
}

function add_action() {}
function add_options_page() {}
function add_menu_page() {}

require_once __DIR__ . '/../includes/class-dd-maintenance.php';
require_once __DIR__ . '/../includes/class-dd-maintenance-restore.php';
require_once __DIR__ . '/../includes/class-dd-maintenance-settings.php';
require_once __DIR__ . '/../includes/class-dd-maintenance-backup.php';

$test_backup_dir = DD_Maintenance::backup_dir();
assert( is_dir( $test_backup_dir ), 'Pasta de backups deve ser criada.' );

// 1. Cria arquivos simulados de backup: um single zip, um multipart (3 volumes) e um dump SQL
$single_zip = $test_backup_dir . '/site-backup-single-2026-08-24.zip';
file_put_contents( $single_zip, str_repeat( 'Z', 1024 * 50 ) ); // 50 KB

$multi_base = 'site-backup-multi-2026-08-24';
$part1      = $test_backup_dir . '/' . $multi_base . '.part001.zip';
$part2      = $test_backup_dir . '/' . $multi_base . '.part002.zip';
$part3      = $test_backup_dir . '/' . $multi_base . '.part003.zip';
$sql_file   = $test_backup_dir . '/' . $multi_base . '.sql';

file_put_contents( $part1, str_repeat( '1', 1024 * 100 ) ); // 100 KB
file_put_contents( $part2, str_repeat( '2', 1024 * 100 ) ); // 100 KB
file_put_contents( $part3, str_repeat( '3', 1024 * 50 ) );  // 50 KB
file_put_contents( $sql_file, "CREATE TABLE wp_test (id INT);\nINSERT INTO wp_test VALUES (1);" );

// 2. Testa get_local_backups()
$backups = DD_Maintenance_Restore::get_local_backups();
assert( count( $backups ) === 2, 'Deve identificar exatamente 2 pacotes de backup.' );

// Encontra o pacote multi-part
$multi_found = null;
$single_found = null;
foreach ( $backups as $b ) {
	if ( $b['identifier'] === $multi_base ) {
		$multi_found = $b;
	} elseif ( $b['identifier'] === 'site-backup-single-2026-08-24' ) {
		$single_found = $b;
	}
}

assert( null !== $multi_found, 'Pacote multipart deve ser encontrado.' );
assert( $multi_found['is_multipart'] === true, 'Deve ser identificado como multipart.' );
assert( $multi_found['total_parts'] === 3, 'Deve conter 3 partes.' );
assert( count( $multi_found['parts'] ) === 3, 'Array parts deve ter 3 itens.' );
assert( $multi_found['parts'][0]['filename'] === $multi_base . '.part001.zip', 'Parte 1 deve ser ordenada primeiro.' );
assert( $multi_found['parts'][1]['filename'] === $multi_base . '.part002.zip', 'Parte 2 deve ser ordenada em segundo.' );
assert( $multi_found['parts'][2]['filename'] === $multi_base . '.part003.zip', 'Parte 3 deve ser ordenada em terceiro.' );
assert( $multi_found['has_sql'] === true, 'Deve identificar o dump SQL associado.' );
assert( $multi_found['sql_filename'] === $multi_base . '.sql', 'Nome do arquivo SQL deve ser correto.' );

assert( null !== $single_found, 'Pacote single deve ser encontrado.' );
assert( $single_found['is_multipart'] === false, 'Não deve ser multipart.' );
assert( $single_found['total_parts'] === 1, 'Deve ter 1 parte.' );
assert( $single_found['parts'][0]['filename'] === 'site-backup-single-2026-08-24.zip', 'Nome do single zip deve ser correto.' );

// 3. Testa geração de URL de download
$url1 = DD_Maintenance_Settings::get_download_url( 'site-backup-single-2026-08-24.zip' );
assert( strpos( $url1, 'action=dd_maintenance_download_backup' ) !== false, 'URL deve conter a action de download.' );
assert( strpos( $url1, 'file=site-backup-single-2026-08-24.zip' ) !== false, 'URL deve conter o nome do arquivo.' );
assert( strpos( $url1, '_wpnonce=' ) !== false, 'URL deve conter nonce.' );

$url_sql = DD_Maintenance_Settings::get_download_url( $multi_base . '.sql' );
assert( strpos( $url_sql, 'file=' . $multi_base . '.sql' ) !== false, 'URL do SQL deve ser correta.' );

// 4. Testa exclusão de backup local
$deleted = DD_Maintenance_Restore::delete_local_backup( $multi_base );
assert( $deleted === true, 'Exclusão do backup deve retornar true.' );
assert( ! file_exists( $part1 ), 'Parte 1 deve ter sido excluída.' );
assert( ! file_exists( $part2 ), 'Parte 2 deve ter sido excluída.' );
assert( ! file_exists( $part3 ), 'Parte 3 deve ter sido excluída.' );
assert( ! file_exists( $sql_file ), 'SQL associado deve ter sido excluído.' );

// Limpa single zip
DD_Maintenance_Restore::delete_local_backup( 'site-backup-single-2026-08-24' );
assert( ! file_exists( $single_zip ), 'Single zip deve ter sido excluído.' );

$after_clean = DD_Maintenance_Restore::get_local_backups();
assert( count( $after_clean ) === 0, 'Após exclusão não deve restar backups.' );

// Limpa diretório de testes
@unlink( $test_backup_dir . '/.htaccess' );
@unlink( $test_backup_dir . '/web.config' );
@unlink( $test_backup_dir . '/index.php' );
@rmdir( $test_backup_dir );

echo "Todos os testes de listagem, download e gestão de backups passaram com sucesso!\n";

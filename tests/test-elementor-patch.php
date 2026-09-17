<?php
declare(strict_types=1);

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		public function __construct( string $code ) { $this->code = $code; }
		public function get_error_code(): string { return $this->code; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
}
if ( ! function_exists( '__' ) ) {
	function __( string $text ): string { return $text; }
}
if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( string $path ): string { return str_replace( '\\', '/', $path ); }
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0755, true ); }
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $name ): string { return preg_replace( '/[^A-Za-z0-9._-]/', '-', $name ); }
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( int $length ): string { return str_repeat( 'x', $length ); }
}
$options = array();
function get_option( string $name, $default = false ) { global $options; return $options[ $name ] ?? $default; }
function update_option( string $name, $value, bool $autoload = true ): bool { global $options; $options[ $name ] = $value; return true; }
$events = array();
class DD_Maintenance {
	public static function record_event( string $operation, string $event, array $context = array() ): array {
		global $events;
		$events[] = array( $operation, $event, $context );
		return array();
	}
}

$root = sys_get_temp_dir() . '/dd-elementor-' . bin2hex( random_bytes( 4 ) );
$plugin_dir = $root . '/plugins';
$content_dir = $root . '/content';
$manager = $plugin_dir . '/elementor/core/dynamic-tags/manager.php';
wp_mkdir_p( dirname( $manager ) );
wp_mkdir_p( $content_dir . '/uploads/dd-maintenance' );

define( 'ABSPATH', $root . '/' );
define( 'WP_PLUGIN_DIR', $plugin_dir );
define( 'WP_CONTENT_DIR', $content_dir );
define( 'WPMU_PLUGIN_DIR', $content_dir . '/mu-plugins' );

$source = <<<'PHP'
<?php
namespace Elementor\Core\DynamicTags;
class Manager {
	public function create_tag( $tag_id, $tag_name, array $settings = [] ) {
		$tag_class = $this->get_tag_data_class( $tag_id, $tag_name, $settings );
		return $tag_class ? new $tag_class( $settings ) : null;
	}
	public function get_tag_data_content( $tag_id, $tag_name, array $settings = [] ) {
		return $this->create_tag( $tag_id, $tag_name, $settings );
	}
	public function get_tag_data_class( $tag_id, $tag_name, array $settings = [] ) {
		return $this->get_tag_class( $tag_name );
	}
}
PHP;
file_put_contents( $manager, $source );
require_once __DIR__ . '/../includes/class-dd-maintenance-elementor-compatibility.php';
$skipped = DD_Maintenance_Elementor_Compatibility::apply_restore_decision( false );
assert( 'skipped' === $skipped['status'], 'decisão não autorizada deve pular compatibilidade' );
assert( ! file_exists( $content_dir . '/mu-plugins/dd-elementor-compat.php' ), 'decisão não autorizada não deve instalar shield' );
assert( 'elementor' === $events[0][0] && 'compatibility_skipped' === $events[0][1], 'decisão deve emitir evento operacional redigido' );

$result = DD_Maintenance_Elementor_Compatibility::apply( array( $manager ) );
assert( 'completed' === $result['status'], 'implementação real deve aplicar o patch reconhecido' );
assert( 'patched' === $result['files'][0]['status'], 'arquivo reconhecido deve ser modificado' );
assert( is_file( $result['files'][0]['backup'] ), 'backup do original deve existir' );
assert( hash_file( 'sha256', $result['files'][0]['backup'] ) === $result['files'][0]['backup_checksum'], 'backup deve corresponder ao checksum registrado' );
assert( false === strpos( (string) file_get_contents( $manager ), 'array $settings' ), 'assinaturas estritas devem ser removidas' );

$idempotent = DD_Maintenance_Elementor_Compatibility::apply( array( $manager ) );
assert( 'already_patched' === $idempotent['files'][0]['status'], 'segunda aplicação deve ser idempotente' );

$unknown = $plugin_dir . '/elementor/core/dynamic-tags/unknown.php';

$modified = $plugin_dir . '/pro-elements/core/dynamic-tags/manager.php';
wp_mkdir_p( dirname( $modified ) );
file_put_contents( $modified, str_replace( '$this->get_tag_data_class', '$this->missing_tag_class', $source ) );
$modified_before = file_get_contents( $modified );
$modified_result = DD_Maintenance_Elementor_Compatibility::apply( array( $modified ) );
assert( 'rejected' === $modified_result['status'], 'versão modificada deve ser rejeitada pelo fingerprint estrutural' );
assert( $modified_before === file_get_contents( $modified ), 'versão modificada não pode ser alterada' );
file_put_contents( $unknown, "<?php namespace Elementor\\Core\\DynamicTags; class Manager {}\n" );
$unknown_before = file_get_contents( $unknown );
$unknown_result = DD_Maintenance_Elementor_Compatibility::apply( array( $unknown ) );
assert( 'rejected' === $unknown_result['status'], 'arquivo de terceiro desconhecido deve ser rejeitado' );
assert( $unknown_before === file_get_contents( $unknown ), 'arquivo desconhecido não pode ser alterado' );
$nested = $plugin_dir . '/third-party/elementor/core/dynamic-tags/manager.php';
wp_mkdir_p( dirname( $nested ) );
file_put_contents( $nested, $source );
$nested_result = DD_Maintenance_Elementor_Compatibility::apply( array( $nested ) );
assert( 'rejected_path' === $nested_result['files'][0]['status'], 'plugin aninhado fora da allowlist deve ser rejeitado' );

$undone = DD_Maintenance_Elementor_Compatibility::undo( $manager );
assert( 'undone' === $undone['status'], 'desfazer deve restaurar o backup verificado' );
assert( $source === file_get_contents( $manager ), 'desfazer deve restaurar byte a byte o original' );

$reapplied = DD_Maintenance_Elementor_Compatibility::apply( array( $manager ) );
assert( 'patched' === $reapplied['files'][0]['status'], 'reaplicação deve criar novo estado verificável' );
file_put_contents( $manager, (string) file_get_contents( $manager ) . "\n// alteração externa após o patch\n" );
$conflict = DD_Maintenance_Elementor_Compatibility::undo( $manager );
assert( 'undo_conflict' === $conflict['status'], 'alteração de terceiro deve impedir undo destrutivo' );
$explicit = DD_Maintenance_Elementor_Compatibility::apply_restore_decision( true );
assert( 'completed' === $explicit['status'], 'decisão explícita deve aplicar compatibilidade' );
assert( is_file( $content_dir . '/mu-plugins/dd-elementor-compat.php' ), 'decisão explícita deve instalar shield próprio' );
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
echo "Elementor compatibility integration validated successfully!\n";

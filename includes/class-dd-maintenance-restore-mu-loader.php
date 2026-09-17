<?php
/**
 * Geração idempotente do loader MU temporário de restauração.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_Restore_Mu_Loader {
	private $directory;

	public function __construct( string $directory = '' ) {
		$this->directory = '' !== $directory ? $directory : ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' );
	}

	public function path(): string {
		return rtrim( wp_normalize_path( $this->directory ), '/' ) . '/dd-maintenance-loader.php';
	}

	public function create(): bool {
		if ( ! is_dir( $this->directory ) && ! wp_mkdir_p( $this->directory ) ) {
			return false;
		}
		if ( ! is_dir( $this->directory ) || is_link( $this->directory ) ) {
			return false;
		}
		$source  = self::template();
		$current = is_file( $this->path() ) ? file_get_contents( $this->path() ) : false;
		if ( is_string( $current ) && hash( 'sha256', $current ) === hash( 'sha256', $source ) ) {
			return true;
		}
		$written = file_put_contents( $this->path(), $source, LOCK_EX );
		return false !== $written && (int) $written === strlen( $source );
	}

	public function remove(): bool {
		if ( ! file_exists( $this->path() ) ) {
			return true;
		}
		return unlink( $this->path() );
	}

	public static function template(): string {
		return <<<'PHP'
<?php
/** Plugin Name: DD Maintenance Restore Loader */
defined( 'ABSPATH' ) || exit;

if ( ( isset( $_POST['action'] ) && 'dd_maintenance_ajax_restore' === $_POST['action'] ) || ( isset( $_GET['action'] ) && 'dd_maintenance_ajax_restore' === $_GET['action'] ) ) {
	add_filter( 'pre_option_siteurl', static function ( $val ) { return $val; }, 1 );
	add_filter( 'pre_option_home', static function ( $val ) { return $val; }, 1 );
	add_filter( 'wp_die_handler', static function () {
		return static function ( $message, $title = '', $args = array() ) {
			if ( is_string( $message ) && false !== strpos( $message, 'database tables are unavailable' ) ) {
				return;
			}
			if ( function_exists( '_default_wp_die_handler' ) ) {
				_default_wp_die_handler( $message, $title, $args );
			}
		};
	}, 1 );
}

if ( ! class_exists( 'DD_Maintenance' ) ) {
	$candidates = array(
		__DIR__ . '/../plugins/dd-maintenance/dd-maintenance.php',
		__DIR__ . '/../plugins/backuper/backuper.php',
		defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/dd-maintenance/dd-maintenance.php' : '',
		defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/backuper/backuper.php' : '',
	);
	foreach ( $candidates as $file ) {
		if ( '' !== $file && file_exists( $file ) ) {
			require_once $file;
			break;
		}
	}
}
if ( class_exists( 'DD_Maintenance' ) ) {
	DD_Maintenance::instance();
}
PHP;
	}
}

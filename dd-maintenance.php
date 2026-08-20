<?php
/**
 * Plugin Name:       DD Maintenance
 * Plugin URI:        https://example.com/dd-maintenance
 * Description:       Solução completa de manutenção para WordPress: gerenciamento seguro de travas no wp-config.php (DISALLOW_FILE_MODS e DISALLOW_FILE_EDIT com senha), backups completos (arquivos + banco de dados), envio para S3 / DigitalOcean Spaces e atualização automatizada de plugins e core.
 * Version:           2.0.0
 * Requires at least: 5.4
 * Requires PHP:      7.4
 * Author:            DD & Aleq
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dd-maintenance
 */

defined( 'ABSPATH' ) || exit;

define( 'DD_MAINTENANCE_VERSION', '2.0.0' );
define( 'DD_MAINTENANCE_FILE', __FILE__ );
define( 'DD_MAINTENANCE_DIR', plugin_dir_path( __FILE__ ) );
define( 'DD_MAINTENANCE_URL', plugin_dir_url( __FILE__ ) );
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}
if ( ! defined( 'MONTH_IN_SECONDS' ) ) {
	define( 'MONTH_IN_SECONDS', 2592000 );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

// Constantes de compatibilidade com versões antigas do Backuper.
if ( ! defined( 'BACKUPER_VERSION' ) ) {
	define( 'BACKUPER_VERSION', DD_MAINTENANCE_VERSION );
}
if ( ! defined( 'BACKUPER_FILE' ) ) {
	define( 'BACKUPER_FILE', DD_MAINTENANCE_FILE );
}
if ( ! defined( 'BACKUPER_DIR' ) ) {
	define( 'BACKUPER_DIR', DD_MAINTENANCE_DIR );
}

// Carregamento dos módulos do DD Maintenance.
require_once DD_MAINTENANCE_DIR . 'includes/class-dd-maintenance-config.php';
require_once DD_MAINTENANCE_DIR . 'includes/class-dd-maintenance-backup.php';
require_once DD_MAINTENANCE_DIR . 'includes/class-dd-maintenance-s3.php';
require_once DD_MAINTENANCE_DIR . 'includes/class-dd-maintenance-restore.php';
require_once DD_MAINTENANCE_DIR . 'includes/class-dd-maintenance-updater.php';
require_once DD_MAINTENANCE_DIR . 'includes/class-dd-maintenance-settings.php';
require_once DD_MAINTENANCE_DIR . 'includes/class-dd-maintenance.php';

// Classes legadas como aliases para compatibilidade retroativa.
if ( ! class_exists( 'Backuper' ) ) {
	class Backuper extends DD_Maintenance {}
}
if ( ! class_exists( 'Backuper_Backup' ) ) {
	class Backuper_Backup extends DD_Maintenance_Backup {}
}
if ( ! class_exists( 'Backuper_S3' ) ) {
	class Backuper_S3 extends DD_Maintenance_S3 {}
}
if ( ! class_exists( 'Backuper_Updater' ) ) {
	class Backuper_Updater extends DD_Maintenance_Updater {}
}
if ( ! class_exists( 'Backuper_Settings' ) ) {
	class Backuper_Settings extends DD_Maintenance_Settings {}
}
if ( ! class_exists( 'DD_Gerenciador_Updates' ) ) {
	class DD_Gerenciador_Updates extends DD_Maintenance_Config {}
}

register_activation_hook( __FILE__, array( 'DD_Maintenance', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DD_Maintenance', 'deactivate' ) );

DD_Maintenance::instance();

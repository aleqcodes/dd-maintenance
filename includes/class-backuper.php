<?php
/**
 * Arquivo de compatibilidade com versões antigas do Backuper.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-dd-maintenance.php';
require_once __DIR__ . '/class-dd-maintenance-legacy-compatibility.php';
DD_Maintenance_Legacy_Compatibility::register();
DD_Maintenance_Legacy_Compatibility::register_wrapper( 'class-backuper.php' );

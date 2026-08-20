<?php
/**
 * Arquivo de compatibilidade retroativa para DD Maintenance.
 * Redireciona o carregamento para o arquivo principal dd-maintenance.php.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/dd-maintenance.php';

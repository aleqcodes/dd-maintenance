<?php
/**
 * Compatibilidade retroativa para nomes públicos do Backuper.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Legacy_Compatibility {

	/**
	 * Os aliases serão removidos na próxima major version após a migração formal
	 * dos consumidores externos e a publicação do aviso de depreciação.
	 */
	const REMOVAL_TARGET = 'next-major-version';

	/**
	 * Registra os nomes públicos mantidos por compatibilidade.
	 */
	public static function register(): void {
		$aliases = array(
			'Backuper'              => 'DD_Maintenance',
			'Backuper_Backup'       => 'DD_Maintenance_Backup',
			'Backuper_S3'           => 'DD_Maintenance_S3',
			'Backuper_Updater'      => 'DD_Maintenance_Updater',
			'Backuper_Settings'     => 'DD_Maintenance_Settings',
			'DD_Gerenciador_Updates'=> 'DD_Maintenance_Config',
		);

		foreach ( $aliases as $legacy => $canonical ) {
			if ( ! class_exists( $legacy ) && class_exists( $canonical ) ) {
				class_alias( $canonical, $legacy );
			}
		}
	}
}

<?php
/** @package DD_Maintenance */
defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_Workflow_Steps {
	const BACKUP_INIT     = 'init';
	const BACKUP_DATABASE = 'database';
	const BACKUP_INDEX    = 'index';
	const BACKUP_ZIP      = 'zip';
	const BACKUP_FINALIZE = 'finalize';
	const BACKUP_UPLOAD   = 'upload';

	const RESTORE_INIT     = 'restore_init';
	const RESTORE_EXTRACT  = 'restore_extract';
	const RESTORE_DATABASE = 'restore_database';
	const RESTORE_FILES    = 'restore_files';
	const RESTORE_FINALIZE = 'restore_finalize';

	/** @return string[] */
	public static function backup(): array {
		return array( self::BACKUP_INIT, self::BACKUP_DATABASE, self::BACKUP_INDEX, self::BACKUP_ZIP, self::BACKUP_FINALIZE, self::BACKUP_UPLOAD );
	}

	/** @return string[] */
	public static function restore(): array {
		return array( self::RESTORE_INIT, self::RESTORE_EXTRACT, self::RESTORE_DATABASE, self::RESTORE_FILES, self::RESTORE_FINALIZE );
	}
}

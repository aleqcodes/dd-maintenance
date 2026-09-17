<?php
/** @package DD_Maintenance */
defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_Error_Codes {
	const INVALID_REQUEST       = 'invalid_request';
	const INVALID_CONFIGURATION = 'invalid_configuration';
	const SESSION_NOT_FOUND     = 'session_not_found';
	const SESSION_LOCKED        = 'session_locked';
	const SESSION_CORRUPTED     = 'session_corrupted';
	const SESSION_EXPIRED       = 'session_expired';
	const SESSION_SCHEMA_INVALID = 'session_schema_invalid';
	const SESSION_SCHEMA_VERSION = 'session_schema_version';
	const SESSION_SAVE_FAILED   = 'session_save_failed';
	const SESSION_CLEANUP_INCOMPLETE = 'session_cleanup_incomplete';
	const VOLUME_INVALID        = 'restore_zip_invalid';
	const CHECKSUM_MISMATCH     = 'restore_volume_checksum_mismatch';
	const CLEANUP_FAILED        = 'cleanup_failed';

	/** @return array<string,string> */
	public static function all(): array {
		return array(
			'invalid_request' => self::INVALID_REQUEST,
			'invalid_configuration' => self::INVALID_CONFIGURATION,
			'session_not_found' => self::SESSION_NOT_FOUND,
			'session_locked' => self::SESSION_LOCKED,
			'session_corrupted' => self::SESSION_CORRUPTED,
			'session_expired' => self::SESSION_EXPIRED,
			'session_schema_invalid' => self::SESSION_SCHEMA_INVALID,
			'session_schema_version' => self::SESSION_SCHEMA_VERSION,
			'session_save_failed' => self::SESSION_SAVE_FAILED,
			'session_cleanup_incomplete' => self::SESSION_CLEANUP_INCOMPLETE,
			'restore_zip_invalid' => self::VOLUME_INVALID,
			'restore_volume_checksum_mismatch' => self::CHECKSUM_MISMATCH,
			'cleanup_failed' => self::CLEANUP_FAILED,
		);
	}
}

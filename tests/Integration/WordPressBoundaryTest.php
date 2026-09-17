<?php

declare(strict_types=1);

namespace DDMaintenance\Tests\Integration;

use DDMaintenance\Tests\Support\LegacyScriptRunner;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/LegacyScriptRunner.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-settings.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-backup-action-controller.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-admin-action-controller.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-restore-action-controller.php';

final class WordPressBoundaryTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['dd_phpunit_hooks']       = array();
		$GLOBALS['dd_phpunit_logged_in']   = true;
		$GLOBALS['dd_phpunit_can_manage']  = true;
		$GLOBALS['dd_phpunit_valid_nonce'] = true;
		$GLOBALS['dd_phpunit_fail_update_option'] = false;
	}
	public function testRestoreUploadInitCallsProductionHandlerAndCreatesSession(): void {
		$settings = new \DD_Maintenance_Settings();
		$_POST    = array( 'mode' => 'upload_init' );

		try {
			$settings->ajax_handle_restore();
			$this->fail( 'O handler deveria responder por JSON.' );
		} catch ( \DD_Maintenance_Test_Json_Response $response ) {
			$this->assertTrue( $response->success );
			$this->assertIsArray( $response->data );
			$this->assertStringStartsWith( 'upload_restore_', $response->data['upload_session_id'] );

			$session_dir = \DD_Maintenance::backup_dir() . '/' . $response->data['upload_session_id'];
			$this->assertDirectoryExists( $session_dir );
			rmdir( $session_dir );
		} finally {
			$_POST = array();
		}
	}
	public function testRestoreUploadInitRejectsMissingCapabilityOrNonce(): void {
		$settings = new \DD_Maintenance_Settings();
		$_POST    = array( 'mode' => 'upload_init' );

		$GLOBALS['dd_phpunit_can_manage'] = false;
		try {
			$settings->ajax_handle_restore();
			$this->fail( 'O handler deveria rejeitar uma capacidade ausente.' );
		} catch ( \DD_Maintenance_Test_Json_Response $response ) {
			$this->assertFalse( $response->success );
			$this->assertSame( 'Sessão expirada ou sem permissão.', $response->data['message'] );
		} finally {
			$_POST = array();
		}

		$GLOBALS['dd_phpunit_can_manage']  = true;
		$GLOBALS['dd_phpunit_valid_nonce'] = false;
		$_POST                              = array( 'mode' => 'upload_init' );
		try {
			$settings->ajax_handle_restore();
			$this->fail( 'O handler deveria rejeitar um nonce inválido.' );
		} catch ( \DD_Maintenance_Test_Json_Response $response ) {
			$this->assertFalse( $response->success );
		} finally {
			$_POST = array();
		}
	}
	public function testAdminBackupHandlerRequiresCapabilityAndNonce(): void {
		$handler = new \DD_Maintenance_Admin_Backup_Handler( new \DD_Maintenance_Settings_Implementation() );

		$GLOBALS['dd_phpunit_can_manage'] = false;
		try {
			$handler->handle_backup();
			$this->fail( 'A ação administrativa deveria rejeitar capacidade ausente.' );
		} catch ( \DD_Maintenance_Test_Wp_Die $error ) {
			$this->assertSame( 'Sem permissão.', $error->getMessage() );
		}

		$GLOBALS['dd_phpunit_can_manage']  = true;
		$GLOBALS['dd_phpunit_valid_nonce'] = false;
		try {
			$handler->handle_backup();
			$this->fail( 'A ação administrativa deveria rejeitar nonce inválido.' );
		} catch ( \DD_Maintenance_Test_Wp_Die $error ) {
			$this->assertSame( 'Nonce inválido.', $error->getMessage() );
		}
	}

	public function testInternalSettingsImplementationDoesNotRegisterWordPressHooks(): void {
		new \DD_Maintenance_Settings_Implementation();

		$this->assertSame( array(), $GLOBALS['dd_phpunit_hooks'] );
	}
	public function testAdministrativePolicyDeclaresAuthMethodAndRollbackBoundary(): void {
		$destructive = \DD_Maintenance_Admin_Request::policy( 'dd_maintenance_delete_backup' );
		$this->assertSame( 'manage_options', $destructive['capability'] );
		$this->assertSame( 'dd_maintenance_delete_backup', $destructive['nonce'] );
		$this->assertSame( 'POST', $destructive['method'] );
		$this->assertFalse( $destructive['public'] );
		$this->assertTrue( $destructive['rollback_blocked'] );

		$restore_ajax = \DD_Maintenance_Admin_Request::policy( 'dd_maintenance_ajax_restore' );
		$this->assertTrue( $restore_ajax['public'] );
		$this->assertSame( 'dd_maint_ajax_nonce', $restore_ajax['nonce'] );
		$this->assertSame( 'json', $restore_ajax['response'] );
	}

	public function testRestoreSessionDoesNotTrustRequestHostForTargetUrls(): void {
		$backup_dir = \DD_Maintenance::backup_dir();
		$zip_path   = $backup_dir . '/security.part001.zip';
		file_put_contents( $zip_path, '' );
		$_SERVER['HTTP_HOST'] = 'attacker.example.test';
		$GLOBALS['dd_phpunit_options'] = array(
			'siteurl' => 'javascript:alert(1)',
			'home'    => 'https://trusted.example.test/',
		);

		$restore = new \DD_Maintenance_Restore_Implementation();
		$session = $restore->init_restore_session( array( $zip_path ), '', false, 'security-correlation' );

		$this->assertIsArray( $session );
		$this->assertSame( '', $session['target_siteurl'] );
		$this->assertSame( 'https://trusted.example.test', $session['target_home'] );

		( new \DD_Maintenance_Session_Store() )->remove_directory( $session['extract_dir'] );
		unlink( $zip_path );
		$_SERVER['HTTP_HOST'] = '';
	}

	public function testGeneratedRestoreLoaderDoesNotBuildUrlsFromRequestHost(): void {
		$loader_dir = WP_CONTENT_DIR . '/mu-plugins';
		\DD_Maintenance_Restore_Implementation::create_mu_plugin_loader( array( 'test' => true ) );
		$loader_file = $loader_dir . '/dd-maintenance-loader.php';
		$loader_code = file_get_contents( $loader_file );

		$this->assertIsString( $loader_code );
		$this->assertStringContainsString( 'return $val;', $loader_code );
		$this->assertStringNotContainsString( 'HTTP_HOST', $loader_code );
		$loader_checksum = hash_file( 'sha256', $loader_file );
		$this->assertTrue( \DD_Maintenance_Restore_Implementation::create_mu_plugin_loader( array( 'test' => true ) ) );
		$this->assertSame( $loader_checksum, hash_file( 'sha256', $loader_file ) );
	}





	public function testBackupControllerRegistersProductionAndLegacyHooks(): void {
		$settings = new class {
			public function handle_backup(): string { return 'handled'; }
			public function ajax_handle_action(): string { return 'ajax'; }
		};
		$controller = new \DD_Maintenance_Backup_Action_Controller( $settings );
		$controller->register();

		$this->assertArrayHasKey( 'admin_post_dd_maintenance_run_backup', $GLOBALS['dd_phpunit_hooks'] );
		$this->assertArrayHasKey( 'admin_post_backuper_run_backup', $GLOBALS['dd_phpunit_hooks'] );
		$this->assertArrayHasKey( 'wp_ajax_dd_maintenance_ajax_action', $GLOBALS['dd_phpunit_hooks'] );
		$this->assertSame( 'handled', ( $GLOBALS['dd_phpunit_hooks']['admin_post_dd_maintenance_run_backup'][0] )() );
	}
	public function testLegacyBackupHookDelegatesAndRecordsUsage(): void {
		$GLOBALS['dd_phpunit_options'][ \DD_Maintenance_Legacy_Compatibility::USAGE_OPTION ] = array();
		$settings = new class {
			public function handle_backup(): string { return 'handled'; }
		};
		$controller = new \DD_Maintenance_Backup_Action_Controller( $settings );
		$controller->register();

		$this->assertSame( 'handled', ( $GLOBALS['dd_phpunit_hooks']['admin_post_backuper_run_backup'][0] )() );
		$usage = \DD_Maintenance_Legacy_Compatibility::usage();
		$this->assertArrayHasKey( 'hook_admin_post_backuper_run_backup', $usage );
		$this->assertSame( 1, $usage['hook_admin_post_backuper_run_backup']['count'] );
	}
	public function testLegacyMigrationTableCoversPublicContracts(): void {
		$table = \DD_Maintenance_Legacy_Compatibility::migration_table();

		$this->assertSame( 'DD_Maintenance', $table['classes']['Backuper'] );
		$this->assertSame( 'admin_post_dd_maintenance_run_backup', $table['hooks']['admin_post_backuper_run_backup'] );
		$this->assertSame( 'dd_maintenance_settings', $table['options']['backuper_settings'] );
		$this->assertSame( 'dd_maintenance_last_log', $table['transients']['backuper_last_log'] );
		$this->assertSame( 'dd-maintenance.php', $table['wrappers']['class-backuper-s3.php'] );
		$this->assertSame( '3.0.0', $table['removal']['target_version'] );
	}


	public function testLegacyDeprecationNoticePublishesRemovalMajor(): void {
		ob_start();
		\DD_Maintenance_Legacy_Compatibility::render_deprecation_notice();
		$notice = ob_get_clean();

		$this->assertStringContainsString( 'DD Maintenance 3.0.0', $notice );
		$this->assertStringContainsString( 'obsoletos', $notice );
	}


	public function testRestoreControllerRegistersAuthenticatedAndPublicContinuationHooks(): void {
		$controller = new \DD_Maintenance_Restore_Action_Controller( new \stdClass() );
		$controller->register();

		$this->assertArrayHasKey( 'wp_ajax_dd_maintenance_ajax_restore', $GLOBALS['dd_phpunit_hooks'] );
		$this->assertArrayHasKey( 'wp_ajax_nopriv_dd_maintenance_ajax_restore', $GLOBALS['dd_phpunit_hooks'] );
		$this->assertArrayHasKey( 'admin_post_backuper_download_backup', $GLOBALS['dd_phpunit_hooks'] );
	}

	public function testAdminControllerKeepsLegacyActionsAtTheWordPressBoundary(): void {
		$controller = new \DD_Maintenance_Admin_Action_Controller( new \stdClass() );
		$controller->register();

		$this->assertArrayHasKey( 'admin_post_dd_maintenance_save_settings', $GLOBALS['dd_phpunit_hooks'] );
		$this->assertArrayHasKey( 'admin_post_backuper_save_settings', $GLOBALS['dd_phpunit_hooks'] );
		$this->assertArrayHasKey( 'admin_post_backuper_run_full', $GLOBALS['dd_phpunit_hooks'] );
	}

	/**
	 * @dataProvider productionScriptProvider
	 */
	public function testExistingProductionFlowScriptsPassThroughRunner( string $script, string $expectedOutput ): void {
		$result = LegacyScriptRunner::run( $script );

		$this->assertSame( 0, $result['exit_code'], $result['stderr'] );
		$this->assertStringContainsString( $expectedOutput, $result['stdout'] );
	}

	public static function productionScriptProvider(): array {
		return array(
			'nonce capability session and restore' => array( 'test-integrity-regressions.php', 'Regressões de integridade' ),
			'S3 secret boundary' => array( 'test-render-page.php', 'Teste de renderização' ),
			'Elementor production patch' => array( 'test-elementor-patch.php', 'Elementor compatibility' ),
		);
	}
}

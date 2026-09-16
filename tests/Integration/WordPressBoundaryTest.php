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
	}
	public function testRestoreUploadInitCallsProductionHandlerAndCreatesSession(): void {
		$settings = ( new \ReflectionClass( \DD_Maintenance_Settings::class ) )->newInstanceWithoutConstructor();
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
		$settings = ( new \ReflectionClass( \DD_Maintenance_Settings::class ) )->newInstanceWithoutConstructor();
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

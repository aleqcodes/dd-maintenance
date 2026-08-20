<?php
/**
 * Responsável por atualizar plugins e o core do WordPress de forma segura e silenciosa.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Updater {

	/**
	 * Força o método de filesystem 'direct'.
	 *
	 * @return string
	 */
	public function force_direct() {
		return 'direct';
	}

	/**
	 * Inicializa o subsistema de arquivos do WordPress (WP_Filesystem).
	 */
	private function init_filesystem() {
		add_filter( 'filesystem_method', array( $this, 'force_direct' ) );

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			if ( file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
		}

		if ( function_exists( 'WP_Filesystem' ) ) {
			global $wp_filesystem;
			if ( empty( $wp_filesystem ) ) {
				WP_Filesystem();
			}
		}
	}

	/**
	 * Carrega os arquivos necessários do upgrader do WordPress.
	 */
	private function load_upgrader_dependencies() {
		$files = array(
			ABSPATH . 'wp-admin/includes/file.php',
			ABSPATH . 'wp-admin/includes/misc.php',
			ABSPATH . 'wp-admin/includes/template.php',
			ABSPATH . 'wp-admin/includes/update.php',
			ABSPATH . 'wp-admin/includes/plugin.php',
			ABSPATH . 'wp-admin/includes/class-wp-upgrader.php',
			ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php',
			ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php',
			ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php',
			ABSPATH . 'wp-admin/includes/class-core-upgrader.php',
		);

		foreach ( $files as $file ) {
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	}

	/**
	 * Atualiza todos os plugins com atualizações disponíveis.
	 *
	 * @return array|WP_Error
	 */
	public function update_plugins() {
		$this->load_upgrader_dependencies();
		$this->init_filesystem();

		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		$updates = function_exists( 'get_plugin_updates' ) ? get_plugin_updates() : array();

		if ( empty( $updates ) ) {
			return array(
				'updated' => 0,
				'logs'    => array( __( 'Nenhum plugin com atualização disponível.', 'dd-maintenance' ) ),
			);
		}

		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			return new WP_Error( 'updater_missing', __( 'Componente de atualização de plugins do WordPress não carregado.', 'dd-maintenance' ) );
		}

		$skin     = class_exists( 'Automatic_Upgrader_Skin' ) ? new Automatic_Upgrader_Skin() : null;
		$upgrader = new Plugin_Upgrader( $skin );
		$done     = 0;
		$logs     = array();

		foreach ( $updates as $plugin_file => $data ) {
			ob_start();
			$result = $upgrader->upgrade( $plugin_file );
			ob_end_clean();

			$name = isset( $data->Name ) ? $data->Name : $plugin_file;

			if ( ! is_wp_error( $result ) && $result ) {
				$done++;
				$logs[] = sprintf(
					/* translators: %s: Nome do plugin */
					__( 'Plugin atualizado com sucesso: %s', 'dd-maintenance' ),
					$name
				);
			} else {
				$error = is_wp_error( $result ) ? $result->get_error_message() : __( 'erro desconhecido', 'dd-maintenance' );
				if ( $skin && method_exists( $skin, 'get_errors' ) && is_wp_error( $skin->get_errors() ) ) {
					$error .= ' (' . $skin->get_errors()->get_error_message() . ')';
				}
				$logs[] = sprintf(
					/* translators: 1: Nome do plugin, 2: Mensagem de erro */
					__( 'Falha ao atualizar %1$s: %2$s', 'dd-maintenance' ),
					$name,
					$error
				);
			}
		}

		return array(
			'updated' => $done,
			'logs'    => $logs,
		);
	}

	/**
	 * Atualiza o core do WordPress.
	 *
	 * @return array|WP_Error
	 */
	public function update_core() {
		$this->load_upgrader_dependencies();
		$this->init_filesystem();

		// Buffer para o Core_Upgrader não imprimir HTML no buffer do navegador.
		ob_start();

		if ( function_exists( 'wp_version_check' ) ) {
			wp_version_check();
		}

		$core = function_exists( 'get_core_updates' ) ? get_core_updates() : array();

		if ( empty( $core ) || ! is_array( $core ) || 'upgrade' !== $core[0]->response ) {
			ob_end_clean();
			return array(
				'updated' => false,
				'message' => __( 'O WordPress já está na versão mais recente.', 'dd-maintenance' ),
			);
		}

		if ( ! class_exists( 'Core_Upgrader' ) ) {
			ob_end_clean();
			return new WP_Error( 'updater_missing', __( 'Componente de atualização do core do WordPress não carregado.', 'dd-maintenance' ) );
		}

		$skin     = class_exists( 'Automatic_Upgrader_Skin' ) ? new Automatic_Upgrader_Skin() : null;
		$upgrader = new Core_Upgrader( $skin );
		$result   = $upgrader->upgrade( $core[0] );

		ob_end_clean();

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'core_upgrade', $result->get_error_message() );
		}

		if ( false === $result || null === $result ) {
			if ( $skin && method_exists( $skin, 'get_errors' ) && is_wp_error( $skin->get_errors() ) ) {
				return new WP_Error( 'core_upgrade', $skin->get_errors()->get_error_message() );
			}
			return new WP_Error( 'core_upgrade', __( 'Não foi possível atualizar o core do WordPress.', 'dd-maintenance' ) );
		}

		// O Core_Upgrader::upgrade() retorna a string da versão atualizada no sucesso (ex: "6.6.1")
		$version = '';
		if ( is_string( $result ) && '' !== $result ) {
			$version = $result;
		} elseif ( is_object( $result ) && isset( $result->version ) ) {
			$version = (string) $result->version;
		} elseif ( ! empty( $core[0]->version ) ) {
			$version = (string) $core[0]->version;
		} elseif ( function_exists( 'get_bloginfo' ) ) {
			$version = get_bloginfo( 'version' );
		}

		return array(
			'updated' => true,
			'message' => sprintf(
				/* translators: %s: Versão do WordPress */
				__( 'WordPress atualizado com sucesso para a versão %s.', 'dd-maintenance' ),
				$version ? $version : 'mais recente'
			),
		);
	}
}

<?php
/**
 * Gerenciador de travas e constantes do wp-config.php com proteção por senha.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Config {

	const OPTION_PASSWORD_HASH        = 'dd_maintenance_password_hash';
	const LEGACY_OPTION_PASSWORD_HASH = 'dd_gerenciador_updates_password_hash';

	const NONCE_ACTION_SET_PASSWORD = 'dd_maintenance_set_password';
	const NONCE_ACTION_SAVE_CONFIG  = 'dd_maintenance_save_config';

	/**
	 * Retorna o hash da senha configurada (com fallback para versão legada).
	 *
	 * @return string
	 */
	public static function get_password_hash(): string {
		$hash = get_option( self::OPTION_PASSWORD_HASH, '' );

		if ( is_string( $hash ) && '' !== $hash ) {
			return $hash;
		}

		// Fallback para opção do plugin legado.
		$legacy_hash = get_option( self::LEGACY_OPTION_PASSWORD_HASH, '' );
		if ( is_string( $legacy_hash ) && '' !== $legacy_hash ) {
			update_option( self::OPTION_PASSWORD_HASH, $legacy_hash, false );
			return $legacy_hash;
		}

		return '';
	}

	/**
	 * Verifica se já existe uma senha cadastrada.
	 *
	 * @return bool
	 */
	public static function has_password(): bool {
		$hash = self::get_password_hash();
		return '' !== $hash;
	}

	/**
	 * Valida uma senha informada contra o hash armazenado.
	 *
	 * @param string $password Senha em texto puro.
	 * @return bool
	 */
	public static function verify_password( string $password ): bool {
		$hash = self::get_password_hash();
		if ( '' === $hash ) {
			return false;
		}

		return (bool) wp_check_password( $password, $hash );
	}

	/**
	 * Define ou atualiza a senha de proteção.
	 *
	 * @param string $new_password Nova senha.
	 * @return void
	 */
	public static function set_password( string $new_password ): void {
		update_option( self::OPTION_PASSWORD_HASH, wp_hash_password( $new_password ), false );
	}

	/**
	 * Processa submissão de formulários relacionados ao wp-config e senhas.
	 *
	 * @return array|null Mensagem com tipo ('success'|'error') ou null se nenhuma ação.
	 */
	public static function handle_post(): ?array {
		if ( empty( $_POST['dd_maintenance_config_action'] ) ) {
			return null;
		}

		$action = sanitize_key( wp_unslash( $_POST['dd_maintenance_config_action'] ) );

		if ( 'set_password' === $action ) {
			return self::handle_set_password();
		}

		if ( 'save_config' === $action ) {
			return self::handle_save_config();
		}

		return array(
			'type'    => 'error',
			'message' => __( 'Ação inválida.', 'dd-maintenance' ),
		);
	}

	/**
	 * Trata a criação ou alteração da senha.
	 *
	 * @return array
	 */
	public static function handle_set_password(): array {
		check_admin_referer( self::NONCE_ACTION_SET_PASSWORD );

		if ( ! current_user_can( 'manage_options' ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Sem permissão para executar esta ação.', 'dd-maintenance' ),
			);
		}

		$has_password = self::has_password();

		if ( $has_password ) {
			$current_password = self::read_post_string( 'dd_maint_current_password' );

			if ( ! self::verify_password( $current_password ) ) {
				return array(
					'type'    => 'error',
					'message' => __( 'Senha atual incorreta.', 'dd-maintenance' ),
				);
			}
		}

		$new_password     = self::read_post_string( 'dd_maint_new_password' );
		$confirm_password = self::read_post_string( 'dd_maint_confirm_password' );

		if ( strlen( $new_password ) < 6 ) {
			return array(
				'type'    => 'error',
				'message' => __( 'A senha precisa ter pelo menos 6 caracteres.', 'dd-maintenance' ),
			);
		}

		if ( $new_password !== $confirm_password ) {
			return array(
				'type'    => 'error',
				'message' => __( 'As senhas não conferem.', 'dd-maintenance' ),
			);
		}

		self::set_password( $new_password );

		return array(
			'type'    => 'success',
			'message' => $has_password ? __( 'Senha atualizada com sucesso.', 'dd-maintenance' ) : __( 'Senha criada com sucesso.', 'dd-maintenance' ),
		);
	}

	/**
	 * Trata a alteração das constantes no wp-config.php.
	 *
	 * @return array
	 */
	public static function handle_save_config(): array {
		check_admin_referer( self::NONCE_ACTION_SAVE_CONFIG );

		if ( ! current_user_can( 'manage_options' ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Sem permissão para alterar o wp-config.php.', 'dd-maintenance' ),
			);
		}

		if ( ! self::has_password() ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Crie uma senha de proteção antes de alterar o wp-config.php.', 'dd-maintenance' ),
			);
		}

		$password = self::read_post_string( 'dd_maint_password' );

		if ( ! self::verify_password( $password ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Senha incorreta.', 'dd-maintenance' ),
			);
		}

		$result = self::update_wp_config(
			array(
				'DISALLOW_FILE_MODS' => self::read_post_bool( 'dd_maint_file_mods' ),
				'DISALLOW_FILE_EDIT' => self::read_post_bool( 'dd_maint_file_edit' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'type'    => 'error',
				'message' => $result->get_error_message(),
			);
		}

		return array(
			'type'    => 'success',
			'message' => sprintf(
				/* translators: %s: Nome do arquivo de backup do wp-config.php */
				__( 'wp-config.php atualizado com sucesso! Backup de segurança criado em: %s', 'dd-maintenance' ),
				basename( $result['backup_path'] )
			),
		);
	}

	/**
	 * Lê o status atual do wp-config.php e os valores das constantes.
	 *
	 * @return array
	 */
	public static function get_wp_config_status(): array {
		$config_path = self::find_wp_config_path();

		if ( is_wp_error( $config_path ) ) {
			return array(
				'path'     => null,
				'writable' => false,
				'error'    => $config_path->get_error_message(),
				'values'   => array(),
			);
		}

		$writable = is_writable( $config_path );
		$contents = file_get_contents( $config_path );

		if ( false === $contents ) {
			return array(
				'path'     => $config_path,
				'writable' => $writable,
				'error'    => __( 'Não foi possível ler o wp-config.php.', 'dd-maintenance' ),
				'values'   => array(),
			);
		}

		return array(
			'path'     => $config_path,
			'writable' => $writable,
			'error'    => null,
			'values'   => array(
				'DISALLOW_FILE_MODS' => self::read_constant_value( $contents, 'DISALLOW_FILE_MODS' ),
				'DISALLOW_FILE_EDIT' => self::read_constant_value( $contents, 'DISALLOW_FILE_EDIT' ),
			),
		);
	}

	/**
	 * Atualiza constantes no wp-config.php com backup e trava de arquivo.
	 *
	 * @param array $values Mapa de constante => bool.
	 * @return array|WP_Error
	 */
	public static function update_wp_config( array $values ) {
		$config_path = self::find_wp_config_path();

		if ( is_wp_error( $config_path ) ) {
			return $config_path;
		}

		if ( ! is_writable( $config_path ) ) {
			return new WP_Error( 'dd_config_not_writable', __( 'O wp-config.php não tem permissão de escrita para o PHP.', 'dd-maintenance' ) );
		}

		$contents = file_get_contents( $config_path );

		if ( false === $contents ) {
			return new WP_Error( 'dd_config_read_failed', __( 'Não foi possível ler o arquivo wp-config.php.', 'dd-maintenance' ) );
		}

		$updated_contents = $contents;
		$missing          = array();

		foreach ( $values as $constant => $value ) {
			$line    = self::build_define_line( $constant, (bool) $value );
			$pattern = self::build_define_pattern( $constant );

			if ( preg_match( $pattern, $updated_contents ) ) {
				$updated_contents = preg_replace( $pattern, $line, $updated_contents, 1 );
			} else {
				$missing[] = $line;
			}
		}

		if ( ! empty( $missing ) ) {
			$block            = "\n// DD Maintenance\n" . implode( "\n", $missing ) . "\n";
			$updated_contents = self::insert_before_wp_settings( $updated_contents, $block );
		}

		if ( $updated_contents === $contents ) {
			return new WP_Error( 'dd_config_no_changes', __( 'Nenhuma alteração foi necessária (valores idênticos).', 'dd-maintenance' ) );
		}

		$backup_path = $config_path . '.dd-backup-' . gmdate( 'Ymd-His' );

		if ( false === copy( $config_path, $backup_path ) ) {
			return new WP_Error( 'dd_config_backup_failed', __( 'Não foi possível criar o backup de segurança do wp-config.php.', 'dd-maintenance' ) );
		}

		$result = file_put_contents( $config_path, $updated_contents, LOCK_EX );

		if ( false === $result ) {
			@copy( $backup_path, $config_path );
			return new WP_Error( 'dd_config_write_failed', __( 'Não foi possível gravar no wp-config.php. O backup original foi restaurado.', 'dd-maintenance' ) );
		}

		return array(
			'path'        => $config_path,
			'backup_path' => $backup_path,
		);
	}

	/**
	 * Localiza o caminho absoluto do wp-config.php.
	 *
	 * @return string|WP_Error
	 */
	public static function find_wp_config_path() {
		$paths = array(
			ABSPATH . 'wp-config.php',
			dirname( ABSPATH ) . '/wp-config.php',
		);

		foreach ( $paths as $path ) {
			if ( file_exists( $path ) && is_readable( $path ) ) {
				return $path;
			}
		}

		return new WP_Error( 'dd_config_not_found', __( 'Não foi possível localizar o wp-config.php.', 'dd-maintenance' ) );
	}

	/**
	 * Insere bloco antes da linha de require do wp-settings.php ou no final.
	 *
	 * @param string $contents Conteúdo atual.
	 * @param string $block    Bloco de código a inserir.
	 * @return string
	 */
	private static function insert_before_wp_settings( string $contents, string $block ): string {
		$pattern = '/\n\s*require_once\s+ABSPATH\s*\.\s*[\'"]wp-settings\.php[\'"]\s*;/i';

		if ( preg_match( $pattern, $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
			$offset = $matches[0][1];
			return substr( $contents, 0, $offset ) . $block . substr( $contents, $offset );
		}

		return rtrim( $contents ) . $block . "\n";
	}

	/**
	 * Lê o valor booleano de uma constante definida no wp-config.php.
	 *
	 * @param string $contents Conteúdo do arquivo.
	 * @param string $constant Nome da constante.
	 * @return bool|null
	 */
	public static function read_constant_value( string $contents, string $constant ): ?bool {
		if ( preg_match( self::build_define_pattern( $constant ), $contents, $matches ) ) {
			return 'true' === strtolower( $matches[2] );
		}

		return null;
	}

	/**
	 * Constrói regex para localizar define de constante booleana.
	 *
	 * @param string $constant Nome da constante.
	 * @return string
	 */
	private static function build_define_pattern( string $constant ): string {
		return '/define\s*\(\s*([\'"])' . preg_quote( $constant, '/' ) . '\1\s*,\s*(true|false)\s*\)\s*;/i';
	}

	/**
	 * Constrói a linha de define formatada.
	 *
	 * @param string $constant Nome da constante.
	 * @param bool   $value    Valor booleano.
	 * @return string
	 */
	private static function build_define_line( string $constant, bool $value ): string {
		return "define( '" . $constant . "', " . ( $value ? 'true' : 'false' ) . ' );';
	}

	/**
	 * Lê e limpa string de POST.
	 *
	 * @param string $key Chave POST.
	 * @return string
	 */
	private static function read_post_string( string $key ): string {
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}

		return trim( (string) wp_unslash( $_POST[ $key ] ) );
	}

	/**
	 * Lê booleano de POST.
	 *
	 * @param string $key Chave POST.
	 * @return bool
	 */
	private static function read_post_bool( string $key ): bool {
		return 'true' === self::read_post_string( $key );
	}

	/**
	 * Formata o caminho do arquivo para exibição.
	 *
	 * @param array $status Array retornado por get_wp_config_status().
	 * @return string
	 */
	public static function format_status_path( array $status ): string {
		if ( ! empty( $status['error'] ) ) {
			return $status['error'];
		}

		$path = (string) $status['path'];
		if ( ! empty( $status['writable'] ) ) {
			return $path . ' (' . __( 'gravável', 'dd-maintenance' ) . ')';
		}

		return $path . ' (' . __( 'somente leitura', 'dd-maintenance' ) . ')';
	}

	/**
	 * Formata o status da constante para exibição.
	 *
	 * @param array  $status   Array de status.
	 * @param string $constant Nome da constante.
	 * @return string
	 */
	public static function format_constant_status( array $status, string $constant ): string {
		$value = self::get_status_value( $status, $constant );

		if ( null === $value ) {
			return __( 'Não encontrado no wp-config.php (padrão do WordPress: liberado)', 'dd-maintenance' );
		}

		return $value ? __( 'true - BLOQUEADO', 'dd-maintenance' ) : __( 'false - LIBERADO', 'dd-maintenance' );
	}

	/**
	 * Retorna o valor de uma constante no array de status.
	 *
	 * @param array  $status   Array de status.
	 * @param string $constant Nome da constante.
	 * @return bool|null
	 */
	public static function get_status_value( array $status, string $constant ): ?bool {
		return $status['values'][ $constant ] ?? null;
	}

	/**
	 * Retorna o valor booleano para o <select>.
	 *
	 * @param array  $status   Array de status.
	 * @param string $constant Nome da constante.
	 * @return bool
	 */
	public static function get_select_value( array $status, string $constant ): bool {
		return self::get_status_value( $status, $constant ) ?? false;
	}

	/**
	 * Atualiza $table_prefix no wp-config.php de forma atômica e segura.
	 *
	 * @param string $new_prefix Novo prefixo de tabelas (ex: 'wp_').
	 * @return bool|WP_Error
	 */
	public static function update_table_prefix( string $new_prefix ) {
		$config_path = self::find_wp_config_path();
		if ( is_wp_error( $config_path ) ) {
			return $config_path;
		}

		if ( ! is_writable( $config_path ) ) {
			return new WP_Error( 'dd_config_not_writable', __( 'O wp-config.php não tem permissão de escrita.', 'dd-maintenance' ) );
		}

		$contents = file_get_contents( $config_path );
		if ( false === $contents ) {
			return new WP_Error( 'dd_config_read_failed', __( 'Não foi possível ler o wp-config.php.', 'dd-maintenance' ) );
		}

		$pattern = '/(\$table_prefix\s*=\s*[\'"])(.*?)([\'"]\s*;)/i';
		if ( preg_match( $pattern, $contents, $matches ) ) {
			if ( $matches[2] === $new_prefix ) {
				return true;
			}
			$updated = preg_replace( $pattern, '${1}' . addcslashes( $new_prefix, '\\$' ) . '${3}', $contents, 1 );
		} else {
			return false;
		}

		$backup_path = $config_path . '.dd-backup-prefix-' . gmdate( 'Ymd-His' );
		@copy( $config_path, $backup_path );

		$result = file_put_contents( $config_path, $updated, LOCK_EX );
		if ( false === $result ) {
			@copy( $backup_path, $config_path );
			return new WP_Error( 'dd_config_write_failed', __( 'Falha ao salvar prefixo no wp-config.php.', 'dd-maintenance' ) );
		}

		return true;
	}
}

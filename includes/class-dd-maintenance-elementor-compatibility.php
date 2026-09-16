<?php
/**
 * Compatibilidade explícita e reversível do Elementor.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Elementor_Compatibility {

	const PATCH_VERSION = '1.0.0';
	const HISTORY_OPTION = 'dd_maintenance_elementor_patch_history';
	const LOG_OPTION = 'dd_maintenance_elementor_patch_log';

	/**
	 * Aplica o patch somente quando o operador autorizou a compatibilidade.
	 *
	 * @param bool $enabled Decisão explícita do fluxo de restauração.
	 * @return array
	 */
	public static function apply_restore_decision( bool $enabled ): array {
		if ( ! $enabled ) {
			return array( 'status' => 'skipped', 'version' => self::PATCH_VERSION );
		}

		$result = self::apply();
		self::install_shield();
		return $result;
	}

	/**
	 * Procura e corrige somente arquivos Elementor reconhecidos.
	 *
	 * @param array|null $files Arquivos explícitos; null procura instalações conhecidas.
	 * @return array
	 */
	public static function apply( ?array $files = null ): array {
		$files = null === $files ? self::candidate_files() : $files;
		$results = array();
		$seen = array();

		foreach ( $files as $file ) {
			$normalized = self::normalize_file( $file );
			if ( '' === $normalized || isset( $seen[ $normalized ] ) ) {
				continue;
			}
			$seen[ $normalized ] = true;
			$results[] = self::patch_file( $normalized );
		}

		if ( empty( $results ) ) {
			$result = array(
				'status'  => 'not_found',
				'version' => self::PATCH_VERSION,
			);
			self::record( $result );
			return $result;
		}

		$patched = false;
		foreach ( $results as $result ) {
			if ( in_array( $result['status'] ?? '', array( 'patched', 'already_patched' ), true ) ) {
				$patched = true;
				break;
			}
		}

		$summary = array(
			'status'  => $patched ? 'completed' : 'rejected',
			'version' => self::PATCH_VERSION,
			'files'   => $results,
		);
		self::record( $summary );
		return $summary;
	}

	/**
	 * Desfaz o último patch verificado de um arquivo.
	 *
	 * @param string $file Arquivo opcional.
	 * @return array
	 */
	public static function undo( string $file = '' ): array {
		$history = function_exists( 'get_option' ) ? get_option( self::HISTORY_OPTION, array() ) : array();
		$history = is_array( $history ) ? array_reverse( $history ) : array();
		$target = self::normalize_file( $file );

		foreach ( $history as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['backup'] ) || ( $target && $target !== ( $entry['file'] ?? '' ) ) ) {
				continue;
			}
			$backup = self::normalize_file( $entry['backup'] );
			if ( '' === $backup || ! is_file( $backup ) || hash_file( 'sha256', $backup ) !== ( $entry['backup_checksum'] ?? '' ) ) {
				continue;
			}
			if ( ! self::is_allowed_file( $entry['file'] ?? '' ) || is_link( $entry['file'] ) ) {
				continue;
			}
			if ( ! self::atomic_copy( $backup, $entry['file'] ) ) {
				$result = array( 'status' => 'undo_failed', 'file' => $entry['file'] );
				self::record( $result );
				return $result;
			}
			$result = array(
				'status'  => 'undone',
				'file'    => $entry['file'],
				'version' => $entry['version'] ?? self::PATCH_VERSION,
			);
			self::record( $result );
			return $result;
		}

		$result = array( 'status' => 'undo_unavailable', 'version' => self::PATCH_VERSION );
		self::record( $result );
		return $result;
	}

	/**
	 * Instala o shield próprio sem tocar em arquivos de terceiros.
	 *
	 * @return bool
	 */
	public static function install_shield(): bool {
		$directory = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/mu-plugins' : '' );
		if ( '' === $directory || ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) ) {
			return false;
		}
		$file = rtrim( wp_normalize_path( $directory ), '/' ) . '/dd-elementor-compat.php';
		$source = "<?php\n/** Plugin Name: DD Maintenance - Elementor Compatibility Shield */\n";
		$source .= "defined( 'ABSPATH' ) || exit;\n";
		$source .= "if ( ! function_exists( 'dd_maintenance_elementor_shield' ) ) {\n";
		$source .= "function dd_maintenance_elementor_shield( \$content ) {\n";
		$source .= "if ( ! is_string( \$content ) || false === strpos( \$content, '[elementor-tag' ) ) return \$content;\n";
		$source .= "return preg_replace_callback( '/\\[elementor-tag\\s+([^\\]]+)\\]/i', static function( \$m ) {\n";
		$source .= "if ( preg_match( '/\\bsettings\\s*=\\s*[\\\"\\\\\']([^\\\"\\\\\']*)[\\\"\\\\\']/i', \$m[1], \$s ) && '' !== trim( \$s[1] ) && 'null' !== strtolower( trim( \$s[1] ) ) ) return \$m[0];\n";
		$source .= "\$attrs = preg_replace( '/\\s+settings\\s*=\\s*[\\\"\\\\\'][^\\\"\\\\\']*[\\\"\\\\\']/i', '', \$m[1] );\n";
		$source .= "return '[elementor-tag ' . trim( \$attrs ) . ' settings=\"%7B%7D\"]'; }, \$content );\n";
		$source .= "}\nadd_filter( 'the_content', 'dd_maintenance_elementor_shield', 1 );\nadd_filter( 'widget_text', 'dd_maintenance_elementor_shield', 1 );\n}\n";
		$source .= "/* DD_MAINTENANCE_ELEMENTOR_SHIELD_VERSION:" . self::PATCH_VERSION . " */\n";
		if ( is_file( $file ) && hash_file( 'sha256', $file ) === hash( 'sha256', $source ) ) {
			return true;
		}
		return self::atomic_write( $file, $source );
	}

	/**
	 * Compatibilidade de API para chamadores antigos.
	 *
	 * @return bool
	 */
	public static function patch_elementor_php8_compatibility(): bool {
		$result = self::apply();
		return in_array( $result['status'] ?? '', array( 'completed', 'already_patched' ), true );
	}

	/**
	 * Compatibilidade de API para o shield legado.
	 *
	 * @return bool
	 */
	public static function install_permanent_elementor_shield(): bool {
		return self::install_shield();
	}

	/**
	 * Normaliza tags dinâmicas antes do parser do Elementor.
	 *
	 * @param mixed $content Conteúdo a normalizar.
	 * @return mixed
	 */
	public static function fix_elementor_dynamic_tags( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, '[elementor-tag' ) ) {
			return $content;
		}
		$trimmed = trim( $content );
		if ( '' !== $trimmed && ( '{' === $trimmed[0] || '[' === $trimmed[0] ) ) {
			$json = json_decode( $trimmed, true );
			if ( null !== $json && JSON_ERROR_NONE === json_last_error() ) {
				array_walk_recursive(
					$json,
					static function ( &$item ) {
						if ( is_string( $item ) && false !== strpos( $item, '[elementor-tag' ) ) {
							$item = self::fix_elementor_dynamic_tags_string( $item );
						}
					}
				);
				return wp_json_encode( $json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
		}
		return self::fix_elementor_dynamic_tags_string( $content );
	}

	/**
	 * @param string $content Conteúdo contendo tag.
	 * @return string
	 */
	public static function fix_elementor_dynamic_tags_string( string $content ): string {
		return preg_replace_callback(
			'/\[elementor-tag\s+((?:\\"[^\\"]*\\"|[^\]])+)\]/i',
			static function ( $matches ) {
				$attrs = $matches[1];
				if ( preg_match( '/\bsettings\s*=\s*(?:\\"([^\\"]*)\\"|"([^"]*)")/i', $attrs, $settings ) ) {
					$value = isset( $settings[2] ) && '' !== $settings[2] ? $settings[2] : ( $settings[1] ?? '' );
					if ( '' !== trim( $value ) && 'null' !== strtolower( trim( $value ) ) && '%7B%7D' !== $value ) {
						return $matches[0];
					}
				}
				$attrs = trim( preg_replace( '/\bsettings\s*=\s*(?:\\"[^\\"]*\\"|"[^"]*")/i', '', $attrs ) );
				return '[elementor-tag ' . $attrs . ' settings="%7B%7D"]';
			},
			$content
		);
	}

	/**
	 * @return array
	 */
	private static function candidate_files(): array {
		$roots = array();
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$roots[] = WP_PLUGIN_DIR;
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$roots[] = WP_CONTENT_DIR . '/plugins';
		}
		$files = array();
		foreach ( $roots as $root ) {
			$root = rtrim( wp_normalize_path( $root ), '/' );
			$files[] = $root . '/elementor/core/dynamic-tags/manager.php';
			$files[] = $root . '/pro-elements/core/dynamic-tags/manager.php';
			$found = glob( $root . '/*elementor*/core/dynamic-tags/manager.php' );
			if ( is_array( $found ) ) {
				$files = array_merge( $files, $found );
			}
		}
		return $files;
	}

	/**
	 * @param string $file Caminho candidato.
	 * @return array
	 */
	private static function patch_file( string $file ): array {
		if ( ! self::is_allowed_file( $file ) ) {
			$result = array( 'status' => 'rejected_path', 'file' => $file );
			self::record( $result );
			return $result;
		}
		$content = file_get_contents( $file );
		if ( ! is_string( $content ) || '' === $content ) {
			$result = array( 'status' => 'unreadable', 'file' => $file );
			self::record( $result );
			return $result;
		}
		$checksum = hash( 'sha256', $content );
		if ( false !== strpos( $content, 'DD_MAINTENANCE_ELEMENTOR_PATCH_VERSION:' . self::PATCH_VERSION ) ) {
			$result = array( 'status' => 'already_patched', 'file' => $file, 'checksum_before' => $checksum, 'version' => self::PATCH_VERSION );
			self::record( $result );
			return $result;
		}
		if ( ! self::recognized_source( $content ) ) {
			$result = array( 'status' => 'rejected_checksum', 'file' => $file, 'checksum_before' => $checksum, 'version' => self::PATCH_VERSION );
			self::record( $result );
			return $result;
		}

		$backup = self::backup_path( $file, $checksum );
		if ( ! self::atomic_copy( $file, $backup ) ) {
			$result = array( 'status' => 'backup_failed', 'file' => $file, 'checksum_before' => $checksum );
			self::record( $result );
			return $result;
		}
		$patched = self::patch_source( $content );
		if ( ! is_string( $patched ) || $patched === $content || ! self::recognized_source( $patched, true ) ) {
			$result = array( 'status' => 'rejected_patch', 'file' => $file, 'checksum_before' => $checksum );
			self::record( $result );
			return $result;
		}
		if ( ! self::atomic_write( $file, $patched ) ) {
			$result = array( 'status' => 'write_failed', 'file' => $file, 'checksum_before' => $checksum );
			self::record( $result );
			return $result;
		}
		$history = function_exists( 'get_option' ) ? get_option( self::HISTORY_OPTION, array() ) : array();
		$history = is_array( $history ) ? $history : array();
		$entry = array(
			'file'             => $file,
			'backup'           => $backup,
			'version'          => self::PATCH_VERSION,
			'checksum_before'  => $checksum,
			'checksum_after'   => hash( 'sha256', $patched ),
			'backup_checksum'  => hash_file( 'sha256', $backup ),
			'result'           => 'patched',
			'created_at'       => time(),
		);
		$history[] = $entry;
		if ( function_exists( 'update_option' ) ) {
			update_option( self::HISTORY_OPTION, array_slice( $history, -20 ), false );
		}
		$result = array_merge( array( 'status' => 'patched' ), $entry );
		self::record( $result );
		return $result;
	}

	private static function recognized_source( string $content, bool $patched = false ): bool {
		if ( false === preg_match( '/namespace\s+Elementor\\\\Core\\\\DynamicTags/i', $content ) || false === preg_match( '/class\s+Manager\b/i', $content ) ) {
			return false;
		}
		$methods = preg_match_all( '/function\s+(?:create_tag|get_tag_data_content|get_tag_data_class)\s*\(\s*\$tag_id\s*,\s*\$tag_name\s*,\s*array\s+\$settings\b/i', $content, $matches );
		if ( ! $patched && 3 !== $methods ) {
			return false;
		}
		if ( $patched && ( false !== strpos( $content, 'array $settings' ) || false === strpos( $content, 'DD_MAINTENANCE_ELEMENTOR_PATCH_VERSION:' . self::PATCH_VERSION ) ) ) {
			return false;
		}
		foreach ( array( '$this->get_tag_data_class', '$this->create_tag', '$this->get_tag_class' ) as $fingerprint ) {
			if ( false === strpos( $content, $fingerprint ) ) {
				return false;
			}
		}
		return true;
	}

	private static function patch_source( string $content ): string {
		$patched = preg_replace( '/function\s+([a-zA-Z0-9_]+)\s*\(\s*(\$tag_id\s*,\s*\$tag_name\s*,)\s*array\s*(\$settings\b)/i', 'function $1( $2 $3', $content );
		if ( ! is_string( $patched ) ) {
			return $content;
		}
		if ( false === strpos( $patched, '$settings = is_array( $settings ) ? $settings : [];' ) ) {
			$patched = preg_replace( '/(public\s+function\s+create_tag\s*\([^)]*\)\s*\{)/i', "$1\n\t\t\$settings = is_array( \$settings ) ? \$settings : [];", $patched, 1 );
			$patched = preg_replace( '/(public\s+function\s+get_tag_data_content\s*\([^)]*\)\s*\{)/i', "$1\n\t\t\$settings = is_array( \$settings ) ? \$settings : [];", $patched, 1 );
		}
		return "/* DD_MAINTENANCE_ELEMENTOR_PATCH_VERSION:" . self::PATCH_VERSION . " */\n" . $patched;
	}

	private static function backup_path( string $file, string $checksum ): string {
		$root = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/uploads/dd-maintenance/elementor-backups' : dirname( $file );
		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
			return '';
		}
		if ( is_link( $root ) || false === realpath( $root ) || wp_normalize_path( realpath( $root ) ) !== wp_normalize_path( $root ) ) {
			return '';
		}
		return rtrim( wp_normalize_path( $root ), '/' ) . '/' . sanitize_file_name( basename( $file ) ) . '.' . substr( $checksum, 0, 16 ) . '.bak';
	}

	private static function is_allowed_file( string $file ): bool {
		$normalized = self::normalize_file( $file );
		if ( '' === $normalized || 'manager.php' !== basename( $normalized ) || ! preg_match( '#/(?:elementor|pro-elements)/core/dynamic-tags/manager\.php$#i', $normalized ) ) {
			return false;
		}
		$real = realpath( $normalized );
		if ( false === $real || is_link( $normalized ) ) {
			return false;
		}
		$roots = array();
		if ( defined( 'WP_PLUGIN_DIR' ) ) $roots[] = realpath( WP_PLUGIN_DIR );
		if ( defined( 'WP_CONTENT_DIR' ) ) $roots[] = realpath( WP_CONTENT_DIR . '/plugins' );
		foreach ( $roots as $root ) {
			if ( $root && ( $real === $root || 0 === strpos( wp_normalize_path( $real ), rtrim( wp_normalize_path( $root ), '/' ) . '/' ) ) ) return true;
		}
		return false;
	}

	private static function normalize_file( $file ): string {
		if ( ! is_string( $file ) || '' === $file || false !== strpos( $file, "\0" ) ) return '';
		return wp_normalize_path( $file );
	}

	private static function atomic_copy( string $source, string $target ): bool {
		if ( is_link( $source ) || ! is_file( $source ) ) return false;
		$parent      = dirname( $target );
		$parent_real = realpath( $parent );
		if ( is_link( $parent ) || false === $parent_real || wp_normalize_path( $parent_real ) !== wp_normalize_path( $parent ) ) return false;
		if ( is_link( $target ) ) return false;
		$temp = $target . '.tmp-' . wp_generate_password( 12, false );
		if ( is_link( $temp ) || ! copy( $source, $temp ) ) return false;
		return rename( $temp, $target );
	}

	private static function atomic_write( string $target, string $content ): bool {
		$parent      = dirname( $target );
		$parent_real = realpath( $parent );
		if ( is_link( $parent ) || false === $parent_real || wp_normalize_path( $parent_real ) !== wp_normalize_path( $parent ) ) return false;
		if ( is_link( $target ) ) return false;
		$temp = $target . '.tmp-' . wp_generate_password( 12, false );
		if ( is_link( $temp ) || false === file_put_contents( $temp, $content, LOCK_EX ) ) return false;
		return rename( $temp, $target );
	}

	private static function record( array $result ): void {
		$result['recorded_at'] = time();
		if ( function_exists( 'update_option' ) ) {
			$log = get_option( self::LOG_OPTION, array() );
			$log = is_array( $log ) ? $log : array();
			$log[] = $result;
			update_option( self::LOG_OPTION, array_slice( $log, -50 ), false );
		}
	}
}

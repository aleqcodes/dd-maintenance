<?php
/**
 * Integração com S3 compatível (DigitalOcean Spaces / S3 / Dokploy / MinIO) via Signature V4.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-dd-maintenance-settings-repository.php';
require_once __DIR__ . '/class-dd-maintenance-s3-endpoint.php';
require_once __DIR__ . '/class-dd-maintenance-s3-signer.php';
require_once __DIR__ . '/class-dd-maintenance-s3-response-parser.php';
require_once __DIR__ . '/class-dd-maintenance-s3-retry-policy.php';
require_once __DIR__ . '/class-dd-maintenance-s3-transport.php';


class DD_Maintenance_S3 {

	const EMPTY_PAYLOAD_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	/**
	 * Regiões conhecidas do DigitalOcean Spaces.
	 *
	 * @var string[]
	 */
	const KNOWN_REGIONS = array( 'nyc3', 'ams3', 'sfo3', 'sgp1', 'lon1', 'fra1', 'tor1', 'blr1', 'syd1' );

	/**
	 * Configurações do plugin.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Access key (Spaces / S3).
	 *
	 * @var string
	 */
	private $access_key;

	/**
	 * Secret key (Spaces / S3).
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Nome do bucket.
	 *
	 * @var string
	 */
	private $bucket;

	/**
	 * Região (ex.: nyc3, ams3, sfo3, us-east-1).
	 *
	 * @var string
	 */
	private $region;

	/**
	 * Endpoint customizado (opcional).
	 *
	 * @var string
	 */
	private $endpoint;

	/**
	 * Transporte de upload injetado para testes e integrações controladas.
	 *
	 * @var callable|null
	 */
	private $put_transport;

	/** @var DD_Maintenance_S3_Endpoint */
	private $endpoint_normalizer;

	/** @var DD_Maintenance_S3_Signer */
	private $signer;

	/** @var DD_Maintenance_S3_Response_Parser */
	private $response_parser;

	/** @var DD_Maintenance_S3_Retry_Policy */
	private $retry_policy;

	/** @var DD_Maintenance_S3_Transport */
	private $transport;

	/**
	 * Construtor.
	 *
	 * @param callable|null $put_transport Transporte opcional para o PUT.
	 */
	public function __construct( $put_transport = null ) {
		if ( null !== $put_transport && ! is_callable( $put_transport ) ) {
			throw new InvalidArgumentException( 'O transporte de upload S3 deve ser chamável.' );
		}
		$this->put_transport = $put_transport;

		$saved_settings = ( new DD_Maintenance_Settings_Repository() )->get();

		$this->settings   = is_array( $saved_settings ) ? $saved_settings : array();
		$this->access_key = isset( $this->settings['s3_access_key'] ) ? trim( (string) $this->settings['s3_access_key'] ) : '';
		$this->secret_key = isset( $this->settings['s3_secret_key'] ) ? trim( (string) $this->settings['s3_secret_key'] ) : '';
		$this->bucket     = isset( $this->settings['s3_bucket'] ) ? trim( (string) $this->settings['s3_bucket'] ) : '';
		$this->region     = isset( $this->settings['s3_region'] ) && '' !== trim( (string) $this->settings['s3_region'] ) ? trim( (string) $this->settings['s3_region'] ) : 'nyc3';
		$this->endpoint   = isset( $this->settings['s3_endpoint'] ) ? trim( (string) $this->settings['s3_endpoint'] ) : '';

		// Variáveis de ambiente são preferidas à opção do WordPress.
		if ( function_exists( 'getenv' ) ) {
			$env_access_key = getenv( 'DD_MAINTENANCE_S3_KEY' );
			$env_secret_key = getenv( 'DD_MAINTENANCE_S3_SECRET' );
			if ( false !== $env_access_key && '' !== trim( (string) $env_access_key ) ) {
				$this->access_key = trim( (string) $env_access_key );
			}
			if ( false !== $env_secret_key && '' !== trim( (string) $env_secret_key ) ) {
				$this->secret_key = trim( (string) $env_secret_key );
			}
		}

		// Constantes do wp-config.php têm a prioridade máxima.
		if ( defined( 'DD_MAINTENANCE_S3_KEY' ) && '' !== trim( (string) DD_MAINTENANCE_S3_KEY ) ) {
			$this->access_key = trim( (string) DD_MAINTENANCE_S3_KEY );
		}
		if ( defined( 'DD_MAINTENANCE_S3_SECRET' ) && '' !== trim( (string) DD_MAINTENANCE_S3_SECRET ) ) {
			$this->secret_key = trim( (string) DD_MAINTENANCE_S3_SECRET );
		}
		if ( defined( 'DD_MAINTENANCE_S3_BUCKET' ) && '' !== trim( (string) DD_MAINTENANCE_S3_BUCKET ) ) {
			$this->bucket = trim( (string) DD_MAINTENANCE_S3_BUCKET );
		}
		if ( defined( 'DD_MAINTENANCE_S3_REGION' ) && '' !== trim( (string) DD_MAINTENANCE_S3_REGION ) ) {
			$this->region = trim( (string) DD_MAINTENANCE_S3_REGION );
		}
		if ( defined( 'DD_MAINTENANCE_S3_ENDPOINT' ) && '' !== trim( (string) DD_MAINTENANCE_S3_ENDPOINT ) ) {
			$this->endpoint = trim( (string) DD_MAINTENANCE_S3_ENDPOINT );
		}
		$this->endpoint_normalizer = new DD_Maintenance_S3_Endpoint();
		$this->signer             = new DD_Maintenance_S3_Signer( $this->access_key, $this->secret_key, $this->region );
		$this->response_parser    = new DD_Maintenance_S3_Response_Parser();
		$this->retry_policy       = new DD_Maintenance_S3_Retry_Policy( 2 );
		$this->transport          = new DD_Maintenance_S3_Transport();
	}


	/**
	 * Verifica se as credenciais S3 foram informadas.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return ! empty( $this->access_key ) && ! empty( $this->secret_key ) && ! empty( $this->bucket );
	}

	/**
	 * Nome do bucket.
	 *
	 * @return string
	 */
	public function get_bucket() {
		return $this->bucket;
	}

	/**
	 * Região configurada.
	 *
	 * @return string
	 */
	public function get_region() {
		return $this->region;
	}

	/**
	 * Endpoint base completo do serviço S3.
	 *
	 * @param string|null $region Região a usar.
	 * @return string
	 */
	public function get_endpoint( $region = null ) {
		$region  = $region ? $region : $this->region;
		$endpoint = $this->endpoint_normalizer->normalize( $this->bucket, $region, (string) $this->endpoint );
		if ( '' === $endpoint ) {
			return 'https://' . $this->bucket . '.' . $region . '.digitaloceanspaces.com';
		}
		return $endpoint;
	}

	/**
	 * Retorna o cabeçalho Host correto (incluindo porta se não-padrão).
	 *
	 * @param string|null $endpoint URL do endpoint.
	 * @return string
	 */
	public function get_host( $endpoint = null ) {
		return $this->endpoint_normalizer->host( $endpoint ? $endpoint : $this->get_endpoint() );
	}

	/**
	 * Codifica a URI mantendo as barras.
	 *
	 * @param string $key Chave do objeto.
	 * @return string
	 */
	private function encode_uri( $key ) {
		return $this->endpoint_normalizer->object_uri( (string) $key );
	}

	/**
	 * Verifica se o bucket existe em uma região (probe anônimo para DigitalOcean Spaces).
	 *
	 * @param string $region Região a testar.
	 * @return string 'found' | 'moved' | 'notfound' | 'error'
	 */
	public function probe_region( $region ) {
		if ( ! empty( $this->endpoint ) ) {
			return 'found';
		}

		$url  = 'https://' . $this->bucket . '.' . $region . '.digitaloceanspaces.com/';
		$resp = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'sslverify'   => true,
			)
		);

		if ( is_wp_error( $resp ) ) {
			return 'error';
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );

		// 200 = bucket público, 403 = bucket existe (privado). Ambos indicam região correta.
		if ( 200 === $code || 403 === $code ) {
			return 'found';
		}

		// 301/302/307/308 = redirecionado para outra região.
		if ( in_array( $code, array( 301, 302, 307, 308 ), true ) ) {
			return 'moved';
		}

		return 'notfound';
	}

	/**
	 * Detecta automaticamente a região do bucket (DigitalOcean Spaces).
	 *
	 * @return string|WP_Error Região detectada ou erro.
	 */
	public function detect_region() {
		if ( ! empty( $this->endpoint ) ) {
			return $this->region;
		}

		// Testa primeiro a região configurada.
		$result = $this->probe_region( $this->region );
		if ( 'found' === $result ) {
			return $this->region;
		}

		foreach ( self::KNOWN_REGIONS as $region ) {
			if ( $region === $this->region ) {
				continue;
			}

			$result = $this->probe_region( $region );
			if ( 'found' === $result ) {
				return $region;
			}
		}

		return new WP_Error( 'region_detect', __( 'Não foi possível localizar o bucket em nenhuma região do DigitalOcean Spaces.', 'dd-maintenance' ) );
	}

	/**
	 * Garante que a região configurada é a correta, detectando e salvando se necessário.
	 *
	 * @return true|WP_Error
	 */
	public function ensure_region() {
		if ( ! empty( $this->endpoint ) ) {
			return true;
		}

		$result = $this->probe_region( $this->region );
		if ( 'found' === $result ) {
			return true;
		}

		$detected = $this->detect_region();
		if ( is_wp_error( $detected ) ) {
			return $detected;
		}

		$this->region                = $detected;
		$this->signer                = new DD_Maintenance_S3_Signer( $this->access_key, $this->secret_key, $this->region );
		$this->settings['s3_region'] = $detected;
		update_option( 'dd_maintenance_settings', $this->settings, false );

		return true;
	}

	/**
	 * Gera os cabeçalhos assinados (SigV4) padronizados e seguros para S3.
	 *
	 * @param string $method       Método HTTP (PUT, GET, HEAD, etc.).
	 * @param string $uri          URI canônica (ex.: /pasta/arquivo.zip).
	 * @param string $query        Query string canônica (sem '?').
	 * @param string $payload_hash Hash SHA256 do corpo (ou EMPTY_PAYLOAD_HASH).
	 * @param array  $extra_headers Cabeçalhos extras opcionais (ex: x-amz-acl).
	 * @return array
	 */
	private function sign_request( $method, $uri, $query, $payload_hash, $extra_headers = array() ) {
		return $this->signer->sign(
			(string) $method,
			(string) $uri,
			(string) $query,
			(string) $payload_hash,
			$this->get_host(),
			is_array( $extra_headers ) ? $extra_headers : array()
		);
	}

	/**
	 * Envia um arquivo para o bucket S3 / Spaces.
	 *
	 * @param string $key          Chave do objeto (ex.: site/2026-08-20/arquivo.zip).
	 * @param string $file_path    Caminho local do arquivo.
	 * @param string $content_type Tipo de conteúdo.
	 * @return array|WP_Error
	 */
	public function put_object( $key, $file_path, $content_type = 'application/zip' ) {
		$this->record_s3_event( 'request_started', 'put_object', 'running' );
		if ( ! $this->is_configured() ) {
			return $this->finish_s3_event( 'put_object', new WP_Error( 's3_config', __( 'Configure as credenciais do S3 / DigitalOcean Spaces.', 'dd-maintenance' ) ) );
		}
		if ( ! file_exists( $file_path ) ) {
			return $this->finish_s3_event( 'put_object', new WP_Error( 'file_missing', __( 'Arquivo de backup não encontrado.', 'dd-maintenance' ) ) );
		}

		$region_ok = $this->ensure_region();
		if ( is_wp_error( $region_ok ) ) {
			return $this->finish_s3_event( 'put_object', $region_ok );
		}

		$size = filesize( $file_path );
		if ( false === $size ) {
			return $this->finish_s3_event( 'put_object', new WP_Error( 'file_size', __( 'Não foi possível determinar o tamanho do arquivo para upload.', 'dd-maintenance' ) ) );
		}
		$payload_hash = hash_file( 'sha256', $file_path );
		if ( false === $payload_hash ) {
			return $this->finish_s3_event( 'put_object', new WP_Error( 'file_hash', __( 'Não foi possível calcular a integridade do arquivo para upload.', 'dd-maintenance' ) ) );
		}
		$uri          = $this->encode_uri( $key );
		$auth         = $this->sign_request( 'PUT', $uri, '', $payload_hash );
		$headers      = array(
			'Host'                 => $auth['Host'],
			'Authorization'        => $auth['Authorization'],
			'x-amz-content-sha256' => $auth['x-amz-content-sha256'],
			'x-amz-date'           => $auth['x-amz-date'],
			'Content-Type'         => $content_type,
			'Content-Length'       => (string) $size,
		);
		$endpoint_url = $this->get_endpoint() . $uri;
		$result       = $this->stream_put( $endpoint_url, $headers, $file_path, $size );
		if ( $this->retry_policy->should_retry( $result, 1, true ) ) {
			$result = $this->stream_put( $endpoint_url, $headers, $file_path, $size );
		}
		if ( is_wp_error( $result ) ) {
			return $this->finish_s3_event( 'put_object', $result );
		}
		return $this->finish_s3_event(
			'put_object',
			array(
				'key'  => $key,
				'etag' => isset( $result['etag'] ) ? $result['etag'] : '',
			)
		);
	}

	/**
	 * Exclui um objeto do bucket S3 / Spaces.
	 *
	 * @param string $key Chave do objeto no bucket.
	 * @return true|WP_Error
	 */
	public function delete_object( string $key ) {
		$this->record_s3_event( 'request_started', 'delete_object', 'running' );
		if ( ! $this->is_configured() ) {
			return $this->finish_s3_event( 'delete_object', new WP_Error( 's3_config', __( 'Configure as credenciais do S3 / DigitalOcean Spaces.', 'dd-maintenance' ) ) );
		}
		if ( ! $this->is_safe_remote_key( $key ) ) {
			return $this->finish_s3_event( 'delete_object', new WP_Error( 's3_key_invalid', __( 'A chave remota solicitada é inválida.', 'dd-maintenance' ) ) );
		}
		$region_ok = $this->ensure_region();
		if ( is_wp_error( $region_ok ) ) {
			return $this->finish_s3_event( 'delete_object', $region_ok );
		}
		$uri     = $this->encode_uri( $key );
		$auth    = $this->sign_request( 'DELETE', $uri, '', self::EMPTY_PAYLOAD_HASH );
		$headers = array(
			'Host'                 => $auth['Host'],
			'Authorization'        => $auth['Authorization'],
			'x-amz-content-sha256' => $auth['x-amz-content-sha256'],
			'x-amz-date'           => $auth['x-amz-date'],
		);
		$response = $this->transport->request( $this->get_endpoint() . $uri, 'DELETE', $headers, '', 30 );
		if ( is_wp_error( $response ) ) {
			return $this->finish_s3_event( 'delete_object', $response );
		}
		$parsed = $this->response_parser->parse( $response, 'delete' );
		return $this->finish_s3_event( 'delete_object', is_wp_error( $parsed ) ? $parsed : true );
	}
	/**
	 * @param string $prefix Prefixo de busca (ex: site-name/).
	 * @param int    $max_keys Limite total de objetos; zero lista todas as páginas.
	 * @return array|WP_Error Array de objetos com key, size, last_modified.
	 */
	public function list_objects( string $prefix = '', int $max_keys = 0 ) {
		$this->record_s3_event( 'request_started', 'list_objects', 'running' );
		if ( ! $this->is_configured() ) {
			return $this->finish_s3_event( 'list_objects', new WP_Error( 's3_config', __( 'Configure as credenciais do S3 / DigitalOcean Spaces.', 'dd-maintenance' ) ) );
		}

		$region_ok = $this->ensure_region();
		if ( is_wp_error( $region_ok ) ) {
			return $this->finish_s3_event( 'list_objects', $region_ok );
		}

		$objects            = array();
		$continuation_token = '';
		$remaining          = $max_keys > 0 ? $max_keys : PHP_INT_MAX;

		do {
			$query_params = array(
				'list-type' => '2',
				'max-keys'  => (string) min( 1000, $remaining ),
			);
			if ( '' !== $prefix ) {
				$query_params['prefix'] = $prefix;
			}
			if ( '' !== $continuation_token ) {
				$query_params['continuation-token'] = $continuation_token;
			}
			ksort( $query_params );

			$query_parts = array();
			foreach ( $query_params as $k => $v ) {
				$query_parts[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
			}
			$query_string = implode( '&', $query_parts );

			$uri  = '/';
			$auth = $this->sign_request( 'GET', $uri, $query_string, self::EMPTY_PAYLOAD_HASH );

			$headers = array(
				'Host'                 => $auth['Host'],
				'Authorization'        => $auth['Authorization'],
				'x-amz-content-sha256' => $auth['x-amz-content-sha256'],
				'x-amz-date'           => $auth['x-amz-date'],
			);

			$url      = $this->get_endpoint() . $uri . '?' . $query_string;
			$response = $this->transport->request( $url, 'GET', $headers, '', 30 );
			if ( is_wp_error( $response ) ) {
				return $this->finish_s3_event( 'list_objects', $response );
			}
			$parsed = $this->response_parser->parse( $response, 'list' );
			if ( is_wp_error( $parsed ) ) {
				return $this->finish_s3_event( 'list_objects', $parsed );
			}
			$body = $parsed['body'];

			if ( preg_match_all( '/<Contents>(.*?)<\/Contents>/s', $body, $matches ) ) {
				foreach ( $matches[1] as $content_xml ) {
					$key           = preg_match( '/<Key>(.*?)<\/Key>/s', $content_xml, $k ) ? html_entity_decode( trim( $k[1] ), ENT_QUOTES | ENT_XML1, 'UTF-8' ) : '';
					$size          = preg_match( '/<Size>(.*?)<\/Size>/s', $content_xml, $s ) ? (int) $s[1] : 0;
					$last_modified = preg_match( '/<LastModified>(.*?)<\/LastModified>/s', $content_xml, $lm ) ? trim( $lm[1] ) : '';

					if ( '' !== $key ) {
						$objects[] = array(
							'key'            => $key,
							'size'           => $size,
							'size_formatted' => size_format( $size ),
							'last_modified'  => $last_modified,
						);
						$remaining--;
						if ( $remaining <= 0 ) {
							break;
						}
					}
				}
			}

			$is_truncated = preg_match( '/<IsTruncated>\s*true\s*<\/IsTruncated>/i', $body );
			$next_token   = preg_match( '/<NextContinuationToken>(.*?)<\/NextContinuationToken>/s', $body, $token_match )
				? html_entity_decode( trim( $token_match[1] ), ENT_QUOTES | ENT_XML1, 'UTF-8' )
				: '';

			if ( $is_truncated && ( '' === $next_token || $next_token === $continuation_token ) ) {
				return $this->finish_s3_event( 'list_objects', new WP_Error( 's3_list_pagination', __( 'O S3 informou mais objetos, mas não forneceu um token de continuação válido.', 'dd-maintenance' ) ) );
			}

			$continuation_token = $next_token;
		} while ( $is_truncated && $remaining > 0 );

		return $this->finish_s3_event( 'list_objects', $objects );
	}

	/**
	 * Retorna a lista de backups remotos no S3 agrupados por pacote (igual aos backups locais).
	 *
	 * @param string $prefix Prefixo de busca no bucket (ex: site-slug ou vazio).
	 * @return array|WP_Error Array de backups agrupados ou erro ao consultar o bucket.
	 */
	public function get_remote_backups( string $prefix = '' ) {
		$site_slug = sanitize_title( get_bloginfo( 'name' ) );
		$site_slug = $site_slug ? $site_slug : 'site';

		$search_prefix = '' !== $prefix ? $prefix : $site_slug;
		$objects       = $this->list_objects( $search_prefix );

		if ( is_wp_error( $objects ) || empty( $objects ) ) {
			// Fallback: se não encontrou com o prefixo do site, busca na raiz do bucket.
			if ( '' !== $search_prefix ) {
				$objects_root = $this->list_objects( '' );
				if ( ! is_wp_error( $objects_root ) && ! empty( $objects_root ) ) {
					$objects = $objects_root;
				} elseif ( is_wp_error( $objects_root ) && empty( $objects ) ) {
					$objects = $objects_root;
				}
			}
		}

		if ( is_wp_error( $objects ) ) {
			return $objects;
		}
		if ( empty( $objects ) ) {
			return array();
		}

		$groups = array();

		foreach ( $objects as $obj ) {
			$key      = $obj['key'];
			$filename = basename( $key );
			$size     = (int) $obj['size'];
			$mtime    = ! empty( $obj['last_modified'] ) ? strtotime( $obj['last_modified'] ) : time();
			$folder   = dirname( $key );
			if ( '.' === $folder ) {
				$folder = '';
			}

			// Ignora arquivos que não sejam .zip ou .sql
			$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'zip', 'sql' ), true ) ) {
				continue;
			}

			// Multi-part volume: ex: site-2026-08-24-1321.part001.zip
			if ( preg_match( '/^(.+)\.part(\d+)\.zip$/i', $filename, $matches ) ) {
				$base_name = $matches[1];
				$part_num  = (int) $matches[2];

				if ( ! isset( $groups[ $base_name ] ) ) {
					$groups[ $base_name ] = array(
						'base_name'     => $base_name,
						'display_name'  => $base_name,
						'folder'        => $folder,
						'is_multipart'  => true,
						'parts'         => array(),
						'total_size'    => 0,
						'latest_mtime'  => $mtime,
						'last_modified' => $obj['last_modified'],
						'has_sql'       => false,
						'sql_key'       => '',
						'sql_size'      => 0,
					);
				}
				$groups[ $base_name ]['is_multipart'] = true;
				$groups[ $base_name ]['display_name']  = $base_name;

				$groups[ $base_name ]['parts'][ $part_num ] = array(
					'filename'       => $filename,
					'key'            => $key,
					'size'           => $size,
					'size_formatted' => size_format( $size ),
					'part'           => $part_num,
				);
				$groups[ $base_name ]['total_size'] += $size;
				if ( $mtime > $groups[ $base_name ]['latest_mtime'] ) {
					$groups[ $base_name ]['latest_mtime']  = $mtime;
					$groups[ $base_name ]['last_modified'] = $obj['last_modified'];
				}
				if ( empty( $groups[ $base_name ]['folder'] ) && ! empty( $folder ) ) {
					$groups[ $base_name ]['folder'] = $folder;
				}
			} elseif ( 'zip' === $ext ) {
				// Single ZIP file: ex: site-2026-08-24-1321.zip
				$base_name = preg_replace( '/\.zip$/i', '', $filename );

				if ( ! isset( $groups[ $base_name ] ) ) {
					$groups[ $base_name ] = array(
						'base_name'     => $base_name,
						'display_name'  => $filename,
						'folder'        => $folder,
						'is_multipart'  => false,
						'parts'         => array(
							1 => array(
								'filename'       => $filename,
								'key'            => $key,
								'size'           => $size,
								'size_formatted' => size_format( $size ),
								'part'           => 1,
							),
						),
						'total_size'    => $size,
						'latest_mtime'  => $mtime,
						'last_modified' => $obj['last_modified'],
						'has_sql'       => false,
						'sql_key'       => '',
						'sql_filename'  => '',
						'sql_size'      => 0,
					);
				} else {
					$groups[ $base_name ]['display_name'] = $filename;
					$groups[ $base_name ]['parts'][1] = array(
						'filename'       => $filename,
						'key'            => $key,
						'size'           => $size,
						'size_formatted' => size_format( $size ),
						'part'           => 1,
					);
					$groups[ $base_name ]['total_size'] += $size;
				}
			} elseif ( 'sql' === $ext ) {
				// SQL dump: ex: site-2026-08-24-1321.sql
				$sql_base = preg_replace( '/\.sql$/i', '', $filename );

				if ( isset( $groups[ $sql_base ] ) ) {
					$groups[ $sql_base ]['has_sql']           = true;
					$groups[ $sql_base ]['sql_key']           = $key;
					$groups[ $sql_base ]['sql_filename']      = $filename;
					$groups[ $sql_base ]['sql_size']          = $size;
					$groups[ $sql_base ]['sql_size_formatted'] = size_format( $size );
					$groups[ $sql_base ]['total_size']       += $size;
					if ( $mtime > $groups[ $sql_base ]['latest_mtime'] ) {
						$groups[ $sql_base ]['latest_mtime']  = $mtime;
						$groups[ $sql_base ]['last_modified'] = $obj['last_modified'];
					}
				} else {
					$groups[ $sql_base ] = array(
						'base_name'          => $sql_base,
						'display_name'       => $filename . ' (' . __( 'Dump SQL', 'dd-maintenance' ) . ')',
						'folder'             => $folder,
						'is_multipart'       => false,
						'parts'              => array(),
						'total_size'         => $size,
						'latest_mtime'       => $mtime,
						'last_modified'      => $obj['last_modified'],
						'has_sql'            => true,
						'sql_key'            => $key,
						'sql_filename'       => $filename,
						'sql_size'           => $size,
						'sql_size_formatted' => size_format( $size ),
					);
				}
			}
		}

		$backups = array();
		foreach ( $groups as $base => $data ) {
			ksort( $data['parts'], SORT_NUMERIC );
			$parts_list = array_values( $data['parts'] );
			$count      = count( $parts_list );
			$mtime      = $data['latest_mtime'];

			$backups[] = array(
				'identifier'         => $base,
				'display_name'       => $data['is_multipart'] ? sprintf( '%s (%d volumes)', $base, $count ) : $data['display_name'],
				'folder'             => $data['folder'],
				'is_multipart'       => $data['is_multipart'],
				'total_parts'        => $count,
				'parts'              => $parts_list,
				'has_sql'            => ! empty( $data['has_sql'] ),
				'sql_key'            => $data['sql_key'] ?? '',
				'sql_filename'       => $data['sql_filename'] ?? '',
				'sql_size'           => $data['sql_size'] ?? 0,
				'sql_size_formatted' => $data['sql_size_formatted'] ?? '',
				'size'               => $data['total_size'],
				'size_formatted'     => size_format( $data['total_size'] ),
				'timestamp'          => $mtime,
				'date_formatted'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $mtime ), 'd/m/Y H:i:s' ),
				'last_modified'      => $data['last_modified'],
			);
		}

		// Ordena do mais recente para o mais antigo
		usort(
			$backups,
			function( $a, $b ) {
				return $b['timestamp'] - $a['timestamp'];
			}
		);

		return $backups;
	}

	/**
	 * Procura e exclui todos os arquivos/partes remotos de um backup no S3 a partir do identificador do backup.
	 *
	 * @param string $identifier Nome base do backup ou arquivo zip (ex: site-2026-08-24-150000).
	 * @return array Array com 'deleted' (quantidade de objetos excluídos) e 'errors' (erros eventuais).
	 */
	public function delete_backup_remote( string $identifier ): array {
		if ( ! $this->is_configured() ) {
			return array(
				'deleted' => 0,
				'errors'  => array( __( 'S3 / Spaces não configurado.', 'dd-maintenance' ) ),
			);
		}

		$requested = sanitize_file_name( $identifier );
		if ( '' === $requested || $requested !== basename( $requested ) || false !== strpos( $identifier, '..' ) ) {
			return array(
				'deleted' => 0,
				'errors'  => array( __( 'Identificador de backup remoto inválido.', 'dd-maintenance' ) ),
			);
		}
		$base_name = preg_replace( '/\.part\d+\.zip$/i', '', $requested );
		$base_name = preg_replace( '/\.(zip|sql)$/i', '', $base_name );
		$site_slug = sanitize_title( get_bloginfo( 'name' ) );
		$site_slug = $site_slug ? $site_slug : 'site';
		$objects   = $this->list_objects( $site_slug );
		if ( is_wp_error( $objects ) ) {
			$objects = $this->list_objects( '' );
		}
		if ( is_wp_error( $objects ) || empty( $objects ) ) {
			return array(
				'deleted' => 0,
				'errors'  => is_wp_error( $objects ) ? array( $objects->get_error_message() ) : array(),
			);
		}

		$deleted_count = 0;
		$errors        = array();
		foreach ( $objects as $obj ) {
			$key      = isset( $obj['key'] ) ? (string) $obj['key'] : '';
			$filename = basename( $key );
			$folder   = dirname( $key );
			if ( ! $this->is_safe_remote_key( $key ) || ( '.' !== $folder && 0 !== strpos( $folder . '/', $site_slug . '/' ) ) ) {
				continue;
			}
			$is_part   = (bool) preg_match( '/^' . preg_quote( $base_name, '/' ) . '\.part\d+\.zip$/i', $filename );
			$is_single = $filename === $base_name . '.zip' || $filename === $base_name . '.sql';
			if ( ! $is_part && ! $is_single ) {
				continue;
			}
			$del = $this->delete_object( $key );
			if ( is_wp_error( $del ) ) {
				$errors[] = sprintf( __( 'Erro ao excluir %1$s no S3: %2$s', 'dd-maintenance' ), $key, $del->get_error_message() );
			} else {
				$deleted_count++;
			}
		}
		return array( 'deleted' => $deleted_count, 'errors' => $errors );
	}
	
	private function is_safe_remote_key( string $key ): bool {
		return '' !== $key && 0 !== strpos( $key, '/' ) && false === strpos( $key, '..' ) && 0 === preg_match( '/[\x00-\x1F\x7F]/', $key );
	}

	/**
	 * Executa o PUT com streaming do arquivo (curl quando disponível).
	 *
	 * @param string $url     URL completa de envio.
	 * @param array  $headers Cabeçalhos HTTP com Host e SigV4.
	 * @param string $file    Arquivo local.
	 * @param int    $size    Tamanho em bytes.
	 * @return array|WP_Error
	 */
	private function stream_put( $url, $headers, $file, $size ) {
		$size    = max( 0, (int) $size );
		$timeout = max( 180, min( 900, (int) ceil( $size / ( 256 * 1024 ) ) + 60 ) );

		if ( function_exists( 'set_time_limit' ) && ! ini_get( 'safe_mode' ) ) {
			set_time_limit( $timeout + 60 );
		}
		if ( function_exists( 'ini_set' ) ) {
			ini_set( 'max_execution_time', (string) ( $timeout + 60 ) );
		}
		ignore_user_abort( true );

		if ( null !== $this->put_transport ) {
			$response = $this->transport->put_with_callback( $this->put_transport, $url, $headers, $file, $size );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			return $this->parse_put_response( $response );
		}

		if ( function_exists( 'curl_init' ) ) {
			$handle = fopen( $file, 'rb' );
			if ( ! $handle ) {
				return new WP_Error( 'file_open', __( 'Não foi possível abrir o arquivo para envio.', 'dd-maintenance' ) );
			}

			$ch = curl_init( $url );
			if ( false === $ch ) {
				fclose( $handle );
				return new WP_Error( 's3_transport_init', __( 'Não foi possível inicializar o transporte cURL para o upload.', 'dd-maintenance' ) );
			}

			$request_id = '';
			$curl_headers = array(
				'Expect:',
				'Host: ' . $headers['Host'],
				'Authorization: ' . $headers['Authorization'],
				'x-amz-content-sha256: ' . $headers['x-amz-content-sha256'],
				'x-amz-date: ' . $headers['x-amz-date'],
				'Content-Type: ' . $headers['Content-Type'],
				'Content-Length: ' . (string) $size,
			);

			curl_setopt_array(
				$ch,
				array(
					CURLOPT_CUSTOMREQUEST   => 'PUT',
					CURLOPT_HTTPHEADER      => $curl_headers,
					CURLOPT_UPLOAD          => true,
					CURLOPT_INFILE          => $handle,
					CURLOPT_INFILESIZE      => $size,
					CURLOPT_RETURNTRANSFER  => true,
					CURLOPT_FOLLOWLOCATION  => false,
					CURLOPT_CONNECTTIMEOUT  => 15,
					CURLOPT_TIMEOUT         => $timeout,
					CURLOPT_LOW_SPEED_LIMIT => 1024,
					CURLOPT_LOW_SPEED_TIME  => 60,
					CURLOPT_SSL_VERIFYPEER  => true,
					CURLOPT_SSL_VERIFYHOST  => 2,
					CURLOPT_HEADERFUNCTION  => static function ( $curl, string $header_line ) use ( &$request_id ): int {
						if ( preg_match( '/^x-amz-(request-id|id-2):\s*(.+)$/i', $header_line, $matches ) ) {
							$request_id = trim( $matches[2] );
						}
						return strlen( $header_line );
					},
				)
			);

			$body  = curl_exec( $ch );
			$code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$error = curl_error( $ch );
			curl_close( $ch );
			fclose( $handle );

			return $this->parse_put_response(
				array(
					'status'  => $code,
					'body'    => (string) $body,
					'error'   => $error,
					'headers' => array( 'x-amz-request-id' => $request_id ),
				)
			);
		}

		$body_limit = $this->get_safe_body_limit();
		if ( $size > $body_limit ) {
			return new WP_Error(
				's3_curl_required',
				sprintf(
					/* translators: 1: File size, 2: Safe memory limit */
					__( 'O upload de %1$s excede o limite seguro de %2$s sem cURL. Instale ou habilite a extensão cURL para enviar arquivos grandes.', 'dd-maintenance' ),
					size_format( $size ),
					size_format( $body_limit )
				)
			);
		}

		$body = file_get_contents( $file );
		if ( false === $body ) {
			return new WP_Error( 's3_file_read', __( 'Não foi possível ler o arquivo para o transporte HTTP.', 'dd-maintenance' ) );
		}

		$response = wp_remote_request(
			$url,
			array(
				'method'     => 'PUT',
				'timeout'    => 75,
				'redirection' => 0,
				'headers'    => $headers,
				'body'       => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->parse_put_response(
			array(
				'status'  => (int) wp_remote_retrieve_response_code( $response ),
				'body'    => wp_remote_retrieve_body( $response ),
				'headers' => array(
					'x-amz-request-id' => wp_remote_retrieve_header( $response, 'x-amz-request-id' ),
					'etag'             => wp_remote_retrieve_header( $response, 'etag' ),
				),
			)
		);
	}

	/**
	 * Normaliza a resposta HTTP dos transportes de upload.
	 *
	 * @param array $response Resposta normalizada do transporte.
	 * @return array|WP_Error
	 */
	private function parse_put_response( array $response ) {
		$code = isset( $response['status'] ) ? (int) $response['status'] : 0;
		if ( $code >= 200 && $code < 300 ) {
			$parsed = $this->response_parser->parse( $response, 'upload' );
			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}
			return array( 'etag' => $parsed['etag'] ?? '' );
		}
		$parsed = $this->response_parser->parse( $response, 'upload' );
		if ( ! is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$error_code = 0 === $code && ! empty( $response['error'] )
			? 's3_transport'
			: ( $this->response_parser->is_retryable_status( $code ) ? 's3_upload_retryable' : 's3_upload' );
		return new WP_Error( $error_code, $parsed->get_error_message() );
	}

	/**
	 * Identifica respostas HTTP que podem ser repetidas com segurança.
	 *
	 * @param int $code Código HTTP.
	 * @return bool
	 */
	private function is_retryable_http_code( $code ): bool {
		return 408 === (int) $code || 429 === (int) $code || ( (int) $code >= 500 && (int) $code <= 599 );
	}

	/**
	 * Identifica falhas transitórias de transporte do upload.
	 *
	 * @param WP_Error $error Erro retornado pelo transporte.
	 * @return bool
	 */
	private function is_retryable_upload_error( $error ): bool {
		if ( ! is_wp_error( $error ) || ! method_exists( $error, 'get_error_code' ) ) {
			return false;
		}

		return in_array(
			(string) $error->get_error_code(),
			array( 'http_request_failed', 's3_transport', 's3_upload_retryable' ),
			true
		);
	}

	/**
	 * Calcula o limite de corpo que pode ser materializado sem cURL.
	 *
	 * @return int
	 */
	private function get_safe_body_limit(): int {
		$memory_limit = $this->parse_size( function_exists( 'ini_get' ) ? ini_get( 'memory_limit' ) : '' );
		if ( $memory_limit <= 0 ) {
			return 32 * 1024 * 1024;
		}

		$reserved  = max( 16 * 1024 * 1024, (int) floor( $memory_limit / 4 ) );
		$available = $memory_limit - memory_get_usage( true ) - $reserved;
		return max( 0, $available );
	}

	/**
	 * Converte um valor de memória do PHP para bytes.
	 *
	 * @param string $value Valor como 128M, 1G ou -1.
	 * @return int
	 */
	private function parse_size( $value ): int {
		$value = trim( (string) $value );
		if ( '' === $value || '-1' === $value ) {
			return 0;
		}

		$unit   = strtolower( substr( $value, -1 ) );
		$number = (float) $value;
		switch ( $unit ) {
			case 'g':
				$number *= 1024;
				// fall through
			case 'm':
				$number *= 1024;
				// fall through
			case 'k':
				$number *= 1024;
				break;
		}

		return max( 0, (int) $number );
	}

	/**
	 * Transforma erros HTTP do S3 em mensagens úteis e amigáveis.
	 *
	 * @param int    $code       Código HTTP.
	 * @param string $body       Corpo da resposta.
	 * @param string $error      Erro do curl (se houver).
	 * @param string $request_id Identificador retornado pelo S3.
	 * @return string
	 */
	private function friendly_error( $code, $body, $error = '', $request_id = '' ) {
		if ( $error ) {
			$message = $error;
		} else {
			$err_code = $this->extract_error_code( $body );
			$err_msg  = $this->extract_error_message( $body );

			switch ( $err_code ) {
				case 'InvalidAccessKeyId':
					$message = __( 'A Access Key informada não existe no servidor S3/DigitalOcean. Confira se colou a chave correta e não trocou com a Secret Key.', 'dd-maintenance' );
					break;

				case 'SignatureDoesNotMatch':
					$message = __( 'A assinatura não confere (SignatureDoesNotMatch). Verifique a Secret Key, o nome do bucket e a região configurada.', 'dd-maintenance' );
					break;

				case 'InvalidArgument':
					$message = sprintf(
						/* translators: %s: Mensagem de erro do servidor */
						__( 'Erro de argumento inválido no S3/Spaces: %s', 'dd-maintenance' ),
						$err_msg ? $err_msg : $body
					);
					break;

				case 'AccessDenied':
					$message = __( 'Acesso negado (AccessDenied): a chave não tem permissão de escrita neste bucket ou foi criada restrita a outro Space.', 'dd-maintenance' );
					break;

				case 'NoSuchBucket':
					$message = __( 'Bucket não encontrado (NoSuchBucket). Confira o nome do bucket e use o botão "Detectar região automaticamente".', 'dd-maintenance' );
					break;

				default:
					$message = sprintf(
						/* translators: 1: HTTP code, 2: Body text */
						__( 'Erro no upload (HTTP %1$s): %2$s', 'dd-maintenance' ),
						$code,
						$err_msg ? $err_msg : ( $body ? $body : __( 'Resposta vazia do servidor.', 'dd-maintenance' ) )
					);
					break;
			}
		}
		if ( (int) $code > 0 && false === strpos( $message, 'HTTP ' . (int) $code ) ) {
			$message .= sprintf(
				/* translators: %d: HTTP status code */
				__( ' (HTTP %d)', 'dd-maintenance' ),
				(int) $code
			);
		}

		if ( '' !== trim( (string) $request_id ) ) {
			$message .= sprintf(
				/* translators: %s: S3 request ID */
				__( ' (Request ID: %s)', 'dd-maintenance' ),
				$request_id
			);
		}

		return $message;
	}

	/**
	 * Extrai o código de erro do corpo XML da resposta do S3.
	 *
	 * @param string $body Corpo da resposta.
	 * @return string
	 */
	private function extract_error_code( $body ) {
		if ( is_string( $body ) && preg_match( '/<Code>([^<]+)<\/Code>/i', $body, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}

	/**
	 * Extrai a mensagem de erro do corpo XML da resposta do S3.
	 *
	 * @param string $body Corpo da resposta.
	 * @return string
	 */
	private function extract_error_message( $body ) {
		if ( is_string( $body ) && preg_match( '/<Message>([^<]+)<\/Message>/i', $body, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}

	/**
	 * Gera a signing key da Signature V4.
	 *
	 * @param string $date    Data (YYYYMMDD).
	 * @param string $region  Região.
	 * @param string $service Serviço (s3).
	 * @param string $secret  Secret key.
	 * @return string
	 */
	private function get_signing_key( $date, $region, $service, $secret ) {
		$k_date    = hash_hmac( 'sha256', $date, 'AWS4' . $secret, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );
		return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	}
	private function record_s3_event( string $event, string $step, string $status, string $failure_code = '' ): void {
		if ( ! class_exists( 'DD_Maintenance' ) || ! method_exists( 'DD_Maintenance', 'record_event' ) ) {
			return;
		}
		DD_Maintenance::record_event(
			's3',
			$event,
			array(
				'step'         => $step,
				'status'       => $status,
				'failure_code' => $failure_code,
				'error_count'  => '' !== $failure_code ? 1 : 0,
			)
		);
	}

	private function finish_s3_event( string $step, $result ) {
		$failure_code = is_wp_error( $result ) ? (string) $result->get_error_code() : '';
		$this->record_s3_event( 'request_finished', $step, '' === $failure_code ? 'success' : 'failure', $failure_code );
		return $result;
	}
}

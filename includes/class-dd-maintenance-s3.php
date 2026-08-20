<?php
/**
 * Integração com S3 compatível (DigitalOcean Spaces / S3 / Dokploy / MinIO) via Signature V4.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

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
	 * Construtor.
	 */
	public function __construct() {
		$saved_settings = get_option( 'dd_maintenance_settings', null );
		if ( null === $saved_settings ) {
			$saved_settings = get_option( 'backuper_settings', array() );
		}

		$this->settings   = is_array( $saved_settings ) ? $saved_settings : array();
		$this->access_key = isset( $this->settings['s3_access_key'] ) ? trim( (string) $this->settings['s3_access_key'] ) : '';
		$this->secret_key = isset( $this->settings['s3_secret_key'] ) ? trim( (string) $this->settings['s3_secret_key'] ) : '';
		$this->bucket     = isset( $this->settings['s3_bucket'] ) ? trim( (string) $this->settings['s3_bucket'] ) : '';
		$this->region     = isset( $this->settings['s3_region'] ) && '' !== trim( (string) $this->settings['s3_region'] ) ? trim( (string) $this->settings['s3_region'] ) : 'nyc3';
		$this->endpoint   = isset( $this->settings['s3_endpoint'] ) ? trim( (string) $this->settings['s3_endpoint'] ) : '';
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
		$region = $region ? $region : $this->region;

		if ( ! empty( $this->endpoint ) ) {
			$endpoint = rtrim( $this->endpoint, '/' );

			if ( ! preg_match( '#^https?://#i', $endpoint ) ) {
				$endpoint = 'https://' . $endpoint;
			}

			$parsed = wp_parse_url( $endpoint );
			$host   = isset( $parsed['host'] ) ? $parsed['host'] : '';

			// Se for DigitalOcean Spaces e o host não tiver o bucket no prefixo, adiciona.
			if ( strpos( $host, 'digitaloceanspaces.com' ) !== false && 0 !== strpos( $host, $this->bucket . '.' ) ) {
				$scheme   = isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'https';
				$port_str = ! empty( $parsed['port'] ) ? ':' . $parsed['port'] : '';
				return $scheme . '://' . $this->bucket . '.' . $host . $port_str;
			}

			return $endpoint;
		}

		return 'https://' . $this->bucket . '.' . $region . '.digitaloceanspaces.com';
	}

	/**
	 * Retorna o cabeçalho Host correto (incluindo porta se não-padrão).
	 *
	 * @param string|null $endpoint URL do endpoint.
	 * @return string
	 */
	public function get_host( $endpoint = null ) {
		$endpoint   = $endpoint ? $endpoint : $this->get_endpoint();
		$parsed_url = wp_parse_url( $endpoint );
		$host       = isset( $parsed_url['host'] ) ? $parsed_url['host'] : '';

		if ( ! empty( $parsed_url['port'] ) && ! in_array( (int) $parsed_url['port'], array( 80, 443 ), true ) ) {
			$host .= ':' . $parsed_url['port'];
		}

		return $host;
	}

	/**
	 * Codifica a URI mantendo as barras.
	 *
	 * @param string $key Chave do objeto.
	 * @return string
	 */
	private function encode_uri( $key ) {
		return '/' . implode( '/', array_map( 'rawurlencode', explode( '/', ltrim( $key, '/' ) ) ) );
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
		$this->settings['s3_region'] = $detected;
		update_option( 'dd_maintenance_settings', $this->settings );

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
		$amz_date   = gmdate( 'Ymd\THis\Z' );
		$date_stamp = gmdate( 'Ymd' );
		$host       = $this->get_host();

		// Cabeçalhos essenciais assinados na Signature V4 (compatível com AWS, Spaces, MinIO e Dokploy).
		$sign_headers = array_merge(
			array(
				'host'                 => $host,
				'x-amz-content-sha256' => $payload_hash,
				'x-amz-date'           => $amz_date,
			),
			$extra_headers
		);

		ksort( $sign_headers );

		$canonical_headers_str = '';
		$signed_headers        = array();
		foreach ( $sign_headers as $header => $value ) {
			$header                 = strtolower( trim( $header ) );
			$canonical_headers_str .= $header . ':' . trim( (string) $value ) . "\n";
			$signed_headers[]       = $header;
		}
		$signed_headers_str = implode( ';', $signed_headers );

		$canonical_request = "{$method}\n{$uri}\n{$query}\n{$canonical_headers_str}\n{$signed_headers_str}\n{$payload_hash}";

		$scope          = "{$date_stamp}/{$this->region}/s3/aws4_request";
		$string_to_sign = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash( 'sha256', $canonical_request );

		$signing_key = $this->get_signing_key( $date_stamp, $this->region, 's3', $this->secret_key );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		return array(
			'Authorization'        => "AWS4-HMAC-SHA256 Credential={$this->access_key}/{$scope}, SignedHeaders={$signed_headers_str}, Signature={$signature}",
			'x-amz-content-sha256' => $payload_hash,
			'x-amz-date'           => $amz_date,
			'Host'                 => $host,
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
		if ( ! $this->is_configured() ) {
			return new WP_Error( 's3_config', __( 'Configure as credenciais do S3 / DigitalOcean Spaces.', 'dd-maintenance' ) );
		}
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_missing', __( 'Arquivo de backup não encontrado.', 'dd-maintenance' ) );
		}

		// Garante a região correta quando usando DigitalOcean Spaces.
		$region_ok = $this->ensure_region();
		if ( is_wp_error( $region_ok ) ) {
			return $region_ok;
		}

		$size         = (int) filesize( $file_path );
		$payload_hash = hash_file( 'sha256', $file_path );
		$uri          = $this->encode_uri( $key );

		$auth = $this->sign_request( 'PUT', $uri, '', $payload_hash );

		$headers = array(
			'Host'                 => $auth['Host'],
			'Authorization'        => $auth['Authorization'],
			'x-amz-content-sha256' => $auth['x-amz-content-sha256'],
			'x-amz-date'           => $auth['x-amz-date'],
			'Content-Type'         => $content_type,
			'Content-Length'       => (string) $size,
		);

		$endpoint_url = $this->get_endpoint() . $uri;

		$result = $this->stream_put( $endpoint_url, $headers, $file_path, $size );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'key'  => $key,
			'etag' => isset( $result['etag'] ) ? $result['etag'] : '',
		);
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
		if ( function_exists( 'curl_init' ) ) {
			$handle = fopen( $file, 'rb' );
			if ( ! $handle ) {
				return new WP_Error( 'file_open', __( 'Não foi possível abrir o arquivo para envio.', 'dd-maintenance' ) );
			}

			$ch = curl_init( $url );

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
					CURLOPT_CUSTOMREQUEST  => 'PUT',
					CURLOPT_HTTPHEADER     => $curl_headers,
					CURLOPT_UPLOAD         => true,
					CURLOPT_INFILE         => $handle,
					CURLOPT_INFILESIZE     => $size,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_CONNECTTIMEOUT => 30,
					CURLOPT_TIMEOUT        => 3600,
					CURLOPT_SSL_VERIFYPEER => true,
					CURLOPT_SSL_VERIFYHOST => 2,
				)
			);

			$body  = curl_exec( $ch );
			$code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$error = curl_error( $ch );
			curl_close( $ch );
			fclose( $handle );

			if ( $code >= 200 && $code < 300 ) {
				return array( 'etag' => '' );
			}

			return new WP_Error( 's3_upload', $this->friendly_error( $code, $body, $error ) );
		}

		// Fallback para envio através da API HTTP do WordPress.
		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'PUT',
				'timeout' => 3600,
				'headers' => $headers,
				'body'    => file_get_contents( $file ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return array( 'etag' => wp_remote_retrieve_header( $response, 'etag' ) );
		}

		return new WP_Error( 's3_upload', $this->friendly_error( $code, wp_remote_retrieve_body( $response ), '' ) );
	}

	/**
	 * Transforma erros HTTP do S3 em mensagens úteis e amigáveis.
	 *
	 * @param int    $code  Código HTTP.
	 * @param string $body  Corpo da resposta.
	 * @param string $error Erro do curl (se houver).
	 * @return string
	 */
	private function friendly_error( $code, $body, $error = '' ) {
		if ( $error ) {
			return $error;
		}

		$err_code = $this->extract_error_code( $body );
		$err_msg  = $this->extract_error_message( $body );

		switch ( $err_code ) {
			case 'InvalidAccessKeyId':
				return __( 'A Access Key informada não existe no servidor S3/DigitalOcean. Confira se colou a chave correta e não trocou com a Secret Key.', 'dd-maintenance' );

			case 'SignatureDoesNotMatch':
				return __( 'A assinatura não confere (SignatureDoesNotMatch). Verifique a Secret Key, o nome do bucket e a região configurada.', 'dd-maintenance' );

			case 'InvalidArgument':
				return sprintf(
					/* translators: %s: Mensagem de erro do servidor */
					__( 'Erro de argumento inválido no S3/Spaces: %s', 'dd-maintenance' ),
					$err_msg ? $err_msg : $body
				);

			case 'AccessDenied':
				return __( 'Acesso negado (AccessDenied): a chave não tem permissão de escrita neste bucket ou foi criada restrita a outro Space.', 'dd-maintenance' );

			case 'NoSuchBucket':
				return __( 'Bucket não encontrado (NoSuchBucket). Confira o nome do bucket e use o botão "Detectar região automaticamente".', 'dd-maintenance' );

			default:
				return sprintf(
					/* translators: 1: HTTP code, 2: Body text */
					__( 'Erro no upload (HTTP %1$s): %2$s', 'dd-maintenance' ),
					$code,
					$err_msg ? $err_msg : $body
				);
		}
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
}

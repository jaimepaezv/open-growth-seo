<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Integrations;

use OpenGrowthSolutions\OpenGrowthSEO\Jobs\Queue;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class IndexNow {
	private const STATUS_OPTION = 'ogs_seo_indexnow_status';
	private const LAST_SENT_OPTION = 'ogs_seo_indexnow_last_sent';

	public function register(): void {
		add_action( 'save_post', array( $this, 'queue_url' ) );
		add_action( 'trashed_post', array( $this, 'queue_deleted' ) );
		add_action( 'deleted_post', array( $this, 'queue_deleted' ) );
		add_action( 'untrashed_post', array( $this, 'queue_url' ) );
		add_action( 'ogs_seo_indexnow_process', array( $this, 'process_queue' ) );
		add_action( 'template_redirect', array( $this, 'serve_key_file' ) );
		add_filter( 'ogs_seo_audit_checks', array( $this, 'register_audit_checks' ) );

		add_action( 'admin_post_ogs_seo_indexnow_generate_key', array( $this, 'admin_generate_key' ) );
		add_action( 'admin_post_ogs_seo_indexnow_verify_key', array( $this, 'admin_verify_key' ) );
		add_action( 'admin_post_ogs_seo_indexnow_process_now', array( $this, 'admin_process_now' ) );
		add_action( 'admin_post_ogs_seo_indexnow_requeue_failed', array( $this, 'admin_requeue_failed' ) );
		add_action( 'admin_post_ogs_seo_indexnow_clear_failed', array( $this, 'admin_clear_failed' ) );
		add_action( 'admin_post_ogs_seo_indexnow_release_lock', array( $this, 'admin_release_lock' ) );
	}

	public function register_audit_checks( array $checks ): array {
		$checks['indexnow_health'] = array( $this, 'audit_health' );
		return $checks;
	}

	public function audit_health(): array {
		if ( ! Settings::get( 'indexnow_enabled', 0 ) ) {
			return array();
		}
		$key = $this->sanitize_key( (string) Settings::get( 'indexnow_key', '' ) );
		if ( '' === $key ) {
			return array(
				'severity'       => 'important',
				'title'          => __( 'IndexNow is enabled but key is missing/invalid', 'open-growth-seo' ),
				'recommendation' => __( 'Generate or provide a valid IndexNow key and verify it before enabling submissions.', 'open-growth-seo' ),
			);
		}
		$status = $this->inspect_status();
		$pending = isset( $status['queue']['pending'] ) ? (int) $status['queue']['pending'] : 0;
		$last_sent = isset( $status['last_sent'] ) ? (int) $status['last_sent'] : 0;
		$runner  = isset( $status['queue']['runner'] ) && is_array( $status['queue']['runner'] ) ? $status['queue']['runner'] : array();
		if ( ! empty( $runner['stale_lock'] ) ) {
			return array(
				'severity'       => 'important',
				'title'          => __( 'IndexNow queue lock appears stale', 'open-growth-seo' ),
				'recommendation' => __( 'Release the queue lock from the Integrations screen, then re-run queue processing and verify outbound requests.', 'open-growth-seo' ),
			);
		}
		if ( $pending > 500 && ( time() - $last_sent ) > DAY_IN_SECONDS ) {
			return array(
				'severity'       => 'minor',
				'title'          => __( 'IndexNow queue appears delayed', 'open-growth-seo' ),
				'recommendation' => __( 'Run IndexNow processing manually and review endpoint connectivity/retry settings.', 'open-growth-seo' ),
			);
		}
		return array();
	}

	public function queue_url( int $post_id ): void {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! Settings::get( 'indexnow_enabled', 0 ) ) {
			return;
		}
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return;
		}
		$post_type = get_post_type( $post_id );
		$object = $post_type ? get_post_type_object( (string) $post_type ) : null;
		if ( ! $object || empty( $object->public ) || empty( $object->publicly_queryable ) ) {
			return;
		}
		$url = get_permalink( $post_id );
		if ( ! is_string( $url ) || '' === $url ) {
			return;
		}
		( new Queue() )->enqueue( $url );
	}

	public function queue_deleted( int $post_id ): void {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! Settings::get( 'indexnow_enabled', 0 ) ) {
			return;
		}
		$url = get_permalink( $post_id );
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			$url = home_url( '/?p=' . $post_id );
		}
		( new Queue() )->enqueue( $url );
	}

	public function process_queue( string $source = 'system' ): array {
		if ( ! Settings::get( 'indexnow_enabled', 0 ) ) {
			return array(
				'ok'      => false,
				'status'  => 'disabled',
				'message' => __( 'IndexNow is disabled.', 'open-growth-seo' ),
			);
		}
		$key = $this->sanitize_key( (string) Settings::get( 'indexnow_key', '' ) );
		$queue = new Queue();
		$source = sanitize_key( $source );
		if ( '' === $source ) {
			$source = 'system';
		}
		$lock_token = $queue->acquire_lock( $source, max( 120, min( 1800, absint( Settings::get( 'integration_request_timeout', 8 ) ) * 15 ) ) );
		if ( '' === $lock_token ) {
			$message = __( 'IndexNow queue is already being processed.', 'open-growth-seo' );
			$this->set_status( true, $message );
			return array(
				'ok'      => true,
				'status'  => 'locked',
				'message' => $message,
			);
		}

		if ( '' === $key ) {
			$this->set_status( false, __( 'IndexNow key is missing or invalid.', 'open-growth-seo' ) );
			$queue->release_lock( $lock_token, 'failure', __( 'IndexNow key is missing or invalid.', 'open-growth-seo' ), 0 );
			return array(
				'ok'      => false,
				'status'  => 'failure',
				'message' => __( 'IndexNow key is missing or invalid.', 'open-growth-seo' ),
			);
		}

		$batch_size  = max( 1, min( 100, absint( Settings::get( 'indexnow_batch_size', 100 ) ) ) );
		$max_retries = max( 1, min( 20, absint( Settings::get( 'indexnow_max_retries', 5 ) ) ) );
		$rate_limit  = max( 10, min( 3600, absint( Settings::get( 'indexnow_rate_limit_seconds', 60 ) ) ) );
		$last_sent   = absint( get_option( self::LAST_SENT_OPTION, 0 ) );
		if ( time() - $last_sent < $rate_limit ) {
			wp_schedule_single_event( max( time() + MINUTE_IN_SECONDS, $last_sent + $rate_limit ), 'ogs_seo_process_indexnow_queue' );
			$queue->release_lock( $lock_token, 'rate_limited', __( 'IndexNow rate limit active.', 'open-growth-seo' ), 0 );
			return array(
				'ok'      => true,
				'status'  => 'rate_limited',
				'message' => __( 'IndexNow rate limit active.', 'open-growth-seo' ),
			);
		}

		$claim = $queue->claim_batch( $batch_size );
		$batch = isset( $claim['urls'] ) && is_array( $claim['urls'] ) ? $claim['urls'] : array();
		$claim_token = isset( $claim['token'] ) ? (string) $claim['token'] : '';
		if ( empty( $batch ) || '' === $claim_token ) {
			$queue->release_lock( $lock_token, 'idle', __( 'No eligible IndexNow URLs are ready to process.', 'open-growth-seo' ), 0 );
			return array(
				'ok'      => true,
				'status'  => 'idle',
				'message' => __( 'No eligible IndexNow URLs are ready to process.', 'open-growth-seo' ),
			);
		}

		$endpoint = esc_url_raw( (string) Settings::get( 'indexnow_endpoint', 'https://api.indexnow.org/indexnow' ) );
		if ( '' === $endpoint ) {
			$queue->mark_failure_with_claim( $batch, __( 'IndexNow endpoint is invalid.', 'open-growth-seo' ), $max_retries, $claim_token );
			$this->set_status( false, __( 'IndexNow endpoint is invalid.', 'open-growth-seo' ) );
			$queue->release_lock( $lock_token, 'failure', __( 'IndexNow endpoint is invalid.', 'open-growth-seo' ), count( $batch ) );
			return array(
				'ok'      => false,
				'status'  => 'failure',
				'message' => __( 'IndexNow endpoint is invalid.', 'open-growth-seo' ),
			);
		}

		$body = wp_json_encode(
			array(
				'host'        => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				'key'         => $key,
				'keyLocation' => home_url( '/' . $key . '.txt' ),
				'urlList'     => array_values( $batch ),
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => max( 3, min( 20, absint( Settings::get( 'integration_request_timeout', 8 ) ) ) ),
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => $body,
			)
		);

		update_option( self::LAST_SENT_OPTION, time(), false );
		if ( is_wp_error( $response ) ) {
			$message = sanitize_text_field( $response->get_error_message() );
			$queue->mark_failure_with_claim( $batch, $message, $max_retries, $claim_token );
			$this->set_status( false, $message );
			IntegrationLogger::log( 'indexnow', 'error', $message, array( 'operation' => 'submit_batch' ) );
			$queue->release_lock( $lock_token, 'failure', $message, count( $batch ) );
			return array(
				'ok'      => false,
				'status'  => 'failure',
				'message' => $message,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 200, 202 ), true ) ) {
			$queue->mark_success_with_claim( $batch, $claim_token );
			$this->set_status( true, sprintf( __( 'IndexNow batch sent (%d URLs).', 'open-growth-seo' ), count( $batch ) ) );
			IntegrationLogger::log(
				'indexnow',
				'info',
				'IndexNow batch sent.',
				array(
					'count' => count( $batch ),
					'http_code' => $code,
				)
			);
			$queue->release_lock( $lock_token, 'success', sprintf( __( 'IndexNow batch sent (%d URLs).', 'open-growth-seo' ), count( $batch ) ), count( $batch ) );
			return array(
				'ok'      => true,
				'status'  => 'success',
				'message' => sprintf( __( 'IndexNow batch sent (%d URLs).', 'open-growth-seo' ), count( $batch ) ),
			);
		}

		$message = sprintf( __( 'IndexNow endpoint returned HTTP %d.', 'open-growth-seo' ), $code );
		$queue->mark_failure_with_claim( $batch, $message, $max_retries, $claim_token );
		$this->set_status( false, $message );
		IntegrationLogger::log( 'indexnow', 'error', $message, array( 'http_code' => $code ) );
		$queue->release_lock( $lock_token, 'failure', $message, count( $batch ) );
		return array(
			'ok'      => false,
			'status'  => 'failure',
			'message' => $message,
		);
	}

	public function serve_key_file(): void {
		$key = $this->sanitize_key( (string) Settings::get( 'indexnow_key', '' ) );
		if ( '' === $key ) {
			return;
		}
		$query_key = isset( $_GET['ogs_indexnow_key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ogs_indexnow_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public key endpoint.
		if ( '1' === $query_key || strtolower( $query_key ) === strtolower( $key ) ) {
			nocache_headers();
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html( $key );
			exit;
		}
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( '' === $path ) {
			return;
		}
		$target = '/' . $key . '.txt';
		if ( strtolower( untrailingslashit( $path ) ) !== strtolower( $target ) ) {
			return;
		}
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $key );
		exit;
	}

	public function verify_key_accessible(): array {
		$key = $this->sanitize_key( (string) Settings::get( 'indexnow_key', '' ) );
		if ( '' === $key ) {
			delete_option( 'ogs_seo_indexnow_key_verified' );
			return array(
				'ok'      => false,
				'message' => __( 'IndexNow key is missing or invalid.', 'open-growth-seo' ),
			);
		}
		$urls          = $this->verification_urls( $key );
		$urls          = array_values( array_unique( array_filter( $urls ) ) );
		$urls          = apply_filters( 'ogs_seo_indexnow_verification_urls', $urls, $key );
		$last_code     = 0;
		$last_body_hit = false;
		$last_error    = '';
		$timeout       = max( 3, min( 20, absint( Settings::get( 'integration_request_timeout', 8 ) ) ) );
		foreach ( $urls as $url ) {
			$url = esc_url_raw( (string) $url );
			if ( '' === $url ) {
				continue;
			}
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => $timeout,
				)
			);
			if ( is_wp_error( $response ) ) {
				$last_error = sanitize_text_field( $response->get_error_message() );
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = trim( (string) wp_remote_retrieve_body( $response ) );
			$last_code     = $code;
			$last_body_hit = ( $body === $key );
			if ( 200 === $code && $body === $key ) {
				update_option( 'ogs_seo_indexnow_key_verified', time(), false );
				return array(
					'ok'      => true,
					'message' => __( 'IndexNow key is accessible and valid.', 'open-growth-seo' ),
				);
			}
		}
		if ( '' !== $last_error ) {
			$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
			if ( in_array( $host, array( 'localhost', '127.0.0.1' ), true ) && ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				return array(
					'ok'      => false,
					'message' => sprintf( __( 'Key fetch failed from WP-CLI runtime (%s). Try verification from wp-admin browser request.', 'open-growth-seo' ), $last_error ),
				);
			}
		}
		delete_option( 'ogs_seo_indexnow_key_verified' );
		return array(
			'ok'      => false,
			'message' => sprintf( __( 'Key verification failed (HTTP %1$d, body match: %2$s).', 'open-growth-seo' ), $last_code, $last_body_hit ? 'yes' : 'no' ),
		);
	}

	private function verification_urls( string $key ): array {
		$urls = array(
			home_url( '/' . $key . '.txt' ),
			add_query_arg( 'ogs_indexnow_key', '1', home_url( '/' ) ),
		);
		$home_url = home_url( '/' );
		$host     = strtolower( (string) wp_parse_url( $home_url, PHP_URL_HOST ) );
		if ( in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
			$path           = (string) wp_parse_url( $home_url, PHP_URL_PATH );
			$path           = '' !== $path ? $path : '/';
			$docker_service = 'http://wordpress' . $path;
			$docker_service = untrailingslashit( $docker_service ) . '/';
			$urls[]         = untrailingslashit( $docker_service ) . '/' . $key . '.txt';
			$urls[]         = add_query_arg( 'ogs_indexnow_key', '1', $docker_service );
		}
		return $urls;
	}

	public function inspect_status(): array {
		$status = get_option( self::STATUS_OPTION, array() );
		if ( ! is_array( $status ) ) {
			$status = array();
		}
		$queue = new Queue();
		return array(
			'enabled'      => (bool) Settings::get( 'indexnow_enabled', 0 ),
			'endpoint'     => esc_url_raw( (string) Settings::get( 'indexnow_endpoint', '' ) ),
			'key_present'  => '' !== $this->sanitize_key( (string) Settings::get( 'indexnow_key', '' ) ),
			'key_verified' => absint( get_option( 'ogs_seo_indexnow_key_verified', 0 ) ),
			'last_sent'    => absint( get_option( self::LAST_SENT_OPTION, 0 ) ),
			'last_status'  => $status,
			'queue'        => $queue->inspect(),
		);
	}

	public function generate_key(): string {
		try {
			$raw = wp_generate_password( 32, false, false );
		} catch ( \Throwable $e ) {
			unset( $e );
			$raw = md5( uniqid( 'ogs-indexnow', true ) );
		}
		$key      = strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', $raw ) );
		$key      = substr( $key, 0, 64 );
		$settings = Settings::get_all();
		$settings['indexnow_key'] = $key;
		Settings::update( $settings );
		delete_option( 'ogs_seo_indexnow_key_verified' );
		return $key;
	}

	public function admin_generate_key(): void {
		$this->guard_admin_action();
		$key = $this->generate_key();
		add_settings_error( 'ogs_seo', 'indexnow_key_generated', __( 'New IndexNow key generated.', 'open-growth-seo' ), 'updated' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		IntegrationLogger::log( 'indexnow', 'info', 'IndexNow key generated.', array( 'length' => strlen( $key ) ) );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-integrations' ) );
		exit;
	}

	public function admin_verify_key(): void {
		$this->guard_admin_action();
		$result = $this->verify_key_accessible();
		add_settings_error( 'ogs_seo', 'indexnow_key_verify', (string) $result['message'], ! empty( $result['ok'] ) ? 'updated' : 'error' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-integrations' ) );
		exit;
	}

	public function admin_process_now(): void {
		$this->guard_admin_action();
		$result = $this->process_queue( 'admin' );
		$message_type = ! empty( $result['ok'] ) ? 'updated' : 'error';
		add_settings_error( 'ogs_seo', 'indexnow_process', (string) ( $result['message'] ?? __( 'IndexNow queue processing triggered.', 'open-growth-seo' ) ), $message_type );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-integrations' ) );
		exit;
	}

	public function admin_requeue_failed(): void {
		$this->guard_admin_action();
		$count = ( new Queue() )->requeue_failed_recent( 20 );
		add_settings_error(
			'ogs_seo',
			'indexnow_requeue_failed',
			0 === $count
				? __( 'No failed IndexNow URLs were available to requeue.', 'open-growth-seo' )
				: sprintf( __( 'Requeued %d failed IndexNow URLs.', 'open-growth-seo' ), $count ),
			0 === $count ? 'info' : 'updated'
		);
		IntegrationLogger::log( 'indexnow', 'info', 'Failed IndexNow URLs requeued.', array( 'count' => $count ) );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-integrations' ) );
		exit;
	}

	public function admin_clear_failed(): void {
		$this->guard_admin_action();
		( new Queue() )->clear_failed();
		add_settings_error( 'ogs_seo', 'indexnow_clear_failed', __( 'IndexNow failed queue history cleared.', 'open-growth-seo' ), 'updated' );
		IntegrationLogger::log( 'indexnow', 'info', 'IndexNow failed queue history cleared.' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-integrations' ) );
		exit;
	}

	public function admin_release_lock(): void {
		$this->guard_admin_action();
		( new Queue() )->force_release_lock();
		add_settings_error( 'ogs_seo', 'indexnow_release_lock', __( 'IndexNow queue lock released.', 'open-growth-seo' ), 'updated' );
		IntegrationLogger::log( 'indexnow', 'warning', 'IndexNow queue lock released manually.' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-integrations' ) );
		exit;
	}

	private function sanitize_key( string $key ): string {
		$key = trim( sanitize_text_field( $key ) );
		if ( ! preg_match( '/^[a-zA-Z0-9]{8,128}$/', $key ) ) {
			return '';
		}
		return $key;
	}

	private function set_status( bool $ok, string $message ): void {
		update_option(
			self::STATUS_OPTION,
			array(
				'ok'      => $ok ? 1 : 0,
				'message' => sanitize_text_field( $message ),
				'time'    => time(),
			),
			false
		);
	}

	private function guard_admin_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage IndexNow.', 'open-growth-seo' ) );
		}
		check_admin_referer( 'ogs_seo_indexnow_action' );
	}
}

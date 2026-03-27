<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Jobs;

use OpenGrowthSolutions\OpenGrowthSEO\Support\DeveloperTools;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class Queue {
	private const CRON_HOOK = 'ogs_seo_process_indexnow_queue';
	private const OPTION_KEY = 'ogs_seo_indexnow_queue';
	private const FAILED_OPTION_KEY = 'ogs_seo_indexnow_failed';
	private const STATE_OPTION_KEY = 'ogs_seo_indexnow_queue_state';
	private const DEFAULT_LOCK_TTL = 300;

	public function register(): void {
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
	}

	public function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	public function enqueue( string $url ): void {
		$url = $this->normalize_url( $url );
		if ( '' === $url ) {
			return;
		}
		$queue = $this->read_queue();
		if ( isset( $queue[ $url ] ) && is_array( $queue[ $url ] ) ) {
			$queue[ $url ]['updated']  = time();
			$queue[ $url ]['next_try'] = 0;
			$queue[ $url ]['claim_token'] = '';
			$queue[ $url ]['claimed_until'] = 0;
			$this->write_queue( $queue );
			$this->ensure_processing_scheduled( time() + MINUTE_IN_SECONDS );
			return;
		}
		$queue[ $url ] = array(
			'url'        => $url,
			'attempts'   => 0,
			'next_try'   => 0,
			'updated'    => time(),
			'last_error' => '',
			'claim_token'  => '',
			'claimed_until' => 0,
		);
		$max_size = max( 100, min( 50000, absint( Settings::get( 'indexnow_queue_max_size', 5000 ) ) ) );
		if ( count( $queue ) > $max_size ) {
			uasort(
				$queue,
				static function ( array $a, array $b ): int {
					return (int) ( $a['updated'] ?? 0 ) <=> (int) ( $b['updated'] ?? 0 );
				}
			);
			$queue = array_slice( $queue, -$max_size, null, true );
		}
		$this->write_queue( $queue );
		$this->ensure_processing_scheduled( time() + MINUTE_IN_SECONDS );
	}

	public function next_batch( int $limit ): array {
		$limit = max( 1, min( 100, $limit ) );
		$queue = $this->read_queue();
		if ( empty( $queue ) ) {
			return array();
		}
		$now   = time();
		$items = array();
		foreach ( $queue as $row ) {
			if ( ! is_array( $row ) || empty( $row['url'] ) ) {
				continue;
			}
			if ( (int) ( $row['next_try'] ?? 0 ) > $now ) {
				continue;
			}
			$items[] = (string) $row['url'];
			if ( count( $items ) >= $limit ) {
				break;
			}
		}
		return $items;
	}

	public function mark_success( array $urls ): void {
		$this->mark_success_with_claim( $urls, '' );
	}

	public function mark_success_with_claim( array $urls, string $claim_token ): void {
		if ( empty( $urls ) ) {
			return;
		}
		$queue = $this->read_queue();
		foreach ( $urls as $url ) {
			$url = $this->normalize_url( (string) $url );
			if ( '' === $url ) {
				continue;
			}
			if ( '' !== $claim_token && (string) ( $queue[ $url ]['claim_token'] ?? '' ) !== $claim_token ) {
				continue;
			}
			unset( $queue[ $url ] );
		}
		$this->write_queue( $queue );
		$this->ensure_next_due_processing( $queue );
	}

	public function mark_failure( array $urls, string $message, int $max_retries ): void {
		$this->mark_failure_with_claim( $urls, $message, $max_retries, '' );
	}

	public function mark_failure_with_claim( array $urls, string $message, int $max_retries, string $claim_token ): void {
		if ( empty( $urls ) ) {
			return;
		}
		$queue       = $this->read_queue();
		$max_retries = max( 1, min( 20, $max_retries ) );
		$message     = sanitize_text_field( $message );
		foreach ( $urls as $url ) {
			$url = $this->normalize_url( (string) $url );
			if ( '' === $url || ! isset( $queue[ $url ] ) || ! is_array( $queue[ $url ] ) ) {
				continue;
			}
			if ( '' !== $claim_token && (string) ( $queue[ $url ]['claim_token'] ?? '' ) !== $claim_token ) {
				continue;
			}
			$attempts = (int) ( $queue[ $url ]['attempts'] ?? 0 ) + 1;
			if ( $attempts > $max_retries ) {
				$this->append_failed_item( (string) $url, $attempts, $message );
				unset( $queue[ $url ] );
				continue;
			}
			$backoff = min( 12 * HOUR_IN_SECONDS, (int) pow( 2, max( 0, $attempts - 1 ) ) * MINUTE_IN_SECONDS );
			$queue[ $url ]['attempts']   = $attempts;
			$queue[ $url ]['next_try']   = time() + $backoff;
			$queue[ $url ]['updated']    = time();
			$queue[ $url ]['last_error'] = $message;
			$queue[ $url ]['claim_token']  = '';
			$queue[ $url ]['claimed_until'] = 0;
		}
		$this->write_queue( $queue );
		$this->ensure_next_due_processing( $queue );
	}

	public function pending_count(): int {
		return count( $this->read_queue() );
	}

	public function failed_recent(): array {
		$failed = get_option( self::FAILED_OPTION_KEY, array() );
		return is_array( $failed ) ? $failed : array();
	}

	public function inspect(): array {
		$queue   = $this->read_queue();
		$next    = 0;
		$due_now = 0;
		$claimed = 0;
		$now     = time();
		if ( ! empty( $queue ) ) {
			$timestamps = array_map(
				static fn( array $row ): int => isset( $row['next_try'] ) ? (int) $row['next_try'] : 0,
				array_values( $queue )
			);
			$next = (int) min( $timestamps );
			foreach ( $queue as $row ) {
				$claim_until = (int) ( $row['claimed_until'] ?? 0 );
				if ( $claim_until > $now ) {
					++$claimed;
					continue;
				}
				if ( (int) ( $row['next_try'] ?? 0 ) <= $now ) {
					++$due_now;
				}
			}
		}
		$state = $this->read_state();
		return array(
			'pending'     => count( $queue ),
			'due_now'     => $due_now,
			'claimed'     => $claimed,
			'next_try_at' => $next,
			'failed'      => $this->failed_recent(),
			'failed_count' => count( $this->failed_recent() ),
			'schedule'    => array(
				'hook'          => self::CRON_HOOK,
				'next_scheduled'=> (int) wp_next_scheduled( self::CRON_HOOK ),
			),
			'runner'      => array(
				'is_running'           => $this->is_lock_active( $state ),
				'lock_acquired_at'     => (int) ( $state['lock_acquired_at'] ?? 0 ),
				'lock_expires_at'      => (int) ( $state['lock_expires_at'] ?? 0 ),
				'last_run_at'          => (int) ( $state['last_run_at'] ?? 0 ),
				'last_success_at'      => (int) ( $state['last_success_at'] ?? 0 ),
				'last_failure_at'      => (int) ( $state['last_failure_at'] ?? 0 ),
				'last_status'          => sanitize_key( (string) ( $state['last_status'] ?? 'idle' ) ),
				'last_message'         => sanitize_text_field( (string) ( $state['last_message'] ?? '' ) ),
				'last_batch_size'      => (int) ( $state['last_batch_size'] ?? 0 ),
				'consecutive_failures' => (int) ( $state['consecutive_failures'] ?? 0 ),
				'run_count'            => (int) ( $state['run_count'] ?? 0 ),
				'stale_lock'           => ! empty( $state['lock_token'] ) && (int) ( $state['lock_expires_at'] ?? 0 ) <= $now,
				'stale_for'            => ! empty( $state['lock_token'] ) && (int) ( $state['lock_expires_at'] ?? 0 ) <= $now ? max( 0, $now - (int) $state['lock_expires_at'] ) : 0,
			),
		);
	}

	public function run(): void {
		do_action( 'ogs_seo_indexnow_process' );
	}

	public function claim_batch( int $limit, int $claim_ttl = self::DEFAULT_LOCK_TTL ): array {
		$limit     = max( 1, min( 100, $limit ) );
		$claim_ttl = max( 30, min( 900, $claim_ttl ) );
		$queue     = $this->read_queue();
		if ( empty( $queue ) ) {
			return array(
				'token' => '',
				'urls'  => array(),
			);
		}
		$now   = time();
		$token = wp_generate_password( 20, false, false );
		$urls  = array();
		foreach ( $queue as $url => $row ) {
			if ( ! is_array( $row ) || empty( $row['url'] ) ) {
				continue;
			}
			if ( (int) ( $row['next_try'] ?? 0 ) > $now ) {
				continue;
			}
			if ( (int) ( $row['claimed_until'] ?? 0 ) > $now ) {
				continue;
			}
			$queue[ $url ]['claim_token']   = $token;
			$queue[ $url ]['claimed_until'] = $now + $claim_ttl;
			$queue[ $url ]['updated']       = $now;
			$urls[]                         = (string) $row['url'];
			if ( count( $urls ) >= $limit ) {
				break;
			}
		}
		if ( empty( $urls ) ) {
			return array(
				'token' => '',
				'urls'  => array(),
			);
		}
		$this->write_queue( $queue );
		return array(
			'token' => $token,
			'urls'  => $urls,
		);
	}

	public function acquire_lock( string $source = 'system', int $ttl = self::DEFAULT_LOCK_TTL ): string {
		$source = sanitize_key( $source );
		if ( '' === $source ) {
			$source = 'system';
		}
		$ttl   = max( 60, min( 1800, $ttl ) );
		$state = $this->read_state();
		if ( $this->is_lock_active( $state ) ) {
			return '';
		}
		$token                     = wp_generate_password( 24, false, false );
		$state['lock_token']       = $token;
		$state['lock_source']      = $source;
		$state['lock_acquired_at'] = time();
		$state['lock_expires_at']  = time() + $ttl;
		$state['last_run_source']  = $source;
		$this->write_state( $state );
		return $token;
	}

	public function release_lock( string $token, string $status, string $message = '', int $batch_size = 0 ): void {
		$state = $this->read_state();
		if ( '' !== $token && (string) ( $state['lock_token'] ?? '' ) !== $token ) {
			return;
		}
		$status = sanitize_key( $status );
		if ( '' === $status ) {
			$status = 'completed';
		}
		$state['lock_token']       = '';
		$state['lock_source']      = '';
		$state['lock_acquired_at'] = 0;
		$state['lock_expires_at']  = 0;
		$state['last_run_at']      = time();
		$state['last_status']      = $status;
		$state['last_message']     = sanitize_text_field( $message );
		$state['last_batch_size']  = max( 0, $batch_size );
		$state['run_count']        = (int) ( $state['run_count'] ?? 0 ) + 1;
		if ( in_array( $status, array( 'success', 'completed', 'idle', 'rate_limited' ), true ) ) {
			$state['last_success_at']      = time();
			$state['consecutive_failures'] = 0;
		}
		if ( in_array( $status, array( 'failure', 'error' ), true ) ) {
			$state['last_failure_at']      = time();
			$state['consecutive_failures'] = (int) ( $state['consecutive_failures'] ?? 0 ) + 1;
		}
		$this->write_state( $state );
	}

	public function force_release_lock(): void {
		$queue = $this->read_queue();
		foreach ( $queue as $url => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$queue[ $url ]['claim_token']   = '';
			$queue[ $url ]['claimed_until'] = 0;
		}
		$this->write_queue( $queue );
		$state                     = $this->read_state();
		$state['lock_token']       = '';
		$state['lock_source']      = '';
		$state['lock_acquired_at'] = 0;
		$state['lock_expires_at']  = 0;
		$state['last_status']      = 'released';
		$state['last_message']     = __( 'Queue lock released manually.', 'open-growth-seo' );
		$state['last_run_at']      = time();
		$this->write_state( $state );
	}

	public function requeue_failed_recent( int $limit = 20 ): int {
		$limit  = max( 1, min( 100, $limit ) );
		$failed = $this->failed_recent();
		if ( empty( $failed ) ) {
			return 0;
		}
		$requeued = 0;
		foreach ( array_slice( array_reverse( $failed ), 0, $limit ) as $row ) {
			$url = isset( $row['url'] ) ? $this->normalize_url( (string) $row['url'] ) : '';
			if ( '' === $url ) {
				continue;
			}
			$this->enqueue( $url );
			++$requeued;
		}
		if ( $requeued > 0 ) {
			$remaining = array_slice( $failed, 0, max( 0, count( $failed ) - $requeued ) );
			update_option( self::FAILED_OPTION_KEY, $remaining, false );
		}
		return $requeued;
	}

	public function clear_failed(): void {
		update_option( self::FAILED_OPTION_KEY, array(), false );
		DeveloperTools::clear_diagnostics_cache();
	}

	private function read_queue(): array {
		$raw = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$queue = array();
		foreach ( $raw as $key => $value ) {
			if ( is_string( $value ) ) {
				$url = $this->normalize_url( $value );
				if ( '' === $url ) {
					continue;
				}
				$queue[ $url ] = array(
					'url'        => $url,
					'attempts'   => 0,
					'next_try'   => 0,
					'updated'    => time(),
					'last_error' => '',
				);
				continue;
			}
			if ( ! is_array( $value ) ) {
				$key_url = $this->normalize_url( (string) $key );
				if ( '' === $key_url ) {
					continue;
				}
				$queue[ $key_url ] = array(
					'url'        => $key_url,
					'attempts'   => 0,
					'next_try'   => 0,
					'updated'    => time(),
					'last_error' => '',
				);
				continue;
			}
			$url = $this->normalize_url( (string) ( $value['url'] ?? $key ) );
			if ( '' === $url ) {
				continue;
			}
			$queue[ $url ] = array(
				'url'        => $url,
				'attempts'   => max( 0, absint( $value['attempts'] ?? 0 ) ),
				'next_try'   => max( 0, absint( $value['next_try'] ?? 0 ) ),
				'updated'    => max( 0, absint( $value['updated'] ?? time() ) ),
				'last_error' => sanitize_text_field( (string) ( $value['last_error'] ?? '' ) ),
				'claim_token'  => sanitize_text_field( (string) ( $value['claim_token'] ?? '' ) ),
				'claimed_until' => max( 0, absint( $value['claimed_until'] ?? 0 ) ),
			);
		}
		return $queue;
	}

	private function write_queue( array $queue ): void {
		update_option( self::OPTION_KEY, $queue, false );
		DeveloperTools::clear_diagnostics_cache();
	}

	private function append_failed_item( string $url, int $attempts, string $message ): void {
		$failed = get_option( self::FAILED_OPTION_KEY, array() );
		if ( ! is_array( $failed ) ) {
			$failed = array();
		}
		$failed[] = array(
			'url'      => $url,
			'attempts' => $attempts,
			'error'    => sanitize_text_field( $message ),
			'time'     => time(),
		);
		if ( count( $failed ) > 100 ) {
			$failed = array_slice( $failed, -100 );
		}
		update_option( self::FAILED_OPTION_KEY, $failed, false );
		DeveloperTools::clear_diagnostics_cache();
	}

	private function read_state(): array {
		$state = get_option( self::STATE_OPTION_KEY, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		return array(
			'lock_token'           => sanitize_text_field( (string) ( $state['lock_token'] ?? '' ) ),
			'lock_source'          => sanitize_key( (string) ( $state['lock_source'] ?? '' ) ),
			'lock_acquired_at'     => max( 0, absint( $state['lock_acquired_at'] ?? 0 ) ),
			'lock_expires_at'      => max( 0, absint( $state['lock_expires_at'] ?? 0 ) ),
			'last_run_at'          => max( 0, absint( $state['last_run_at'] ?? 0 ) ),
			'last_success_at'      => max( 0, absint( $state['last_success_at'] ?? 0 ) ),
			'last_failure_at'      => max( 0, absint( $state['last_failure_at'] ?? 0 ) ),
			'last_status'          => sanitize_key( (string) ( $state['last_status'] ?? 'idle' ) ),
			'last_message'         => sanitize_text_field( (string) ( $state['last_message'] ?? '' ) ),
			'last_batch_size'      => max( 0, absint( $state['last_batch_size'] ?? 0 ) ),
			'consecutive_failures' => max( 0, absint( $state['consecutive_failures'] ?? 0 ) ),
			'run_count'            => max( 0, absint( $state['run_count'] ?? 0 ) ),
			'last_run_source'      => sanitize_key( (string) ( $state['last_run_source'] ?? '' ) ),
		);
	}

	private function write_state( array $state ): void {
		update_option( self::STATE_OPTION_KEY, $state, false );
		DeveloperTools::clear_diagnostics_cache();
	}

	private function is_lock_active( array $state ): bool {
		return ! empty( $state['lock_token'] ) && (int) ( $state['lock_expires_at'] ?? 0 ) > time();
	}

	private function ensure_processing_scheduled( int $timestamp ): void {
		$timestamp = max( time() + MINUTE_IN_SECONDS, $timestamp );
		$next      = (int) wp_next_scheduled( self::CRON_HOOK );
		if ( $next <= 0 || $next > $timestamp + 30 ) {
			wp_schedule_single_event( $timestamp, self::CRON_HOOK );
		}
	}

	private function ensure_next_due_processing( array $queue ): void {
		$next_due = 0;
		foreach ( $queue as $row ) {
			if ( ! is_array( $row ) || empty( $row['url'] ) ) {
				continue;
			}
			$claim_until = (int) ( $row['claimed_until'] ?? 0 );
			if ( $claim_until > time() ) {
				continue;
			}
			$next_try = max( time() + MINUTE_IN_SECONDS, (int) ( $row['next_try'] ?? 0 ) );
			$next_due = 0 === $next_due ? $next_try : min( $next_due, $next_try );
		}
		if ( $next_due > 0 ) {
			$this->ensure_processing_scheduled( $next_due );
		}
	}

	private function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( str_starts_with( $url, '/' ) ) {
			$url = home_url( $url );
		}
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$normalized = strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] );
		$normalized .= ! empty( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( ! empty( $parts['query'] ) ) {
			$normalized .= '?' . (string) $parts['query'];
		}
		return $normalized;
	}
}

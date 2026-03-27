<?php
use OpenGrowthSolutions\OpenGrowthSEO\Jobs\Queue;
use PHPUnit\Framework\TestCase;

final class JobsQueueOperationalTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_options'] = array();
		$GLOBALS['ogs_test_scheduled_events'] = array();
	}

	public function test_enqueue_schedules_processing_and_deduplicates_urls(): void {
		$queue = new Queue();
		$queue->enqueue( '/sample-post' );
		$queue->enqueue( 'https://example.com/sample-post' );

		$inspect = $queue->inspect();
		$this->assertSame( 1, (int) $inspect['pending'] );
		$this->assertGreaterThan( 0, (int) $inspect['schedule']['next_scheduled'] );
	}

	public function test_claim_batch_prevents_duplicate_claim_until_released(): void {
		$queue = new Queue();
		$queue->enqueue( '/claim-a' );
		$queue->enqueue( '/claim-b' );

		$claim = $queue->claim_batch( 2, 300 );
		$this->assertNotSame( '', (string) $claim['token'] );
		$this->assertCount( 2, $claim['urls'] );

		$second = $queue->claim_batch( 2, 300 );
		$this->assertSame( '', (string) $second['token'] );
		$this->assertSame( array(), $second['urls'] );

		$queue->mark_success_with_claim( $claim['urls'], (string) $claim['token'] );
		$this->assertSame( 0, $queue->pending_count() );
	}

	public function test_mark_failure_with_claim_requeues_and_tracks_failed_items(): void {
		$queue = new Queue();
		$queue->enqueue( '/needs-retry' );
		$claim = $queue->claim_batch( 1, 300 );

		$queue->mark_failure_with_claim( $claim['urls'], 'Temporary endpoint error', 1, (string) $claim['token'] );
		$inspect = $queue->inspect();
		$this->assertSame( 1, (int) $inspect['pending'] );
		$this->assertSame( 0, (int) $inspect['failed_count'] );
		$raw_queue = (array) get_option( 'ogs_seo_indexnow_queue', array() );
		$raw_queue['https://example.com/needs-retry']['next_try'] = 0;
		update_option( 'ogs_seo_indexnow_queue', $raw_queue, false );

		$claim = $queue->claim_batch( 1, 300 );
		$queue->mark_failure_with_claim( $claim['urls'], 'Permanent endpoint error', 1, (string) $claim['token'] );
		$inspect = $queue->inspect();
		$this->assertSame( 0, (int) $inspect['pending'] );
		$this->assertSame( 1, (int) $inspect['failed_count'] );

		$requeued = $queue->requeue_failed_recent( 10 );
		$this->assertSame( 1, $requeued );
		$this->assertSame( 1, $queue->pending_count() );

		$queue->clear_failed();
		$this->assertSame( array(), $queue->failed_recent() );
	}

	public function test_runner_lock_tracks_state_and_manual_release(): void {
		$queue = new Queue();
		$token = $queue->acquire_lock( 'admin', 300 );
		$this->assertNotSame( '', $token );

		$inspect = $queue->inspect();
		$this->assertTrue( (bool) $inspect['runner']['is_running'] );

		$queue->release_lock( $token, 'success', 'Processed batch.', 5 );
		$inspect = $queue->inspect();
		$this->assertFalse( (bool) $inspect['runner']['is_running'] );
		$this->assertSame( 'success', (string) $inspect['runner']['last_status'] );
		$this->assertSame( 5, (int) $inspect['runner']['last_batch_size'] );

		$token = $queue->acquire_lock( 'system', 300 );
		$this->assertNotSame( '', $token );
		$queue->force_release_lock();
		$inspect = $queue->inspect();
		$this->assertFalse( (bool) $inspect['runner']['is_running'] );
		$this->assertSame( 'released', (string) $inspect['runner']['last_status'] );
	}
}

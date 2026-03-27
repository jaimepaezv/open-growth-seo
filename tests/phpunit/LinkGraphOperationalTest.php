<?php
use PHPUnit\Framework\TestCase;
use OpenGrowthSolutions\OpenGrowthSEO\AEO\LinkGraph;

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( int $post_id ): string {
		return (string) ( $GLOBALS['ogs_test_permalinks'][ $post_id ] ?? '' );
	}
}

if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( string $field, int $post_id ) {
		if ( 'post_content' !== $field ) {
			return '';
		}
		return (string) ( $GLOBALS['ogs_test_post_contents'][ $post_id ] ?? '' );
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( int $post_id ): string {
		return (string) ( $GLOBALS['ogs_test_post_types_map'][ $post_id ] ?? 'post' );
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key, bool $single = false ) {
		unset( $single );
		return (string) ( $GLOBALS['ogs_test_post_meta'][ $post_id ][ $key ] ?? '' );
	}
}

if ( ! function_exists( 'get_post_modified_time' ) ) {
	function get_post_modified_time( string $format, bool $gmt, int $post_id ): string {
		unset( $format, $gmt );
		return (string) ( $GLOBALS['ogs_test_post_modified'][ $post_id ] ?? '' );
	}
}

final class LinkGraphOperationalTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_options'] = array();
		$GLOBALS['ogs_test_permalinks'] = array(
			101 => 'https://example.com/a/',
			102 => 'https://example.com/b/',
			103 => 'https://example.com/cornerstone/',
		);
		$GLOBALS['ogs_test_post_contents'] = array(
			101 => '<p><a href="/b/">Internal link to b</a></p>',
			102 => '<p>Unlinked target content body.</p>',
			103 => '<p>Cornerstone page with no inbound links.</p>',
		);
		$GLOBALS['ogs_test_post_types_map'] = array(
			101 => 'post',
			102 => 'post',
			103 => 'page',
		);
		$GLOBALS['ogs_test_post_meta'] = array(
			103 => array(
				'ogs_seo_cornerstone' => '1',
			),
		);
		$GLOBALS['ogs_test_post_modified'] = array(
			101 => gmdate( 'Y-m-d H:i:s', strtotime( '-20 days' ) ),
			102 => gmdate( 'Y-m-d H:i:s', strtotime( '-5 days' ) ),
			103 => gmdate( 'Y-m-d H:i:s', strtotime( '-400 days' ) ),
		);
		$GLOBALS['ogs_test_get_posts_callback'] = static function ( array $args ): array {
			unset( $args );
			return array( 101, 102, 103 );
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ogs_test_get_posts_callback'] );
		parent::tearDown();
	}

	public function test_snapshot_includes_orphan_clusters_and_remediation_queue(): void {
		$snapshot = LinkGraph::snapshot( true, 100 );
		$this->assertIsArray( $snapshot );
		$this->assertArrayHasKey( 'orphan_clusters', $snapshot );
		$this->assertArrayHasKey( 'remediation_queue', $snapshot );
		$this->assertNotEmpty( $snapshot['orphan_clusters'] );
		$this->assertNotEmpty( $snapshot['remediation_queue'] );

		$queue_post_ids = array_map(
			static fn( $row ) => is_array( $row ) ? (int) ( $row['post_id'] ?? 0 ) : 0,
			(array) $snapshot['remediation_queue']
		);
		$this->assertContains( 103, $queue_post_ids );
	}

	public function test_remediation_queue_api_returns_priority_sorted_items(): void {
		LinkGraph::snapshot( true, 100 );
		$queue = LinkGraph::remediation_queue( 5 );
		$this->assertNotEmpty( $queue );
		$this->assertLessThanOrEqual( 5, count( $queue ) );
		$this->assertGreaterThanOrEqual(
			(int) ( $queue[1]['priority'] ?? 0 ),
			(int) ( $queue[0]['priority'] ?? 0 )
		);
	}
}

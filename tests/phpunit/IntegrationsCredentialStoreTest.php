<?php
use OpenGrowthSolutions\OpenGrowthSEO\Integrations\CredentialStore;
use PHPUnit\Framework\TestCase;

final class IntegrationsCredentialStoreTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_options'] = array();
	}

	public function test_describe_service_reports_missing_and_stored_secrets(): void {
		CredentialStore::set_service_secret( 'google_search_console', 'client_id', 'client-id-value' );
		$summary = CredentialStore::describe_service( 'google_search_console', array( 'client_id', 'client_secret' ) );

		$this->assertSame( 1, (int) $summary['stored_count'] );
		$this->assertSame( array( 'client_secret' ), array_values( (array) $summary['missing_required'] ) );
		$this->assertTrue( (bool) $summary['encryption_available'] );
		$this->assertNotEmpty( $summary['fields']['client_id']['masked'] ?? '' );
	}

	public function test_clear_service_secret_removes_metadata(): void {
		CredentialStore::set_service_secret( 'bing_webmaster', 'api_key', 'bing-secret-value' );
		CredentialStore::clear_service_secret( 'bing_webmaster', 'api_key' );

		$summary = CredentialStore::describe_service( 'bing_webmaster', array( 'api_key' ) );
		$this->assertSame( 0, (int) $summary['stored_count'] );
		$this->assertSame( array( 'api_key' ), array_values( (array) $summary['missing_required'] ) );
	}
}

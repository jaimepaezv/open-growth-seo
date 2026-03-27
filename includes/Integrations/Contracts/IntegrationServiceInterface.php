<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Integrations\Contracts;

defined( 'ABSPATH' ) || exit;

interface IntegrationServiceInterface {
	public function slug(): string;

	public function label(): string;

	public function enabled_setting_key(): string;

	public function secret_fields(): array;

	public function status( array $settings, array $state, array $secrets ): array;

	public function test_connection( array $settings, array $secrets ): array;
}

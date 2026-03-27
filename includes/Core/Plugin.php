<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Core;

use OpenGrowthSolutions\OpenGrowthSEO\Admin\Admin;
use OpenGrowthSolutions\OpenGrowthSEO\Admin\Editor;
use OpenGrowthSolutions\OpenGrowthSEO\Admin\PluginLinks;
use OpenGrowthSolutions\OpenGrowthSEO\Admin\SetupWizard;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\Breadcrumbs;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\FrontendMeta;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\SiteOutputs;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\Redirects;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\Hreflang;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\LocalSeoLocations;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\Sitemaps;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\Robots;
use OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager;
use OpenGrowthSolutions\OpenGrowthSEO\AEO\Analyzer;
use OpenGrowthSolutions\OpenGrowthSEO\GEO\BotControls;
use OpenGrowthSolutions\OpenGrowthSEO\SFO\Analyzer as SfoAnalyzer;
use OpenGrowthSolutions\OpenGrowthSEO\Audit\AuditManager;
use OpenGrowthSolutions\OpenGrowthSEO\REST\Routes;
use OpenGrowthSolutions\OpenGrowthSEO\CLI\Commands;
use OpenGrowthSolutions\OpenGrowthSEO\Jobs\Queue;
use OpenGrowthSolutions\OpenGrowthSEO\Integrations\Manager as IntegrationManager;
use OpenGrowthSolutions\OpenGrowthSEO\Integrations\IndexNow;
use OpenGrowthSolutions\OpenGrowthSEO\Installation\Manager as InstallationManager;
use OpenGrowthSolutions\OpenGrowthSEO\Compatibility\ConflictManager;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Privacy;
use OpenGrowthSolutions\OpenGrowthSEO\Support\SiteHealth;

defined( 'ABSPATH' ) || exit;

class Plugin {
	private static ?self $instance = null;

	private bool $initialized = false;

	private ?ServiceRegistry $service_registry = null;

	public static function boot(): self {
		$plugin = self::instance();
		$plugin->init();
		return $plugin;
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		$definitions            = $this->service_definitions();
		$this->service_registry = new ServiceRegistry( $definitions );
		$this->service_registry->register();
		$this->initialized = true;

		/**
		 * Fires after the core plugin bootstrap finishes.
		 *
		 * @param Plugin $plugin Plugin instance.
		 * @param array  $services Registered service instances keyed by identifier.
		 */
		do_action( 'ogs_seo_core_booted', $this, $this->services() );
	}

	/**
	 * @return array<string, object>
	 */
	public function services(): array {
		return null === $this->service_registry ? array() : $this->service_registry->services();
	}

	public function get_service( string $service_id ): ?object {
		return null === $this->service_registry ? null : $this->service_registry->get( $service_id );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function service_definitions(): array {
		$definitions = array(
			'conflict_manager' => array(
				'class'    => ConflictManager::class,
				'contexts' => array( 'admin' ),
			),
			'admin' => array(
				'class'    => Admin::class,
				'contexts' => array( 'admin' ),
			),
			'editor' => array(
				'class'    => Editor::class,
				'contexts' => array( 'admin' ),
			),
			'plugin_links' => array(
				'class'    => PluginLinks::class,
				'contexts' => array( 'admin' ),
			),
			'setup_wizard' => array(
				'class'    => SetupWizard::class,
				'contexts' => array( 'admin' ),
			),
			'frontend_meta' => array(
				'class' => FrontendMeta::class,
			),
			'breadcrumbs' => array(
				'class' => Breadcrumbs::class,
			),
			'site_outputs' => array(
				'class' => SiteOutputs::class,
			),
			'redirects' => array(
				'class' => Redirects::class,
			),
			'hreflang' => array(
				'class' => Hreflang::class,
			),
			'local_locations' => array(
				'class' => LocalSeoLocations::class,
			),
			'sitemaps' => array(
				'class' => Sitemaps::class,
			),
			'robots' => array(
				'class' => Robots::class,
			),
			'schema_manager' => array(
				'class' => SchemaManager::class,
			),
			'analyzer' => array(
				'class' => Analyzer::class,
			),
			'bot_controls' => array(
				'class' => BotControls::class,
			),
			'sfo_analyzer' => array(
				'class' => SfoAnalyzer::class,
			),
			'audit_manager' => array(
				'class' => AuditManager::class,
			),
			'rest_routes' => array(
				'class' => Routes::class,
			),
			'queue' => array(
				'class' => Queue::class,
			),
			'integration_manager' => array(
				'class' => IntegrationManager::class,
			),
			'indexnow' => array(
				'class' => IndexNow::class,
			),
			'installation_manager' => array(
				'class'    => InstallationManager::class,
				'contexts' => array( 'admin' ),
			),
			'privacy' => array(
				'class'    => Privacy::class,
				'contexts' => array( 'admin' ),
			),
			'site_health' => array(
				'class'    => SiteHealth::class,
				'contexts' => array( 'admin' ),
			),
			'cli_commands' => array(
				'class'    => Commands::class,
				'contexts' => array( 'cli' ),
			),
		);

		/**
		 * Filter core service definitions before bootstrap registration.
		 *
		 * @param array  $definitions Service definitions keyed by identifier.
		 * @param Plugin $plugin      Plugin instance.
		 */
		$definitions = apply_filters( 'ogs_seo_core_services', $definitions, $this );

		return is_array( $definitions ) ? $definitions : array();
	}
}

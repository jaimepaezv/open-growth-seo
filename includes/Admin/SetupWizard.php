<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Admin;

use OpenGrowthSolutions\OpenGrowthSEO\Compatibility\Detector;
use OpenGrowthSolutions\OpenGrowthSEO\Installation\Manager as InstallationManager;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;
use OpenGrowthSolutions\OpenGrowthSEO\Support\ExperienceMode;

defined( 'ABSPATH' ) || exit;

class SetupWizard {
	private const DRAFT_OPTION = 'ogs_seo_wizard_draft';
	private const MAX_STEP     = 3;
	private const ALLOWED_SITE_TYPES = array( 'general', 'business', 'blog', 'ecommerce', 'forum' );
	private const ALLOWED_ACTIONS    = array( 'next', 'back', 'apply', 'cancel', 'restart' );

	public function register(): void {
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$draft     = $this->get_draft();
		$step      = isset( $draft['step'] ) ? absint( $draft['step'] ) : 1;
		$step      = min( self::MAX_STEP, max( 1, $step ) );
		$context   = $this->detect_context();
		$choices   = isset( $draft['choices'] ) && is_array( $draft['choices'] ) ? $draft['choices'] : $this->default_choices( $context );
		$completed = (int) Settings::get( 'wizard_completed', 0 );

		echo '<div class="ogs-wizard" aria-live="polite">';
		echo '<p>' . esc_html__( 'Use this setup wizard to configure safe defaults quickly. You can reopen it anytime.', 'open-growth-seo' ) . '</p>';
		if ( $completed && 1 === $step && empty( $draft ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Setup was already completed. You can run it again to adjust baseline defaults.', 'open-growth-seo' ) . '</p></div>';
		}
		if ( ! empty( $draft ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'A previous wizard session was found. Continue where you left off or restart if needed.', 'open-growth-seo' ) . '</p></div>';
		}
		$this->render_step_nav( $step );
		echo '<form method="post" id="ogs-setup-wizard-form">';
		wp_nonce_field( 'ogs_seo_setup_wizard' );
		echo '<input type="hidden" name="ogs_wizard_step" value="' . esc_attr( (string) $step ) . '" />';

		if ( 1 === $step ) {
			$this->render_step_detection( $context );
		}
		if ( 2 === $step ) {
			$this->render_step_configuration( $choices, $context );
		}
		if ( 3 === $step ) {
			$this->render_step_summary( $choices, $context );
		}

		$this->render_step_actions( $step );
		echo '</form>';
		echo '</div>';
	}

	public function handle_actions(): void {
		if ( ! isset( $_POST['ogs_wizard_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'ogs_seo_setup_wizard' ) ) {
			add_settings_error( 'ogs_seo', 'wizard_nonce', __( 'Security check failed. Please retry.', 'open-growth-seo' ), 'error' );
			return;
		}

		$action      = sanitize_key( (string) wp_unslash( $_POST['ogs_wizard_action'] ) );
		if ( ! in_array( $action, self::ALLOWED_ACTIONS, true ) ) {
			add_settings_error( 'ogs_seo', 'wizard_action', __( 'Unknown wizard action.', 'open-growth-seo' ), 'error' );
			$this->persist_settings_errors();
			return;
		}
		$step        = isset( $_POST['ogs_wizard_step'] ) ? absint( wp_unslash( $_POST['ogs_wizard_step'] ) ) : 1;
		$step        = min( self::MAX_STEP, max( 1, $step ) );
		$context     = $this->detect_context();
		$raw_choices = isset( $_POST['ogs_wizard'] ) ? (array) wp_unslash( $_POST['ogs_wizard'] ) : array();
		if ( empty( $raw_choices ) ) {
			$draft = $this->get_draft();
			if ( isset( $draft['choices'] ) && is_array( $draft['choices'] ) ) {
				$raw_choices = $draft['choices'];
			}
		}
		$choices = $this->sanitize_choices( $raw_choices, $context );

		if ( 'restart' === $action ) {
			delete_option( self::DRAFT_OPTION );
			add_settings_error( 'ogs_seo', 'wizard_restart', __( 'Setup wizard restarted.', 'open-growth-seo' ), 'updated' );
			$this->redirect_with_settings_errors( admin_url( 'admin.php?page=ogs-seo-setup' ) );
		}

		if ( 'cancel' === $action ) {
			update_option(
				self::DRAFT_OPTION,
				array(
					'step' => $step,
					'choices' => $choices,
				),
				false
			);
			add_settings_error( 'ogs_seo', 'wizard_cancel', __( 'Setup paused. You can continue later.', 'open-growth-seo' ), 'updated' );
			$this->redirect_with_settings_errors( admin_url( 'admin.php?page=ogs-seo-dashboard' ) );
		}

		if ( 'apply' === $action && self::MAX_STEP !== $step ) {
			add_settings_error( 'ogs_seo', 'wizard_step', __( 'Complete all steps before applying setup.', 'open-growth-seo' ), 'error' );
			update_option(
				self::DRAFT_OPTION,
				array(
					'step'    => $step,
					'choices' => $choices,
				),
				false
			);
			$this->redirect_with_settings_errors( admin_url( 'admin.php?page=ogs-seo-setup' ) );
		}

		$validation = $this->validate_step( $step, $choices, $context );
		if ( ! empty( $validation ) ) {
			foreach ( $validation as $msg ) {
				add_settings_error( 'ogs_seo', 'wizard_validation', $msg, 'error' );
			}
			update_option(
				self::DRAFT_OPTION,
				array(
					'step' => $step,
					'choices' => $choices,
				),
				false
			);
			$this->persist_settings_errors();
			return;
		}

		if ( 'back' === $action ) {
			$new_step = max( 1, $step - 1 );
			update_option(
				self::DRAFT_OPTION,
				array(
					'step' => $new_step,
					'choices' => $choices,
				),
				false
			);
			$this->redirect_with_settings_errors( admin_url( 'admin.php?page=ogs-seo-setup' ) );
		}

		if ( 'apply' === $action ) {
			$this->apply_choices( $choices, $context );
			delete_option( self::DRAFT_OPTION );
			add_settings_error( 'ogs_seo', 'wizard_done', __( 'Setup completed successfully.', 'open-growth-seo' ), 'updated' );
			$this->redirect_with_settings_errors( admin_url( 'admin.php?page=ogs-seo-dashboard' ) );
		}

		$new_step = min( self::MAX_STEP, $step + 1 );
		update_option(
			self::DRAFT_OPTION,
			array(
				'step' => $new_step,
				'choices' => $choices,
			),
			false
		);
		$this->redirect_with_settings_errors( admin_url( 'admin.php?page=ogs-seo-setup' ) );
	}

	private function get_draft(): array {
		$draft = get_option( self::DRAFT_OPTION, array() );
		return is_array( $draft ) ? $draft : array();
	}

	private function detect_context(): array {
		$report      = ( new InstallationManager() )->get_state();
		if ( empty( $report['schema_version'] ) ) {
			$report = ( new InstallationManager() )->rerun( 'wizard_context', false );
		}
		$diagnostics = isset( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ? $report['diagnostics'] : array();
		$signals     = isset( $diagnostics['signals'] ) && is_array( $diagnostics['signals'] ) ? $diagnostics['signals'] : array();
		$compat      = isset( $diagnostics['compatibility'] ) && is_array( $diagnostics['compatibility'] ) ? $diagnostics['compatibility'] : array();
		$robots_info = isset( $diagnostics['permissions']['robots'] ) && is_array( $diagnostics['permissions']['robots'] ) ? $diagnostics['permissions']['robots'] : array();
		$class       = isset( $report['classification'] ) && is_array( $report['classification'] ) ? $report['classification'] : array();
		$findings    = isset( $diagnostics['findings'] ) && is_array( $diagnostics['findings'] ) ? $diagnostics['findings'] : array();
		$pending     = isset( $report['pending'] ) && is_array( $report['pending'] ) ? $report['pending'] : array();
		$recommendations = isset( $report['recommendations'] ) && is_array( $report['recommendations'] ) ? $report['recommendations'] : array();
		$events      = isset( $report['events'] ) && is_array( $report['events'] ) ? $report['events'] : array();
		$repair_actions = isset( $report['repair_actions'] ) && is_array( $report['repair_actions'] ) ? $report['repair_actions'] : array();
		$seo_plugins = isset( $compat['active_seo_conflicts'] ) && is_array( $compat['active_seo_conflicts'] ) ? $compat['active_seo_conflicts'] : array();
		$languages   = isset( $signals['languages'] ) && is_array( $signals['languages'] ) ? $signals['languages'] : $this->detect_languages();

		return array(
			'site_type'       => isset( $class['wizard_site_type'] ) ? sanitize_key( (string) $class['wizard_site_type'] ) : $this->detect_site_type(),
			'site_profile'    => isset( $class['primary'] ) ? sanitize_key( (string) $class['primary'] ) : $this->detect_site_type(),
			'secondary_types' => isset( $class['secondary'] ) && is_array( $class['secondary'] ) ? array_map( 'sanitize_key', $class['secondary'] ) : array(),
			'confidence'      => isset( $class['confidence'] ) ? absint( $class['confidence'] ) : 0,
			'class_signals'   => isset( $class['signals'] ) && is_array( $class['signals'] ) ? array_map( 'sanitize_text_field', $class['signals'] ) : array(),
			'woo'             => ! empty( $signals['feature_plugins']['woocommerce'] ) || class_exists( 'WooCommerce' ),
			'languages'       => $languages,
			'seo_plugins'     => $seo_plugins,
			'indexable'       => ! empty( $diagnostics['runtime']['blog_public'] ),
			'robots_exists'   => ! empty( $robots_info['exists'] ),
			'robots_writable' => ! empty( $robots_info['writable'] ),
			'robots_source'   => isset( $robots_info['source'] ) ? sanitize_text_field( (string) $robots_info['source'] ) : 'virtual',
			'multisite'       => ! empty( $diagnostics['runtime']['multisite'] ),
			'status'          => isset( $report['status'] ) ? sanitize_key( (string) $report['status'] ) : 'partial_configuration',
			'setup_complete'  => ! empty( $report['setup_complete'] ),
			'wizard_recommended' => ! empty( $report['wizard_recommended'] ),
			'findings'        => $findings,
			'pending'         => $pending,
			'recommendations' => $recommendations,
			'events'          => $events,
			'repair_actions'  => array_values( array_map( 'sanitize_key', $repair_actions ) ),
		);
	}

	private function detect_robots( string $robots_path ): array {
		$exists = file_exists( $robots_path );
		return array(
			'exists'   => $exists,
			'writable' => ! $exists || is_writable( $robots_path ),
			'source'   => $exists ? 'physical' : 'virtual',
		);
	}

	private function detect_site_type(): string {
		$state = ( new InstallationManager() )->get_state();
		if ( ! empty( $state['classification']['wizard_site_type'] ) ) {
			return sanitize_key( (string) $state['classification']['wizard_site_type'] );
		}
		if ( class_exists( 'WooCommerce' ) ) {
			return 'ecommerce';
		}
		if ( class_exists( 'bbPress' ) ) {
			return 'forum';
		}
		$posts = function_exists( 'wp_count_posts' ) && is_object( wp_count_posts( 'post' ) ) ? (int) wp_count_posts( 'post' )->publish : 0;
		$pages = function_exists( 'wp_count_posts' ) && is_object( wp_count_posts( 'page' ) ) ? (int) wp_count_posts( 'page' )->publish : 0;
		if ( $posts > $pages ) {
			return 'blog';
		}
		if ( $pages > 10 ) {
			return 'business';
		}
		return 'general';
	}

	private function detect_languages(): array {
		$languages = array( get_locale() );
		if ( function_exists( 'pll_languages_list' ) ) {
			$languages = array_merge( $languages, (array) pll_languages_list() );
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && function_exists( 'icl_get_languages' ) ) {
			$wpml = icl_get_languages( 'skip_missing=0' );
			if ( is_array( $wpml ) ) {
				foreach ( $wpml as $lang ) {
					if ( ! empty( $lang['language_code'] ) ) {
						$languages[] = (string) $lang['language_code'];
					}
				}
			}
		}
		return array_values( array_unique( array_map( 'sanitize_text_field', $languages ) ) );
	}

	private function detect_seo_plugins(): array {
		$plugins        = array();
		foreach ( Detector::detect_providers() as $provider ) {
			if ( empty( $provider['active'] ) ) {
				continue;
			}
			$label = isset( $provider['label'] ) ? sanitize_text_field( (string) $provider['label'] ) : '';
			if ( '' !== $label ) {
				$plugins[] = $label;
			}
		}
		return array_values( array_unique( $plugins ) );
	}

	private function default_choices( array $context ): array {
		return array(
			'mode'                   => 'simple',
			'site_type'              => $context['site_type'],
			'visibility'             => 'keep',
			'safe_mode_seo_conflict' => ! empty( $context['seo_plugins'] ) ? '1' : '0',
			'confirm_private'        => '0',
		);
	}

	private function sanitize_choices( array $raw, array $context ): array {
		$defaults = $this->default_choices( $context );
		$choices  = wp_parse_args( $raw, $defaults );

		$choices['mode']                   = ExperienceMode::normalize( (string) $choices['mode'] );
		$choices['site_type']              = sanitize_key( (string) $choices['site_type'] );
		$choices['site_type']              = in_array( $choices['site_type'], self::ALLOWED_SITE_TYPES, true ) ? $choices['site_type'] : $defaults['site_type'];
		$choices['visibility']             = in_array( $choices['visibility'], array( 'keep', 'public', 'private' ), true ) ? $choices['visibility'] : 'keep';
		$choices['safe_mode_seo_conflict'] = ! empty( $choices['safe_mode_seo_conflict'] ) ? '1' : '0';
		$choices['confirm_private']        = ! empty( $choices['confirm_private'] ) ? '1' : '0';

		if ( ! empty( $context['seo_plugins'] ) ) {
			$choices['safe_mode_seo_conflict'] = '1';
		}

		return $choices;
	}

	private function validate_step( int $step, array $choices, array $context ): array {
		$errors = array();
		$site_type = isset( $choices['site_type'] ) ? (string) $choices['site_type'] : '';
		if ( 2 === $step && 'ecommerce' === $site_type && empty( $context['woo'] ) ) {
			$errors[] = __( 'eCommerce mode requires WooCommerce to be active. Choose another site type or activate WooCommerce first.', 'open-growth-seo' );
		}
		if ( 2 === $step && 'private' === $choices['visibility'] && '1' !== $choices['confirm_private'] ) {
			$errors[] = __( 'Confirm index blocking before continuing with private visibility.', 'open-growth-seo' );
		}
		if ( 2 === $step && ! empty( $context['seo_plugins'] ) && '1' !== $choices['safe_mode_seo_conflict'] ) {
			$errors[] = __( 'Safe mode is required while another SEO plugin is active.', 'open-growth-seo' );
		}
		return $errors;
	}

	private function apply_choices( array $choices, array $context ): void {
		$settings                             = Settings::get_all();
		$settings['mode']                     = ExperienceMode::normalize( (string) $choices['mode'] );
		$settings['seo_masters_plus_enabled'] = ExperienceMode::ADVANCED === (string) $settings['mode'] ? 1 : 0;
		$settings['safe_mode_seo_conflict']   = (int) $choices['safe_mode_seo_conflict'];
		$settings['wizard_completed']         = 1;
		$settings['sitemap_enabled']          = 1;
		$settings['robots_mode']              = 'managed';
		$settings['schema_organization_enabled'] = 1;
		$settings['schema_article_enabled']   = 1;

		$post_types = array( 'post', 'page' );
		if ( $context['woo'] ) {
			$post_types[] = 'product';
		}
		$settings['sitemap_post_types'] = array_values( array_unique( array_map( 'sanitize_key', $post_types ) ) );

		if ( 'private' === $choices['visibility'] ) {
			update_option( 'blog_public', '0', false );
			$settings['default_index'] = 'noindex,follow';
		}
		if ( 'public' === $choices['visibility'] ) {
			update_option( 'blog_public', '1', false );
			$settings['default_index'] = 'index,follow';
		}
		if ( 'keep' === $choices['visibility'] ) {
			$settings['default_index'] = ! empty( $context['indexable'] ) ? 'index,follow' : 'noindex,follow';
		}

		if ( 'ecommerce' === $choices['site_type'] && $context['woo'] ) {
			$settings['title_template'] = '%%title%% %%sep%% %%sitename%%';
			$settings['woo_product_archive'] = 'index';
			$settings['woo_product_cat_archive'] = 'index';
			$settings['woo_product_tag_archive'] = 'noindex';
			$settings['woo_schema_mode'] = 'native';
		}
		if ( 'business' === $choices['site_type'] ) {
			$settings['schema_local_business_enabled'] = 1;
		}
		if ( 'forum' === $choices['site_type'] ) {
			$settings['schema_discussion_enabled'] = 1;
			$settings['schema_qapage_enabled']     = 1;
			$settings['search_results']            = 'noindex';
		}
		if ( 'blog' === $choices['site_type'] ) {
			$settings['schema_article_enabled'] = 1;
			$settings['author_archive']         = 'index';
		}

		Settings::update( $settings );
		( new InstallationManager() )->rerun( 'wizard_apply', true );
	}

	private function render_step_nav( int $step ): void {
		echo '<p class="screen-reader-text" role="status">' . esc_html( sprintf( __( 'Setup wizard step %1$d of %2$d.', 'open-growth-seo' ), $step, self::MAX_STEP ) ) . '</p>';
		echo '<ol class="ogs-inline-stats ogs-wizard-progress" aria-label="' . esc_attr__( 'Setup wizard progress', 'open-growth-seo' ) . '">';
		for ( $index = 1; $index <= self::MAX_STEP; $index++ ) {
			$label = sprintf(
				/* translators: %d: step number. */
				__( 'Step %d', 'open-growth-seo' ),
				$index
			);
			$is_current = $index === $step;
			echo '<li class="' . esc_attr( $is_current ? 'is-current' : '' ) . '"' . ( $is_current ? ' aria-current="step"' : '' ) . '><strong>' . esc_html( $label ) . '</strong>' . ( $is_current ? ' - ' . esc_html__( 'Current', 'open-growth-seo' ) : '' ) . '</li>';
		}
		echo '</ol>';
	}

	private function render_step_detection( array $context ): void {
		echo '<h2>' . esc_html__( 'Step 1: Environment Check', 'open-growth-seo' ) . '</h2>';
		echo '<p>' . esc_html__( 'We detected your environment, repair status, and recommended safe defaults before applying anything.', 'open-growth-seo' ) . '</p>';
		echo '<div class="ogs-grid ogs-grid-overview ogs-wizard-overview">';
		echo '<div class="ogs-card"><h3>' . esc_html__( 'Detected profile', 'open-growth-seo' ) . '</h3><p class="ogs-status ogs-status-good">' . esc_html( ucfirst( str_replace( '_', ' ', (string) $context['site_profile'] ) ) ) . '</p><p class="description">' . esc_html( sprintf( __( 'Confidence %d%%', 'open-growth-seo' ), (int) $context['confidence'] ) ) . '</p></div>';
		echo '<div class="ogs-card"><h3>' . esc_html__( 'Installation state', 'open-growth-seo' ) . '</h3><p class="ogs-status ' . esc_attr( 'ready' === $context['status'] ? 'ogs-status-good' : 'ogs-status-warn' ) . '">' . esc_html( ucfirst( str_replace( '_', ' ', (string) $context['status'] ) ) ) . '</p><p class="description">' . esc_html( ! empty( $context['setup_complete'] ) ? __( 'A setup baseline already exists. Re-running adjusts it safely.', 'open-growth-seo' ) : __( 'This looks like an incomplete or first-time setup path.', 'open-growth-seo' ) ) . '</p></div>';
		echo '<div class="ogs-card"><h3>' . esc_html__( 'SEO coexistence', 'open-growth-seo' ) . '</h3><p class="ogs-status ' . esc_attr( empty( $context['seo_plugins'] ) ? 'ogs-status-good' : 'ogs-status-warn' ) . '">' . esc_html( empty( $context['seo_plugins'] ) ? __( 'Clear', 'open-growth-seo' ) : __( 'Safe mode required', 'open-growth-seo' ) ) . '</p><p class="description">' . esc_html( empty( $context['seo_plugins'] ) ? __( 'No other supported SEO plugin detected.', 'open-growth-seo' ) : implode( ', ', $context['seo_plugins'] ) ) . '</p></div>';
		echo '</div>';
		echo '<table class="widefat striped"><tbody>';
		echo '<tr><th>' . esc_html__( 'Detected site profile', 'open-growth-seo' ) . '</th><td>' . esc_html( ucfirst( str_replace( '_', ' ', (string) $context['site_profile'] ) ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Suggested setup baseline', 'open-growth-seo' ) . '</th><td>' . esc_html( ucfirst( (string) $context['site_type'] ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Detection confidence', 'open-growth-seo' ) . '</th><td>' . esc_html( sprintf( '%d%%', (int) $context['confidence'] ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Installation status', 'open-growth-seo' ) . '</th><td>' . esc_html( ucfirst( str_replace( '_', ' ', (string) $context['status'] ) ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'WooCommerce', 'open-growth-seo' ) . '</th><td>' . esc_html( $context['woo'] ? __( 'Active', 'open-growth-seo' ) : __( 'Not active', 'open-growth-seo' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Languages', 'open-growth-seo' ) . '</th><td>' . esc_html( implode( ', ', $context['languages'] ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Other SEO plugins', 'open-growth-seo' ) . '</th><td>' . esc_html( empty( $context['seo_plugins'] ) ? __( 'None detected', 'open-growth-seo' ) : implode( ', ', $context['seo_plugins'] ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Indexability', 'open-growth-seo' ) . '</th><td>' . esc_html( $context['indexable'] ? __( 'Search indexing allowed', 'open-growth-seo' ) : __( 'Search indexing discouraged', 'open-growth-seo' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'robots.txt source', 'open-growth-seo' ) . '</th><td>' . esc_html( 'physical' === $context['robots_source'] ? __( 'Physical file detected', 'open-growth-seo' ) : __( 'Virtual WordPress output', 'open-growth-seo' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'robots.txt writable', 'open-growth-seo' ) . '</th><td>' . esc_html( $context['robots_writable'] ? __( 'Writable', 'open-growth-seo' ) : __( 'Not writable', 'open-growth-seo' ) ) . '</td></tr>';
		echo '</tbody></table>';
		if ( ! empty( $context['repair_actions'] ) ) {
			echo '<p><strong>' . esc_html__( 'Automatic recovery actions', 'open-growth-seo' ) . '</strong></p><ul>';
			foreach ( $context['repair_actions'] as $action ) {
				echo '<li>' . esc_html( ucfirst( str_replace( '_', ' ', (string) $action ) ) ) . '</li>';
			}
			echo '</ul>';
		}
		if ( ! empty( $context['class_signals'] ) ) {
			echo '<p><strong>' . esc_html__( 'Signals used for detection', 'open-growth-seo' ) . '</strong></p><ul>';
			foreach ( array_slice( (array) $context['class_signals'], 0, 5 ) as $signal ) {
				echo '<li>' . esc_html( (string) $signal ) . '</li>';
			}
			echo '</ul>';
		}
		if ( ! empty( $context['pending'] ) ) {
			echo '<p><strong>' . esc_html__( 'Needs follow-up after install', 'open-growth-seo' ) . '</strong></p><ul>';
			foreach ( array_slice( (array) $context['pending'], 0, 5 ) as $item ) {
				if ( is_array( $item ) && ! empty( $item['message'] ) ) {
					echo '<li>' . esc_html( (string) $item['message'] ) . '</li>';
				}
			}
			echo '</ul>';
		}
		if ( ! empty( $context['findings'] ) ) {
			echo '<p><strong>' . esc_html__( 'Environment notes', 'open-growth-seo' ) . '</strong></p><ul>';
			foreach ( array_slice( (array) $context['findings'], 0, 5 ) as $finding ) {
				if ( is_array( $finding ) && ! empty( $finding['message'] ) ) {
					echo '<li>' . esc_html( (string) $finding['message'] ) . '</li>';
				}
			}
			echo '</ul>';
		}
		if ( ! empty( $context['recommendations'] ) ) {
			echo '<p><strong>' . esc_html__( 'What Open Growth SEO will optimize first', 'open-growth-seo' ) . '</strong></p><ul>';
			foreach ( array_slice( array_values( $context['recommendations'] ), 0, 5 ) as $recommendation ) {
				if ( is_array( $recommendation ) ) {
					$reason = isset( $recommendation['reason'] ) ? (string) $recommendation['reason'] : '';
					if ( '' !== trim( $reason ) ) {
						echo '<li>' . esc_html( $reason ) . '</li>';
					}
				}
			}
			echo '</ul>';
		}
		if ( $context['multisite'] ) {
			echo '<p class="description">' . esc_html__( 'Multisite detected: setup applies to this site only.', 'open-growth-seo' ) . '</p>';
		}
		if ( ! $context['robots_writable'] ) {
			echo '<p class="description">' . esc_html__( 'A non-writable physical robots.txt file can override managed bot settings. Use Bots & Crawlers in expert mode if needed.', 'open-growth-seo' ) . '</p>';
		}
	}

	private function render_step_configuration( array $choices, array $context ): void {
		echo '<h2>' . esc_html__( 'Step 2: Choose Defaults', 'open-growth-seo' ) . '</h2>';
		echo '<p>' . esc_html__( 'Pick a baseline. The wizard keeps safe defaults on by default and reserves higher-risk controls for later expert review.', 'open-growth-seo' ) . '</p>';
		echo '<div class="ogs-grid ogs-grid-2">';
		echo '<div class="ogs-card">';

		echo '<p><strong>' . esc_html__( 'Mode', 'open-growth-seo' ) . '</strong><br/>';
		echo '<label><input type="radio" name="ogs_wizard[mode]" value="simple" ' . checked( 'simple', $choices['mode'], false ) . '/> ' . esc_html__( 'Simple (recommended)', 'open-growth-seo' ) . '</label><br/>';
		echo '<label><input type="radio" name="ogs_wizard[mode]" value="advanced" ' . checked( 'advanced', $choices['mode'], false ) . '/> ' . esc_html__( 'Advanced', 'open-growth-seo' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Advanced mode enables SEO MASTERS PLUS expert controls by default. Keep Simple mode for novice-safe workflows.', 'open-growth-seo' ) . '</p>';

		echo '<p><label><strong>' . esc_html__( 'Site Type', 'open-growth-seo' ) . '</strong><br/>';
		echo '<select name="ogs_wizard[site_type]">';
		foreach ( array( 'general', 'business', 'blog', 'ecommerce', 'forum' ) as $type ) {
			echo '<option value="' . esc_attr( $type ) . '" ' . selected( $choices['site_type'], $type, false ) . '>' . esc_html( ucfirst( $type ) ) . '</option>';
		}
		echo '</select></label></p>';

		echo '<p><strong>' . esc_html__( 'Indexability', 'open-growth-seo' ) . '</strong><br/>';
		echo '<label><input type="radio" name="ogs_wizard[visibility]" value="keep" ' . checked( 'keep', $choices['visibility'], false ) . '/> ' . esc_html__( 'Keep current site visibility', 'open-growth-seo' ) . '</label><br/>';
		echo '<label><input type="radio" name="ogs_wizard[visibility]" value="public" ' . checked( 'public', $choices['visibility'], false ) . '/> ' . esc_html__( 'Allow indexing', 'open-growth-seo' ) . '</label><br/>';
		echo '<label><input type="radio" name="ogs_wizard[visibility]" value="private" ' . checked( 'private', $choices['visibility'], false ) . '/> ' . esc_html__( 'Discourage indexing (staging/private)', 'open-growth-seo' ) . '</label></p>';
		echo '<p id="ogs-private-confirm" ' . ( 'private' === $choices['visibility'] ? '' : 'hidden="hidden"' ) . '><label><input type="checkbox" name="ogs_wizard[confirm_private]" value="1" ' . checked( '1', $choices['confirm_private'], false ) . '/> ' . esc_html__( 'I understand that this can reduce search visibility.', 'open-growth-seo' ) . '</label></p>';

		$force_safe_mode = ! empty( $context['seo_plugins'] );
		echo '<p><label><input type="checkbox" name="ogs_wizard[safe_mode_seo_conflict]" value="1" ' . checked( '1', $choices['safe_mode_seo_conflict'], false ) . disabled( $force_safe_mode, true, false ) . '/> ' . esc_html__( 'Enable safe mode when another SEO plugin is active', 'open-growth-seo' ) . '</label></p>';
		if ( ! empty( $context['seo_plugins'] ) ) {
			echo '<p class="description">' . esc_html__( 'Safe mode is enforced because another SEO plugin is active.', 'open-growth-seo' ) . '</p>';
		}
		echo '</div>';
		echo '<div class="ogs-card"><h3>' . esc_html__( 'Configuration notes', 'open-growth-seo' ) . '</h3><ul>';
		echo '<li>' . esc_html__( 'Simple mode keeps the publishing workflow calmer and reduces accidental expert overrides.', 'open-growth-seo' ) . '</li>';
		echo '<li>' . esc_html__( 'The selected site type mostly influences schema, sitemap, and archive defaults. You can refine details later.', 'open-growth-seo' ) . '</li>';
		echo '<li>' . esc_html__( 'Private visibility is intended for staging or intentionally private environments and requires explicit confirmation.', 'open-growth-seo' ) . '</li>';
		if ( ! empty( $context['woo'] ) ) {
			echo '<li>' . esc_html__( 'WooCommerce is active, so product archive defaults and native product schema will be prepared automatically.', 'open-growth-seo' ) . '</li>';
		}
		if ( ! empty( $context['multisite'] ) ) {
			echo '<li>' . esc_html__( 'This wizard only configures the current site in the network.', 'open-growth-seo' ) . '</li>';
		}
		echo '</ul></div>';
		echo '</div>';
	}

	private function render_step_summary( array $choices, array $context ): void {
		echo '<h2>' . esc_html__( 'Step 3: Review and Apply', 'open-growth-seo' ) . '</h2>';
		echo '<p>' . esc_html__( 'Review the configuration that will be applied. This step highlights what changes immediately and what still needs manual follow-up.', 'open-growth-seo' ) . '</p>';
		echo '<div class="ogs-grid ogs-grid-2">';
		echo '<div class="ogs-card">';
		echo '<table class="widefat striped"><tbody>';
		echo '<tr><th>' . esc_html__( 'Mode', 'open-growth-seo' ) . '</th><td>' . esc_html( ucfirst( $choices['mode'] ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'SEO MASTERS PLUS', 'open-growth-seo' ) . '</th><td>' . esc_html( 'advanced' === (string) $choices['mode'] ? __( 'Enabled', 'open-growth-seo' ) : __( 'Disabled', 'open-growth-seo' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Site type', 'open-growth-seo' ) . '</th><td>' . esc_html( ucfirst( $choices['site_type'] ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Visibility choice', 'open-growth-seo' ) . '</th><td>' . esc_html( ucfirst( $choices['visibility'] ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Safe mode', 'open-growth-seo' ) . '</th><td>' . esc_html( '1' === $choices['safe_mode_seo_conflict'] ? __( 'Enabled', 'open-growth-seo' ) : __( 'Disabled', 'open-growth-seo' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Default sitemap post types', 'open-growth-seo' ) . '</th><td>' . esc_html( $context['woo'] ? 'post, page, product' : 'post, page' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Wizard re-open', 'open-growth-seo' ) . '</th><td>' . esc_html__( 'Always available from Open Growth SEO > Setup Wizard.', 'open-growth-seo' ) . '</td></tr>';
		echo '</tbody></table>';
		echo '</div>';
		echo '<div class="ogs-card"><h3>' . esc_html__( 'After applying', 'open-growth-seo' ) . '</h3><ul>';
		echo '<li>' . esc_html__( 'Installation diagnostics will run again and refresh the setup profile.', 'open-growth-seo' ) . '</li>';
		echo '<li>' . esc_html__( 'Safe defaults will be applied conservatively without overwriting established custom settings unnecessarily.', 'open-growth-seo' ) . '</li>';
		if ( ! empty( $context['pending'] ) ) {
			foreach ( array_slice( (array) $context['pending'], 0, 4 ) as $item ) {
				if ( is_array( $item ) && ! empty( $item['message'] ) ) {
					echo '<li>' . esc_html( (string) $item['message'] ) . '</li>';
				}
			}
		} else {
			echo '<li>' . esc_html__( 'No immediate follow-up items are currently detected.', 'open-growth-seo' ) . '</li>';
		}
		echo '</ul></div>';
		echo '</div>';
		foreach ( $choices as $key => $value ) {
			echo '<input type="hidden" name="ogs_wizard[' . esc_attr( (string) $key ) . ']" value="' . esc_attr( (string) $value ) . '" />';
		}
	}

	private function render_step_actions( int $step ): void {
		echo '<p class="ogs-actions">';
		if ( $step > 1 ) {
			echo '<button type="submit" class="button" name="ogs_wizard_action" value="back">' . esc_html__( 'Back', 'open-growth-seo' ) . '</button> ';
		}
		if ( $step < self::MAX_STEP ) {
			echo '<button type="submit" class="button button-primary" name="ogs_wizard_action" value="next">' . esc_html__( 'Continue', 'open-growth-seo' ) . '</button> ';
		} else {
			echo '<button type="submit" class="button button-primary" name="ogs_wizard_action" value="apply">' . esc_html__( 'Apply Configuration', 'open-growth-seo' ) . '</button> ';
		}
		echo '<button type="submit" class="button" name="ogs_wizard_action" value="cancel">' . esc_html__( 'Pause Wizard', 'open-growth-seo' ) . '</button> ';
		echo '<button type="submit" class="button" name="ogs_wizard_action" value="restart" onclick="return confirm(\'' . esc_js( __( 'Restarting will discard the current wizard draft. Continue?', 'open-growth-seo' ) ) . '\');">' . esc_html__( 'Restart', 'open-growth-seo' ) . '</button>';
		echo '</p>';
	}

	private function persist_settings_errors(): void {
		set_transient( 'settings_errors', get_settings_errors(), 30 );
	}

	private function redirect_with_settings_errors( string $url ): void {
		$this->persist_settings_errors();
		wp_safe_redirect( $url );
		exit;
	}
}

<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Admin;

use OpenGrowthSolutions\OpenGrowthSEO\AEO\Analyzer;
use OpenGrowthSolutions\OpenGrowthSEO\AEO\LinkGraph;
use OpenGrowthSolutions\OpenGrowthSEO\AEO\LinkSuggestions;
use OpenGrowthSolutions\OpenGrowthSEO\GEO\BotControls;
use OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager;
use OpenGrowthSolutions\OpenGrowthSEO\SFO\Analyzer as SfoAnalyzer;
use OpenGrowthSolutions\OpenGrowthSEO\Schema\Eligibility;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\Breadcrumbs;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;
use OpenGrowthSolutions\OpenGrowthSEO\Support\ExperienceMode;
use OpenGrowthSolutions\OpenGrowthSEO\Support\SeoMastersPlus;

defined( 'ABSPATH' ) || exit;

class Editor {
	private const EXCLUDED_POST_TYPES = array( 'attachment' );

	private array $fields = array(
		'ogs_seo_title'              => 'sanitize_text_field',
		'ogs_seo_description'        => 'sanitize_textarea_field',
		'ogs_seo_focus_keyphrase'    => 'sanitize_text_field',
		'ogs_seo_cornerstone'        => array( self::class, 'sanitize_cornerstone_meta' ),
		'ogs_seo_primary_term'       => array( self::class, 'sanitize_primary_term_meta' ),
		'ogs_seo_canonical'          => array( self::class, 'sanitize_canonical_url_meta' ),
		'ogs_seo_robots'             => array( self::class, 'sanitize_robots_pair_meta' ),
		'ogs_seo_index'              => array( self::class, 'sanitize_index_meta' ),
		'ogs_seo_follow'             => array( self::class, 'sanitize_follow_meta' ),
		'ogs_seo_social_title'       => 'sanitize_text_field',
		'ogs_seo_social_description' => 'sanitize_textarea_field',
		'ogs_seo_social_image'       => 'esc_url_raw',
		'ogs_seo_max_snippet'        => array( self::class, 'sanitize_numeric_preview_meta' ),
		'ogs_seo_max_image_preview'  => array( self::class, 'sanitize_image_preview_meta' ),
		'ogs_seo_max_video_preview'  => array( self::class, 'sanitize_numeric_preview_meta' ),
		'ogs_seo_nosnippet'          => array( self::class, 'sanitize_inherit_toggle_meta' ),
		'ogs_seo_noarchive'          => array( self::class, 'sanitize_inherit_toggle_meta' ),
		'ogs_seo_notranslate'        => array( self::class, 'sanitize_inherit_toggle_meta' ),
		'ogs_seo_unavailable_after'  => array( self::class, 'sanitize_unavailable_after_meta' ),
		'ogs_seo_data_nosnippet_ids' => array( self::class, 'sanitize_id_lines_meta' ),
		'ogs_seo_schema_type'        => array( self::class, 'sanitize_schema_type_meta' ),
		'ogs_seo_schema_course_code' => 'sanitize_text_field',
		'ogs_seo_schema_course_credential' => 'sanitize_text_field',
		'ogs_seo_schema_program_credential' => 'sanitize_text_field',
		'ogs_seo_schema_duration'    => 'sanitize_text_field',
		'ogs_seo_schema_program_mode' => 'sanitize_text_field',
		'ogs_seo_schema_person_job_title' => 'sanitize_text_field',
		'ogs_seo_schema_person_affiliation' => 'sanitize_text_field',
		'ogs_seo_schema_same_as'     => array( self::class, 'sanitize_same_as_lines_meta' ),
		'ogs_seo_schema_scholarly_identifier' => 'sanitize_text_field',
		'ogs_seo_schema_scholarly_publication' => 'sanitize_text_field',
		'ogs_seo_schema_event_location_name' => 'sanitize_text_field',
		'ogs_seo_schema_event_attendance_mode' => array( self::class, 'sanitize_event_attendance_mode_meta' ),
		'ogs_seo_schema_service_type' => 'sanitize_text_field',
		'ogs_seo_schema_service_area' => 'sanitize_text_field',
		'ogs_seo_schema_service_channel_url' => 'esc_url_raw',
		'ogs_seo_schema_offer_price' => array( self::class, 'sanitize_decimal_meta' ),
		'ogs_seo_schema_offer_currency' => 'sanitize_text_field',
		'ogs_seo_schema_offer_category' => 'sanitize_text_field',
		'ogs_seo_schema_api_docs_url' => 'esc_url_raw',
		'ogs_seo_schema_project_status' => 'sanitize_text_field',
		'ogs_seo_schema_review_rating' => array( self::class, 'sanitize_rating_meta' ),
		'ogs_seo_schema_reviewed_item_name' => 'sanitize_text_field',
		'ogs_seo_schema_reviewed_item_type' => 'sanitize_text_field',
		'ogs_seo_schema_defined_term_code' => 'sanitize_text_field',
		'ogs_seo_schema_defined_term_set' => 'sanitize_text_field',
	);

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'metabox' ) );
		add_action( 'save_post', array( $this, 'save' ) );
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'classic_editor_assets' ) );
		add_action( 'wp_ajax_ogs_seo_schema_preview', array( $this, 'schema_preview_ajax' ) );
	}

	public function register_meta(): void {
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $post_types as $post_type ) {
			if ( ! $this->is_supported_post_type( (string) $post_type ) ) {
				continue;
			}
			foreach ( $this->fields as $key => $sanitize ) {
				register_post_meta(
					(string) $post_type,
					$key,
					array(
						'show_in_rest'      => array(
							'schema' => array(
								'type'    => 'string',
								'default' => '',
								'context' => array( 'view', 'edit' ),
							),
						),
						'single'            => true,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => $sanitize,
						'auth_callback'     => array( self::class, 'can_edit_meta' ),
					)
				);
			}
		}
	}

	public function editor_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$this->enqueue_editor_assets_for_screen( $screen );
	}

	public function classic_editor_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( is_object( $screen ) && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return;
		}
		$this->enqueue_editor_assets_for_screen( $screen );
	}

	private function enqueue_editor_assets_for_screen( $screen ): void {
		if ( ! is_object( $screen ) || empty( $screen->post_type ) || ! $this->is_supported_post_type( (string) $screen->post_type ) ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style(
			'ogs-seo-admin',
			OGS_SEO_URL . 'assets/css/admin.css',
			array(),
			OGS_SEO_VERSION
		);
		wp_enqueue_script(
			'ogs-seo-editor',
			OGS_SEO_URL . 'assets/js/editor.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-components', 'wp-element', 'wp-data', 'wp-i18n', 'wp-block-editor', 'media-editor', 'wp-api-fetch', 'wp-notices' ),
			OGS_SEO_VERSION,
			true
		);
		$post_id = $this->current_editor_post_id();
		$settings = Settings::get_all();
		wp_localize_script(
			'ogs-seo-editor',
			'ogsSeoEditor',
			array(
				'siteName'                => (string) get_bloginfo( 'name' ),
				'siteDescription'         => (string) get_bloginfo( 'description' ),
				'blogPublic'              => (int) get_option( 'blog_public', 1 ),
				'indexabilityPublicText'  => __( 'Site visibility is public. You can still set this URL to noindex if needed.', 'open-growth-seo' ),
				'indexabilityPrivateText' => __( 'Site visibility discourages indexing globally. URL-level index settings can still be saved but may be overridden by site visibility.', 'open-growth-seo' ),
				'selectImageText'         => __( 'Select from Media Library', 'open-growth-seo' ),
				'replaceImageText'        => __( 'Replace image', 'open-growth-seo' ),
				'removeImageText'         => __( 'Remove image', 'open-growth-seo' ),
				'mediaFrameTitle'         => __( 'Select social image', 'open-growth-seo' ),
				'mediaFrameButton'        => __( 'Use image URL', 'open-growth-seo' ),
				'mediaPreviewAlt'         => __( 'Selected social image preview', 'open-growth-seo' ),
				'manualImageHint'         => __( 'Paste an image URL or choose one from the Media Library.', 'open-growth-seo' ),
				'editorMode'              => ExperienceMode::current( $settings ),
				'editorSections'          => ExperienceMode::editor_section_defaults( $settings ),
				'mastersPlus'             => array(
					'available'   => SeoMastersPlus::is_available( $settings ),
					'active'      => SeoMastersPlus::is_active( $settings ),
					'settingsUrl' => admin_url( 'admin.php?page=ogs-seo-masters-plus' ),
				),
				'mastersDiagnostics'      => ( $post_id > 0 && SeoMastersPlus::is_available( $settings ) ) ? $this->masters_plus_editor_snapshot( $post_id, $settings ) : array(),
				'postId'                  => $post_id,
				'metaDefaults'            => $post_id > 0 ? $this->meta_snapshot( $post_id ) : array(),
				'schemaOverride'          => $post_id > 0 ? $this->schema_override_state( $post_id ) : array(),
				'primaryTermOptions'      => $post_id > 0 ? Breadcrumbs::primary_term_option_records( $post_id ) : array(),
				'linkSuggestions'         => $post_id > 0
					? array(
						'outbound' => LinkSuggestions::outbound_targets( $post_id, 4 ),
						'inbound'  => LinkSuggestions::inbound_opportunities( $post_id, 4 ),
					)
					: array(),
				'contentMetaEndpoint'     => $post_id > 0 ? rest_url( 'ogs-seo/v1/content-meta/' . $post_id ) : '',
				'metaSaveErrorText'       => __( 'Open Growth SEO could not save its fields after the post update. Refresh and try again.', 'open-growth-seo' ),
				'metaSaveSuccessText'     => __( 'Open Growth SEO fields saved.', 'open-growth-seo' ),
				'schemaPreviewAjaxUrl'    => admin_url( 'admin-ajax.php' ),
				'schemaPreviewNonce'      => wp_create_nonce( 'ogs_seo_schema_preview' ),
				'schemaPreviewInitial'    => $post_id > 0 ? $this->schema_runtime_preview_state( $post_id ) : array(),
				'schemaPreviewLoadingText' => __( 'Refreshing saved JSON-LD preview...', 'open-growth-seo' ),
				'schemaPreviewStaleText'  => __( 'This preview reflects the last saved post state. Save the post to refresh final JSON-LD.', 'open-growth-seo' ),
				'schemaPreviewRefreshText' => __( 'Refresh saved preview', 'open-growth-seo' ),
				'schemaPreviewUnavailableText' => __( 'Schema preview is unavailable for this post.', 'open-growth-seo' ),
			)
		);
	}

	public function meta_snapshot( int $post_id ): array {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return array();
		}

		$values = array();
		foreach ( $this->fields as $key => $sanitize ) {
			unset( $sanitize );
			$values[ $key ] = (string) get_post_meta( $post_id, $key, true );
		}
		$values['ogs_seo_index']  = (string) get_post_meta( $post_id, 'ogs_seo_index', true );
		$values['ogs_seo_follow'] = (string) get_post_meta( $post_id, 'ogs_seo_follow', true );
		$values['ogs_seo_robots'] = (string) get_post_meta( $post_id, 'ogs_seo_robots', true );

		if ( '' === $values['ogs_seo_index'] ) {
			$values['ogs_seo_index'] = 'index';
		}
		if ( '' === $values['ogs_seo_follow'] ) {
			$values['ogs_seo_follow'] = 'follow';
		}
		if ( '' === $values['ogs_seo_robots'] ) {
			$values['ogs_seo_robots'] = $values['ogs_seo_index'] . ',' . $values['ogs_seo_follow'];
		}

		return $values;
	}

	public function persist_meta_values( int $post_id, array $payload ): array {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return array();
		}
		$settings = Settings::get_all();

		$index = array_key_exists( 'ogs_seo_index', $payload )
			? self::sanitize_index_meta( (string) $payload['ogs_seo_index'] )
			: ( (string) get_post_meta( $post_id, 'ogs_seo_index', true ) ?: 'index' );
		$follow = array_key_exists( 'ogs_seo_follow', $payload )
			? self::sanitize_follow_meta( (string) $payload['ogs_seo_follow'] )
			: ( (string) get_post_meta( $post_id, 'ogs_seo_follow', true ) ?: 'follow' );

		update_post_meta( $post_id, 'ogs_seo_index', $index );
		update_post_meta( $post_id, 'ogs_seo_follow', $follow );
		update_post_meta( $post_id, 'ogs_seo_robots', $index . ',' . $follow );

		foreach ( $this->fields as $key => $sanitize ) {
			if ( in_array( $key, array( 'ogs_seo_index', 'ogs_seo_follow', 'ogs_seo_robots' ), true ) ) {
				continue;
			}
			if ( ! array_key_exists( $key, $payload ) ) {
				continue;
			}
			$value = call_user_func( $sanitize, (string) $payload[ $key ] );
			if ( 'ogs_seo_max_snippet' === $key || 'ogs_seo_max_video_preview' === $key ) {
				$value = self::sanitize_numeric_preview_meta( (string) $value );
			}
			if ( 'ogs_seo_max_image_preview' === $key ) {
				$value = self::sanitize_image_preview_meta( (string) $value );
			}
			if ( in_array( $key, array( 'ogs_seo_nosnippet', 'ogs_seo_noarchive', 'ogs_seo_notranslate' ), true ) ) {
				$value = self::sanitize_inherit_toggle_meta( (string) $value );
			}
			if ( 'ogs_seo_unavailable_after' === $key ) {
				$value = self::sanitize_unavailable_after_meta( (string) $value );
			}
			if ( 'ogs_seo_data_nosnippet_ids' === $key ) {
				$value = self::sanitize_id_lines_meta( (string) $value );
			}
			if ( 'ogs_seo_schema_type' === $key ) {
				$value = Eligibility::sanitize_override_for_post( (string) $value, $post_id, $settings );
			}
			if ( 'ogs_seo_primary_term' === $key ) {
				$value = Breadcrumbs::sanitize_primary_term_for_post( (string) $value, $post_id );
			}
			update_post_meta( $post_id, $key, $value );
		}

		return $this->meta_snapshot( $post_id );
	}

	public function metabox(): void {
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			if ( ! $this->is_supported_post_type( (string) $post_type ) ) {
				continue;
			}
			add_meta_box( 'ogs-seo-metabox', __( 'Open Growth SEO', 'open-growth-seo' ), array( $this, 'render' ), $post_type, 'normal', 'high' );
		}
	}

	public function render( \WP_Post $post ): void {
		wp_nonce_field( 'ogs_seo_post_meta', 'ogs_seo_post_meta_nonce' );
		$settings                = Settings::get_all();
		$simple_mode             = ExperienceMode::is_simple( $settings );
		$section_defaults        = ExperienceMode::editor_section_defaults( $settings );
		$field_meta              = $this->content_field_meta();
		$meta                    = $this->meta_snapshot( (int) $post->ID );
		$index                   = (string) ( $meta['ogs_seo_index'] ?? 'index' );
		$follow                  = (string) ( $meta['ogs_seo_follow'] ?? 'follow' );
		$nosnippet               = self::sanitize_inherit_toggle_meta( (string) ( $meta['ogs_seo_nosnippet'] ?? '' ) );
		$noarchive               = self::sanitize_inherit_toggle_meta( (string) ( $meta['ogs_seo_noarchive'] ?? '' ) );
		$notranslate             = self::sanitize_inherit_toggle_meta( (string) ( $meta['ogs_seo_notranslate'] ?? '' ) );
		$unavailable_after_value = $this->datetime_local_input_value( (string) ( $meta['ogs_seo_unavailable_after'] ?? '' ) );
		$social_image_value      = (string) ( $meta['ogs_seo_social_image'] ?? '' );
		$schema_state            = $this->schema_override_state( (int) $post->ID, $settings );
		$schema_type_for_details = (string) ( $schema_state['current'] ?: ( $schema_state['auto_detected'] ?? '' ) );
		$show_academic_details   = $this->should_render_academic_schema_section( $schema_type_for_details, $simple_mode );
		$show_specialized_details = $this->should_render_specialized_schema_section( $schema_type_for_details, $simple_mode );
		$primary_term_options    = Breadcrumbs::primary_term_option_records( (int) $post->ID );
		$masters_plus_available  = SeoMastersPlus::is_available( $settings );
		$masters_plus_active     = SeoMastersPlus::is_active( $settings );
		$masters_plus_snapshot   = $masters_plus_available ? $this->masters_plus_editor_snapshot( (int) $post->ID, $settings ) : array();
		?>
		<div class="ogs-editor-layout">
			<?php $this->render_editor_section_start( 'basics', __( 'Basics', 'open-growth-seo' ), $section_defaults['basics'], __( 'Core title, description, canonical, and indexing controls.', 'open-growth-seo' ) ); ?>
				<p><label><strong><?php esc_html_e( 'SEO Title', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_title" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_title'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_title']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_title']['help'] ) ); ?></p>
				<p><label><strong><?php esc_html_e( 'Meta Description', 'open-growth-seo' ); ?></strong><br/><textarea class="widefat" rows="3" name="ogs_seo_description" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_description']['placeholder'] ); ?>"><?php echo esc_textarea( (string) ( $meta['ogs_seo_description'] ?? '' ) ); ?></textarea></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_description']['help'] ) ); ?></p>
				<p><label><strong><?php esc_html_e( 'Canonical URL', 'open-growth-seo' ); ?></strong><br/><input type="text" inputmode="url" class="widefat" name="ogs_seo_canonical" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_canonical'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_canonical']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_canonical']['help'] ) ); ?></p>
				<p class="description"><?php esc_html_e( 'You can use an absolute URL (http/https) or a root-relative path. Invalid values are ignored.', 'open-growth-seo' ); ?></p>
				<div class="ogs-editor-grid">
					<p><label><strong><?php esc_html_e( 'Indexing', 'open-growth-seo' ); ?></strong><br/><select name="ogs_seo_index"><option value="index" <?php selected( $index, 'index' ); ?>>index</option><option value="noindex" <?php selected( $index, 'noindex' ); ?>>noindex</option></select></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_index']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Links', 'open-growth-seo' ); ?></strong><br/><select name="ogs_seo_follow"><option value="follow" <?php selected( $follow, 'follow' ); ?>>follow</option><option value="nofollow" <?php selected( $follow, 'nofollow' ); ?>>nofollow</option></select></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_follow']['help'] ) ); ?></p>
				</div>
				<?php if ( count( $primary_term_options ) > 1 ) : ?>
					<p><label><strong><?php esc_html_e( 'Primary topic', 'open-growth-seo' ); ?></strong><br/>
						<select name="ogs_seo_primary_term">
							<?php foreach ( $primary_term_options as $option ) : ?>
								<option value="<?php echo esc_attr( (string) $option['value'] ); ?>" <?php selected( (string) ( $meta['ogs_seo_primary_term'] ?? '' ), (string) $option['value'] ); ?>><?php echo esc_html( (string) $option['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_primary_term']['help'] ) ); ?></p>
				<?php endif; ?>
				<p class="description"><?php echo esc_html( get_option( 'blog_public' ) ? __( 'Safe default: indexing is currently allowed site-wide. Use noindex here only when this URL should stay out of search.', 'open-growth-seo' ) : __( 'Safe default: site visibility currently discourages indexing globally. This URL-level setting may still be overridden by site visibility.', 'open-growth-seo' ) ); ?></p>
			<?php $this->render_editor_section_end(); ?>

			<?php $this->render_editor_section_start( 'social', __( 'Social', 'open-growth-seo' ), $section_defaults['social'], __( 'Optional overrides for how this URL looks when shared.', 'open-growth-seo' ) ); ?>
				<p><label><strong><?php esc_html_e( 'Social Title (optional)', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_social_title" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_social_title'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_social_title']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_social_title']['help'] ) ); ?></p>
				<p><label><strong><?php esc_html_e( 'Social Description (optional)', 'open-growth-seo' ); ?></strong><br/><textarea class="widefat" rows="2" name="ogs_seo_social_description" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_social_description']['placeholder'] ); ?>"><?php echo esc_textarea( (string) ( $meta['ogs_seo_social_description'] ?? '' ) ); ?></textarea></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_social_description']['help'] ) ); ?></p>
				<p>
					<label for="ogs-seo-social-image-field"><strong><?php esc_html_e( 'Social Image URL (optional)', 'open-growth-seo' ); ?></strong></label><br/>
					<input id="ogs-seo-social-image-field" type="url" class="widefat ogs-social-image-url-field" name="ogs_seo_social_image" value="<?php echo esc_attr( $social_image_value ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_social_image']['placeholder'] ); ?>"/>
					<span class="description ogs-field-help"><?php esc_html_e( 'Paste an image URL or choose one from the Media Library.', 'open-growth-seo' ); ?></span>
					<span class="ogs-social-image-actions">
						<button type="button" class="button ogs-select-social-image"><?php echo esc_html( '' !== $social_image_value ? __( 'Replace image', 'open-growth-seo' ) : __( 'Select from Media Library', 'open-growth-seo' ) ); ?></button>
						<button type="button" class="button-link-delete ogs-remove-social-image<?php echo '' === $social_image_value ? ' ogs-is-hidden' : ''; ?>"><?php esc_html_e( 'Remove image', 'open-growth-seo' ); ?></button>
					</span>
					<span class="ogs-social-image-preview<?php echo '' === $social_image_value ? ' ogs-is-hidden' : ''; ?>">
						<img src="<?php echo esc_url( $social_image_value ); ?>" alt="<?php esc_attr_e( 'Selected social image preview', 'open-growth-seo' ); ?>" />
					</span>
					<?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_social_image']['help'] ) ); ?>
				</p>
				<p class="description"><?php esc_html_e( 'Safe default: leave social fields empty to inherit the SEO title, description, and featured image when available.', 'open-growth-seo' ); ?></p>
			<?php $this->render_editor_section_end(); ?>

			<?php $this->render_editor_section_start( 'advanced-snippet', __( 'Advanced snippet controls', 'open-growth-seo' ), $section_defaults['advanced_snippet'], __( 'Use only when you intentionally want to restrict previews or snippets.', 'open-growth-seo' ) ); ?>
				<div class="ogs-editor-grid">
					<p><label><strong><?php esc_html_e( 'Max Snippet', 'open-growth-seo' ); ?></strong><br/><input type="number" min="-1" step="1" inputmode="numeric" name="ogs_seo_max_snippet" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_max_snippet'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_max_snippet']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_max_snippet']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Max Image Preview', 'open-growth-seo' ); ?></strong><br/><select name="ogs_seo_max_image_preview"><option value="" <?php selected( (string) ( $meta['ogs_seo_max_image_preview'] ?? '' ), '' ); ?>><?php esc_html_e( 'Inherit', 'open-growth-seo' ); ?></option><option value="large" <?php selected( (string) ( $meta['ogs_seo_max_image_preview'] ?? '' ), 'large' ); ?>>large</option><option value="standard" <?php selected( (string) ( $meta['ogs_seo_max_image_preview'] ?? '' ), 'standard' ); ?>>standard</option><option value="none" <?php selected( (string) ( $meta['ogs_seo_max_image_preview'] ?? '' ), 'none' ); ?>>none</option></select></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_max_image_preview']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Max Video Preview', 'open-growth-seo' ); ?></strong><br/><input type="number" min="-1" step="1" inputmode="numeric" name="ogs_seo_max_video_preview" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_max_video_preview'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_max_video_preview']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_max_video_preview']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'nosnippet', 'open-growth-seo' ); ?></strong><br/><select name="ogs_seo_nosnippet"><option value="" <?php selected( $nosnippet, '' ); ?>><?php esc_html_e( 'Inherit', 'open-growth-seo' ); ?></option><option value="1" <?php selected( $nosnippet, '1' ); ?>><?php esc_html_e( 'Enable', 'open-growth-seo' ); ?></option><option value="0" <?php selected( $nosnippet, '0' ); ?>><?php esc_html_e( 'Disable', 'open-growth-seo' ); ?></option></select></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_nosnippet']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'noarchive', 'open-growth-seo' ); ?></strong><br/><select name="ogs_seo_noarchive"><option value="" <?php selected( $noarchive, '' ); ?>><?php esc_html_e( 'Inherit', 'open-growth-seo' ); ?></option><option value="1" <?php selected( $noarchive, '1' ); ?>><?php esc_html_e( 'Enable', 'open-growth-seo' ); ?></option><option value="0" <?php selected( $noarchive, '0' ); ?>><?php esc_html_e( 'Disable', 'open-growth-seo' ); ?></option></select></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_noarchive']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'notranslate', 'open-growth-seo' ); ?></strong><br/><select name="ogs_seo_notranslate"><option value="" <?php selected( $notranslate, '' ); ?>><?php esc_html_e( 'Inherit', 'open-growth-seo' ); ?></option><option value="1" <?php selected( $notranslate, '1' ); ?>><?php esc_html_e( 'Enable', 'open-growth-seo' ); ?></option><option value="0" <?php selected( $notranslate, '0' ); ?>><?php esc_html_e( 'Disable', 'open-growth-seo' ); ?></option></select></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_notranslate']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'unavailable_after', 'open-growth-seo' ); ?></strong><br/><input type="datetime-local" name="ogs_seo_unavailable_after" value="<?php echo esc_attr( $unavailable_after_value ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_unavailable_after']['help'] ) ); ?></p>
				</div>
				<p><label><strong><?php esc_html_e( 'data-nosnippet IDs (one per line, without #)', 'open-growth-seo' ); ?></strong><br/><textarea class="widefat" rows="3" name="ogs_seo_data_nosnippet_ids" placeholder="pricing-table&#10;answer-summary"><?php echo esc_textarea( (string) ( $meta['ogs_seo_data_nosnippet_ids'] ?? '' ) ); ?></textarea></label></p>
				<?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_data_nosnippet_ids']['help'] ) ); ?>
				<p class="description"><?php esc_html_e( 'Guardrail: if nosnippet is enabled, max-snippet is ignored. Keep restrictive controls focused and minimal.', 'open-growth-seo' ); ?></p>
			<?php $this->render_editor_section_end(); ?>

			<?php $this->render_editor_section_start( 'schema', __( 'Schema', 'open-growth-seo' ), $section_defaults['schema'], __( 'Automatic schema is safest. Use overrides only when the visible page clearly matches the selected type.', 'open-growth-seo' ) ); ?>
				<p><label><strong><?php esc_html_e( 'Schema Type Override', 'open-growth-seo' ); ?></strong><br/>
					<select class="widefat" name="ogs_seo_schema_type">
						<?php foreach ( $schema_state['options'] as $option ) : ?>
							<option value="<?php echo esc_attr( (string) $option['value'] ); ?>" <?php selected( $schema_state['current'], (string) $option['value'] ); ?>><?php echo esc_html( (string) $option['label'] ); ?></option>
						<?php endforeach; ?>
						<?php if ( ! empty( $schema_state['legacy_current'] ) ) : ?>
							<option value="<?php echo esc_attr( (string) $schema_state['legacy_current'] ); ?>" selected="selected"><?php echo esc_html( sprintf( __( '%s (saved legacy override)', 'open-growth-seo' ), (string) $schema_state['legacy_current'] ) ); ?></option>
						<?php endif; ?>
					</select>
				</label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_type']['help'] ) ); ?></p>
				<p class="description"><?php echo esc_html( (string) $schema_state['message'] ); ?></p>
				<?php if ( ! empty( $schema_state['auto_detected'] ) ) : ?>
					<p class="description"><?php echo esc_html( sprintf( __( 'Auto currently resolves to %1$s. %2$s', 'open-growth-seo' ), (string) $schema_state['auto_detected'], (string) ( $schema_state['auto_reason'] ?? '' ) ) ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $schema_state['current_reason'] ) ) : ?>
					<p class="description"><?php echo esc_html( (string) $schema_state['current_reason'] ); ?></p>
				<?php endif; ?>
				<?php if ( $simple_mode ) : ?>
					<p class="description"><?php esc_html_e( 'Simple mode hides advanced schema types until you deliberately switch to Advanced mode.', 'open-growth-seo' ); ?></p>
				<?php endif; ?>
			<?php $this->render_editor_section_end(); ?>

			<?php if ( $show_academic_details ) : ?>
			<?php $this->render_editor_section_start( 'academic-schema', __( 'Academic schema details', 'open-growth-seo' ), ! $simple_mode && $this->is_academic_schema_type( $schema_type_for_details ), __( 'Use these fields when the page represents a person, course, degree program, scholarly article, or academic event. This is especially helpful for custom post types.', 'open-growth-seo' ) ); ?>
				<div class="ogs-editor-grid">
					<p><label><strong><?php esc_html_e( 'Course Code', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_course_code" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_course_code'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_course_code']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_course_code']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Course Credential', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_course_credential" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_course_credential'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_course_credential']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_course_credential']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Program Credential', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_program_credential" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_program_credential'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_program_credential']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_program_credential']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Program Duration', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_duration" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_duration'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_duration']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_duration']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Program Mode', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_program_mode" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_program_mode'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_program_mode']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_program_mode']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Person Job Title', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_person_job_title" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_person_job_title'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_person_job_title']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_person_job_title']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Person Affiliation', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_person_affiliation" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_person_affiliation'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_person_affiliation']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_person_affiliation']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Scholarly Identifier', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_scholarly_identifier" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_scholarly_identifier'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_scholarly_identifier']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_scholarly_identifier']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Publication / Journal', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_scholarly_publication" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_scholarly_publication'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_scholarly_publication']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_scholarly_publication']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Event Location Name', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_event_location_name" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_event_location_name'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_event_location_name']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_event_location_name']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Event Attendance Mode', 'open-growth-seo' ); ?></strong><br/><select name="ogs_seo_schema_event_attendance_mode"><option value="" <?php selected( (string) ( $meta['ogs_seo_schema_event_attendance_mode'] ?? '' ), '' ); ?>><?php esc_html_e( 'Default offline event', 'open-growth-seo' ); ?></option><option value="OfflineEventAttendanceMode" <?php selected( (string) ( $meta['ogs_seo_schema_event_attendance_mode'] ?? '' ), 'OfflineEventAttendanceMode' ); ?>><?php esc_html_e( 'Offline / in person', 'open-growth-seo' ); ?></option><option value="OnlineEventAttendanceMode" <?php selected( (string) ( $meta['ogs_seo_schema_event_attendance_mode'] ?? '' ), 'OnlineEventAttendanceMode' ); ?>><?php esc_html_e( 'Online only', 'open-growth-seo' ); ?></option><option value="MixedEventAttendanceMode" <?php selected( (string) ( $meta['ogs_seo_schema_event_attendance_mode'] ?? '' ), 'MixedEventAttendanceMode' ); ?>><?php esc_html_e( 'Mixed online + in person', 'open-growth-seo' ); ?></option></select></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_event_attendance_mode']['help'] ) ); ?></p>
				</div>
				<p><label><strong><?php esc_html_e( 'sameAs URLs (one per line)', 'open-growth-seo' ); ?></strong><br/><textarea class="widefat" rows="4" name="ogs_seo_schema_same_as" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_same_as']['placeholder'] ); ?>"><?php echo esc_textarea( (string) ( $meta['ogs_seo_schema_same_as'] ?? '' ) ); ?></textarea></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_same_as']['help'] ) ); ?></p>
				<p class="description"><?php esc_html_e( 'Recommended university mappings: faculty/staff CPT -> Person, course CPT -> Course, degree/program CPT -> EducationalOccupationalProgram, publication CPT -> ScholarlyArticle, event CPT -> Event.', 'open-growth-seo' ); ?></p>
			<?php $this->render_editor_section_end(); ?>
			<?php endif; ?>

			<?php if ( $show_specialized_details ) : ?>
			<?php $this->render_editor_section_start( 'specialized-schema', __( 'Specialized schema details', 'open-growth-seo' ), ! $simple_mode, __( 'Use these fields for services, APIs, reviews, glossary terms, collections, and other advanced CPT-driven schema types.', 'open-growth-seo' ) ); ?>
				<div class="ogs-editor-grid">
					<p><label><strong><?php esc_html_e( 'Service Type', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_service_type" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_service_type'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_service_type']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_service_type']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Service Area', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_service_area" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_service_area'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_service_area']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_service_area']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Service Channel URL', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_service_channel_url" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_service_channel_url'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_service_channel_url']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_service_channel_url']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Offer Price', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_offer_price" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_offer_price'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_offer_price']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_offer_price']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Offer Currency', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_offer_currency" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_offer_currency'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_offer_currency']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_offer_currency']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Offer Category', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_offer_category" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_offer_category'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_offer_category']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_offer_category']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'API Docs URL', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_api_docs_url" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_api_docs_url'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_api_docs_url']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_api_docs_url']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Project Status', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_project_status" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_project_status'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_project_status']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_project_status']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Review Rating', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_review_rating" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_review_rating'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_review_rating']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_review_rating']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Reviewed Item Name', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_reviewed_item_name" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_reviewed_item_name'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_reviewed_item_name']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_reviewed_item_name']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Reviewed Item Type', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_reviewed_item_type" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_reviewed_item_type'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_reviewed_item_type']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_reviewed_item_type']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Defined Term Code', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_defined_term_code" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_defined_term_code'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_defined_term_code']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_defined_term_code']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Defined Term Set', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_schema_defined_term_set" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_schema_defined_term_set'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $field_meta['ogs_seo_schema_defined_term_set']['placeholder'] ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_schema_defined_term_set']['help'] ) ); ?></p>
				</div>
				<p class="description"><?php esc_html_e( 'Recommended CPT mappings: service CPT -> Service or OfferCatalog, docs/API CPT -> TechArticle or WebAPI, project/case-study CPT -> Project, glossary CPT -> DefinedTerm, glossary index CPT -> DefinedTermSet, tutorial/help CPT -> Guide.', 'open-growth-seo' ); ?></p>
			<?php $this->render_editor_section_end(); ?>
			<?php endif; ?>

			<?php $this->render_editor_section_start( 'schema-preview', __( 'Final schema preview', 'open-growth-seo' ), ! $simple_mode, __( 'This preview shows the saved JSON-LD currently emitted by the runtime schema engine for this post.', 'open-growth-seo' ) ); ?>
				<?php $this->render_schema_runtime_preview( (int) $post->ID ); ?>
			<?php $this->render_editor_section_end(); ?>

			<?php $this->render_editor_section_start( 'aeo-hints', __( 'AEO hints', 'open-growth-seo' ), $section_defaults['aeo_hints'], __( 'Focus on the next best improvements instead of abstract scoring.', 'open-growth-seo' ) ); ?>
				<div class="ogs-editor-grid">
					<p><label><strong><?php esc_html_e( 'Focus keyphrase', 'open-growth-seo' ); ?></strong><br/><input class="widefat" name="ogs_seo_focus_keyphrase" value="<?php echo esc_attr( (string) ( $meta['ogs_seo_focus_keyphrase'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'technical seo audit', 'open-growth-seo' ); ?>"/></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_focus_keyphrase']['help'] ) ); ?></p>
					<p><label><strong><?php esc_html_e( 'Cornerstone content', 'open-growth-seo' ); ?></strong><br/><select name="ogs_seo_cornerstone"><option value="" <?php selected( (string) ( $meta['ogs_seo_cornerstone'] ?? '' ), '' ); ?>><?php esc_html_e( 'Standard page', 'open-growth-seo' ); ?></option><option value="1" <?php selected( (string) ( $meta['ogs_seo_cornerstone'] ?? '' ), '1' ); ?>><?php esc_html_e( 'Cornerstone content', 'open-growth-seo' ); ?></option></select></label><?php echo wp_kses_post( $this->field_help_markup( $field_meta['ogs_seo_cornerstone']['help'] ) ); ?></p>
				</div>
				<p class="description"><?php esc_html_e( 'Safe default: only mark pages as cornerstone when they deserve recurring internal links and regular freshness checks.', 'open-growth-seo' ); ?></p>
				<?php $this->render_content_analysis( $post ); ?>
			<?php $this->render_editor_section_end(); ?>

			<?php if ( $masters_plus_available ) : ?>
				<?php $this->render_editor_section_start( 'masters-plus', __( 'SEO MASTERS PLUS', 'open-growth-seo' ), ! empty( $section_defaults['masters_plus'] ), __( 'Expert diagnostics and guardrails for advanced SEO operations. Keep disabled for novice workflows.', 'open-growth-seo' ) ); ?>
					<?php if ( ! $masters_plus_active ) : ?>
						<p class="description"><?php esc_html_e( 'SEO MASTERS PLUS is disabled. Enable it from plugin settings when you need expert workflows and stricter operational diagnostics.', 'open-growth-seo' ); ?></p>
					<?php else : ?>
						<div class="ogs-editor-grid">
							<p><strong><?php esc_html_e( 'Orphan confidence', 'open-growth-seo' ); ?>:</strong> <?php echo esc_html( (string) ( $masters_plus_snapshot['orphan_confidence'] ?? __( 'Low', 'open-growth-seo' ) ) ); ?></p>
							<p><strong><?php esc_html_e( 'Inbound internal links', 'open-growth-seo' ); ?>:</strong> <?php echo esc_html( (string) ( $masters_plus_snapshot['inbound_links'] ?? 0 ) ); ?></p>
							<p><strong><?php esc_html_e( 'Cluster score', 'open-growth-seo' ); ?>:</strong> <?php echo esc_html( (string) ( $masters_plus_snapshot['cluster_score'] ?? 0 ) ); ?></p>
							<p><strong><?php esc_html_e( 'Canonical state', 'open-growth-seo' ); ?>:</strong> <?php echo esc_html( (string) ( $masters_plus_snapshot['canonical_state'] ?? __( 'Default', 'open-growth-seo' ) ) ); ?></p>
						</div>
						<?php if ( ! empty( $masters_plus_snapshot['actions'] ) ) : ?>
							<p><strong><?php esc_html_e( 'Expert next actions', 'open-growth-seo' ); ?></strong></p>
							<ol class="ogs-coaching-actions">
								<?php foreach ( array_slice( (array) $masters_plus_snapshot['actions'], 0, 3 ) as $action ) : ?>
									<li><?php echo esc_html( (string) $action ); ?></li>
								<?php endforeach; ?>
							</ol>
						<?php endif; ?>
					<?php endif; ?>
				<?php $this->render_editor_section_end(); ?>
			<?php endif; ?>

			<?php $this->render_editor_section_start( 'preview', __( 'Preview', 'open-growth-seo' ), $section_defaults['preview'], __( 'Indicative preview of search and social appearance.', 'open-growth-seo' ) ); ?>
				<?php $this->render_snippet_preview( $post ); ?>
			<?php $this->render_editor_section_end(); ?>
		</div>
		<?php
	}

	private function render_editor_section_start( string $slug, string $title, bool $open, string $description = '' ): void {
		echo '<details class="ogs-editor-section ogs-editor-section-' . esc_attr( $slug ) . '"' . ( $open ? ' open="open"' : '' ) . '>';
		echo '<summary><span class="ogs-editor-section-title">' . esc_html( $title ) . '</span></summary>';
		echo '<div class="ogs-editor-section-body">';
		if ( '' !== $description ) {
			echo '<p class="description ogs-editor-section-description">' . esc_html( $description ) . '</p>';
		}
	}

	private function render_editor_section_end(): void {
		echo '</div></details>';
	}

	public function schema_preview_ajax(): void {
		if ( ! check_ajax_referer( 'ogs_seo_schema_preview', '_ajax_nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Schema preview nonce check failed.', 'open-growth-seo' ),
				),
				403
			);
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		if ( $post_id <= 0 ) {
			wp_send_json_error(
				array(
					'message' => __( 'A valid post ID is required to preview schema.', 'open-growth-seo' ),
				),
				400
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You are not allowed to inspect schema for this post.', 'open-growth-seo' ),
				),
				403
			);
		}

		wp_send_json_success( $this->schema_runtime_preview_state( $post_id ) );
	}

	private function render_schema_runtime_preview( int $post_id ): void {
		$preview = $this->schema_runtime_preview_state( $post_id );

		if ( empty( $preview['available'] ) ) {
			echo '<p class="description">' . esc_html__( 'Schema preview is unavailable for this post.', 'open-growth-seo' ) . '</p>';
			return;
		}

		$summary = isset( $preview['summary'] ) && is_array( $preview['summary'] ) ? $preview['summary'] : array();
		$types   = isset( $preview['node_types'] ) && is_array( $preview['node_types'] ) ? $preview['node_types'] : array();
		$issues  = isset( $preview['issues'] ) && is_array( $preview['issues'] ) ? $preview['issues'] : array();

		echo '<div class="ogs-schema-preview">';
		echo '<p class="description">' . esc_html__( 'Saved-state preview only. Save the post after edits to refresh the final emitted JSON-LD.', 'open-growth-seo' ) . '</p>';
		echo '<ul class="ogs-inline-stats ogs-schema-preview-meta">';
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %d: node count */
				__( 'Nodes: %d', 'open-growth-seo' ),
				(int) ( $summary['node_count'] ?? 0 )
			)
		) . '</li>';
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %d: warning count */
				__( 'Warnings: %d', 'open-growth-seo' ),
				(int) ( $summary['warning_count'] ?? 0 )
			)
		) . '</li>';
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %d: error count */
				__( 'Errors: %d', 'open-growth-seo' ),
				(int) ( $summary['error_count'] ?? 0 )
			)
		) . '</li>';
		echo '</ul>';

		if ( ! empty( $types ) ) {
			echo '<p><strong>' . esc_html__( 'Resolved node types', 'open-growth-seo' ) . ':</strong> ' . esc_html( implode( ', ', $types ) ) . '</p>';
		}

		if ( ! empty( $issues ) ) {
			echo '<ul class="ogs-coaching-list">';
			foreach ( array_slice( $issues, 0, 5 ) as $issue ) {
				echo '<li>' . esc_html( (string) $issue ) . '</li>';
			}
			echo '</ul>';
		}

		echo '<pre class="ogs-rest-response ogs-schema-preview-json">' . esc_html( (string) ( $preview['json_pretty'] ?? '' ) ) . '</pre>';
		echo '</div>';
	}

	private function schema_runtime_preview_state( int $post_id ): array {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return array(
				'available' => false,
				'message'   => __( 'Schema preview is unavailable for this post.', 'open-growth-seo' ),
			);
		}

		$inspection = ( new SchemaManager() )->inspect(
			array(
				'post_id' => $post_id,
			),
			false
		);

		$payload = array();
		if ( isset( $inspection['payload'] ) && is_array( $inspection['payload'] ) ) {
			$payload = $inspection['payload'];
		} elseif ( isset( $inspection['graph'] ) && is_array( $inspection['graph'] ) ) {
			$payload = array(
				'@context' => 'https://schema.org',
				'@graph'   => $inspection['graph'],
			);
		}

		$node_types = array();
		$graph      = isset( $payload['@graph'] ) && is_array( $payload['@graph'] ) ? $payload['@graph'] : array();
		foreach ( $graph as $node ) {
			if ( ! is_array( $node ) || empty( $node['@type'] ) ) {
				continue;
			}
			if ( is_array( $node['@type'] ) ) {
				foreach ( $node['@type'] as $type ) {
					if ( is_string( $type ) && '' !== $type ) {
						$node_types[] = $type;
					}
				}
			} elseif ( is_string( $node['@type'] ) ) {
				$node_types[] = $node['@type'];
			}
		}
		$node_types = array_values( array_unique( array_filter( $node_types ) ) );

		$warnings = isset( $inspection['warnings'] ) && is_array( $inspection['warnings'] ) ? $inspection['warnings'] : array();
		$errors   = isset( $inspection['errors'] ) && is_array( $inspection['errors'] ) ? $inspection['errors'] : array();
		$issues   = array_values(
			array_slice(
				array_unique(
					array_merge(
						array_map( 'strval', $errors ),
						array_map( 'strval', $warnings )
					)
				),
				0,
				8
			)
		);

		return array(
			'available'  => ! empty( $payload ),
			'summary'    => array(
				'node_count'    => count( $graph ),
				'warning_count' => count( $warnings ),
				'error_count'   => count( $errors ),
			),
			'node_types' => $node_types,
			'issues'     => $issues,
			'payload'    => $payload,
			'json_pretty' => ! empty( $payload )
				? wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
				: '',
		);
	}

	private function schema_override_state( int $post_id, array $settings = array() ): array {
		if ( empty( $settings ) ) {
			$settings = Settings::get_all();
		}
		$simple_mode = ExperienceMode::is_simple( $settings );
		$current     = (string) get_post_meta( $post_id, 'ogs_seo_schema_type', true );
		$options     = Eligibility::option_records( $post_id, $settings, $simple_mode );
		$allowed     = wp_list_pluck( $options, 'value' );
		$current_reason = '';
		$message        = __( 'Safe default: use Auto unless the visible page clearly matches a more specific schema type.', 'open-growth-seo' );
		$auto_report    = \OpenGrowthSolutions\OpenGrowthSEO\Schema\EligibilityEngine::auto_detection_report_for_post( $post_id, $settings );
		$auto_detected  = isset( $auto_report['type'] ) ? (string) $auto_report['type'] : '';
		$auto_reason    = isset( $auto_report['reason'] ) ? (string) $auto_report['reason'] : '';

		if ( '' !== $current ) {
			$eligibility   = Eligibility::evaluate_for_post( $current, $post_id, $settings );
			$current_reason = (string) $eligibility['reason'];
			if ( empty( $eligibility['eligible'] ) ) {
				$message = __( 'This saved override is no longer eligible for the current page. Open Growth SEO will fall back to automatic schema output.', 'open-growth-seo' );
			}
		}

		return array(
			'current'        => in_array( $current, $allowed, true ) ? $current : '',
			'legacy_current' => '' !== $current && ! in_array( $current, $allowed, true ) ? $current : '',
			'current_reason' => $current_reason,
			'message'        => $message,
			'auto_detected'  => $auto_detected,
			'auto_reason'    => $auto_reason,
			'options'        => $options,
		);
	}

	private function should_render_academic_schema_section( string $schema_type, bool $simple_mode ): bool {
		if ( ! $simple_mode ) {
			return true;
		}

		return $this->is_academic_schema_type( $schema_type );
	}

	private function should_render_specialized_schema_section( string $schema_type, bool $simple_mode ): bool {
		if ( ! $simple_mode ) {
			return true;
		}
		return $this->is_specialized_schema_type( $schema_type );
	}

	private function is_academic_schema_type( string $schema_type ): bool {
		return in_array(
			$schema_type,
			array(
				'Person',
				'Course',
				'EducationalOccupationalProgram',
				'ScholarlyArticle',
				'Event',
			),
			true
		);
	}

	private function is_specialized_schema_type( string $schema_type ): bool {
		return in_array(
			$schema_type,
			array(
				'Service',
				'OfferCatalog',
				'TechArticle',
				'AboutPage',
				'ContactPage',
				'CollectionPage',
				'EventSeries',
				'WebAPI',
				'Project',
				'Review',
				'Guide',
				'DefinedTerm',
				'DefinedTermSet',
			),
			true
		);
	}

	private function masters_plus_editor_snapshot( int $post_id, array $settings ): array {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return array();
		}

		$assessment      = LinkGraph::orphan_assessment( $post_id );
		$inbound_links   = isset( $assessment['inbound'] ) ? (int) $assessment['inbound'] : 0;
		$cluster_score   = isset( $assessment['cluster_score'] ) ? (int) $assessment['cluster_score'] : 0;
		$orphan_conf     = $this->humanize_suggestion_confidence( (string) ( $assessment['confidence'] ?? 'low' ) );
		$index           = (string) get_post_meta( $post_id, 'ogs_seo_index', true );
		$canonical       = trim( (string) get_post_meta( $post_id, 'ogs_seo_canonical', true ) );
		$schema_override = trim( (string) get_post_meta( $post_id, 'ogs_seo_schema_type', true ) );
		$actions         = array();
		$canonical_state = '' === $canonical ? __( 'Default canonical', 'open-growth-seo' ) : __( 'Manual canonical override', 'open-growth-seo' );

		if ( 'orphan' === (string) ( $assessment['status'] ?? '' ) ) {
			$actions[] = __( 'Fix this first: add at least one internal link from a relevant parent/supporting page.', 'open-growth-seo' );
		} elseif ( $inbound_links < 2 ) {
			$actions[] = __( 'Needs work: strengthen internal link support from related pages.', 'open-growth-seo' );
		}
		if ( '' !== $canonical && 'noindex' === $index ) {
			$actions[] = __( 'Review canonical + noindex combination to avoid mixed indexing signals.', 'open-growth-seo' );
		}
		if ( '' !== $schema_override ) {
			$eligibility = Eligibility::evaluate_for_post( $schema_override, $post_id, $settings );
			if ( empty( $eligibility['eligible'] ) ) {
				$actions[] = __( 'Schema override is no longer eligible for this content. Switch back to Auto or update visible content.', 'open-growth-seo' );
			}
		}
		if ( '1' === (string) get_post_meta( $post_id, 'ogs_seo_cornerstone', true ) && $inbound_links < 3 ) {
			$actions[] = __( 'Cornerstone note: this page should receive broader internal link reinforcement.', 'open-growth-seo' );
		}
		if ( empty( $actions ) ) {
			$actions[] = __( 'Good: expert guardrails did not detect high-risk conflicts on this URL.', 'open-growth-seo' );
		}

		return array(
			'orphan_confidence' => $orphan_conf,
			'inbound_links'     => $inbound_links,
			'cluster_score'     => $cluster_score,
			'canonical_state'   => $canonical_state,
			'actions'           => array_values( array_slice( array_unique( $actions ), 0, 5 ) ),
		);
	}

	private function render_content_analysis( \WP_Post $post ): void {
		$analysis      = Analyzer::analyze_post( (int) $post->ID );
		$geo_analysis  = BotControls::analyze_post( (int) $post->ID );
		$sfo_analysis  = SfoAnalyzer::analyze_post( (int) $post->ID );
		$summary       = isset( $analysis['summary'] ) && is_array( $analysis['summary'] ) ? $analysis['summary'] : array();
		$signals       = isset( $analysis['signals'] ) && is_array( $analysis['signals'] ) ? $analysis['signals'] : array();
		$recs          = isset( $analysis['recommendations'] ) && is_array( $analysis['recommendations'] ) ? $analysis['recommendations'] : array();
		$follow        = isset( $analysis['follow_up_questions'] ) && is_array( $analysis['follow_up_questions'] ) ? $analysis['follow_up_questions'] : array();
		$geo_summary   = isset( $geo_analysis['summary'] ) && is_array( $geo_analysis['summary'] ) ? $geo_analysis['summary'] : array();
		$geo_recs      = isset( $geo_analysis['recommendations'] ) && is_array( $geo_analysis['recommendations'] ) ? $geo_analysis['recommendations'] : array();
		$sfo_summary   = isset( $sfo_analysis['summary'] ) && is_array( $sfo_analysis['summary'] ) ? $sfo_analysis['summary'] : array();
		$sfo_feature   = isset( $sfo_analysis['feature_readiness'] ) && is_array( $sfo_analysis['feature_readiness'] ) ? $sfo_analysis['feature_readiness'] : array();
		$sfo_trend     = isset( $sfo_analysis['trend'] ) && is_array( $sfo_analysis['trend'] ) ? $sfo_analysis['trend'] : array();
		$actions       = array_slice( array_values( array_unique( array_merge( $recs, $geo_recs ) ) ), 0, 4 );
		$outbound_suggestions = LinkSuggestions::outbound_targets( (int) $post->ID, 4 );
		$inbound_suggestions  = ! empty( $summary['cornerstone'] ) ? LinkSuggestions::inbound_opportunities( (int) $post->ID, 4 ) : array();

		echo '<div class="ogs-card ogs-editor-analysis">';
		echo '<p class="ogs-status ogs-status-' . esc_attr( ! empty( $summary['answer_first'] ) && 'Good' === $this->humanize_status( (string) ( $summary['clarity'] ?? 'needs-work' ) ) ? 'good' : 'warn' ) . '">';
		echo esc_html(
			sprintf(
				/* translators: 1: clarity label, 2: words, 3: internal links */
				__( 'AEO status: %1$s | Words: %2$d | Internal links: %3$d', 'open-growth-seo' ),
				$this->humanize_status( (string) ( $summary['clarity'] ?? 'needs-work' ) ),
				(int) ( $summary['word_count'] ?? 0 ),
				(int) ( $summary['internal_links'] ?? 0 )
			)
		);
		echo '</p>';
		echo '<ul class="ogs-coaching-list">';
		echo '<li>' . esc_html( ! empty( $summary['answer_first'] ) ? __( 'Good: the page surfaces a direct answer early enough for extraction.', 'open-growth-seo' ) : __( 'Fix this first: move the clearest answer into the first section.', 'open-growth-seo' ) ) . '</li>';
		echo '<li>' . esc_html(
			! empty( $summary['focus_keyphrase_set'] )
				? (
					! empty( $summary['focus_keyphrase_in_title'] ) && ! empty( $summary['focus_keyphrase_in_intro'] )
						? __( 'Good: the focus keyphrase appears in both the title and opening section.', 'open-growth-seo' )
						: __( 'Needs work: align the focus keyphrase more clearly with the title and opening section.', 'open-growth-seo' )
				)
				: __( 'Needs work: set a focus keyphrase so the plugin can check topical alignment.', 'open-growth-seo' )
		) . '</li>';
		echo '<li>' . esc_html( sprintf( __( 'Readability: %s.', 'open-growth-seo' ), $this->humanize_status( (string) ( $summary['readability'] ?? 'needs-work' ) ) ) ) . '</li>';
		$intent = isset( $signals['intent'] ) && is_array( $signals['intent'] ) ? $signals['intent'] : array();
		echo '<li>' . esc_html( sprintf( __( 'Needs work: intent coverage currently includes %s.', 'open-growth-seo' ), empty( $intent ) ? __( 'no clear supporting intents', 'open-growth-seo' ) : implode( ', ', array_slice( $intent, 0, 4 ) ) ) ) . '</li>';
		echo '<li>' . esc_html( ! empty( $geo_summary['critical_text_visible'] ) ? __( 'Good: key information is present in visible text.', 'open-growth-seo' ) : __( 'Fix this first: add visible text for important details that currently depend on media or layout only.', 'open-growth-seo' ) ) . '</li>';
		if ( ! empty( $geo_summary['schema_text_mismatch'] ) ) {
			echo '<li>' . esc_html__( 'Needs work: schema and visible text may not line up cleanly.', 'open-growth-seo' ) . '</li>';
		}
		if ( ! empty( $summary['cornerstone'] ) ) {
			echo '<li>' . esc_html__( 'Cornerstone content is marked here, so stronger internal linking and freshness matter more.', 'open-growth-seo' ) . '</li>';
		}
		echo '</ul>';

		if ( ! empty( $actions ) ) {
			echo '<p><strong>' . esc_html__( 'Next best actions', 'open-growth-seo' ) . '</strong></p><ol class="ogs-coaching-actions">';
			foreach ( $actions as $action ) {
				echo '<li>' . esc_html( (string) $action ) . '</li>';
			}
			echo '</ol>';
		}
		echo '<div class="ogs-editor-sfo-hints">';
		echo '<p><strong>' . esc_html__( 'SFO drafting hints', 'open-growth-seo' ) . '</strong></p>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: score, 2: trend */
				__( 'SFO score: %1$d/100 | Trend: %2$s', 'open-growth-seo' ),
				(int) ( $sfo_summary['sfo_score'] ?? 0 ),
				ucfirst( str_replace( '-', ' ', (string) ( $sfo_trend['state'] ?? 'new' ) ) )
			)
		) . '</p>';
		echo '<ul class="ogs-coaching-list">';
		echo '<li>' . esc_html( ! empty( $sfo_feature['search_snippet'] ) ? __( 'Good: search-facing title and description are reasonably ready.', 'open-growth-seo' ) : __( 'Needs work: tighten title and description so the page frames well in search results.', 'open-growth-seo' ) ) . '</li>';
		echo '<li>' . esc_html( ! empty( $sfo_feature['featured_answer'] ) ? __( 'Good: direct-answer structure is present for search extraction.', 'open-growth-seo' ) : __( 'Fix this first: add a clear answer block near the top if this page should win answer-like features.', 'open-growth-seo' ) ) . '</li>';
		echo '<li>' . esc_html( ! empty( $sfo_feature['rich_result'] ) ? __( 'Good: structured result readiness looks healthy.', 'open-growth-seo' ) : __( 'Needs work: schema/runtime signals still limit richer search presentation.', 'open-growth-seo' ) ) . '</li>';
		echo '</ul>';
		if ( ! empty( $sfo_analysis['priority_actions'] ) && is_array( $sfo_analysis['priority_actions'] ) ) {
			echo '<p><strong>' . esc_html__( 'SFO next actions', 'open-growth-seo' ) . '</strong></p><ol class="ogs-coaching-actions">';
			foreach ( array_slice( $sfo_analysis['priority_actions'], 0, 2 ) as $action ) {
				if ( is_array( $action ) && ! empty( $action['action'] ) ) {
					echo '<li>' . esc_html( (string) $action['action'] ) . '</li>';
				}
			}
			echo '</ol>';
		}
		echo '</div>';
		if ( ! empty( $follow ) ) {
			echo '<p class="description">' . esc_html__( 'Useful follow-up angles to cover next:', 'open-growth-seo' ) . ' ' . esc_html( implode( ' | ', array_slice( $follow, 0, 3 ) ) ) . '</p>';
		}
		if ( ! empty( $outbound_suggestions ) ) {
			echo '<p><strong>' . esc_html__( 'Suggested internal links to add from this page', 'open-growth-seo' ) . '</strong></p>';
			echo '<ul class="ogs-link-suggestions">';
			foreach ( $outbound_suggestions as $suggestion ) {
				$edit_url = isset( $suggestion['edit_url'] ) ? (string) $suggestion['edit_url'] : '';
				$confidence = $this->humanize_suggestion_confidence( (string) ( $suggestion['confidence'] ?? 'low' ) );
				echo '<li>';
				if ( '' !== $edit_url ) {
					echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html( (string) $suggestion['title'] ) . '</a>';
				} else {
					echo esc_html( (string) $suggestion['title'] );
				}
				if ( ! empty( $suggestion['reason'] ) ) {
					echo '<span class="description"> - ' . esc_html( (string) $suggestion['reason'] . ' | ' . $confidence ) . '</span>';
				} else {
					echo '<span class="description"> - ' . esc_html( $confidence ) . '</span>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}
		if ( ! empty( $inbound_suggestions ) ) {
			echo '<p><strong>' . esc_html__( 'Pages to review for a link back to this cornerstone', 'open-growth-seo' ) . '</strong></p>';
			echo '<ul class="ogs-link-suggestions">';
			foreach ( $inbound_suggestions as $suggestion ) {
				$edit_url = isset( $suggestion['edit_url'] ) ? (string) $suggestion['edit_url'] : '';
				$confidence = $this->humanize_suggestion_confidence( (string) ( $suggestion['confidence'] ?? 'low' ) );
				echo '<li>';
				if ( '' !== $edit_url ) {
					echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html( (string) $suggestion['title'] ) . '</a>';
				} else {
					echo esc_html( (string) $suggestion['title'] );
				}
				if ( ! empty( $suggestion['reason'] ) ) {
					echo '<span class="description"> - ' . esc_html( (string) $suggestion['reason'] . ' | ' . $confidence ) . '</span>';
				} else {
					echo '<span class="description"> - ' . esc_html( $confidence ) . '</span>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	private function render_snippet_preview( \WP_Post $post ): void {
		$settings         = Settings::get_all();
		$site_name        = (string) get_bloginfo( 'name' );
		$site_description = (string) get_bloginfo( 'description' );
		$post_title       = (string) get_the_title( $post->ID );
		$post_excerpt     = (string) get_post_field( 'post_excerpt', $post->ID );
		if ( '' === trim( $post_excerpt ) ) {
			$post_excerpt = wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $post->ID ) ), 30 );
		}
		$seo_title = trim( (string) get_post_meta( $post->ID, 'ogs_seo_title', true ) );
		$seo_desc  = trim( (string) get_post_meta( $post->ID, 'ogs_seo_description', true ) );
		$social_title = trim( (string) get_post_meta( $post->ID, 'ogs_seo_social_title', true ) );
		$social_desc  = trim( (string) get_post_meta( $post->ID, 'ogs_seo_social_description', true ) );
		$social_image = trim( (string) get_post_meta( $post->ID, 'ogs_seo_social_image', true ) );
		$schema_type  = trim( (string) get_post_meta( $post->ID, 'ogs_seo_schema_type', true ) );

		$resolved_title = '' !== $seo_title ? $seo_title : $this->preview_replace_tokens(
			(string) $settings['title_template'],
			array(
				'%%title%%'            => $post_title,
				'%%sitename%%'         => $site_name,
				'%%sep%%'              => (string) $settings['title_separator'],
				'%%site_description%%' => $site_description,
			),
			$post_title . ' ' . (string) $settings['title_separator'] . ' ' . $site_name
		);
		$resolved_desc = '' !== $seo_desc ? $seo_desc : $this->preview_replace_tokens(
			(string) $settings['meta_description_template'],
			array(
				'%%excerpt%%'          => $post_excerpt,
				'%%sitename%%'         => $site_name,
				'%%site_description%%' => $site_description,
				'%%query%%'            => '',
			),
			$post_excerpt
		);
		$resolved_social_title = '' !== $social_title ? $social_title : $resolved_title;
		$resolved_social_desc  = '' !== $social_desc ? $social_desc : $resolved_desc;
		$permalink             = (string) get_permalink( $post->ID );
		if ( '' === $permalink ) {
			$permalink = home_url( '/' );
		}

		echo '<div class="ogs-card" id="ogs-editor-snippet-preview" aria-live="polite"';
		echo ' data-default-title="' . esc_attr( $resolved_title ) . '"';
		echo ' data-default-description="' . esc_attr( $resolved_desc ) . '"';
		echo ' data-default-social-title="' . esc_attr( $resolved_social_title ) . '"';
		echo ' data-default-social-description="' . esc_attr( $resolved_social_desc ) . '"';
		echo ' data-default-social-image="' . esc_attr( $social_image ) . '"';
		echo ' data-image-optional-label="' . esc_attr__( 'Image optional', 'open-growth-seo' ) . '"';
		echo ' data-schema-none-label="' . esc_attr__( 'No schema override. Default contextual schema rules will apply.', 'open-growth-seo' ) . '"';
		echo ' data-schema-set-prefix="' . esc_attr__( 'Schema override set to', 'open-growth-seo' ) . '"';
		echo ' data-schema-set-suffix="' . esc_attr__( 'Ensure visible content still matches this type.', 'open-growth-seo' ) . '">';
		echo '<p style="font-weight:600;margin:0 0 6px;">' . esc_html__( 'Search Appearance Preview', 'open-growth-seo' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Indicative preview only. Search and social platforms may render snippets differently.', 'open-growth-seo' ) . '</p>';
		echo '<div class="ogs-preview-grid">';
		echo '<section class="ogs-preview-block ogs-preview-block-serp">';
		echo '<h3>' . esc_html__( 'SERP (Web)', 'open-growth-seo' ) . '</h3>';
		echo '<div class="ogs-serp-result">';
		echo '<p class="ogs-status ogs-status-good ogs-snippet-title" data-role="serp-title">' . esc_html( $this->preview_trim( $resolved_title, 60 ) ) . '</p>';
		echo '<p class="description ogs-snippet-url">' . esc_html( $permalink ) . '</p>';
		echo '<p class="ogs-snippet-desc" data-role="serp-desc">' . esc_html( $this->preview_trim( $resolved_desc, 160 ) ) . '</p>';
		echo '</div>';
		echo '</section>';
		echo '<section class="ogs-preview-block ogs-preview-block-social">';
		echo '<h3>' . esc_html__( 'Social Card', 'open-growth-seo' ) . '</h3>';
		echo '<div class="ogs-social-card">';
		echo '<div class="ogs-social-image" data-role="social-image">';
		if ( '' !== $social_image ) {
			echo '<img data-role="social-image-tag" src="' . esc_url( $social_image ) . '" alt="' . esc_attr__( 'Selected social image preview', 'open-growth-seo' ) . '" />';
		} else {
			echo '<span data-role="social-image-empty">' . esc_html__( 'Image optional', 'open-growth-seo' ) . '</span>';
		}
		echo '</div>';
		echo '<div class="ogs-social-card-body">';
		echo '<p class="ogs-social-title" data-role="social-title">' . esc_html( $this->preview_trim( $resolved_social_title, 90 ) ) . '</p>';
		echo '<p class="ogs-social-desc" data-role="social-desc">' . esc_html( $this->preview_trim( $resolved_social_desc, 200 ) ) . '</p>';
		echo '<p class="ogs-social-url" data-role="social-url">' . esc_html( wp_parse_url( $permalink, PHP_URL_HOST ) ?: $permalink ) . '</p>';
		echo '</div>';
		echo '</div>';
		echo '</section>';
		echo '<section class="ogs-preview-block ogs-preview-block-rich">';
		echo '<h3>' . esc_html__( 'Rich Result Hint', 'open-growth-seo' ) . '</h3>';
		echo '<div class="ogs-rich-hint-card">';
		echo '<p class="ogs-rich-hint-kicker">' . esc_html__( 'Eligibility-focused signal', 'open-growth-seo' ) . '</p>';
		echo '<p class="description ogs-rich-hint-text" data-role="rich-hint">';
		echo esc_html( '' !== $schema_type ? sprintf( __( 'Schema override set to %s. Ensure visible content matches this type.', 'open-growth-seo' ), $schema_type ) : __( 'No schema override. Default contextual schema rules will apply.', 'open-growth-seo' ) );
		echo '</p>';
		echo '<p class="description ogs-rich-hint-note">' . esc_html__( 'This preview is guidance only. Search features depend on page content and engine eligibility.', 'open-growth-seo' ) . '</p>';
		echo '</div>';
		echo '</section>';
		echo '</div>';
		echo '</div>';
		echo '<script>(function(){const root=document.getElementById("ogs-editor-snippet-preview");if(!root){return;}const q=(s)=>document.querySelector(s);const t=q("input[name=ogs_seo_title]");const d=q("textarea[name=ogs_seo_description]");const st=q("input[name=ogs_seo_social_title]");const sd=q("textarea[name=ogs_seo_social_description]");const si=q("input[name=ogs_seo_social_image]");const schema=q("[name=ogs_seo_schema_type]");const outT=root.querySelector("[data-role=serp-title]");const outD=root.querySelector("[data-role=serp-desc]");const outST=root.querySelector("[data-role=social-title]");const outSD=root.querySelector("[data-role=social-desc]");const outSI=root.querySelector("[data-role=social-image]");const outSU=root.querySelector("[data-role=social-url]");const outHint=root.querySelector("[data-role=rich-hint]");const imageOptional=root.getAttribute("data-image-optional-label")||"Image optional";const schemaNone=root.getAttribute("data-schema-none-label")||"";const schemaPrefix=root.getAttribute("data-schema-set-prefix")||"Schema override set to";const schemaSuffix=root.getAttribute("data-schema-set-suffix")||"";const trim=(v,m)=>{v=(v||"").toString().trim();return v.length>m?v.slice(0,m-3).trim()+"...":v;};const syncImage=(value)=>{if(!outSI){return;}const val=(value||"").toString().trim();outSI.innerHTML="";if(val){const img=document.createElement("img");img.setAttribute("src",val);img.setAttribute("alt","Selected social image preview");img.setAttribute("data-role","social-image-tag");outSI.appendChild(img);return;}const empty=document.createElement("span");empty.setAttribute("data-role","social-image-empty");empty.textContent=imageOptional;outSI.appendChild(empty);};const apply=()=>{const baseT=root.getAttribute("data-default-title")||"";const baseD=root.getAttribute("data-default-description")||"";const baseST=root.getAttribute("data-default-social-title")||"";const baseSD=root.getAttribute("data-default-social-description")||"";const baseSI=root.getAttribute("data-default-social-image")||"";const valT=t&&t.value.trim()?t.value:baseT;const valD=d&&d.value.trim()?d.value:baseD;const valST=st&&st.value.trim()?st.value:baseST||valT;const valSD=sd&&sd.value.trim()?sd.value:baseSD||valD;const valSI=si&&si.value.trim()?si.value:baseSI;if(outT){outT.textContent=trim(valT,60);}if(outD){outD.textContent=trim(valD,160);}if(outST){outST.textContent=trim(valST,90);}if(outSD){outSD.textContent=trim(valSD,200);}if(outSU){try{outSU.textContent=(new URL((q(".ogs-snippet-url")||{}).textContent||window.location.href)).host;}catch(err){outSU.textContent=(q(".ogs-snippet-url")||{}).textContent||window.location.host;}}syncImage(valSI);if(outHint&&schema){outHint.textContent=schema.value.trim()?schemaPrefix+" "+schema.value.trim()+". "+schemaSuffix:schemaNone;}};[t,d,st,sd,si,schema].forEach((el)=>{if(el){el.addEventListener("input",apply);el.addEventListener("change",apply);}});apply();})();</script>';
	}

	private function field_help_markup( string $help ): string {
		if ( '' === trim( $help ) ) {
			return '';
		}
		return '<span class="description ogs-field-help">' . esc_html( $help ) . '</span>';
	}

	private function datetime_local_input_value( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return '';
		}
		return wp_date( 'Y-m-d\TH:i', $timestamp );
	}

	private function content_field_meta(): array {
		return array(
			'ogs_seo_title' => array(
				'help'        => __( 'Title that appears on Google / defines SEO relevance / E.g.: Privacy Policy | MySite', 'open-growth-seo' ),
				'placeholder' => 'Privacy Policy | MySite',
			),
			'ogs_seo_description' => array(
				'help'        => __( 'Description in search results / improves CTR / E.g.: Find out how we protect your data and privacy.', 'open-growth-seo' ),
				'placeholder' => __( 'Find out how we protect your data and privacy.', 'open-growth-seo' ),
			),
			'ogs_seo_focus_keyphrase' => array(
				'help'        => __( 'Primary phrase this page should answer clearly / used for title, intro, and guidance checks / E.g.: technical seo audit', 'open-growth-seo' ),
				'placeholder' => __( 'technical seo audit', 'open-growth-seo' ),
			),
			'ogs_seo_cornerstone' => array(
				'help' => __( 'Mark only strategic pages that should attract internal links and periodic freshness reviews.', 'open-growth-seo' ),
			),
			'ogs_seo_primary_term' => array(
				'help' => __( 'Optional main category/topic used for breadcrumb paths and archive context. Leave on automatic unless one term clearly represents this page.', 'open-growth-seo' ),
			),
			'ogs_seo_canonical' => array(
				'help'        => __( 'Official URL to avoid duplicate content / strengthens SEO / E.g.: https://midominio.com/privacy-policy', 'open-growth-seo' ),
				'placeholder' => 'https://midominio.com/privacy-policy',
			),
			'ogs_seo_index' => array(
				'help' => __( 'Indicates whether Google should index the page / E.g.: index', 'open-growth-seo' ),
			),
			'ogs_seo_follow' => array(
				'help' => __( 'Indicates whether links pass authority / E.g.: follow', 'open-growth-seo' ),
			),
			'ogs_seo_max_snippet' => array(
				'help'        => __( 'Character limit for the snippet on Google / E.g.: 160', 'open-growth-seo' ),
				'placeholder' => '160',
			),
			'ogs_seo_max_image_preview' => array(
				'help' => __( 'Permitted size of image previews / E.g.: large', 'open-growth-seo' ),
			),
			'ogs_seo_max_video_preview' => array(
				'help'        => __( 'Duration of video preview in seconds / E.g.: -1 (no limit)', 'open-growth-seo' ),
				'placeholder' => '-1',
			),
			'ogs_seo_nosnippet' => array(
				'help' => __( 'Prevents Google from displaying a text snippet / E.g.: Disable', 'open-growth-seo' ),
			),
			'ogs_seo_noarchive' => array(
				'help' => __( 'Prevents Google from caching the page / E.g.: Enable', 'open-growth-seo' ),
			),
			'ogs_seo_notranslate' => array(
				'help' => __( 'Prevents Google from offering automatic translation / E.g.: Disable', 'open-growth-seo' ),
			),
			'ogs_seo_unavailable_after' => array(
				'help'        => __( 'Date on which the page stops being indexed / E.g.: 2026-12-31 23:59', 'open-growth-seo' ),
				'placeholder' => '2026-12-31 23:59',
			),
			'ogs_seo_social_title' => array(
				'help'        => __( 'Optional title for social sharing / overrides the SEO title on supported platforms / E.g.: Privacy Policy | MySite', 'open-growth-seo' ),
				'placeholder' => 'Privacy Policy | MySite',
			),
			'ogs_seo_social_description' => array(
				'help'        => __( 'Optional description for social sharing / helps the shared post read clearly / E.g.: Learn how we protect your data and privacy.', 'open-growth-seo' ),
				'placeholder' => __( 'Learn how we protect your data and privacy.', 'open-growth-seo' ),
			),
			'ogs_seo_social_image' => array(
				'help'        => __( 'Optional image for social sharing / used by supported platforms / E.g.: https://midominio.com/uploads/privacy-cover.jpg', 'open-growth-seo' ),
				'placeholder' => 'https://midominio.com/uploads/privacy-cover.jpg',
			),
			'ogs_seo_schema_type' => array(
				'help' => __( 'Use only when the visible page content clearly matches the selected schema type / E.g.: FAQPage', 'open-growth-seo' ),
			),
			'ogs_seo_schema_course_code' => array(
				'help'        => __( 'Internal or public course code for Course schema / E.g.: LAW-101', 'open-growth-seo' ),
				'placeholder' => 'LAW-101',
			),
			'ogs_seo_schema_course_credential' => array(
				'help'        => __( 'Credential associated with the course when applicable / E.g.: Certificate of completion', 'open-growth-seo' ),
				'placeholder' => __( 'Certificate of completion', 'open-growth-seo' ),
			),
			'ogs_seo_schema_program_credential' => array(
				'help'        => __( 'Credential awarded by the full academic program / E.g.: Licenciatura en Derecho', 'open-growth-seo' ),
				'placeholder' => __( 'Licenciatura en Derecho', 'open-growth-seo' ),
			),
			'ogs_seo_schema_duration' => array(
				'help'        => __( 'Program duration for EducationalOccupationalProgram / E.g.: P8S or 8 semesters', 'open-growth-seo' ),
				'placeholder' => '8 semesters',
			),
			'ogs_seo_schema_program_mode' => array(
				'help'        => __( 'Delivery mode such as on-campus, online, hybrid, evening, or weekend.', 'open-growth-seo' ),
				'placeholder' => __( 'On-campus', 'open-growth-seo' ),
			),
			'ogs_seo_schema_person_job_title' => array(
				'help'        => __( 'Visible role for person pages / E.g.: Rector, Professor of Economics, Researcher', 'open-growth-seo' ),
				'placeholder' => __( 'Professor of Economics', 'open-growth-seo' ),
			),
			'ogs_seo_schema_person_affiliation' => array(
				'help'        => __( 'Institution, faculty, or department affiliation when it should be stated explicitly.', 'open-growth-seo' ),
				'placeholder' => __( 'Faculty of Law', 'open-growth-seo' ),
			),
			'ogs_seo_schema_same_as' => array(
				'help'        => __( 'Official profile URLs for Person schema, one per line / E.g.: ORCID, Google Scholar, LinkedIn, faculty page.', 'open-growth-seo' ),
				'placeholder' => "https://orcid.org/0000-0000-0000-0000\nhttps://scholar.google.com/...",
			),
			'ogs_seo_schema_scholarly_identifier' => array(
				'help'        => __( 'DOI, handle, ISBN, or repository identifier for scholarly publications.', 'open-growth-seo' ),
				'placeholder' => 'doi:10.1000/xyz123',
			),
			'ogs_seo_schema_scholarly_publication' => array(
				'help'        => __( 'Journal, proceedings, repository, or publication container name.', 'open-growth-seo' ),
				'placeholder' => __( 'Journal of Applied Research', 'open-growth-seo' ),
			),
			'ogs_seo_schema_event_location_name' => array(
				'help'        => __( 'Visible venue or campus name for academic events.', 'open-growth-seo' ),
				'placeholder' => __( 'Main Campus Auditorium', 'open-growth-seo' ),
			),
			'ogs_seo_schema_event_attendance_mode' => array(
				'help' => __( 'Use only when the event is clearly online or hybrid. Leave default for standard in-person events.', 'open-growth-seo' ),
			),
			'ogs_seo_schema_service_type' => array(
				'help'        => __( 'Short service label or category / E.g.: Admissions consulting, SEO audit, legal clinic.', 'open-growth-seo' ),
				'placeholder' => __( 'Admissions consulting', 'open-growth-seo' ),
			),
			'ogs_seo_schema_service_area' => array(
				'help'        => __( 'Region, audience, or service area if the service is location-based or audience-specific.', 'open-growth-seo' ),
				'placeholder' => __( 'Ecuador', 'open-growth-seo' ),
			),
			'ogs_seo_schema_service_channel_url' => array(
				'help'        => __( 'Primary URL where the service can be requested, booked, or contacted.', 'open-growth-seo' ),
				'placeholder' => 'https://example.com/contact/',
			),
			'ogs_seo_schema_offer_price' => array(
				'help'        => __( 'Offer price when the page publishes a real public price.', 'open-growth-seo' ),
				'placeholder' => '99.00',
			),
			'ogs_seo_schema_offer_currency' => array(
				'help'        => __( 'ISO currency code for the offer / E.g.: USD, EUR.', 'open-growth-seo' ),
				'placeholder' => 'USD',
			),
			'ogs_seo_schema_offer_category' => array(
				'help'        => __( 'Optional category or plan name for the offer.', 'open-growth-seo' ),
				'placeholder' => __( 'Premium plan', 'open-growth-seo' ),
			),
			'ogs_seo_schema_api_docs_url' => array(
				'help'        => __( 'Documentation URL for WebAPI pages.', 'open-growth-seo' ),
				'placeholder' => 'https://example.com/docs/api/',
			),
			'ogs_seo_schema_project_status' => array(
				'help'        => __( 'Project status or delivery state / E.g.: Active, Completed, Pilot.', 'open-growth-seo' ),
				'placeholder' => __( 'Active', 'open-growth-seo' ),
			),
			'ogs_seo_schema_review_rating' => array(
				'help'        => __( 'Review rating from 1 to 5 for pages that are clearly reviews.', 'open-growth-seo' ),
				'placeholder' => '5',
			),
			'ogs_seo_schema_reviewed_item_name' => array(
				'help'        => __( 'Name of the reviewed item when it differs from the page title.', 'open-growth-seo' ),
				'placeholder' => __( 'Admissions platform', 'open-growth-seo' ),
			),
			'ogs_seo_schema_reviewed_item_type' => array(
				'help'        => __( 'Schema type of the reviewed item / E.g.: Product, Service, SoftwareApplication.', 'open-growth-seo' ),
				'placeholder' => 'Service',
			),
			'ogs_seo_schema_defined_term_code' => array(
				'help'        => __( 'Optional code or stable identifier for glossary terms, datasets, or controlled vocabulary entries.', 'open-growth-seo' ),
				'placeholder' => 'TERM-001',
			),
			'ogs_seo_schema_defined_term_set' => array(
				'help'        => __( 'Glossary or vocabulary set name that the term belongs to.', 'open-growth-seo' ),
				'placeholder' => __( 'University Glossary', 'open-growth-seo' ),
			),
			'ogs_seo_data_nosnippet_ids' => array(
				'help' => __( 'HTML IDs to exclude from snippets / one ID per line without # / E.g.: pricing-table', 'open-growth-seo' ),
			),
		);
	}

	private function preview_replace_tokens( string $template, array $tokens, string $fallback ): string {
		$template = trim( $template );
		if ( '' === $template ) {
			return $fallback;
		}
		$resolved = strtr( $template, $tokens );
		$resolved = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $resolved ) ) );
		return '' !== $resolved ? $resolved : $fallback;
	}

	private function preview_trim( string $value, int $limit ): string {
		$value = trim( wp_strip_all_tags( $value ) );
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			return mb_strlen( $value ) > $limit ? mb_substr( $value, 0, $limit - 3 ) . '...' : $value;
		}
		return strlen( $value ) > $limit ? substr( $value, 0, $limit - 3 ) . '...' : $value;
	}

	private function humanize_status( string $value ): string {
		$value = strtolower( trim( str_replace( array( '_', '-' ), ' ', $value ) ) );
		if ( '' === $value ) {
			return __( 'Good', 'open-growth-seo' );
		}
		if ( in_array( $value, array( 'good', 'healthy', 'active', 'ready', 'ok' ), true ) ) {
			return __( 'Good', 'open-growth-seo' );
		}
		if ( in_array( $value, array( 'strong' ), true ) ) {
			return __( 'Good', 'open-growth-seo' );
		}
		if ( in_array( $value, array( 'moderate', 'needs work', 'needs attention', 'warn', 'warning', 'limited' ), true ) ) {
			return __( 'Needs work', 'open-growth-seo' );
		}
		if ( in_array( $value, array( 'needs work', 'bad', 'critical', 'blocked', 'disabled' ), true ) ) {
			return __( 'Fix this first', 'open-growth-seo' );
		}
		return ucwords( $value );
	}

	private function humanize_suggestion_confidence( string $value ): string {
		$value = strtolower( trim( $value ) );
		if ( 'high' === $value ) {
			return __( 'High confidence', 'open-growth-seo' );
		}
		if ( 'medium' === $value ) {
			return __( 'Medium confidence', 'open-growth-seo' );
		}
		return __( 'Low confidence', 'open-growth-seo' );
	}

	public function save( int $post_id ): void {
		if ( ! isset( $_POST['ogs_seo_post_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ogs_seo_post_meta_nonce'] ) ), 'ogs_seo_post_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$payload = array();
		foreach ( array_merge( array( 'ogs_seo_index', 'ogs_seo_follow' ), array_keys( $this->fields ) ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$payload[ $key ] = wp_unslash( $_POST[ $key ] );
			}
		}
		$this->persist_meta_values( $post_id, $payload );
	}

	public static function sanitize_numeric_preview_meta( string $value ): string {
		$value = trim( sanitize_text_field( $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( '-1' === $value ) {
			return '-1';
		}
		return preg_match( '/^\d+$/', $value ) ? $value : '';
	}

	public static function sanitize_robots_pair_meta( string $value ): string {
		$value = strtolower( trim( sanitize_text_field( $value ) ) );
		return in_array( $value, array( 'index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow' ), true ) ? $value : 'index,follow';
	}

	public static function sanitize_cornerstone_meta( string $value ): string {
		$value = trim( sanitize_text_field( $value ) );
		return '1' === $value ? '1' : '';
	}

	public static function sanitize_primary_term_meta( string $value ): string {
		$term_id = absint( $value );
		return $term_id > 0 ? (string) $term_id : '';
	}

	public static function sanitize_index_meta( string $value ): string {
		$value = strtolower( trim( sanitize_text_field( $value ) ) );
		return in_array( $value, array( 'index', 'noindex' ), true ) ? $value : 'index';
	}

	public static function sanitize_follow_meta( string $value ): string {
		$value = strtolower( trim( sanitize_text_field( $value ) ) );
		return in_array( $value, array( 'follow', 'nofollow' ), true ) ? $value : 'follow';
	}

	public static function sanitize_canonical_url_meta( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( str_starts_with( $value, '/' ) ) {
			$value = home_url( $value );
		}
		$value = esc_url_raw( $value );
		if ( '' === $value ) {
			return '';
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		$base = (string) $parts['scheme'] . '://' . (string) $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$base .= ':' . absint( $parts['port'] );
		}
		$base .= isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( ! empty( $parts['query'] ) ) {
			$base .= '?' . (string) $parts['query'];
		}
		return $base;
	}

	public static function sanitize_image_preview_meta( string $value ): string {
		$value = strtolower( trim( sanitize_text_field( $value ) ) );
		return in_array( $value, array( '', 'none', 'standard', 'large' ), true ) ? $value : '';
	}

	public static function sanitize_inherit_toggle_meta( string $value ): string {
		$value = trim( sanitize_text_field( $value ) );
		return in_array( $value, array( '', '0', '1' ), true ) ? $value : '';
	}

	public static function sanitize_unavailable_after_meta( string $value ): string {
		$value = trim( sanitize_text_field( $value ) );
		if ( '' === $value ) {
			return '';
		}
		$timestamp = strtotime( $value );
		return false !== $timestamp ? gmdate( 'd M Y H:i:s', $timestamp ) . ' GMT' : '';
	}

	public static function sanitize_id_lines_meta( string $value ): string {
		$lines = preg_split( '/\r\n|\r|\n/', $value ) ?: array();
		$clean = array();
		foreach ( $lines as $line ) {
			$line = sanitize_html_class( trim( str_replace( '#', '', $line ) ) );
			if ( '' !== $line ) {
				$clean[] = $line;
			}
		}
		return implode( "\n", array_values( array_unique( $clean ) ) );
	}

	public static function sanitize_schema_type_meta( string $value ): string {
		$value = trim( sanitize_text_field( $value ) );
		if ( '' === $value ) {
			return '';
		}
		$allowed = array_keys( Eligibility::supported_types() );
		return in_array( $value, $allowed, true ) ? $value : '';
	}

	public static function sanitize_same_as_lines_meta( string $value ): string {
		$lines = preg_split( '/\r\n|\r|\n/', $value ) ?: array();
		$clean = array();
		foreach ( $lines as $line ) {
			$url = esc_url_raw( trim( $line ) );
			if ( '' !== $url ) {
				$clean[] = $url;
			}
		}
		return implode( "\n", array_values( array_unique( $clean ) ) );
	}

	public static function sanitize_event_attendance_mode_meta( string $value ): string {
		$value = trim( sanitize_text_field( $value ) );
		return in_array( $value, array( '', 'OfflineEventAttendanceMode', 'OnlineEventAttendanceMode', 'MixedEventAttendanceMode' ), true ) ? $value : '';
	}

	public static function sanitize_decimal_meta( string $value ): string {
		$value = trim( sanitize_text_field( $value ) );
		$value = str_replace( ',', '.', $value );
		return preg_match( '/^\d+(\.\d{1,2})?$/', $value ) ? $value : '';
	}

	public static function sanitize_rating_meta( string $value ): string {
		$rating = absint( $value );
		return $rating >= 1 && $rating <= 5 ? (string) $rating : '';
	}

	private function schema_type_options( bool $simple_mode = false ): array {
		$options = array(
			''            => __( 'Auto (recommended)', 'open-growth-seo' ),
			'Article'     => 'Article',
			'BlogPosting' => 'BlogPosting',
		);
		if ( ! $simple_mode ) {
			$options['TechArticle']            = 'TechArticle';
			$options['NewsArticle']            = 'NewsArticle';
			$options['Service']                = 'Service';
			$options['OfferCatalog']           = 'OfferCatalog';
			$options['Person']                 = 'Person';
			$options['Course']                 = 'Course';
			$options['EducationalOccupationalProgram'] = 'EducationalOccupationalProgram';
			$options['Product']                = 'Product';
			$options['FAQPage']                = 'FAQPage';
			$options['ProfilePage']            = 'ProfilePage';
			$options['AboutPage']              = 'AboutPage';
			$options['ContactPage']            = 'ContactPage';
			$options['CollectionPage']         = 'CollectionPage';
			$options['DiscussionForumPosting'] = 'DiscussionForumPosting';
			$options['QAPage']                 = 'QAPage';
			$options['VideoObject']            = 'VideoObject';
			$options['Event']                  = 'Event';
			$options['EventSeries']            = 'EventSeries';
			$options['JobPosting']             = 'JobPosting';
			$options['Recipe']                 = 'Recipe';
			$options['SoftwareApplication']    = 'SoftwareApplication';
			$options['WebAPI']                 = 'WebAPI';
			$options['Project']                = 'Project';
			$options['Review']                 = 'Review';
			$options['Guide']                  = 'Guide';
			$options['Dataset']                = 'Dataset';
			$options['DefinedTerm']            = 'DefinedTerm';
			$options['DefinedTermSet']         = 'DefinedTermSet';
			$options['ScholarlyArticle']       = 'ScholarlyArticle';
		}
		return $options;
	}

	public static function can_edit_meta( bool $allowed, string $meta_key, int $post_id, int $user_id, string $cap = '', array $caps = array() ): bool {
		unset( $allowed, $meta_key, $cap, $caps );
		return user_can( $user_id, 'edit_post', $post_id );
	}

	private function is_supported_post_type( string $post_type ): bool {
		$post_type = sanitize_key( $post_type );
		if ( '' === $post_type || in_array( $post_type, self::EXCLUDED_POST_TYPES, true ) ) {
			return false;
		}
		$object = get_post_type_object( $post_type );
		if ( ! $object || empty( $object->public ) ) {
			return false;
		}
		return post_type_supports( $post_type, 'editor' ) || post_type_supports( $post_type, 'title' );
	}

	private function current_editor_post_id(): int {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Read-only context for editor bootstrapping.
		if ( isset( $_GET['post'] ) ) {
			return absint( wp_unslash( $_GET['post'] ) );
		}
		if ( isset( $_POST['post_ID'] ) ) {
			return absint( wp_unslash( $_POST['post_ID'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing
		return 0;
	}
}

<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Admin;

use OpenGrowthSolutions\OpenGrowthSEO\Support\ExperienceMode;

defined( 'ABSPATH' ) || exit;

class SearchAppearancePage {
	private Admin $admin;

	public function __construct( Admin $admin ) {
		$this->admin = $admin;
	}

	public function render( array $settings ): void {
		$post_types    = get_post_types( array( 'public' => true ), 'objects' );
		$taxonomies    = get_taxonomies( array( 'public' => true ), 'objects' );
		$simple_mode   = ExperienceMode::is_simple( $settings );
		$show_advanced = ExperienceMode::is_advanced_revealed();
		if ( $this->admin->has_seo_plugin_conflict() && ! empty( $settings['safe_mode_seo_conflict'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Another SEO plugin is active. Safe mode is enabled, so Open Growth SEO suppresses global meta output to avoid duplicates.', 'open-growth-seo' ) . '</p></div>';
		}

		echo '<p class="description">' . esc_html__( 'Template variables: %%title%%, %%sitename%%, %%sep%%, %%excerpt%%, %%term%%, %%term_description%%, %%author%%, %%archive_title%%, %%query%%, %%site_description%%', 'open-growth-seo' ) . '</p>';

		echo '<h2>' . esc_html__( 'Global Defaults', 'open-growth-seo' ) . '</h2>';
		$this->admin->field_text( 'title_template', __( 'Default Title Template', 'open-growth-seo' ), $settings['title_template'] );
		$this->admin->field_text( 'meta_description_template', __( 'Default Meta Description Template', 'open-growth-seo' ), $settings['meta_description_template'] );
		$this->admin->field_text( 'title_separator', __( 'Title Separator', 'open-growth-seo' ), $settings['title_separator'] );
		$this->admin->field_select( 'default_index', __( 'Default Robots', 'open-growth-seo' ), $settings['default_index'], $this->admin->robots_options() );
		echo '<p class="description">' . esc_html__( 'Snippet controls apply in this order: URL override, content type default, global default.', 'open-growth-seo' ) . '</p>';
		if ( $simple_mode && ! $show_advanced ) {
			echo '<p class="description">' . esc_html__( 'Advanced hreflang settings are hidden in Simple mode.', 'open-growth-seo' ) . ' <a class="button button-secondary" href="' . esc_url( add_query_arg( 'ogs_show_advanced', '1', admin_url( 'admin.php?page=ogs-seo-search-appearance' ) ) ) . '">' . esc_html__( 'Show advanced hreflang settings', 'open-growth-seo' ) . '</a></p>';
		}
		$this->admin->field_select(
			'default_nosnippet',
			__( 'Default nosnippet', 'open-growth-seo' ),
			(string) $settings['default_nosnippet'],
			array(
				'0' => __( 'Disabled', 'open-growth-seo' ),
				'1' => __( 'Enabled', 'open-growth-seo' ),
			)
		);
		$this->admin->field_text( 'default_max_snippet', __( 'Default max-snippet', 'open-growth-seo' ), (string) $settings['default_max_snippet'] );
		$this->admin->field_select(
			'default_max_image_preview',
			__( 'Default max-image-preview', 'open-growth-seo' ),
			(string) $settings['default_max_image_preview'],
			array(
				'large'    => 'large',
				'standard' => 'standard',
				'none'     => 'none',
			)
		);
		$this->admin->field_text( 'default_max_video_preview', __( 'Default max-video-preview', 'open-growth-seo' ), (string) $settings['default_max_video_preview'] );
		$this->admin->field_select(
			'default_noarchive',
			__( 'Default noarchive', 'open-growth-seo' ),
			(string) $settings['default_noarchive'],
			array(
				'0' => __( 'Disabled', 'open-growth-seo' ),
				'1' => __( 'Enabled', 'open-growth-seo' ),
			)
		);
		$this->admin->field_select(
			'default_notranslate',
			__( 'Default notranslate', 'open-growth-seo' ),
			(string) $settings['default_notranslate'],
			array(
				'0' => __( 'Disabled', 'open-growth-seo' ),
				'1' => __( 'Enabled', 'open-growth-seo' ),
			)
		);
		$this->admin->field_text( 'default_unavailable_after', __( 'Default unavailable_after', 'open-growth-seo' ), (string) $settings['default_unavailable_after'] );
		echo '<p class="description">' . esc_html__( 'Allowed values: max-snippet/max-video-preview = -1 or non-negative integer. max-image-preview = none|standard|large. unavailable_after accepts parseable date/time.', 'open-growth-seo' ) . '</p>';
		$this->admin->field_select(
			'canonical_enabled',
			__( 'Canonical Tags', 'open-growth-seo' ),
			(string) $settings['canonical_enabled'],
			array(
				'1' => __( 'Enabled', 'open-growth-seo' ),
				'0' => __( 'Disabled', 'open-growth-seo' ),
			)
		);
		if ( ! $simple_mode || $show_advanced ) {
			$this->admin->render_hreflang_fields( $settings );
		}
		$this->admin->field_select(
			'og_enabled',
			__( 'Open Graph', 'open-growth-seo' ),
			(string) $settings['og_enabled'],
			array(
				'1' => __( 'Enabled', 'open-growth-seo' ),
				'0' => __( 'Disabled', 'open-growth-seo' ),
			)
		);
		$this->admin->field_select(
			'twitter_enabled',
			__( 'Twitter/X Cards', 'open-growth-seo' ),
			(string) $settings['twitter_enabled'],
			array(
				'1' => __( 'Enabled', 'open-growth-seo' ),
				'0' => __( 'Disabled', 'open-growth-seo' ),
			)
		);
		echo '<h2>' . esc_html__( 'Breadcrumbs', 'open-growth-seo' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Use the [ogs_seo_breadcrumbs] shortcode in templates, blocks, or classic content when you want a breadcrumb trail without theme code.', 'open-growth-seo' ) . '</p>';
		$this->admin->field_select(
			'breadcrumbs_enabled',
			__( 'Breadcrumb trail', 'open-growth-seo' ),
			(string) $settings['breadcrumbs_enabled'],
			array(
				'1' => __( 'Enabled', 'open-growth-seo' ),
				'0' => __( 'Disabled', 'open-growth-seo' ),
			)
		);
		$this->admin->field_text( 'breadcrumbs_home_label', __( 'Breadcrumb home label', 'open-growth-seo' ), (string) $settings['breadcrumbs_home_label'] );
		$this->admin->field_text( 'breadcrumbs_separator', __( 'Breadcrumb separator', 'open-growth-seo' ), (string) $settings['breadcrumbs_separator'] );
		$this->admin->field_select(
			'breadcrumbs_include_current',
			__( 'Include current page in trail', 'open-growth-seo' ),
			(string) $settings['breadcrumbs_include_current'],
			array(
				'1' => __( 'Enabled', 'open-growth-seo' ),
				'0' => __( 'Disabled', 'open-growth-seo' ),
			)
		);
		echo '<h2>' . esc_html__( 'RSS and AI Discovery Outputs', 'open-growth-seo' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Use these outputs to add consistent feed attribution and an optional llms.txt file without editing theme files.', 'open-growth-seo' ) . '</p>';
		$this->admin->field_textarea( 'rss_before_content', __( 'RSS content before each post', 'open-growth-seo' ), (string) $settings['rss_before_content'] );
		echo '<p class="description">' . esc_html__( 'Supports %%title%%, %%sitename%%, and %%url%%.', 'open-growth-seo' ) . '</p>';
		$this->admin->field_textarea( 'rss_after_content', __( 'RSS content after each post', 'open-growth-seo' ), (string) $settings['rss_after_content'] );
		echo '<p class="description">' . esc_html__( 'Use this for attribution, source reminders, or licensing notes in feeds.', 'open-growth-seo' ) . '</p>';
		$this->admin->field_select(
			'llms_txt_enabled',
			__( 'llms.txt', 'open-growth-seo' ),
			(string) $settings['llms_txt_enabled'],
			array(
				'0' => __( 'Disabled', 'open-growth-seo' ),
				'1' => __( 'Enabled', 'open-growth-seo' ),
			)
		);
		$this->admin->field_select(
			'llms_txt_mode',
			__( 'llms.txt mode', 'open-growth-seo' ),
			(string) $settings['llms_txt_mode'],
			array(
				'guided' => __( 'Guided default', 'open-growth-seo' ),
				'custom' => __( 'Custom content', 'open-growth-seo' ),
			)
		);
		$this->admin->field_textarea( 'llms_txt_content', __( 'llms.txt custom content', 'open-growth-seo' ), (string) $settings['llms_txt_content'] );
		echo '<p class="description">' . esc_html__( 'Guided mode generates a conservative machine-readable summary. Custom mode outputs this text at /llms.txt.', 'open-growth-seo' ) . '</p>';

		echo '<h2>' . esc_html__( 'Content Type Defaults', 'open-growth-seo' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Post Type', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Title Template', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Meta Template', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Robots', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'nosnippet', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'max-snippet', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'max-image-preview', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'max-video-preview', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'noarchive', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'notranslate', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'unavailable_after', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
		foreach ( $post_types as $post_type => $object ) {
			$title_t      = isset( $settings['post_type_title_templates'][ $post_type ] ) ? $settings['post_type_title_templates'][ $post_type ] : '';
			$meta_t       = isset( $settings['post_type_meta_templates'][ $post_type ] ) ? $settings['post_type_meta_templates'][ $post_type ] : '';
			$robots       = isset( $settings['post_type_robots_defaults'][ $post_type ] ) ? $settings['post_type_robots_defaults'][ $post_type ] : $settings['default_index'];
			$nosnippet    = isset( $settings['post_type_nosnippet_defaults'][ $post_type ] ) ? (string) $settings['post_type_nosnippet_defaults'][ $post_type ] : '0';
			$max_snippet  = isset( $settings['post_type_max_snippet_defaults'][ $post_type ] ) ? (string) $settings['post_type_max_snippet_defaults'][ $post_type ] : '';
			$max_image    = isset( $settings['post_type_max_image_preview_defaults'][ $post_type ] ) ? (string) $settings['post_type_max_image_preview_defaults'][ $post_type ] : '';
			$max_video    = isset( $settings['post_type_max_video_preview_defaults'][ $post_type ] ) ? (string) $settings['post_type_max_video_preview_defaults'][ $post_type ] : '';
			$noarchive    = isset( $settings['post_type_noarchive_defaults'][ $post_type ] ) ? (string) $settings['post_type_noarchive_defaults'][ $post_type ] : '0';
			$notranslate  = isset( $settings['post_type_notranslate_defaults'][ $post_type ] ) ? (string) $settings['post_type_notranslate_defaults'][ $post_type ] : '0';
			$unavailable  = isset( $settings['post_type_unavailable_after_defaults'][ $post_type ] ) ? (string) $settings['post_type_unavailable_after_defaults'][ $post_type ] : '';
			$singular     = (string) ( $object->labels->singular_name ?? $post_type );
			echo '<tr>';
			echo '<td>' . esc_html( $singular ) . '</td>';
			echo '<td><input type="text" class="regular-text" name="ogs[post_type_title_templates][' . esc_attr( $post_type ) . ']" value="' . esc_attr( $title_t ) . '" placeholder="%%title%% %%sep%% %%sitename%%" aria-label="' . esc_attr( sprintf( __( '%s title template', 'open-growth-seo' ), $singular ) ) . '" /></td>';
			echo '<td><input type="text" class="regular-text" name="ogs[post_type_meta_templates][' . esc_attr( $post_type ) . ']" value="' . esc_attr( $meta_t ) . '" placeholder="%%excerpt%%" aria-label="' . esc_attr( sprintf( __( '%s meta template', 'open-growth-seo' ), $singular ) ) . '" /></td>';
			echo '<td><select name="ogs[post_type_robots_defaults][' . esc_attr( $post_type ) . ']" aria-label="' . esc_attr( sprintf( __( '%s robots default', 'open-growth-seo' ), $singular ) ) . '">';
			foreach ( $this->admin->robots_options() as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '" ' . selected( $robots, $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td>';
			echo '<td><select name="ogs[post_type_nosnippet_defaults][' . esc_attr( $post_type ) . ']" aria-label="' . esc_attr( sprintf( __( '%s nosnippet default', 'open-growth-seo' ), $singular ) ) . '"><option value="0" ' . selected( $nosnippet, '0', false ) . '>' . esc_html__( 'No', 'open-growth-seo' ) . '</option><option value="1" ' . selected( $nosnippet, '1', false ) . '>' . esc_html__( 'Yes', 'open-growth-seo' ) . '</option></select></td>';
			echo '<td><input type="number" min="-1" step="1" inputmode="numeric" class="small-text" name="ogs[post_type_max_snippet_defaults][' . esc_attr( $post_type ) . ']" value="' . esc_attr( $max_snippet ) . '" placeholder="-1" aria-label="' . esc_attr( sprintf( __( '%s max-snippet default', 'open-growth-seo' ), $singular ) ) . '" /></td>';
			echo '<td><select name="ogs[post_type_max_image_preview_defaults][' . esc_attr( $post_type ) . ']" aria-label="' . esc_attr( sprintf( __( '%s max-image-preview default', 'open-growth-seo' ), $singular ) ) . '"><option value="" ' . selected( $max_image, '', false ) . '>' . esc_html__( 'Use global', 'open-growth-seo' ) . '</option><option value="large" ' . selected( $max_image, 'large', false ) . '>large</option><option value="standard" ' . selected( $max_image, 'standard', false ) . '>standard</option><option value="none" ' . selected( $max_image, 'none', false ) . '>none</option></select></td>';
			echo '<td><input type="number" min="-1" step="1" inputmode="numeric" class="small-text" name="ogs[post_type_max_video_preview_defaults][' . esc_attr( $post_type ) . ']" value="' . esc_attr( $max_video ) . '" placeholder="-1" aria-label="' . esc_attr( sprintf( __( '%s max-video-preview default', 'open-growth-seo' ), $singular ) ) . '" /></td>';
			echo '<td><select name="ogs[post_type_noarchive_defaults][' . esc_attr( $post_type ) . ']" aria-label="' . esc_attr( sprintf( __( '%s noarchive default', 'open-growth-seo' ), $singular ) ) . '"><option value="0" ' . selected( $noarchive, '0', false ) . '>' . esc_html__( 'No', 'open-growth-seo' ) . '</option><option value="1" ' . selected( $noarchive, '1', false ) . '>' . esc_html__( 'Yes', 'open-growth-seo' ) . '</option></select></td>';
			echo '<td><select name="ogs[post_type_notranslate_defaults][' . esc_attr( $post_type ) . ']" aria-label="' . esc_attr( sprintf( __( '%s notranslate default', 'open-growth-seo' ), $singular ) ) . '"><option value="0" ' . selected( $notranslate, '0', false ) . '>' . esc_html__( 'No', 'open-growth-seo' ) . '</option><option value="1" ' . selected( $notranslate, '1', false ) . '>' . esc_html__( 'Yes', 'open-growth-seo' ) . '</option></select></td>';
			echo '<td><input type="text" class="regular-text" name="ogs[post_type_unavailable_after_defaults][' . esc_attr( $post_type ) . ']" value="' . esc_attr( $unavailable ) . '" placeholder="2026-12-31 23:59" aria-label="' . esc_attr( sprintf( __( '%s unavailable_after default', 'open-growth-seo' ), $singular ) ) . '" /></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Archive and Taxonomy Defaults', 'open-growth-seo' ) . '</h2>';
		$this->admin->field_text( 'taxonomy_title_template', __( 'Taxonomy Title Template', 'open-growth-seo' ), $settings['taxonomy_title_template'] );
		$this->admin->field_text( 'taxonomy_meta_template', __( 'Taxonomy Meta Template', 'open-growth-seo' ), $settings['taxonomy_meta_template'] );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Taxonomy', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Robots', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
		foreach ( $taxonomies as $taxonomy => $object ) {
			$robots        = isset( $settings['taxonomy_robots_defaults'][ $taxonomy ] ) ? $settings['taxonomy_robots_defaults'][ $taxonomy ] : $settings['default_index'];
			$taxonomy_name = (string) ( $object->labels->singular_name ?? $taxonomy );
			echo '<tr><td>' . esc_html( $taxonomy_name ) . '</td><td><select name="ogs[taxonomy_robots_defaults][' . esc_attr( $taxonomy ) . ']" aria-label="' . esc_attr( sprintf( __( '%s taxonomy robots default', 'open-growth-seo' ), $taxonomy_name ) ) . '">';
			foreach ( $this->admin->robots_options() as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '" ' . selected( $robots, $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';

		$this->admin->field_text( 'author_title_template', __( 'Author Title Template', 'open-growth-seo' ), $settings['author_title_template'] );
		$this->admin->field_text( 'author_meta_template', __( 'Author Meta Template', 'open-growth-seo' ), $settings['author_meta_template'] );
		$this->admin->field_select(
			'author_archive',
			__( 'Author Archive Robots', 'open-growth-seo' ),
			$settings['author_archive'],
			array(
				'index'   => 'index',
				'noindex' => 'noindex',
			)
		);
		$this->admin->field_text( 'date_title_template', __( 'Date Archive Title Template', 'open-growth-seo' ), $settings['date_title_template'] );
		$this->admin->field_text( 'date_meta_template', __( 'Date Archive Meta Template', 'open-growth-seo' ), $settings['date_meta_template'] );
		$this->admin->field_select(
			'date_archive',
			__( 'Date Archive Robots', 'open-growth-seo' ),
			$settings['date_archive'],
			array(
				'index'   => 'index',
				'noindex' => 'noindex',
			)
		);
		$this->admin->field_text( 'search_title_template', __( 'Search Title Template', 'open-growth-seo' ), $settings['search_title_template'] );
		$this->admin->field_text( 'search_meta_template', __( 'Search Meta Template', 'open-growth-seo' ), $settings['search_meta_template'] );
		$this->admin->field_select(
			'search_results',
			__( 'Search Pages Robots', 'open-growth-seo' ),
			$settings['search_results'],
			array(
				'index'   => 'index',
				'noindex' => 'noindex',
			)
		);
		$this->admin->field_text( 'attachment_title_template', __( 'Attachment Title Template', 'open-growth-seo' ), $settings['attachment_title_template'] );
		$this->admin->field_text( 'attachment_meta_template', __( 'Attachment Meta Template', 'open-growth-seo' ), $settings['attachment_meta_template'] );
		$this->admin->field_select(
			'attachment_pages',
			__( 'Attachment Pages Robots', 'open-growth-seo' ),
			$settings['attachment_pages'],
			array(
				'index'   => 'index',
				'noindex' => 'noindex',
			)
		);
		if ( class_exists( 'WooCommerce' ) ) {
			echo '<h2>' . esc_html__( 'WooCommerce Archive Defaults', 'open-growth-seo' ) . '</h2>';
			$this->admin->field_select(
				'woo_product_archive',
				__( 'Product archive robots', 'open-growth-seo' ),
				(string) $settings['woo_product_archive'],
				array(
					'index'   => 'index',
					'noindex' => 'noindex',
				)
			);
			$this->admin->field_select(
				'woo_product_cat_archive',
				__( 'Product category archive robots', 'open-growth-seo' ),
				(string) $settings['woo_product_cat_archive'],
				array(
					'index'   => 'index',
					'noindex' => 'noindex',
				)
			);
			$this->admin->field_select(
				'woo_product_tag_archive',
				__( 'Product tag archive robots', 'open-growth-seo' ),
				(string) $settings['woo_product_tag_archive'],
				array(
					'index'   => 'index',
					'noindex' => 'noindex',
				)
			);
			$this->admin->field_select(
				'woo_filter_results',
				__( 'Faceted/filter URLs robots', 'open-growth-seo' ),
				(string) ( $settings['woo_filter_results'] ?? 'noindex' ),
				array(
					'noindex' => 'noindex',
					'index'   => 'index',
				)
			);
			$this->admin->field_select(
				'woo_filter_canonical_target',
				__( 'Faceted/filter URLs canonical target', 'open-growth-seo' ),
				(string) ( $settings['woo_filter_canonical_target'] ?? 'base' ),
				array(
					'base' => __( 'Base archive URL', 'open-growth-seo' ),
					'self' => __( 'Self URL', 'open-growth-seo' ),
					'none' => __( 'Do not emit canonical', 'open-growth-seo' ),
				)
			);
			$this->admin->field_select(
				'woo_pagination_results',
				__( 'Woo archive pagination robots', 'open-growth-seo' ),
				(string) ( $settings['woo_pagination_results'] ?? 'index' ),
				array(
					'index'   => 'index',
					'noindex' => 'noindex',
				)
			);
			$this->admin->field_select(
				'woo_pagination_canonical_target',
				__( 'Woo archive pagination canonical target', 'open-growth-seo' ),
				(string) ( $settings['woo_pagination_canonical_target'] ?? 'self' ),
				array(
					'self'       => __( 'Self paginated URL', 'open-growth-seo' ),
					'first_page' => __( 'First page URL', 'open-growth-seo' ),
				)
			);
			$this->admin->field_text( 'woo_product_archive_title_template', __( 'Product archive title template', 'open-growth-seo' ), (string) ( $settings['woo_product_archive_title_template'] ?? '' ) );
			$this->admin->field_text( 'woo_product_archive_meta_template', __( 'Product archive meta template', 'open-growth-seo' ), (string) ( $settings['woo_product_archive_meta_template'] ?? '' ) );
			$this->admin->field_text( 'woo_product_cat_title_template', __( 'Product category title template', 'open-growth-seo' ), (string) ( $settings['woo_product_cat_title_template'] ?? '' ) );
			$this->admin->field_text( 'woo_product_cat_meta_template', __( 'Product category meta template', 'open-growth-seo' ), (string) ( $settings['woo_product_cat_meta_template'] ?? '' ) );
			$this->admin->field_text( 'woo_product_tag_title_template', __( 'Product tag title template', 'open-growth-seo' ), (string) ( $settings['woo_product_tag_title_template'] ?? '' ) );
			$this->admin->field_text( 'woo_product_tag_meta_template', __( 'Product tag meta template', 'open-growth-seo' ), (string) ( $settings['woo_product_tag_meta_template'] ?? '' ) );
			$this->admin->field_text( 'woo_archive_social_title_template', __( 'Woo archive social title template', 'open-growth-seo' ), (string) ( $settings['woo_archive_social_title_template'] ?? '' ) );
			$this->admin->field_text( 'woo_archive_social_description_template', __( 'Woo archive social description template', 'open-growth-seo' ), (string) ( $settings['woo_archive_social_description_template'] ?? '' ) );
			$this->admin->field_select(
				'woo_archive_schema_mode',
				__( 'Woo archive schema enrichment', 'open-growth-seo' ),
				(string) ( $settings['woo_archive_schema_mode'] ?? 'none' ),
				array(
					'none'       => __( 'None (safe default)', 'open-growth-seo' ),
					'collection' => __( 'CollectionPage additional type', 'open-growth-seo' ),
				)
			);
			echo '<p class="description">' . esc_html__( 'Expert Woo archive controls coordinate robots, canonical behavior, templates, pagination, and schema policy without emitting product rich-result markup on non-product pages.', 'open-growth-seo' ) . '</p>';
		}

		$this->admin->render_snippet_preview( $settings );
	}
}

<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Compatibility;

use OpenGrowthSolutions\OpenGrowthSEO\Core\Defaults;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class Importer {
	private const STATE_OPTION    = 'ogs_seo_import_state';
	private const ROLLBACK_OPTION = 'ogs_seo_import_rollback';
	private const MAX_ROLLBACK_ITEMS = 3000;

	public function detect(): array {
		$report = Detector::report();

		return array(
			'providers'           => isset( $report['providers'] ) && is_array( $report['providers'] ) ? $report['providers'] : array(),
			'active'              => isset( $report['active'] ) && is_array( $report['active'] ) ? $report['active'] : array(),
			'active_slugs'        => isset( $report['active_slugs'] ) && is_array( $report['active_slugs'] ) ? $report['active_slugs'] : array(),
			'has_conflicts'       => ! empty( $report['has_conflicts'] ),
			'safe_mode_enabled'   => 1 === (int) Settings::get( 'safe_mode_seo_conflict', 1 ),
			'runtime'             => isset( $report['runtime'] ) && is_array( $report['runtime'] ) ? $report['runtime'] : array(),
			'contexts'            => isset( $report['contexts'] ) && is_array( $report['contexts'] ) ? $report['contexts'] : array(),
			'integrations'        => isset( $report['integrations'] ) && is_array( $report['integrations'] ) ? $report['integrations'] : array(),
			'recommendations'     => isset( $report['recommendations'] ) && is_array( $report['recommendations'] ) ? $report['recommendations'] : array(),
			'safe_mode_recommended' => ! empty( $report['safe_mode_recommended'] ),
		);
	}

	public function dry_run( array $requested = array() ): array {
		$detected = $this->detect();
		$slugs    = $this->resolve_target_slugs( $requested, array_keys( $detected['active'] ) );
		$settings_preview = $this->preview_settings_changes( $slugs );
		$meta_preview     = $this->preview_meta_changes( $slugs );

		$report = array(
			'time'             => time(),
			'target_slugs'     => $slugs,
			'detected'         => $detected,
			'settings_preview' => $settings_preview,
			'meta_preview'     => $meta_preview,
			'warnings'         => $this->build_warnings( $detected, $slugs, $settings_preview, $meta_preview ),
		);
		$this->update_state( 'last_dry_run', $report );
		return $report;
	}

	public function run_import( array $args = array() ): array {
		$detected = $this->detect();
		$overwrite = ! empty( $args['overwrite'] );
		$include_settings = ! isset( $args['include_settings'] ) || ! empty( $args['include_settings'] );
		$include_meta = ! isset( $args['include_meta'] ) || ! empty( $args['include_meta'] );
		$limit = isset( $args['limit'] ) ? max( 0, absint( $args['limit'] ) ) : 0;
		$requested_slugs = ( isset( $args['slugs'] ) && is_array( $args['slugs'] ) ) ? $args['slugs'] : array();
		$slugs = $this->resolve_target_slugs( $requested_slugs, array_keys( $detected['active'] ) );

		$summary_before = $this->dry_run( $slugs );
		$rollback = array(
			'time'            => time(),
			'settings_before' => Settings::get_all(),
			'meta_changes'    => array(),
			'truncated'       => false,
		);
		$changes = array(
			'settings_changed' => array(),
			'meta_changed'     => 0,
			'meta_skipped'     => 0,
			'target_slugs'     => $slugs,
		);

		if ( $include_settings ) {
			$changes['settings_changed'] = $this->apply_settings_import( $slugs, $overwrite );
		}
		if ( $include_meta ) {
			$meta_result = $this->apply_meta_import( $slugs, $overwrite, $limit );
			$changes['meta_changed'] = (int) $meta_result['changed'];
			$changes['meta_skipped'] = (int) $meta_result['skipped'];
			$rollback['meta_changes'] = (array) $meta_result['rollback_items'];
			$rollback['truncated'] = ! empty( $meta_result['truncated'] );
		}

		$rollback['settings_after'] = Settings::get_all();
		update_option( self::ROLLBACK_OPTION, $rollback, false );

		$summary_after = $this->dry_run( $slugs );
		$report = array(
			'time'            => time(),
			'target_slugs'    => $slugs,
			'overwrite'       => $overwrite,
			'include_settings' => $include_settings,
			'include_meta'    => $include_meta,
			'limit'           => $limit,
			'changes'         => $changes,
			'before'          => $summary_before,
			'after'           => $summary_after,
			'rollback_ready'  => true,
			'rollback_items'  => count( (array) $rollback['meta_changes'] ),
			'rollback_truncated' => ! empty( $rollback['truncated'] ),
		);
		$this->update_state( 'last_import', $report );

		return $report;
	}

	public function rollback_last_import(): array {
		$snapshot = get_option( self::ROLLBACK_OPTION, array() );
		if ( ! is_array( $snapshot ) || empty( $snapshot['settings_before'] ) ) {
			$report = array(
				'ok'      => false,
				'time'    => time(),
				'message' => __( 'No rollback snapshot available.', 'open-growth-seo' ),
			);
			$this->update_state( 'last_rollback', $report );
			return $report;
		}

		$restored_meta = 0;
		$deleted_meta  = 0;
		$meta_items = isset( $snapshot['meta_changes'] ) && is_array( $snapshot['meta_changes'] ) ? $snapshot['meta_changes'] : array();
		foreach ( array_reverse( $meta_items ) as $item ) {
			$post_id = isset( $item['post_id'] ) ? absint( (string) $item['post_id'] ) : 0;
			$key     = isset( $item['key'] ) ? sanitize_key( (string) $item['key'] ) : '';
			$had_old = ! empty( $item['had_old'] );
			$old     = isset( $item['old'] ) ? (string) $item['old'] : '';
			if ( $post_id <= 0 || '' === $key ) {
				continue;
			}
			if ( $had_old ) {
				update_post_meta( $post_id, $key, $old );
				++$restored_meta;
			} else {
				delete_post_meta( $post_id, $key );
				++$deleted_meta;
			}
		}

		$settings_before = isset( $snapshot['settings_before'] ) && is_array( $snapshot['settings_before'] ) ? $snapshot['settings_before'] : array();
		if ( ! empty( $settings_before ) ) {
			Settings::update( $settings_before );
		}

		delete_option( self::ROLLBACK_OPTION );
		$report = array(
			'ok'              => true,
			'time'            => time(),
			'restored_meta'   => $restored_meta,
			'deleted_meta'    => $deleted_meta,
			'restored_settings' => ! empty( $settings_before ),
		);
		$this->update_state( 'last_rollback', $report );
		return $report;
	}

	public function get_state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	public function has_rollback_snapshot(): bool {
		$snapshot = get_option( self::ROLLBACK_OPTION, array() );
		return is_array( $snapshot ) && ! empty( $snapshot['settings_before'] );
	}

	private function resolve_target_slugs( array $requested, array $active ): array {
		$supported = array_keys( Detector::providers() );
		if ( empty( $requested ) ) {
			return array_values( array_unique( array_map( 'sanitize_key', $active ) ) );
		}
		$requested = array_values( array_unique( array_map( 'sanitize_key', $requested ) ) );
		return array_values( array_intersect( $requested, $supported ) );
	}

	private function preview_settings_changes( array $slugs ): array {
		$current = Settings::get_all();
		$candidate = $current;
		$changes = array();

		foreach ( $slugs as $slug ) {
			$candidate = $this->merge_settings_from_provider( $slug, $candidate, true, true, $changes );
		}
		$candidate = Settings::sanitize( $candidate );

		$fields = array();
		foreach ( $changes as $key => $provider ) {
			$old = isset( $current[ $key ] ) ? $current[ $key ] : null;
			$new = isset( $candidate[ $key ] ) ? $candidate[ $key ] : null;
			if ( $old === $new ) {
				continue;
			}
			$fields[ $key ] = array(
				'from'     => $provider,
				'old'      => is_scalar( $old ) ? (string) $old : '[complex]',
				'new'      => is_scalar( $new ) ? (string) $new : '[complex]',
			);
		}

		return array(
			'count'  => count( $fields ),
			'fields' => $fields,
		);
	}

	private function preview_meta_changes( array $slugs ): array {
		global $wpdb;
		if ( empty( $slugs ) || ! isset( $wpdb->postmeta ) ) {
			return array(
				'total_source_rows' => 0,
				'keys'              => array(),
			);
		}
		$source_keys = $this->source_meta_keys_for_slugs( $slugs );
		if ( empty( $source_keys ) ) {
			return array(
				'total_source_rows' => 0,
				'keys'              => array(),
			);
		}
		$keys = array_values( array_unique( $source_keys ) );
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic IN() placeholders are prepared in the next line.
		$sql = "SELECT meta_key, COUNT(*) AS c FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders) GROUP BY meta_key";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared statement generated from a dynamic placeholders list.
		$prepared = $wpdb->prepare( $sql, $keys );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Executing the prepared SQL built above.
		$rows = $wpdb->get_results( $prepared, ARRAY_A );
		$result = array();
		$total = 0;
		foreach ( (array) $rows as $row ) {
			$key = isset( $row['meta_key'] ) ? sanitize_key( (string) $row['meta_key'] ) : '';
			$count = isset( $row['c'] ) ? absint( (string) $row['c'] ) : 0;
			if ( '' === $key ) {
				continue;
			}
			$result[ $key ] = $count;
			$total += $count;
		}
		return array(
			'total_source_rows' => $total,
			'keys'              => $result,
		);
	}

	private function apply_settings_import( array $slugs, bool $overwrite ): array {
		$current   = Settings::get_all();
		$candidate = $current;
		$changes_from = array();
		foreach ( $slugs as $slug ) {
			$candidate = $this->merge_settings_from_provider( $slug, $candidate, false, $overwrite, $changes_from );
		}
		$candidate = Settings::sanitize( $candidate );
		$changed = array();
		foreach ( $changes_from as $key => $source ) {
			$old = $current[ $key ] ?? null;
			$new = $candidate[ $key ] ?? null;
			if ( $old === $new ) {
				continue;
			}
			$changed[ $key ] = $source;
		}
		if ( ! empty( $changed ) ) {
			Settings::update( $candidate );
		}
		return $changed;
	}

	private function apply_meta_import( array $slugs, bool $overwrite, int $limit ): array {
		global $wpdb;
		$result = array(
			'changed'        => 0,
			'skipped'        => 0,
			'rollback_items' => array(),
			'truncated'      => false,
		);
		if ( empty( $slugs ) || ! isset( $wpdb->postmeta, $wpdb->posts ) ) {
			return $result;
		}

		$source_keys = $this->source_meta_keys_for_slugs( $slugs );
		if ( empty( $source_keys ) ) {
			return $result;
		}
		$keys = array_values( array_unique( $source_keys ) );
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic IN() placeholders are prepared in the next line.
		$sql = "SELECT pm.post_id, pm.meta_key, pm.meta_value
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key IN ($placeholders)
			AND p.post_type NOT IN ('revision','nav_menu_item')";
		if ( $limit > 0 ) {
			$sql .= ' LIMIT ' . absint( $limit );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared statement generated from a dynamic placeholders list.
		$prepared = $wpdb->prepare( $sql, $keys );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Executing the prepared SQL built above.
		$rows = $wpdb->get_results( $prepared, ARRAY_A );

		$robots_by_post = array();
		foreach ( (array) $rows as $row ) {
			$post_id = isset( $row['post_id'] ) ? absint( (string) $row['post_id'] ) : 0;
			$key     = isset( $row['meta_key'] ) ? (string) $row['meta_key'] : '';
			$value   = isset( $row['meta_value'] ) ? (string) $row['meta_value'] : '';
			if ( $post_id <= 0 || '' === $key ) {
				continue;
			}
			$updates = $this->map_meta_to_ogs( $key, $value, $post_id, $robots_by_post );
			if ( empty( $updates ) ) {
				++$result['skipped'];
				continue;
			}
			foreach ( $updates as $target_key => $target_value ) {
				$existing = (string) get_post_meta( $post_id, $target_key, true );
				if ( ! $overwrite && '' !== trim( $existing ) ) {
					++$result['skipped'];
					continue;
				}
				if ( $existing === (string) $target_value ) {
					continue;
				}
				if ( count( $result['rollback_items'] ) < self::MAX_ROLLBACK_ITEMS ) {
					$result['rollback_items'][] = array(
						'post_id' => $post_id,
						'key'     => $target_key,
						'had_old' => '' !== $existing,
						'old'     => $existing,
					);
				} else {
					$result['truncated'] = true;
				}
				update_post_meta( $post_id, $target_key, $target_value );
				++$result['changed'];
			}
		}
		return $result;
	}

	private function map_meta_to_ogs( string $source_key, string $source_value, int $post_id, array &$robots_by_post ): array {
		$updates = array();
		switch ( $source_key ) {
			case '_yoast_wpseo_title':
			case 'rank_math_title':
			case '_aioseo_title':
				$updates['ogs_seo_title'] = sanitize_text_field( $source_value );
				break;
			case '_yoast_wpseo_metadesc':
			case 'rank_math_description':
			case '_aioseo_description':
				$updates['ogs_seo_description'] = sanitize_textarea_field( $source_value );
				break;
			case '_yoast_wpseo_canonical':
			case 'rank_math_canonical_url':
			case '_aioseo_canonical_url':
				$canonical = esc_url_raw( trim( $source_value ) );
				if ( '' !== $canonical ) {
					$updates['ogs_seo_canonical'] = $canonical;
				}
				break;
			case '_yoast_wpseo_opengraph-title':
			case 'rank_math_facebook_title':
			case '_aioseo_og_title':
				$updates['ogs_seo_social_title'] = sanitize_text_field( $source_value );
				break;
			case '_yoast_wpseo_opengraph-description':
			case 'rank_math_facebook_description':
			case '_aioseo_og_description':
				$updates['ogs_seo_social_description'] = sanitize_textarea_field( $source_value );
				break;
			case '_yoast_wpseo_opengraph-image':
			case 'rank_math_facebook_image':
			case '_aioseo_og_image':
				$image = esc_url_raw( trim( $source_value ) );
				if ( '' !== $image ) {
					$updates['ogs_seo_social_image'] = $image;
				}
				break;
			case '_yoast_wpseo_meta-robots-noindex':
				$robots_by_post[ $post_id ]['index'] = in_array( strtolower( trim( $source_value ) ), array( '1', 'noindex', 'true' ), true ) ? 'noindex' : 'index';
				break;
			case '_yoast_wpseo_meta-robots-nofollow':
				$robots_by_post[ $post_id ]['follow'] = in_array( strtolower( trim( $source_value ) ), array( '1', 'nofollow', 'true' ), true ) ? 'nofollow' : 'follow';
				break;
			case 'rank_math_robots':
			case '_aioseo_robots':
				$robots = maybe_unserialize( $source_value );
				if ( is_array( $robots ) ) {
					$values = array_map( 'strtolower', array_map( 'sanitize_text_field', $robots ) );
					$robots_by_post[ $post_id ]['index'] = in_array( 'noindex', $values, true ) ? 'noindex' : 'index';
					$robots_by_post[ $post_id ]['follow'] = in_array( 'nofollow', $values, true ) ? 'nofollow' : 'follow';
				}
				break;
		}

		if ( isset( $robots_by_post[ $post_id ]['index'] ) || isset( $robots_by_post[ $post_id ]['follow'] ) ) {
			$index  = isset( $robots_by_post[ $post_id ]['index'] ) ? $robots_by_post[ $post_id ]['index'] : 'index';
			$follow = isset( $robots_by_post[ $post_id ]['follow'] ) ? $robots_by_post[ $post_id ]['follow'] : 'follow';
			$updates['ogs_seo_index']  = $index;
			$updates['ogs_seo_follow'] = $follow;
			$updates['ogs_seo_robots'] = $index . ',' . $follow;
		}
		return $updates;
	}

	private function source_meta_keys_for_slugs( array $slugs ): array {
		$keys = array();
		foreach ( $slugs as $slug ) {
			if ( 'yoast' === $slug ) {
				$keys = array_merge(
					$keys,
					array(
						'_yoast_wpseo_title',
						'_yoast_wpseo_metadesc',
						'_yoast_wpseo_canonical',
						'_yoast_wpseo_meta-robots-noindex',
						'_yoast_wpseo_meta-robots-nofollow',
						'_yoast_wpseo_opengraph-title',
						'_yoast_wpseo_opengraph-description',
						'_yoast_wpseo_opengraph-image',
					)
				);
			}
			if ( 'rankmath' === $slug ) {
				$keys = array_merge(
					$keys,
					array(
						'rank_math_title',
						'rank_math_description',
						'rank_math_canonical_url',
						'rank_math_robots',
						'rank_math_facebook_title',
						'rank_math_facebook_description',
						'rank_math_facebook_image',
					)
				);
			}
			if ( 'aioseo' === $slug ) {
				$keys = array_merge(
					$keys,
					array(
						'_aioseo_title',
						'_aioseo_description',
						'_aioseo_canonical_url',
						'_aioseo_robots',
						'_aioseo_og_title',
						'_aioseo_og_description',
						'_aioseo_og_image',
					)
				);
			}
		}
		return array_values( array_unique( $keys ) );
	}

	private function merge_settings_from_provider( string $slug, array $candidate, bool $dry_run, bool $overwrite, array &$changes ): array {
		unset( $dry_run );
		$defaults = Defaults::settings();
		if ( 'yoast' === $slug ) {
			$wpseo = get_option( 'wpseo_titles', array() );
			if ( ! is_array( $wpseo ) ) {
				$wpseo = array();
			}
			$this->set_setting_if_allowed( $candidate, 'title_separator', (string) ( $wpseo['separator'] ?? '' ), $changes, $slug, $overwrite, $defaults );
			if ( isset( $wpseo['noindex-author-wpseo'] ) ) {
				$this->set_setting_if_allowed( $candidate, 'author_archive', ! empty( $wpseo['noindex-author-wpseo'] ) ? 'noindex' : 'index', $changes, $slug, $overwrite, $defaults );
			}
			if ( isset( $wpseo['noindex-archive-wpseo'] ) ) {
				$this->set_setting_if_allowed( $candidate, 'date_archive', ! empty( $wpseo['noindex-archive-wpseo'] ) ? 'noindex' : 'index', $changes, $slug, $overwrite, $defaults );
			}
			if ( isset( $wpseo['disable-attachment'] ) ) {
				$this->set_setting_if_allowed( $candidate, 'attachment_pages', ! empty( $wpseo['disable-attachment'] ) ? 'noindex' : 'index', $changes, $slug, $overwrite, $defaults );
			}
		}
		if ( 'rankmath' === $slug ) {
			$titles = get_option( 'rank-math-options-titles', array() );
			if ( ! is_array( $titles ) ) {
				$titles = array();
			}
			$separator = isset( $titles['title_separator'] ) ? (string) $titles['title_separator'] : '';
			$this->set_setting_if_allowed( $candidate, 'title_separator', $separator, $changes, $slug, $overwrite, $defaults );
			if ( isset( $titles['pt_post_title'] ) ) {
				$this->set_setting_if_allowed( $candidate, 'post_type_title_templates', array_replace( (array) $candidate['post_type_title_templates'], array( 'post' => $this->convert_template_tokens( (string) $titles['pt_post_title'] ) ) ), $changes, $slug, $overwrite, $defaults, true );
			}
			if ( isset( $titles['pt_page_title'] ) ) {
				$this->set_setting_if_allowed( $candidate, 'post_type_title_templates', array_replace( (array) $candidate['post_type_title_templates'], array( 'page' => $this->convert_template_tokens( (string) $titles['pt_page_title'] ) ) ), $changes, $slug, $overwrite, $defaults, true );
			}
			if ( isset( $titles['author_robots'] ) ) {
				$this->set_setting_if_allowed( $candidate, 'author_archive', false !== strpos( strtolower( (string) $titles['author_robots'] ), 'noindex' ) ? 'noindex' : 'index', $changes, $slug, $overwrite, $defaults );
			}
		}
		if ( 'aioseo' === $slug ) {
			$aioseo = get_option( 'aioseo_options', array() );
			if ( is_array( $aioseo ) ) {
				$global_title = $this->array_get( $aioseo, array( 'searchAppearance', 'global', 'titleFormat' ) );
				if ( is_string( $global_title ) && '' !== trim( $global_title ) ) {
					$this->set_setting_if_allowed( $candidate, 'title_template', $this->convert_template_tokens( $global_title ), $changes, $slug, $overwrite, $defaults );
				}
				$global_desc = $this->array_get( $aioseo, array( 'searchAppearance', 'global', 'metaDescriptionFormat' ) );
				if ( is_string( $global_desc ) && '' !== trim( $global_desc ) ) {
					$this->set_setting_if_allowed( $candidate, 'meta_description_template', $this->convert_template_tokens( $global_desc ), $changes, $slug, $overwrite, $defaults );
				}
			}
		}
		return $candidate;
	}

	private function set_setting_if_allowed( array &$candidate, string $key, $value, array &$changes, string $source, bool $overwrite, array $defaults, bool $is_complex = false ): void {
		if ( $is_complex ) {
			$current = isset( $candidate[ $key ] ) && is_array( $candidate[ $key ] ) ? $candidate[ $key ] : array();
			$default = isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) ? $defaults[ $key ] : array();
			if ( ! $overwrite && $current !== $default ) {
				return;
			}
			$candidate[ $key ] = $value;
			$changes[ $key ] = $source;
			return;
		}
		$value = is_string( $value ) ? trim( $value ) : $value;
		if ( '' === $value || null === $value ) {
			return;
		}
		$current = isset( $candidate[ $key ] ) ? $candidate[ $key ] : null;
		$default = $defaults[ $key ] ?? null;
		if ( ! $overwrite && $current !== $default ) {
			return;
		}
		$candidate[ $key ] = $value;
		$changes[ $key ] = $source;
	}

	private function convert_template_tokens( string $template ): string {
		$map = array(
			'%title%'      => '%%title%%',
			'%sitename%'   => '%%sitename%%',
			'%sep%'        => '%%sep%%',
			'%excerpt%'    => '%%excerpt%%',
			'%term%'       => '%%term%%',
			'%description%' => '%%term_description%%',
		);
		$output = strtr( $template, $map );
		$output = trim( wp_strip_all_tags( $output ) );
		return preg_replace( '/\s+/', ' ', $output );
	}

	private function build_warnings( array $detected, array $slugs, array $settings_preview, array $meta_preview ): array {
		$warnings = array();
		if ( empty( $slugs ) ) {
			$warnings[] = __( 'No supported SEO provider was selected for import.', 'open-growth-seo' );
		}
		$active_slugs = isset( $detected['active_slugs'] ) && is_array( $detected['active_slugs'] ) ? array_map( 'sanitize_key', $detected['active_slugs'] ) : array();
		$inactive_selected = array_values( array_diff( $slugs, $active_slugs ) );
		if ( ! empty( $inactive_selected ) ) {
			$warnings[] = sprintf(
				/* translators: %s: providers */
				__( 'Selected provider(s) are not currently active: %s. Import can still run from stored options/meta.', 'open-growth-seo' ),
				implode( ', ', $inactive_selected )
			);
		}
		if ( ! empty( $detected['has_conflicts'] ) && empty( $detected['safe_mode_enabled'] ) ) {
			$warnings[] = __( 'Safe mode is disabled while another SEO plugin is active; duplicate output risk remains.', 'open-growth-seo' );
		}
		$runtime = isset( $detected['runtime'] ) && is_array( $detected['runtime'] ) ? $detected['runtime'] : array();
		if ( array_key_exists( 'meets_php', $runtime ) && empty( $runtime['meets_php'] ) ) {
			$warnings[] = sprintf(
				/* translators: 1: required version, 2: actual version */
				__( 'Current PHP version does not meet the plugin baseline (%1$s required, %2$s detected). Validate migration behavior conservatively.', 'open-growth-seo' ),
				(string) ( $runtime['minimum_php'] ?? '' ),
				(string) ( $runtime['php_version'] ?? '' )
			);
		}
		if ( array_key_exists( 'meets_wp', $runtime ) && empty( $runtime['meets_wp'] ) ) {
			$warnings[] = sprintf(
				/* translators: 1: required version, 2: actual version */
				__( 'Current WordPress version does not meet the plugin baseline (%1$s required, %2$s detected). Compatibility fallbacks may be limited.', 'open-growth-seo' ),
				(string) ( $runtime['minimum_wp'] ?? '' ),
				(string) ( $runtime['wp_version'] ?? '' )
			);
		}
		$integrations = isset( $detected['integrations'] ) && is_array( $detected['integrations'] ) ? $detected['integrations'] : array();
		foreach ( array( 'cache', 'translations', 'schema', 'builders' ) as $slug_key ) {
			if ( empty( $integrations[ $slug_key ]['active'] ) || empty( $integrations[ $slug_key ]['label'] ) ) {
				continue;
			}
			$warnings[] = sprintf(
				/* translators: %s: integration group label */
				__( '%s tooling is active. Recheck frontend output after import because cache layers or alternate render pipelines can hide effective changes.', 'open-growth-seo' ),
				(string) $integrations[ $slug_key ]['label']
			);
		}
		if ( 0 === (int) ( $settings_preview['count'] ?? 0 ) && 0 === (int) ( $meta_preview['total_source_rows'] ?? 0 ) ) {
			$warnings[] = __( 'Dry run found no importable settings or metadata for selected providers.', 'open-growth-seo' );
		}
		return $warnings;
	}

	private function update_state( string $key, array $report ): void {
		$state = $this->get_state();
		$state[ $key ] = $report;
		update_option( self::STATE_OPTION, $state, false );
	}

	private function array_get( array $source, array $path ) {
		$node = $source;
		foreach ( $path as $segment ) {
			if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
				return null;
			}
			$node = $node[ $segment ];
		}
		return $node;
	}
}

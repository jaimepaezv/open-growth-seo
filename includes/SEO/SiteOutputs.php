<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\SEO;

use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class SiteOutputs {
	public function register(): void {
		add_action( 'wp_head', array( $this, 'render_verification_tags' ), 2 );
		add_filter( 'the_content_feed', array( $this, 'filter_feed_content' ), 20, 2 );
		add_filter( 'the_excerpt_rss', array( $this, 'filter_feed_excerpt' ), 20 );
		add_action( 'template_redirect', array( $this, 'maybe_render_llms_txt' ), 0 );
	}

	public function render_verification_tags(): void {
		if ( is_admin() || is_feed() ) {
			return;
		}

		$settings = Settings::get_all();
		$tags     = array(
			'google-site-verification' => (string) ( $settings['webmaster_google_verification'] ?? '' ),
			'msvalidate.01'            => (string) ( $settings['webmaster_bing_verification'] ?? '' ),
			'yandex-verification'      => (string) ( $settings['webmaster_yandex_verification'] ?? '' ),
			'p:domain_verify'          => (string) ( $settings['webmaster_pinterest_verification'] ?? '' ),
			'baidu-site-verification'  => (string) ( $settings['webmaster_baidu_verification'] ?? '' ),
		);

		foreach ( $tags as $name => $content ) {
			$content = trim( wp_strip_all_tags( $content ) );
			if ( '' === $content ) {
				continue;
			}
			echo '<meta name="' . esc_attr( $name ) . '" content="' . esc_attr( $content ) . '" />' . "\n";
		}
	}

	public function filter_feed_content( string $content, string $feed_type = '' ): string {
		unset( $feed_type );
		return $this->inject_rss_wrappers( $content );
	}

	public function filter_feed_excerpt( string $excerpt ): string {
		return $this->inject_rss_wrappers( $excerpt );
	}

	public function maybe_render_llms_txt(): void {
		$settings = Settings::get_all();
		if ( empty( $settings['llms_txt_enabled'] ) ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path        = (string) wp_parse_url( home_url( $request_uri ), PHP_URL_PATH );
		if ( '/llms.txt' !== rtrim( $path, '/' ) ) {
			return;
		}

		if ( ! headers_sent() ) {
			status_header( 200 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		}

		echo $this->llms_txt_body( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public function llms_txt_body( array $settings = array() ): string {
		if ( empty( $settings ) ) {
			$settings = Settings::get_all();
		}

		$mode = sanitize_key( (string) ( $settings['llms_txt_mode'] ?? 'guided' ) );
		if ( 'custom' === $mode ) {
			$content = trim( (string) ( $settings['llms_txt_content'] ?? '' ) );
			if ( '' !== $content ) {
				return $content . "\n";
			}
		}

		$lines   = array();
		$lines[] = '# ' . (string) get_bloginfo( 'name' );
		$description = trim( wp_strip_all_tags( (string) get_bloginfo( 'description' ) ) );
		if ( '' !== $description ) {
			$lines[] = 'Description: ' . $description;
		}
		$lines[] = 'URL: ' . home_url( '/' );
		if ( ! empty( $settings['sitemap_enabled'] ) ) {
			$lines[] = 'Sitemap: ' . home_url( '/sitemap.xml' );
		}
		$lines[] = 'Canonical policy: prefer canonical URLs and visible page content as the source of truth.';
		$lines[] = 'Access policy: respect noindex, nosnippet, and data-nosnippet restrictions where present.';

		$before = trim( (string) ( $settings['rss_before_content'] ?? '' ) );
		$after  = trim( (string) ( $settings['rss_after_content'] ?? '' ) );
		if ( '' !== $before || '' !== $after ) {
			$lines[] = 'Feed policy: RSS content includes publisher-defined attribution or guidance.';
		}

		$custom = trim( (string) ( $settings['llms_txt_content'] ?? '' ) );
		if ( '' !== $custom ) {
			$lines[] = '';
			$lines[] = '# Additional notes';
			$lines[] = $custom;
		}

		return implode( "\n", array_filter( $lines ) ) . "\n";
	}

	private function inject_rss_wrappers( string $content ): string {
		$settings = Settings::get_all();
		$before   = $this->render_rss_text( (string) ( $settings['rss_before_content'] ?? '' ) );
		$after    = $this->render_rss_text( (string) ( $settings['rss_after_content'] ?? '' ) );

		if ( '' === $before && '' === $after ) {
			return $content;
		}

		$parts = array();
		if ( '' !== $before ) {
			$parts[] = '<p class="ogs-rss-before">' . esc_html( $before ) . '</p>';
		}
		$parts[] = $content;
		if ( '' !== $after ) {
			$parts[] = '<p class="ogs-rss-after">' . esc_html( $after ) . '</p>';
		}

		return implode( "\n", $parts );
	}

	private function render_rss_text( string $template ): string {
		$template = trim( sanitize_textarea_field( $template ) );
		if ( '' === $template ) {
			return '';
		}

		$post_id = function_exists( 'get_the_ID' ) ? absint( get_the_ID() ) : 0;
		$title   = $post_id > 0 ? (string) get_the_title( $post_id ) : '';
		$url     = $post_id > 0 ? (string) get_permalink( $post_id ) : home_url( '/' );

		$replacements = array(
			'%%title%%'    => $title,
			'%%sitename%%' => (string) get_bloginfo( 'name' ),
			'%%url%%'      => $url,
		);

		return trim( preg_replace( '/\s+/', ' ', strtr( $template, $replacements ) ) );
	}
}

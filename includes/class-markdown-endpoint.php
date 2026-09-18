<?php

/**
 * Markdown path endpoint.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents;

/**
 * Serves supported posts from their `/markdown` path.
 *
 * @package Content_For_Agents
 */
class Markdown_Endpoint {

	/**
	 * Constructor.
	 *
	 * @param Loader $loader The loader instance.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'parse_request', $this, 'maybe_serve', 1 );
	}

	/**
	 * Serve a supported post when the request path ends in `/markdown`.
	 *
	 * @param \WP $wp Current WordPress environment instance.
	 */
	public function maybe_serve( $wp ): void {
		if ( '' === (string) get_option( 'permalink_structure', '' ) ) {
			return;
		}

		$post_path = self::get_post_path( (string) $wp->request );
		if ( null === $post_path ) {
			return;
		}

		if ( '' === $post_path ) {
			if ( 'page' !== get_option( 'show_on_front' ) ) {
				return;
			}
			$post_id = (int) get_option( 'page_on_front' );
		} else {
			$post_id = $this->url_to_post_id( home_url( '/' . $post_path . '/' ) );
			if ( ! $post_id ) {
				$post_id = $this->url_to_post_id( home_url( '/' . $post_path ) );
			}
		}

		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || ! $this->can_serve( $post ) ) {
			return;
		}

		Markdown_Response::serve( $post );
	}

	/**
	 * Extract a post path from WordPress's normalized request path.
	 *
	 * @internal
	 *
	 * @param string $path Request path.
	 * An empty string represents the static front page. Null means the request
	 * is not a Markdown endpoint.
	 *
	 * @return string|null Post path, an empty string for the front page, or null.
	 */
	public static function get_post_path( string $path ): ?string {
		$path   = trim( $path, '/' );
		$suffix = '/markdown';

		if ( 'markdown' === $path ) {
			return '';
		}

		if ( ! str_ends_with( $path, $suffix ) ) {
			return null;
		}

		return substr( $path, 0, -strlen( $suffix ) );
	}

	/**
	 * Build the Markdown endpoint URL for a post.
	 *
	 * Plain `?p=123` permalinks are unsupported because they cannot represent
	 * the endpoint path. Their individual Markdown URL is intentionally empty.
	 *
	 * @param int|\WP_Post $post Post ID or object.
	 * @return string Endpoint URL, or an empty string when unsupported.
	 */
	public static function get_url( $post ): string {
		if ( '' === (string) get_option( 'permalink_structure', '' ) ) {
			return '';
		}

		$permalink = get_permalink( $post );
		if ( ! is_string( $permalink ) || '' === $permalink
			|| wp_parse_url( $permalink, PHP_URL_QUERY ) ) {
			return '';
		}

		return untrailingslashit( $permalink ) . '/markdown';
	}

	/**
	 * Resolve a URL to a post ID using VIP's cached lookup.
	 *
	 * @param string $url Permalink to resolve.
	 * @return int Post ID, or zero when no post matches.
	 */
	private function url_to_post_id( string $url ): int {
		if ( function_exists( 'wpcom_vip_url_to_postid' ) ) {
			return (int) wpcom_vip_url_to_postid( $url );
		}

		// Core fallback supports local and non-VIP development environments.
		return (int) url_to_postid( $url ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.url_to_postid_url_to_postid
	}

	/**
	 * Determine whether Markdown may be served for a post.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool Whether the post may be served.
	 */
	private function can_serve( \WP_Post $post ): bool {
		if ( ! post_type_supports( $post->post_type, 'content-for-agents' ) ) {
			return false;
		}

		if ( 'publish' === $post->post_status || current_user_can( 'read_post', $post->ID ) ) {
			return true;
		}

		if ( empty( $_GET['preview'] ) || ! isset( $_GET['preview_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET['preview_nonce'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return (bool) wp_verify_nonce( $nonce, 'post_preview_' . $post->ID );
	}
}

<?php

/**
 * Markdown conversion failure logging.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents;

use Throwable;
use WP_Error;

/**
 * Logs conversion failures without content or exception messages.
 */
class Markdown_Conversion_Logger {
	/**
	 * Cache group for rate-limiting conversion diagnostics.
	 */
	const CACHE_GROUP = 'content_for_agents_errors';

	/**
	 * Log at most one conversion failure per post and site every five minutes.
	 *
	 * @param WP_Error $error   Conversion failure.
	 * @param int      $post_id Post ID for locating the affected document.
	 */
	public static function log( WP_Error $error, int $post_id ): void {
		$site_id = get_current_blog_id();

		// Include the site because post IDs can repeat across a multisite network.
		if ( ! wp_cache_add( 'conversion_error_' . $site_id . '_' . $post_id, true, self::CACHE_GROUP, 300 ) ) {
			return;
		}

		$block_name = 'unknown';
		$cause      = $error;
		if ( 'content_for_agents_block_conversion_failed' === $error->get_error_code() ) {
			$context = $error->get_error_data();
			if ( is_array( $context ) && ( $context['cause'] ?? null ) instanceof WP_Error ) {
				$block_name = $context['block_name'] ?? 'unknown';
				$cause      = $context['cause'];
			}
		}

		$origin = $cause->get_error_data();
		$source = $origin instanceof Throwable ? wp_normalize_path( $origin->getFile() ) : 'unknown';
		$root   = trailingslashit( wp_normalize_path( ABSPATH ) );
		$source = str_starts_with( $source, $root ) ? substr( $source, strlen( $root ) ) : basename( $source );
		// WordPress applies this pattern when registering a block type.
		$block_name = is_string( $block_name ) && preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $block_name ) ? $block_name : 'unknown';
		$error_code = sanitize_key( (string) $cause->get_error_code() );
		$error_code = '' !== $error_code ? $error_code : 'unknown';

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Production diagnostics for conversion failures.
			sprintf(
				'content-for-agents: Markdown conversion failed; site_id=%d; post_id=%d; block=%s; error=%s; code=%s; source=%s:%d',
				$site_id,
				$post_id,
				$block_name,
				$origin instanceof Throwable ? get_class( $origin ) : 'WP_Error',
				$error_code,
				$source,
				$origin instanceof Throwable ? $origin->getLine() : 0
			)
		);
	}
}

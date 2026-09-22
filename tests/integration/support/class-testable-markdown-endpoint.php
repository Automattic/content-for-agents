<?php

/**
 * Markdown endpoint test double.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents\Tests\Integration;

use Content_For_Agents\Markdown_Endpoint;

/**
 * Exposes protected request checks for integration coverage.
 */
class Testable_Markdown_Endpoint extends Markdown_Endpoint {

	/**
	 * Expose the shared post eligibility check.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	public function can_serve_for_test( \WP_Post $post ): bool {
		return $this->can_serve( $post );
	}

	/**
	 * Expose the query request check.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	public function can_serve_query_for_test( \WP_Post $post ): bool {
		return $this->can_serve_query( $post );
	}

	/**
	 * Expose query-parameter parsing.
	 *
	 * @return bool
	 */
	public function request_wants_markdown_query_for_test(): bool {
		return $this->request_wants_markdown_query();
	}

	/**
	 * Expose query post resolution.
	 *
	 * @return \WP_Post|null
	 */
	public function get_query_post_for_test(): ?\WP_Post {
		return $this->get_query_post();
	}
}

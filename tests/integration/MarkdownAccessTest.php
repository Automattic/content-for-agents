<?php

/**
 * Markdown access integration tests.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents\Tests\Integration;

use Content_For_Agents\Markdown_Access;

/**
 * Exercises core access rules and integration vetoes.
 */
class MarkdownAccessTest extends \WP_UnitTestCase {

	/**
	 * Restore request and authentication state after each test.
	 */
	public function tear_down(): void {
		unset( $_GET['preview'], $_GET['preview_nonce'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Access-control integrations can veto an otherwise-public response.
	 */
	public function test_integration_can_deny_published_markdown(): void {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );

		add_filter( 'content_for_agents_can_serve_markdown', '__return_false' );
		$this->assertFalse( Markdown_Access::can_serve( $post ) );
	}

	/**
	 * The access filter receives the discovery context.
	 */
	public function test_discovery_context_is_passed_to_access_filter(): void {
		$post     = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		$contexts = array();

		add_filter(
			'content_for_agents_can_serve_markdown',
			static function ( bool $allowed, \WP_Post $filtered_post, string $context ) use ( &$contexts, $post ): bool {
				$contexts[] = $context;
				return $allowed && $post->ID === $filtered_post->ID;
			},
			10,
			3
		);

		$this->assertTrue( Markdown_Access::can_serve( $post, Markdown_Access::CONTEXT_DISCOVERY ) );
		$this->assertSame( array( Markdown_Access::CONTEXT_DISCOVERY ), $contexts );
	}

	/**
	 * A filter cannot expose unpublished content to an anonymous visitor.
	 */
	public function test_filter_cannot_override_core_post_status_protection(): void {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'private' ) );

		add_filter( 'content_for_agents_can_serve_markdown', '__return_true' );
		$this->assertFalse( Markdown_Access::can_serve( $post ) );
	}

	/**
	 * A filter cannot advertise password-protected content publicly.
	 */
	public function test_filter_cannot_override_discovery_password_protection(): void {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);

		add_filter( 'content_for_agents_can_serve_markdown', '__return_true' );
		$this->assertFalse( Markdown_Access::can_serve( $post, Markdown_Access::CONTEXT_DISCOVERY ) );
	}

	/**
	 * Integrations can prevent visitor-specific responses entering shared cache.
	 */
	public function test_integration_can_veto_shared_cache(): void {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );

		add_filter( 'content_for_agents_can_cache_markdown', '__return_false' );
		$this->assertFalse( Markdown_Access::can_cache( $post ) );
	}

	/**
	 * A filter cannot make an authenticated response shared-cacheable.
	 */
	public function test_filter_cannot_override_core_authenticated_cache_protection(): void {
		$post    = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		add_filter( 'content_for_agents_can_cache_markdown', '__return_true' );
		$this->assertFalse( Markdown_Access::can_cache( $post ) );
	}
}

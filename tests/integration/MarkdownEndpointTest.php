<?php

/**
 * Markdown endpoint integration tests.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents\Tests\Integration;

use Content_For_Agents\Discovery;
use Content_For_Agents\LLMs_Txt;
use Content_For_Agents\Loader;
use Content_For_Agents\Markdown_Cache_Invalidator;
use Content_For_Agents\Markdown_Endpoint;

require_once __DIR__ . '/support/class-testable-markdown-endpoint.php';

/**
 * Exercises Markdown URL generation under different permalink modes.
 */
class MarkdownEndpointTest extends \WP_UnitTestCase {

	/**
	 * Original permalink structure.
	 *
	 * @var string
	 */
	private $permalink_structure;

	/**
	 * Original front-page display setting.
	 *
	 * @var string
	 */
	private $show_on_front;

	/**
	 * Original static front-page ID.
	 *
	 * @var int
	 */
	private $page_on_front;

	/**
	 * Endpoint instance used to inspect query request checks.
	 *
	 * @var Testable_Markdown_Endpoint
	 */
	private $endpoint;

	/**
	 * Only the `/markdown` path forms match the endpoint.
	 *
	 * @dataProvider endpoint_path_provider
	 *
	 * @param string $path     Request path.
	 * @param string|null $expected Expected post path.
	 */
	public function test_get_post_path_only_accepts_markdown_endpoint( string $path, ?string $expected ): void {
		$this->assertSame( $expected, Markdown_Endpoint::get_post_path( $path ) );
	}

	/**
	 * Endpoint path scenarios.
	 *
	 * @return array<string, array{string, string|null}>
	 */
	public function endpoint_path_provider(): array {
		return array(
			'normalized path endpoint'     => array( 'example/markdown', 'example' ),
			'path endpoint'                => array( '/example/markdown', 'example' ),
			'path endpoint trailing slash' => array( '/example/markdown/', 'example' ),
			'nested path endpoint'         => array( '/news/example/markdown', 'news/example' ),
			'dot md removed'               => array( '/example.md', null ),
			'canonical path'               => array( '/example/', null ),
			'plain permalink path'         => array( '/', null ),
			'root markdown page'           => array( '/markdown', null ),
		);
	}

	/**
	 * A nested page remains reachable when URL-to-post lookup misses its path.
	 */
	public function test_nested_page_path_falls_back_to_canonical_page(): void {
		$this->configure_permalink_structure( '/%postname%/' );
		$parent_id  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'partners',
			)
		);
		$child_id   = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'strategic-alliances',
				'post_parent' => $parent_id,
			)
		);
		$force_miss = static function (): string {
			return 'https://invalid.example/';
		};
		add_filter( 'url_to_postid', $force_miss );
		try {
			$this->assertSame( $child_id, $this->endpoint->get_post_from_path_for_test( 'partners/strategic-alliances' )->ID );
			$this->assertNull( $this->endpoint->get_post_from_path_for_test( 'partners/not-a-page' ) );
			$other_permalink = static function ( string $link, int $post_id ) use ( $child_id ): string {
				return $child_id === $post_id ? home_url( '/other-page/' ) : $link;
			};
			add_filter( 'page_link', $other_permalink, 10, 2 );
			try {
				$this->assertNull( $this->endpoint->get_post_from_path_for_test( 'partners/strategic-alliances' ) );
			} finally {
				remove_filter( 'page_link', $other_permalink );
			}
		} finally {
			remove_filter( 'url_to_postid', $force_miss );
		}
	}

	/**
	 * Preserve the test suite's permalink structure.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->permalink_structure = (string) get_option( 'permalink_structure' );
		$this->show_on_front       = (string) get_option( 'show_on_front' );
		$this->page_on_front       = (int) get_option( 'page_on_front' );
		$this->endpoint            = new Testable_Markdown_Endpoint( new Loader() );
	}

	/**
	 * Restore the test suite's permalink structure.
	 */
	public function tear_down(): void {
		unset( $_GET['markdown'], $_GET['p'], $_GET['page_id'], $_GET['preview'], $_GET['preview_id'], $_GET['preview_nonce'] );
		wp_set_current_user( 0 );
		$this->configure_permalink_structure( $this->permalink_structure );
		update_option( 'show_on_front', $this->show_on_front );
		update_option( 'page_on_front', $this->page_on_front );
		parent::tear_down();
	}

	/**
	 * The supported query values remain accepted.
	 *
	 * @dataProvider markdown_query_value_provider
	 *
	 * @param string $value    Query value.
	 * @param bool   $expected Whether the value enables Markdown.
	 */
	public function test_markdown_query_values( string $value, bool $expected ): void {
		$_GET['markdown'] = $value;

		$this->assertSame( $expected, $this->endpoint->request_wants_markdown_query_for_test() );
	}

	/**
	 * Query parameter scenarios.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public function markdown_query_value_provider(): array {
		return array(
			'true'      => array( 'true', true ),
			'one'       => array( '1', true ),
			'yes'       => array( 'yes', true ),
			'on'        => array( 'on', true ),
			'false'     => array( 'false', false ),
			'empty'     => array( '', false ),
			'arbitrary' => array( 'markdown', false ),
		);
	}

	/**
	 * Protected editorial statuses remain eligible after WordPress resolves them.
	 *
	 * @dataProvider protected_status_provider
	 *
	 * @param string $status Post status.
	 */
	public function test_resolved_protected_status_query_is_supported( string $status ): void {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$args    = array( 'post_status' => $status );
		if ( 'future' === $status ) {
			$args['post_date']     = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
			$args['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		}
		$post_id = self::factory()->post->create( $args );
		wp_set_current_user( $user_id );

		$this->assertTrue(
			$this->endpoint->can_serve_query_for_test( get_post( $post_id ) )
		);
	}

	/**
	 * Protected editorial status scenarios.
	 *
	 * @return array<string, array{string}>
	 */
	public function protected_status_provider(): array {
		return array(
			'draft'     => array( 'draft' ),
			'pending'   => array( 'pending' ),
			'scheduled' => array( 'future' ),
		);
	}

	/**
	 * Anonymous visitors may use the query endpoint for published posts.
	 */
	public function test_anonymous_published_query_is_supported(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertTrue(
			$this->endpoint->can_serve_query_for_test( get_post( $post_id ) )
		);
	}

	/**
	 * Published posts resolve through the query form with plain permalinks.
	 */
	public function test_anonymous_published_plain_permalink_query_resolves_post(): void {
		$this->configure_permalink_structure( '' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$url     = add_query_arg(
			array(
				'p'        => $post_id,
				'markdown' => 'true',
			),
			home_url( '/' )
		);
		$this->go_to( $url );
		$_GET['p']        = (string) $post_id;
		$_GET['markdown'] = 'true';

		$post = $this->endpoint->get_query_post_for_test();
		$this->assertInstanceOf( \WP_Post::class, $post );
		$this->assertSame( $post_id, $post->ID );
	}

	/**
	 * An authenticated post ID cannot bypass WordPress's singular query.
	 *
	 * @dataProvider non_singular_post_id_provider
	 *
	 * @param string $query_var Query variable to inject.
	 * @param string $post_type Post type to create.
	 */
	public function test_authenticated_non_singular_query_does_not_resolve_post_id( string $query_var, string $post_type ): void {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_type'   => $post_type,
			)
		);
		wp_set_current_user( $user_id );
		$this->go_to( add_query_arg( 's', 'missing', home_url( '/' ) ) );
		$_GET[ $query_var ] = (string) $post_id;
		$_GET['markdown']   = 'true';

		$this->assertFalse( is_singular() );
		$this->assertNull( $this->endpoint->get_query_post_for_test() );
	}

	/**
	 * Post ID query variables that must not trigger fallback resolution.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function non_singular_post_id_provider(): array {
		return array(
			'post' => array( 'p', 'post' ),
			'page' => array( 'page_id', 'page' ),
		);
	}

	/**
	 * Anonymous lookup cannot expose a published, non-public custom post type.
	 */
	public function test_anonymous_non_public_custom_post_type_is_unsupported(): void {
		register_post_type(
			'cfa_internal',
			array(
				'public'             => false,
				'publicly_queryable' => false,
				'supports'           => array( 'content-for-agents' ),
			)
		);

		try {
			$post_id = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_type'   => 'cfa_internal',
				)
			);
			$this->go_to( add_query_arg( 'p', $post_id, home_url( '/' ) ) );
			$_GET['p']        = (string) $post_id;
			$_GET['markdown'] = 'true';

			$this->assertFalse( is_singular() );
			$this->assertNull( $this->endpoint->get_query_post_for_test() );
		} finally {
			unregister_post_type( 'cfa_internal' );
		}
	}

	/**
	 * A resolved private post is eligible when the current user can read it.
	 */
	public function test_readable_private_query_is_supported(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id = self::factory()->post->create( array( 'post_status' => 'private' ) );
		wp_set_current_user( $user_id );

		$this->assertTrue(
			$this->endpoint->can_serve_query_for_test( get_post( $post_id ) )
		);
	}

	/**
	 * Anonymous visitors cannot use the query form to read private posts.
	 */
	public function test_anonymous_private_query_is_unsupported(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'private' ) );

		$this->assertFalse( $this->endpoint->can_serve_query_for_test( get_post( $post_id ) ) );
	}

	/**
	 * Internal post statuses are never exposed through the query form.
	 *
	 * @dataProvider internal_status_provider
	 *
	 * @param string $status Post status.
	 */
	public function test_internal_status_query_is_unsupported( string $status ): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id = self::factory()->post->create( array( 'post_status' => $status ) );
		wp_set_current_user( $user_id );

		$this->assertFalse( $this->endpoint->can_serve_query_for_test( get_post( $post_id ) ) );
	}

	/**
	 * Internal post status scenarios.
	 *
	 * @return array<string, array{string}>
	 */
	public function internal_status_provider(): array {
		return array(
			'auto draft' => array( 'auto-draft' ),
			'trash'      => array( 'trash' ),
		);
	}

	/**
	 * The static front page has no individual query-form Markdown URL.
	 */
	public function test_static_front_page_query_is_unsupported(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );

		$page = get_post( $page_id );
		$this->assertFalse( $this->endpoint->can_serve_for_test( $page ) );
		$this->assertFalse( $this->endpoint->can_serve_query_for_test( $page ) );

		$this->go_to( add_query_arg( 'markdown', 'true', home_url( '/' ) ) );
		$_GET['markdown'] = 'true';
		$this->assertTrue( is_front_page() );
		$this->assertNull( $this->endpoint->get_query_post_for_test() );
	}

	/**
	 * A valid autosave preview resolves its revised content.
	 */
	public function test_authenticated_autosave_preview_resolves_revised_content(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_author'  => $user_id,
				'post_content' => 'Published content',
				'post_status'  => 'publish',
			)
		);
		wp_set_current_user( $user_id );
		$autosave_id = wp_create_post_autosave(
			array(
				'post_ID'      => $post_id,
				'post_content' => 'Autosave preview content',
				'post_title'   => 'Autosave preview title',
				'post_type'    => 'post',
			)
		);
		$this->assertIsInt( $autosave_id );

		$preview_url = get_preview_post_link(
			$post_id,
			array(
				'markdown'      => 'true',
				'preview_id'    => $post_id,
				'preview_nonce' => wp_create_nonce( 'post_preview_' . $post_id ),
			)
		);
		$this->assertIsString( $preview_url );
		parse_str( (string) wp_parse_url( $preview_url, PHP_URL_QUERY ), $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		_show_post_preview();

		try {
			$this->go_to( $preview_url );
			parse_str( (string) wp_parse_url( $preview_url, PHP_URL_QUERY ), $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			$post = $this->endpoint->get_query_post_for_test();
			$this->assertInstanceOf( \WP_Post::class, $post );
			$this->assertSame( $post_id, $post->ID );
			$this->assertSame( 'Autosave preview content', $post->post_content );
		} finally {
			remove_filter( 'the_preview', '_set_preview' );
		}
	}

	/**
	 * A preview flag without a singular post is ignored.
	 */
	public function test_non_singular_preview_request_is_unsupported(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		$this->go_to(
			add_query_arg(
				array(
					'preview'  => 'true',
					'markdown' => 'true',
				),
				home_url( '/' )
			)
		);
		$_GET['preview']  = 'true';
		$_GET['markdown'] = 'true';

		$this->assertNull( $this->endpoint->get_query_post_for_test() );
	}

	/**
	 * A resolved draft remains unavailable to anonymous users.
	 */
	public function test_anonymous_draft_query_is_unsupported(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->assertFalse(
			$this->endpoint->can_serve_query_for_test( get_post( $post_id ) )
		);
	}

	/**
	 * A resolved draft still requires permission to read the requested post.
	 */
	public function test_draft_query_requires_read_permission(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_set_current_user( $user_id );

		$this->assertFalse(
			$this->endpoint->can_serve_query_for_test( get_post( $post_id ) )
		);
	}

	/**
	 * A resolved draft requires read permission, not edit permission.
	 */
	public function test_draft_query_allows_read_without_edit_permission(): void {
		$user_id         = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$post_id         = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$grant_read_post = static function ( array $caps, string $cap ): array {
			if ( 'read_post' === $cap ) {
				return array( 'read' );
			}

			return $caps;
		};
		add_filter( 'map_meta_cap', $grant_read_post, 10, 4 );
		wp_set_current_user( $user_id );

		try {
			$this->assertTrue( current_user_can( 'read_post', $post_id ) );
			$this->assertFalse( current_user_can( 'edit_post', $post_id ) );
			$this->assertTrue(
				$this->endpoint->can_serve_query_for_test( get_post( $post_id ) )
			);
		} finally {
			remove_filter( 'map_meta_cap', $grant_read_post, 10 );
		}
	}

	/**
	 * A normal draft query may be resolved by WordPress for an editor.
	 */
	public function test_authenticated_draft_request_uses_wordpress_resolution(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_set_current_user( $user_id );

		$this->go_to(
			add_query_arg(
				array(
					'p'        => $post_id,
					'markdown' => 'true',
				),
				home_url( '/' )
			)
		);
		$_GET['p']        = (string) $post_id;
		$_GET['markdown'] = 'true';

		$this->assertTrue( is_singular() );
		$post = $this->endpoint->get_query_post_for_test();
		$this->assertInstanceOf( \WP_Post::class, $post );
		$this->assertSame( $post_id, $post->ID );
	}

	/**
	 * Pretty permalinks receive the path endpoint.
	 */
	public function test_get_url_returns_markdown_path_for_pretty_permalinks(): void {
		$this->configure_permalink_structure( '/%postname%/' );
		$post_id = self::factory()->post->create(
			array(
				'post_name'   => 'example',
				'post_status' => 'publish',
			)
		);

		$this->assertSame( home_url( '/example/markdown' ), Markdown_Endpoint::get_url( $post_id ) );
	}

	/**
	 * A page may use the root Markdown path as its permalink.
	 */
	public function test_get_url_preserves_page_with_markdown_slug(): void {
		$this->configure_permalink_structure( '/%postname%/' );
		$page_id = self::factory()->post->create(
			array(
				'post_name'   => 'markdown',
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);

		$this->assertSame( home_url( '/markdown/markdown' ), Markdown_Endpoint::get_url( $page_id ) );
	}

	/**
	 * Plain permalinks use the query endpoint as their public Markdown URL.
	 */
	public function test_get_url_returns_query_endpoint_for_plain_permalinks(): void {
		$this->configure_permalink_structure( '' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame( Markdown_Endpoint::get_query_url( $post_id ), Markdown_Endpoint::get_url( $post_id ) );
		$this->assertSame( '', Markdown_Endpoint::get_path_url( $post_id ) );
	}

	/**
	 * The query endpoint represents published posts with plain permalinks.
	 */
	public function test_get_query_url_supports_plain_permalinks(): void {
		$this->configure_permalink_structure( '' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame(
			add_query_arg(
				array(
					'p'        => $post_id,
					'markdown' => 'true',
				),
				home_url( '/' )
			),
			Markdown_Endpoint::get_query_url( $post_id )
		);
	}

	/**
	 * Discovery and the index use the query endpoint with plain permalinks.
	 */
	public function test_plain_permalink_query_endpoint_is_discoverable(): void {
		$this->configure_permalink_structure( '' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$post    = get_post( $post_id );
		$url     = Markdown_Endpoint::get_query_url( $post );
		$this->go_to( get_permalink( $post ) );

		$discovery = new Discovery( new Loader() );
		ob_start();
		$discovery->add_markdown_alternate_link();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'type="text/markdown"', $output );
		$this->assertStringContainsString( esc_url( $url ), $output );
		$this->assertSame( $url, LLMs_Txt::get_post_markdown_url( $post ) );
	}

	/**
	 * Cache invalidation includes the public query endpoint.
	 */
	public function test_cache_invalidation_includes_query_endpoint_values(): void {
		$this->configure_permalink_structure( '' );
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$permalink   = get_permalink( $post_id );
		$invalidator = new Markdown_Cache_Invalidator( new Loader() );
		$urls        = $invalidator->add_vip_purge_urls( array(), $post_id );

		$this->assertSame(
			array(
				$permalink,
				add_query_arg( 'markdown', 'true', $permalink ),
			),
			$urls
		);
	}

	/**
	 * A static front page also remains unsupported under plain permalinks.
	 */
	public function test_get_url_returns_empty_string_for_plain_permalink_static_front_page(): void {
		$this->configure_permalink_structure( '' );
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );

		$this->assertSame( '', Markdown_Endpoint::get_url( $page_id ) );
	}

	/**
	 * A static front page does not receive a root Markdown endpoint.
	 */
	public function test_get_url_returns_empty_string_for_static_front_page(): void {
		$this->configure_permalink_structure( '/%postname%/' );
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );

		$this->assertSame( '', Markdown_Endpoint::get_url( $page_id ) );
		$this->assertSame( '', Markdown_Endpoint::get_query_url( $page_id ) );
	}

	/**
	 * Update both the option and rewrite object used by get_permalink().
	 *
	 * @param string $structure Permalink structure.
	 */
	private function configure_permalink_structure( string $structure ): void {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( $structure );
		update_option( 'permalink_structure', $structure );
	}
}

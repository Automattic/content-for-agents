<?php

/**
 * Markdown endpoint integration tests.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents\Tests\Integration;

use Content_For_Agents\Markdown_Endpoint;

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
			'static front page endpoint'   => array( '/markdown', '' ),
		);
	}

	/**
	 * Preserve the test suite's permalink structure.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->permalink_structure = (string) get_option( 'permalink_structure' );
		$this->show_on_front       = (string) get_option( 'show_on_front' );
		$this->page_on_front       = (int) get_option( 'page_on_front' );
	}

	/**
	 * Restore the test suite's permalink structure.
	 */
	public function tear_down(): void {
		$this->configure_permalink_structure( $this->permalink_structure );
		update_option( 'show_on_front', $this->show_on_front );
		update_option( 'page_on_front', $this->page_on_front );
		parent::tear_down();
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
	 * Plain permalinks safely omit the unsupported endpoint.
	 */
	public function test_get_url_returns_empty_string_for_plain_permalinks(): void {
		$this->configure_permalink_structure( '' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame( '', Markdown_Endpoint::get_url( $post_id ) );
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
	 * A static front page receives the root Markdown endpoint.
	 */
	public function test_get_url_returns_root_markdown_path_for_static_front_page(): void {
		$this->configure_permalink_structure( '/%postname%/' );
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );

		$this->assertSame( home_url( '/markdown' ), Markdown_Endpoint::get_url( $page_id ) );
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

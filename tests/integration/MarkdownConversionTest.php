<?php

/**
 * Markdown conversion integration tests.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents\Tests\Integration;

use Content_For_Agents\Block_Markdown_Registry;
use Content_For_Agents\Markdown_Converter;

/**
 * Exercises Markdown conversion in WordPress.
 */
class MarkdownConversionTest extends \WP_UnitTestCase {

	/**
	 * The registration action remains available to integration plugins.
	 */
	public function test_block_registry_callback_is_registered_during_init(): void {
		$this->assertTrue( Block_Markdown_Registry::has( 'content-for-agents/test-block' ) );
	}

	/**
	 * Registered block callbacks participate in post conversion.
	 */
	public function test_registered_block_callback_converts_post_content(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:content-for-agents/test-block {"text":"Hello agents"} /-->',
				'post_status'  => 'draft',
			)
		);

		$markdown = ( new Markdown_Converter() )->post_to_markdown( $post_id );

		$this->assertSame( '**Hello agents**', $markdown );
	}

	/**
	 * Decorative aria-hidden text is omitted from nested block conversion.
	 */
	public function test_aria_hidden_content_is_omitted_from_nested_block_conversion(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => <<<'HTML'
<!-- wp:group -->
<div class="wp-block-group"><!-- wp:accordion-heading -->
<h3 class="wp-block-accordion-heading has-icon has-icon-right"><button type="button" class="wp-block-accordion-heading__toggle"><span class="wp-block-accordion-heading__toggle-title">Supplies</span><span class="wp-block-accordion-heading__toggle-icon" aria-hidden="true">+</span></button></h3>
<!-- /wp:accordion-heading -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><span aria-hidden="false">Supplies</span>+<span aria-hidden="true"><strong>Decorative</strong></span></h3>
<!-- /wp:heading --></div>
<!-- /wp:group -->
HTML
				,
				'post_status'  => 'draft',
			)
		);

		$markdown = ( new Markdown_Converter() )->post_to_markdown( $post_id );

		$this->assertSame( "### Supplies\n\n### Supplies+", $markdown );
	}

	/**
	 * Classic Editor HTML uses the same converter and hidden-content handling.
	 */
	public function test_classic_editor_html_converts_and_omits_aria_hidden_content(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<h2>Classic heading</h2><p>Classic content<span aria-hidden="true">+</span>.</p><p>Visible + remains.</p>',
				'post_status'  => 'draft',
			)
		);

		$markdown = ( new Markdown_Converter() )->post_to_markdown( $post_id );

		$this->assertSame( "## Classic heading\n\nClassic content.\n\nVisible + remains.", $markdown );
	}
}

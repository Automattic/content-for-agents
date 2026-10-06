<?php

/**
 * Structural targets for the planned HTML renderer rewrite.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents\Tests\Renderer_Targets;

use Content_For_Agents\HTML_To_Markdown_Converter;

/**
 * These tests are intentionally outside the passing integration suite until
 * the renderer can preserve blocks nested in list items.
 */
class RendererStructureTest extends \WP_UnitTestCase {
	/** A quote, code fence, and following prose remain in their list item. */
	public function test_list_item_owns_nested_blocks_and_following_prose(): void {
		$html     = file_get_contents( __DIR__ . '/../fixtures/renderer-list-blocks.html' );
		$expected = file_get_contents( __DIR__ . '/../fixtures/renderer-list-blocks.md' );
		$this->assertIsString( $html );
		$this->assertIsString( $expected );
		$this->assertSame( rtrim( $expected ), ( new HTML_To_Markdown_Converter() )->convert( $html ) );
	}
}

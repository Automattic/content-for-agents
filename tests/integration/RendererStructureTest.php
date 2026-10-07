<?php

/**
 * Structural regression coverage for HTML rendering.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents\Tests\Integration;

use Content_For_Agents\HTML_To_Markdown_Converter;

/**
 * Verifies that nested Markdown blocks stay inside their HTML list item.
 */
class RendererStructureTest extends \WP_UnitTestCase {
	/** Empty containers do not turn a tight list into a loose list. */
	public function test_empty_nested_blocks_do_not_loosen_list(): void {
		$converter = new HTML_To_Markdown_Converter();
		foreach ( array( '<p></p>', '<div></div>', '<blockquote></blockquote>', '<ul></ul>' ) as $empty ) {
			$html = '<ul><li>A' . $empty . '</li><li>B</li></ul>';
			$this->assertSame( "- A\n- B", $converter->convert( $html ), $empty );
		}
	}

	/** A quote, code fence, and following prose remain in their list item. */
	public function test_list_item_owns_nested_blocks_and_following_prose(): void {
		$html     = file_get_contents( __DIR__ . '/../fixtures/renderer-list-blocks.html' );
		$expected = file_get_contents( __DIR__ . '/../fixtures/renderer-list-blocks.md' );
		$this->assertIsString( $html );
		$this->assertIsString( $expected );
		$this->assertSame( rtrim( $expected ), ( new HTML_To_Markdown_Converter() )->convert( $html ) );
	}
}

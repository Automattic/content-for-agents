<?php

/**
 * Markdown conversion integration tests.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents\Tests\Integration;

use Content_For_Agents\Block_Markdown_Registry;
use Content_For_Agents\HTML_To_Markdown_Converter;
use Content_For_Agents\Markdown_Converter;

/**
 * Exercises Markdown conversion in WordPress.
 */
class MarkdownConversionTest extends \WP_UnitTestCase {
	/**
	 * Number of metadata callback executions in the current test.
	 *
	 * @var int
	 */
	private static int $metadata_callback_executions = 0;

	/**
	 * Test callback referenced by contentForAgents metadata.
	 *
	 * @return string Callback Markdown.
	 */
	public static function metadata_markdown_callback(): string {
		++self::$metadata_callback_executions;
		return 'Metadata parent callback';
	}

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
	 * Parent metadata callbacks take precedence over parent registry callbacks.
	 */
	public function test_parent_metadata_callback_precedes_parent_registry_callback(): void {
		$registry_executions                = 0;
		self::$metadata_callback_executions = 0;
		register_block_type(
			'content-for-agents/metadata-parent',
			array(
				'supports' => array(
					'contentForAgents' => array(
						'callback' => self::class . '::metadata_markdown_callback',
					),
				),
			)
		);
		Block_Markdown_Registry::register(
			'content-for-agents/metadata-parent',
			static function () use ( &$registry_executions ): string {
				++$registry_executions;
				return 'Registry parent callback';
			}
		);

		try {
			$markdown = $this->convert_post_content( '<!-- wp:content-for-agents/metadata-parent /-->' );
		} finally {
			unregister_block_type( 'content-for-agents/metadata-parent' );
		}

		$this->assertSame( 'Metadata parent callback', $markdown );
		$this->assertSame( 1, self::$metadata_callback_executions );
		$this->assertSame( 0, $registry_executions );
	}

	/**
	 * A parent registry callback owns the block and suppresses descendants.
	 */
	public function test_parent_registry_callback_precedes_descendant_callbacks(): void {
		$parent_executions = 0;
		$child_executions  = 0;
		Block_Markdown_Registry::register(
			'content-for-agents/registry-parent',
			static function () use ( &$parent_executions ): string {
				++$parent_executions;
				return 'Parent registry callback';
			}
		);
		Block_Markdown_Registry::register(
			'content-for-agents/registry-child',
			static function () use ( &$child_executions ): string {
				++$child_executions;
				return 'Child registry callback';
			}
		);

		$content = <<<'HTML'
<!-- wp:content-for-agents/registry-parent -->
<div>Parent HTML.<!-- wp:content-for-agents/registry-child /--></div>
<!-- /wp:content-for-agents/registry-parent -->
HTML;

		$this->assertSame( 'Parent registry callback', $this->convert_post_content( $content ) );
		$this->assertSame( 1, $parent_executions );
		$this->assertSame( 0, $child_executions );
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

	/**
	 * Markdown conversion uses stored block content, not the HTML presentation filter.
	 */
	public function test_post_conversion_does_not_apply_the_content_filter(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>Stored content.</p><!-- /wp:paragraph -->',
				'post_status'  => 'draft',
			)
		);
		$calls   = 0;
		$filter  = static function () use ( &$calls ): string {
			++$calls;
			return 'Filtered presentation content.';
		};

		add_filter( 'the_content', $filter );
		try {
			$markdown = ( new Markdown_Converter() )->post_to_markdown( $post_id );
		} finally {
			remove_filter( 'the_content', $filter );
		}

		$this->assertSame( 'Stored content.', $markdown );
		$this->assertSame( 0, $calls );
	}

	/**
	 * Details retain wrapper-owned summary text around nested blocks.
	 */
	public function test_details_retains_owned_summary(): void {
		$content = <<<'HTML'
<!-- wp:details -->
<details class="wp-block-details"><summary>Details summary: </summary><!-- wp:paragraph --><p>Nested details paragraph.</p><!-- /wp:paragraph --></details>
<!-- /wp:details -->
HTML;

		$this->assertSame( 'Details summary: Nested details paragraph.', $this->convert_post_content( $content ) );
	}

	/**
	 * Media Text retains its owned image and nested block content.
	 */
	public function test_media_text_retains_owned_image(): void {
		$content = <<<'HTML'
<!-- wp:media-text {"mediaType":"image","mediaUrl":"https://example.com/media.jpg"} -->
<div class="wp-block-media-text is-stacked-on-mobile"><figure class="wp-block-media-text__media"><img src="https://example.com/media.jpg" alt="Media image"></figure><div class="wp-block-media-text__content"><!-- wp:paragraph --><p>Nested media paragraph.</p><!-- /wp:paragraph --></div></div>
<!-- /wp:media-text -->
HTML;

		$this->assertSame(
			"![Media image](https://example.com/media.jpg)\n\nNested media paragraph.",
			$this->convert_post_content( $content )
		);
	}

	/**
	 * Wrapper-owned fragments remain ordered around a registered callback.
	 */
	public function test_wrapper_owned_content_surrounds_registered_callback(): void {
		$content = <<<'HTML'
<!-- wp:group -->
<div class="wp-block-group">Owned before.<!-- wp:content-for-agents/test-block {"text":"Callback middle"} /-->Owned after.</div>
<!-- /wp:group -->
HTML;

		$this->assertSame(
			"Owned before.\n\n**Callback middle**\n\nOwned after.",
			$this->convert_post_content( $content )
		);
	}

	/**
	 * Callback detection reaches through multiple wrapper levels.
	 */
	public function test_nested_callback_detection_preserves_all_wrapper_content(): void {
		$content = <<<'HTML'
<!-- wp:group -->
<div class="wp-block-group">Outer before.<!-- wp:group --><div class="wp-block-group">Inner before.<!-- wp:content-for-agents/test-block {"text":"Nested callback"} /-->Inner after.</div><!-- /wp:group -->Outer after.</div>
<!-- /wp:group -->
HTML;

		$this->assertSame(
			"Outer before.\n\nInner before.\n\n**Nested callback**\n\nInner after.\n\nOuter after.",
			$this->convert_post_content( $content )
		);
	}

	/**
	 * Strategy detection does not execute registered callbacks.
	 */
	public function test_registered_callback_executes_once_when_zipping(): void {
		$executions = 0;
		Block_Markdown_Registry::register(
			'content-for-agents/counting-block',
			static function () use ( &$executions ): string {
				++$executions;
				return 'Counted callback';
			}
		);

		$content = <<<'HTML'
<!-- wp:group -->
<div class="wp-block-group">Before count.<!-- wp:group --><div class="wp-block-group"><!-- wp:content-for-agents/counting-block /--></div><!-- /wp:group -->After count.</div>
<!-- /wp:group -->
HTML;

		$this->assertSame( "Before count.\n\nCounted callback\n\nAfter count.", $this->convert_post_content( $content ) );
		$this->assertSame( 1, $executions );
	}

	/**
	 * Quotes with custom descendants retain the dedicated citation behavior.
	 */
	public function test_quote_with_callback_uses_child_and_citation_path_once(): void {
		$executions = 0;
		Block_Markdown_Registry::register(
			'content-for-agents/quote-child',
			static function () use ( &$executions ): string {
				++$executions;
				return '**Quoted callback**';
			}
		);

		$content = <<<'HTML'
<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:content-for-agents/quote-child /--><cite>Quote citation</cite></blockquote>
<!-- /wp:quote -->
HTML;

		$this->assertSame( "> **Quoted callback**\n> \n> Quote citation", $this->convert_post_content( $content ) );
		$this->assertSame( 1, $executions );
	}

	/**
	 * Strip and children-only metadata force ordered zipper conversion.
	 */
	public function test_metadata_modes_force_zipping_and_preserve_owned_siblings(): void {
		register_block_type(
			'content-for-agents/strip-block',
			array(
				'supports' => array(
					'contentForAgents' => array( 'mode' => 'strip' ),
				),
			)
		);
		register_block_type(
			'content-for-agents/children-only-block',
			array(
				'supports' => array(
					'contentForAgents' => array( 'mode' => 'children-only' ),
				),
			)
		);

		$content = <<<'HTML'
<!-- wp:group -->
<div class="wp-block-group">Owned before.<!-- wp:content-for-agents/strip-block -->STRIPPED SENTINEL<!-- /wp:content-for-agents/strip-block --><!-- wp:content-for-agents/children-only-block --><section>CHILDREN WRAPPER SENTINEL<!-- wp:paragraph --><p>Kept child.</p><!-- /wp:paragraph --></section><!-- /wp:content-for-agents/children-only-block -->Owned after.</div>
<!-- /wp:group -->
HTML;

		try {
			$markdown = $this->convert_post_content( $content );
		} finally {
			unregister_block_type( 'content-for-agents/strip-block' );
			unregister_block_type( 'content-for-agents/children-only-block' );
		}

		$this->assertSame( "Owned before.\n\nKept child.\n\nOwned after.", $markdown );
		$this->assertStringNotContainsString( 'STRIPPED SENTINEL', $markdown );
		$this->assertStringNotContainsString( 'CHILDREN WRAPPER SENTINEL', $markdown );
	}

	/**
	 * Inline Markdown stays within table cells and preserves surrounding spaces.
	 */
	public function test_table_cells_support_inline_markdown_and_spacing(): void {
		$html = <<<'HTML'
<table>
	<thead><tr><th>Format</th><th>Example</th></tr></thead>
	<tbody>
		<tr><td>Code</td><td>Before <code>value</code> after</td></tr>
		<tr><td>Strong</td><td>Before <strong>bold</strong> after</td></tr>
		<tr><td>Emphasis</td><td>Before <em>italic</em> after</td></tr>
		<tr><td>Link</td><td>Before <a href="https://example.com/">example</a> after</td></tr>
		<tr><td>Image</td><td>Before <img src="https://example.com/image.jpg" alt="Example"> after</td></tr>
	</tbody>
</table>
HTML;

		$markdown = ( new HTML_To_Markdown_Converter() )->convert( $html );

		$this->assertSame(
			<<<'MARKDOWN'
| Format | Example |
| --- | --- |
| Code | Before `value` after |
| Strong | Before **bold** after |
| Emphasis | Before *italic* after |
| Link | Before [example](https://example.com/) after |
| Image | Before ![Example](https://example.com/image.jpg) after |
MARKDOWN
			,
			$markdown
		);
	}

	/**
	 * Cell-internal line and block boundaries do not split Markdown table rows.
	 */
	public function test_table_cells_support_line_and_block_boundaries(): void {
		$html = <<<'HTML'
<table>
	<thead><tr><th>Format</th><th>Example</th></tr></thead>
	<tbody>
		<tr><td>Break</td><td>First line<br>Second line</td></tr>
		<tr><td>Paragraphs</td><td><p>First paragraph</p><p>Second paragraph</p></td></tr>
		<tr><td>Divisions</td><td><div>First division</div><div>Second division</div></td></tr>
		<tr><td>After</td><td>Final row</td></tr>
	</tbody>
</table>
HTML;

		$markdown = ( new HTML_To_Markdown_Converter() )->convert( $html );

		$this->assertSame(
			<<<'MARKDOWN'
| Format | Example |
| --- | --- |
| Break | First line<br>Second line |
| Paragraphs | First paragraph<br>Second paragraph |
| Divisions | First division<br>Second division |
| After | Final row |
MARKDOWN
			,
			$markdown
		);
	}

	/**
	 * Nested tables flatten into the active cell and literal pipes are escaped.
	 */
	public function test_nested_tables_flatten_into_the_active_cell(): void {
		$html = <<<'HTML'
<table>
	<tr><th>Outer</th><th>Other</th></tr>
	<tr><td>Before<table><tr><td>Nested | one</td><td>Nested two</td></tr></table>after</td><td>End</td></tr>
</table>
HTML;

		$markdown = ( new HTML_To_Markdown_Converter() )->convert( $html );

		$this->assertSame(
			<<<'MARKDOWN'
| Outer | Other |
| --- | --- |
| Before<br>Nested \| one<br>Nested two<br>after | End |
MARKDOWN
			,
			$markdown
		);
	}

	/**
	 * Representative Gutenberg HTML matches the golden Markdown fixture.
	 */
	public function test_block_hammer_fixture_matches_golden_markdown(): void {
		$html     = file_get_contents( __DIR__ . '/../fixtures/block-hammer.html' );
		$expected = file_get_contents( __DIR__ . '/../fixtures/block-hammer.md' );

		$this->assertIsString( $html );
		$this->assertIsString( $expected );

		$post_id  = self::factory()->post->create(
			array(
				'post_content' => $html,
				'post_status'  => 'draft',
			)
		);
		$markdown = ( new Markdown_Converter() )->post_to_markdown( $post_id );

		$this->assertSame( rtrim( $expected ), $markdown );
		$this->assert_block_hammer_structure( $markdown );
	}

	/**
	 * Asserts structural invariants for the block-hammer fixture.
	 *
	 * @param string $markdown Converted fixture Markdown.
	 */
	private function assert_block_hammer_structure( string $markdown ): void {
		$sentinels = array(
			'SENTINEL-START',
			'SENTINEL-INLINE',
			'SENTINEL-QUOTE',
			'SENTINEL-CODE',
			'SENTINEL-GROUP',
			'SENTINEL-COLUMN-A',
			'SENTINEL-COLUMN-B',
			'SENTINEL-BUTTONS-CONTAINER',
			'SENTINEL-CTA-LINK',
			'SENTINEL-DETAILS',
			'SENTINEL-PREFORMATTED',
			'SENTINEL-VERSE',
			'SENTINEL-GALLERY',
			'SENTINEL-MEDIA-TEXT',
			'SENTINEL-TABLE',
			'SENTINEL-UNKNOWN',
			'SENTINEL-END',
		);
		$last_pos  = -1;
		foreach ( $sentinels as $sentinel ) {
			$this->assertSame( 1, substr_count( $markdown, $sentinel ), $sentinel . ' must occur exactly once.' );
			$position = strpos( $markdown, $sentinel );
			$this->assertIsInt( $position );
			$this->assertGreaterThan( $last_pos, $position, $sentinel . ' is out of order.' );
			$last_pos = $position;
		}

		$this->assertStringNotContainsString( 'SENTINEL-HIDDEN', $markdown );

		preg_match_all( '/^```$/m', $markdown, $standalone_fences );
		preg_match_all( '/```/', $markdown, $all_fences );
		$this->assertNotEmpty( $standalone_fences[0] );
		$this->assertSame( 0, count( $standalone_fences[0] ) % 2 );
		$this->assertCount( count( $all_fences[0] ), $standalone_fences[0], 'Every code fence must be standalone.' );

		$lines       = explode( "\n", $markdown );
		$table_lines = array_values(
			array_filter(
				$lines,
				static function ( string $line ): bool {
					return str_starts_with( $line, '|' );
				}
			)
		);
		$this->assertNotEmpty( $table_lines );
		$column_delimiters = null;
		foreach ( $table_lines as $table_line ) {
			$this->assertStringNotContainsString( "\n", $table_line );
			preg_match_all( '/(?<!\\\\)\|/', $table_line, $delimiters );
			$column_delimiters ??= count( $delimiters[0] );
			$this->assertSame( $column_delimiters, count( $delimiters[0] ), 'Table rows must have consistent columns.' );
		}

		preg_match_all( '/<[^>]+>/', $markdown, $raw_tags );
		$this->assertSame( array( '<br>' ), array_values( array_unique( $raw_tags[0] ) ) );
		$this->assertDoesNotMatchRegularExpression( '/[ \t]+$/m', $markdown );
		$this->assertDoesNotMatchRegularExpression( '/\n{3,}/', $markdown );
	}

	/**
	 * Converts serialized post content through the full block pipeline.
	 *
	 * @param string $content Serialized post content.
	 * @return string Converted Markdown.
	 */
	private function convert_post_content( string $content ): string {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => $content,
				'post_status'  => 'draft',
			)
		);

		return ( new Markdown_Converter() )->post_to_markdown( $post_id );
	}
}

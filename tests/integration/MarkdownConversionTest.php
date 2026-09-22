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
				return "**Quoted callback**\n  \nSecond quoted line";
			}
		);

		$content = <<<'HTML'
<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:content-for-agents/quote-child /--><cite>Quote citation</cite></blockquote>
<!-- /wp:quote -->
HTML;

		$this->assertSame( "> **Quoted callback**\n>\n> Second quoted line\n>\n> Quote citation", $this->convert_post_content( $content ) );
		$this->assertSame( 1, $executions );
	}

	/**
	 * A custom quote child does not flatten links or emphasis in its citation.
	 */
	public function test_quote_with_callback_preserves_formatted_citation(): void {
		$content = <<<'HTML'
<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:content-for-agents/test-block {"text":"Quoted child"} /--><cite title="a > b"><em>Editorial</em> <a href="https://example.com/source">source</a></cite></blockquote>
<!-- /wp:quote -->
HTML;
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->assertSame(
			"> **Quoted child**\n>\n> *Editorial* [source](https://example.com/source)",
			( new Markdown_Converter() )->blocks_to_markdown( parse_blocks( $content ), get_post( $post_id ) )
		);

		$content = str_replace( ' title="a > b"', '', $content );
		$this->assertSame(
			"> **Quoted child**\n>\n> *Editorial* [source](https://example.com/source)",
			$this->convert_post_content( $content )
		);
	}

	/**
	 * Quote-owned paragraphs retain their order around a custom child and cite.
	 */
	public function test_quote_with_callback_preserves_owned_text_around_citation(): void {
		$content = <<<'HTML'
<!-- wp:quote -->
<blockquote class="wp-block-quote"><p>Owned <strong>intro</strong>.</p><!-- wp:content-for-agents/test-block {"text":"Quoted child"} /--><p>Owned ending.</p><cite><em>Editorial</em> <a href="https://example.com/source">source</a></cite></blockquote>
<!-- /wp:quote -->
HTML;

		$this->assertSame(
			"> Owned **intro**.\n>\n> **Quoted child**\n>\n> Owned ending.\n>\n> *Editorial* [source](https://example.com/source)",
			$this->convert_post_content( $content )
		);
	}

	/**
	 * Callback-bearing quotes do not expose script or style bodies.
	 */
	public function test_quote_with_callback_omits_owned_script_and_style_content(): void {
		$content = <<<'HTML'
<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:content-for-agents/test-block {"text":"Quoted child"} /--><!-- <cite>HIDDEN COMMENT</cite> --><script>HIDDEN SCRIPT</script><style>HIDDEN STYLE</style><cite>Safe source</cite></blockquote>
<!-- /wp:quote -->
HTML;
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->assertSame(
			"> **Quoted child**\n>\n> Safe source",
			( new Markdown_Converter() )->blocks_to_markdown( parse_blocks( $content ), get_post( $post_id ) )
		);
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
	 * Audio and video URLs survive both direct src and nested source markup.
	 */
	public function test_media_elements_preserve_the_first_source_url(): void {
		$html = <<<'HTML'
<figure><audio controls src="https://example.com/one.mp3"><source src="https://example.com/ignored.mp3"></audio><figcaption>Audio caption</figcaption></figure>
<figure><video controls><source src="https://example.com/one.mp4"><source src="https://example.com/ignored.mp4"></video><figcaption>Video caption</figcaption></figure>
HTML;

		$this->assertSame(
			"[Audio](https://example.com/one.mp3)\n*Audio caption*\n\n[Video](https://example.com/one.mp4)\n*Video caption*",
			( new HTML_To_Markdown_Converter() )->convert( $html )
		);
	}

	/**
	 * Link destinations remain valid when source URLs contain parentheses.
	 */
	public function test_link_destinations_escape_parentheses(): void {
		$html = '<p><a href="https://example.com/a(b)">Link</a> <img src="https://example.com/a(b).png" alt="Image"></p><audio src="https://example.com/a(b).mp3"></audio><video><source src="https://example.com/a(b).mp4"></video>';

		$this->assertSame(
			"[Link](https://example.com/a\\(b\\)) ![Image](https://example.com/a\\(b\\).png)\n\n[Audio](https://example.com/a\\(b\\).mp3)[Video](https://example.com/a\\(b\\).mp4)",
			( new HTML_To_Markdown_Converter() )->convert( $html )
		);
		$this->assertSame(
			'[Backslash](https://example.com/a\\\\b) [Space](https://example.com/a%20b) [Angle](https://example.com/a%3Cb%3E)',
			( new HTML_To_Markdown_Converter() )->convert( '<a href="https://example.com/a\\b">Backslash</a> <a href="https://example.com/a b">Space</a> <a href="https://example.com/a&lt;b&gt;">Angle</a>' )
		);
	}

	/**
	 * Adjacent links stay separate through comments and non-rendering wrappers.
	 */
	public function test_adjacent_links_have_a_separator(): void {
		$html = '<a href="https://example.com/a">First</a><!-- marker --><span><a href="https://example.com/b">Second</a></span><a href="https://example.com/c">Third</a>';
		$this->assertSame(
			'[First](https://example.com/a) [Second](https://example.com/b) [Third](https://example.com/c)',
			( new HTML_To_Markdown_Converter() )->convert( $html )
		);
	}

	/**
	 * Nested list items retain the indentation that makes them structural.
	 */
	public function test_nested_list_items_keep_their_indentation(): void {
		$html = '<ul><li>Parent<ul><li>Child</li></ul></li></ul>';
		$this->assertSame( "- Parent\n\n  - Child", ( new HTML_To_Markdown_Converter() )->convert( $html ) );
	}

	/**
	 * Preformatted code retains indentation while incidental HTML spacing does not.
	 */
	public function test_preformatted_code_keeps_indentation(): void {
		$html = "<pre>first\n  indented\n</pre><p>  After.</p>";
		$this->assertSame( "```\nfirst\n  indented\n```\n\nAfter.", ( new HTML_To_Markdown_Converter() )->convert( $html ) );
		$this->assertSame(
			"```\nfirst  \n\n\n  indented  \n```",
			( new HTML_To_Markdown_Converter() )->convert( "<pre>first  \n\n\n  indented  \n</pre>" )
		);
	}

	/**
	 * Code delimiters exceed the longest backtick sequence in their content.
	 */
	public function test_code_delimiters_do_not_collide_with_content(): void {
		$converter = new HTML_To_Markdown_Converter();
		$this->assertSame( "````\nconst fence = \"```\";\n````", $converter->convert( '<pre>const fence = "```";</pre>' ) );
		$this->assertSame( '``a`b``', $converter->convert( '<p><code>a`b</code></p>' ) );
		$this->assertSame( '`` `a ``', $converter->convert( '<code>`a</code>' ) );
		$this->assertSame( '`` a` ``', $converter->convert( '<code>a`</code>' ) );
		$this->assertSame( '```` ``` ````', $converter->convert( '<code>```</code>' ) );
		$this->assertSame( "````\n```\n````", $converter->convert( '<pre>&#96;&#96;&#96;</pre>' ) );
		$this->assertSame( '`` ` ``', $converter->convert( '<code>&#96;</code>' ) );
		$this->assertSame( '`foo bar`', $converter->convert( '<code>foo<br>bar</code>' ) );
		$this->assertSame( '`  x  `', $converter->convert( '<code> x </code>' ) );
		$this->assertSame( '` `', $converter->convert( '<code> </code>' ) );
		$this->assertSame( "```\nabc\n```", $converter->convert( '<pre>abc' ) );
		$this->assertSame( '`abc`', $converter->convert( '<code>abc' ) );
	}

	/**
	 * Ordinary text still trims trailing spaces and excess line breaks.
	 */
	public function test_non_code_whitespace_is_normalized(): void {
		$html = '<p>Hello </p><p>World</p><p>A<br><br><br>B</p>';
		$this->assertSame( "Hello\n\nWorld\n\nA\n\nB", ( new HTML_To_Markdown_Converter() )->convert( $html ) );
	}

	/**
	 * Ordinary ordered siblings keep their position around an authoritative callback.
	 */
	public function test_ordered_list_keeps_fallback_markers_around_callback(): void {
		Block_Markdown_Registry::register(
			'content-for-agents/ordered-list-item',
			static function (): string {
				return '6. **Callback item**';
			}
		);
		$content = <<<'HTML'
<!-- wp:list {"ordered":true,"start":5} -->
<ol class="wp-block-list" start="5"><!-- wp:list-item -->
<li>First item</li>
<!-- /wp:list-item --><!-- wp:content-for-agents/ordered-list-item /--><!-- wp:list-item -->
<li>Third item</li>
<!-- /wp:list-item --></ol>
<!-- /wp:list -->
HTML;

		$this->assertSame( "5. First item\n6. **Callback item**\n7. Third item", $this->convert_post_content( $content ) );
	}

	/**
	 * Nested callbacks do not remove their list item's marker; suppressed items
	 * do not consume the next ordered marker.
	 */
	public function test_ordered_list_keeps_markers_with_nested_and_suppressed_callbacks(): void {
		Block_Markdown_Registry::register(
			'content-for-agents/nested-list-content',
			static function (): string {
				return '**Nested callback**';
			}
		);
		Block_Markdown_Registry::register(
			'content-for-agents/suppressed-list-item',
			static function (): string {
				return '';
			}
		);
		$content = <<<'HTML'
<!-- wp:list {"ordered":true,"start":3} -->
<ol class="wp-block-list" start="3"><!-- wp:list-item -->
<li>First item <!-- wp:content-for-agents/nested-list-content /--> ending.</li>
<!-- /wp:list-item --><!-- wp:content-for-agents/suppressed-list-item /--><!-- wp:list-item -->
<li>Second item</li>
<!-- /wp:list-item --></ol>
<!-- /wp:list -->
HTML;

		$this->assertSame( "3. First item\n\n   **Nested callback**\n\n   ending.\n4. Second item", $this->convert_post_content( $content ) );
	}

	/**
	 * Html-fallback metadata does not turn a native list item into a callback.
	 */
	public function test_ordered_list_html_fallback_metadata_keeps_marker(): void {
		$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( 'core/list-item' );
		$this->assertNotNull( $block_type );
		$supports = $block_type->supports;

		$block_type->supports['contentForAgents'] = array( 'mode' => 'html-fallback' );

		try {
			Block_Markdown_Registry::register(
				'content-for-agents/list-metadata-trigger',
				static function (): string {
					return '6. Callback item';
				}
			);
			$content = <<<'HTML'
<!-- wp:list {"ordered":true,"start":5} -->
<ol class="wp-block-list" start="5"><!-- wp:list-item -->
<li>Native item</li>
<!-- /wp:list-item --><!-- wp:content-for-agents/list-metadata-trigger /--></ol>
<!-- /wp:list -->
HTML;
			$this->assertSame( "5. Native item\n6. Callback item", $this->convert_post_content( $content ) );
		} finally {
			$block_type->supports = $supports;
		}
	}

	/**
	 * The HTML fallback omits script and style elements with their bodies.
	 */
	public function test_html_converter_omits_script_and_style_bodies(): void {
		$html = '<p>Before.</p><script>HIDDEN SCRIPT</script><style>HIDDEN STYLE</style><p>After.</p>';

		$this->assertSame( "Before.\n\nAfter.", ( new HTML_To_Markdown_Converter() )->convert( $html ) );
	}

	/**
	 * Context-dependent core blocks resolve against the post being converted,
	 * without executing shortcodes as the_content would.
	 */
	public function test_dynamic_post_blocks_convert_without_executing_shortcodes(): void {
		$executions = 0;
		add_shortcode(
			'block_gauntlet_shortcode',
			static function () use ( &$executions ): string {
				++$executions;
				return '<strong>SENTINEL-SHORTCODE</strong> output.';
			}
		);

		try {
			$post_id  = self::factory()->post->create(
				array(
					'post_title'   => 'SENTINEL-POST-TITLE',
					'post_excerpt' => 'SENTINEL-POST-EXCERPT text.',
					'post_content' => '<!-- wp:post-title /--><!-- wp:post-excerpt /--><!-- wp:shortcode -->[block_gauntlet_shortcode]<!-- /wp:shortcode -->',
					'post_status'  => 'draft',
				)
			);
			$markdown = ( new Markdown_Converter() )->post_to_markdown( $post_id );
		} finally {
			remove_shortcode( 'block_gauntlet_shortcode' );
		}

		$this->assertSame( "## SENTINEL-POST-TITLE\n\nSENTINEL-POST-EXCERPT text.\n\n[block_gauntlet_shortcode]", $markdown );
		$this->assertSame( 0, $executions );
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
	public function test_block_gauntlet_fixture_matches_golden_markdown(): void {
		Block_Markdown_Registry::register(
			'content-for-agents/gauntlet-list-item',
			static function ( array $block ): string {
				return '- **' . ( $block['attrs']['text'] ?? '' ) . '**';
			}
		);
		$html     = file_get_contents( __DIR__ . '/../fixtures/block-gauntlet.html' );
		$expected = file_get_contents( __DIR__ . '/../fixtures/block-gauntlet.md' );

		$this->assertIsString( $html );
		$this->assertIsString( $expected );
		$this->assert_block_gauntlet_types( $html );

		$post_id  = self::factory()->post->create(
			array(
				'post_content' => $html,
				'post_status'  => 'draft',
			)
		);
		$markdown = ( new Markdown_Converter() )->post_to_markdown( $post_id );

		$this->assertSame( rtrim( $expected ), $markdown );
		$this->assert_block_gauntlet_structure( $markdown );
	}

	/**
	 * Keep the fixture's audited authored core-block set explicit. This is not
	 * every registered WordPress 7.0 block: query-generated and fallback blocks
	 * need contextual fixtures rather than representative static markup.
	 *
	 * @param string $html Serialized fixture blocks.
	 */
	private function assert_block_gauntlet_types( string $html ): void {
		preg_match_all( '/<!-- wp:([a-z-]+)(?=\s|-->)/', $html, $matches );
		$actual = array_values( array_unique( $matches[1] ) );
		sort( $actual );

		$this->assertSame(
			array(
				'accordion',
				'accordion-heading',
				'accordion-item',
				'accordion-panel',
				'audio',
				'button',
				'buttons',
				'code',
				'column',
				'columns',
				'cover',
				'details',
				'embed',
				'file',
				'freeform',
				'gallery',
				'group',
				'heading',
				'html',
				'image',
				'list',
				'list-item',
				'math',
				'media-text',
				'more',
				'nextpage',
				'paragraph',
				'preformatted',
				'pullquote',
				'quote',
				'separator',
				'social-link',
				'social-links',
				'spacer',
				'table',
				'text-columns',
				'verse',
				'video',
			),
			$actual
		);
	}

	/**
	 * Asserts structural invariants for the block-gauntlet fixture.
	 *
	 * @param string $markdown Converted fixture Markdown.
	 */
	private function assert_block_gauntlet_structure( string $markdown ): void {
		$sentinels = array(
			'SENTINEL-START',
			'SENTINEL-INLINE',
			'SENTINEL-QUOTE',
			'SENTINEL-PULLQUOTE',
			'SENTINEL-CODE',
			'SENTINEL-GROUP',
			'SENTINEL-COLUMN-A',
			'SENTINEL-COLUMN-B',
			'SENTINEL-BUTTONS-CONTAINER',
			'SENTINEL-CTA-LINK',
			'SENTINEL-DETAILS',
			'SENTINEL-ACCORDION-QUESTION',
			'SENTINEL-PANEL-ANSWER',
			'SENTINEL-MATH',
			'SENTINEL-PREFORMATTED',
			'SENTINEL-VERSE',
			'SENTINEL-GALLERY',
			'SENTINEL-MEDIA-TEXT',
			'SENTINEL-COVER',
			'SENTINEL-FILE',
			'SENTINEL-AUDIO',
			'SENTINEL-VIDEO',
			'SENTINEL-EMBED',
			'SENTINEL-LIST-ITEM',
			'SENTINEL-LIST-CALLBACK',
			'SENTINEL-TEXT-COLUMNS',
			'SENTINEL-FREEFORM',
			'SENTINEL-SOCIAL',
			'SENTINEL-CUSTOM-QUOTE',
			'SENTINEL-CUSTOM-SUMMARY',
			'SENTINEL-CUSTOM-CHILD-DETAILS',
			'SENTINEL-MEDIA-CUSTOM-IMAGE',
			'SENTINEL-MEDIA-CUSTOM-CHILD',
			'SENTINEL-CUSTOM-COVER',
			'SENTINEL-CUSTOM-GROUP-BEFORE',
			'SENTINEL-CUSTOM-GROUP-CHILD',
			'SENTINEL-CUSTOM-GROUP-AFTER',
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

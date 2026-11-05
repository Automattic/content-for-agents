<?php

/**
 * Focused cases for the production inline buffer.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents\Tests\Integration;

use Content_For_Agents\HTML_To_Markdown_Converter;
use Content_For_Agents\Markdown_Inline_Buffer;
use WP_HTML_Processor;

/** Verify inline boundaries independently of block rendering. */
class InlineBufferTest extends \WP_UnitTestCase {
	/**
	 * Check inline formatting both directly and through the full converter.
	 *
	 * @dataProvider inline_cases
	 * @param string $html Authored HTML.
	 * @param string $expected Expected Markdown.
	 */
	public function test_inline_output( string $html, string $expected ): void {
		$this->assertSame( $expected, $this->convert_inline( $html ), $html );
		$this->assertSame( $expected, ( new HTML_To_Markdown_Converter() )->convert( $html ), $html );
	}

	/** Whitespace, nesting, punctuation, links, images, and empty inline elements. */
	public function inline_cases(): array {
		return array(
			array( '<strong>A</strong><strong>B</strong>', '**AB**' ),
			array( '<strong>A </strong><strong>B</strong>', '**A** **B**' ),
			array( '<p>Before<strong><a href="/x">link</a></strong>after</p>', 'Before[**link**](/x)after' ),
			array( '<p><strong>Label: </strong>Text</p>', '**Label:** Text' ),
			array( '<p>Before<strong> label</strong> after</p>', 'Before **label** after' ),
			array( '<p><b>“</b>This post follows.</p>', '<strong>“</strong>This post follows.' ),
			array( '<p><strong>First<br>Second</strong></p>', "**First\\\nSecond**" ),
			array( '<p>Use <code>a`b</code>.</p>', 'Use ``a`b``.' ),
			array( '<a href="/x"><em>A</em><em>B</em></a>', '[*AB*](/x)' ),
			array( '<em>A</em><a href="/x"></a><em>B</em>', '*AB*' ),
			array( '<p>Before<em> label </em>after</p>', 'Before *label* after' ),
			array( '<p>Meanwhile<strong>, Related Top-Performing Posts</strong> lists results.</p>', 'Meanwhile<strong>, Related Top-Performing Posts</strong> lists results.' ),
			array( '<p>embeddings<em>.</em></p>', 'embeddings<em>.</em>' ),
			array( '<p><em>Caption <span>test</span>(</em></p>', '*Caption test(*' ),
			array( '<p>Use <code><a href="https://example.com/option">option="true"</a></code> and <code>prefix<a href="https://example.com/value">value</a>suffix</code>.</p>', 'Use [`option="true"`](https://example.com/option) and `prefix`[`value`](https://example.com/value)`suffix`.' ),
			array( '<p><q>quoted</q> <sub>low</sub> <sup>high</sup></p>', '<q>quoted</q> <sub>low</sub> <sup>high</sup>' ),
			array( '<p><sub></sub><sup></sup>Text</p>', 'Text' ),
			array( '<p><a href="/x">Link</a> <img src="/x.png" alt="Image" title="Title"></p>', '[Link](/x) ![Image](/x.png "Title")' ),
			array( '<p><strong>Bold <em>and italic</em></strong>.</p>', '**Bold *and italic***.' ),
			array( '<p><strong><a>Plain label</a></strong></p>', '**Plain label**' ),
			array( '<strong>A <a href="/x">linked</a> B</strong>', '**A [linked](/x) B**' ),
			array( '<a href="/x"><strong>Bold</strong> text</a>', '[**Bold** text](/x)' ),
			array( '<strong><a href="/x">Link</a> tail</strong>', '**[Link](/x) tail**' ),
			array( '<strong>A</strong><em>B</em>', '**A***B*' ),
			array( '<em>A</em><strong>B</strong>', '*A***B**' ),
			array( '<strong>A</strong><strong><em>B</em></strong>', '**A*B***' ),
			array( '<a href="/x"><strong>A</strong><strong>B</strong></a>', '[**AB**](/x)' ),
			array( '<strong><a href="/x"><img src="/i.png" alt="I"></a></strong>', '[**![I](/i.png)**](/x)' ),
			array( '<strong>A<img src="/i.png" alt="I">B</strong>', '**A![I](/i.png)B**' ),
			array( '<a href="/x">Before <img src="/i.png" alt="I"> after</a>', '[Before ![I](/i.png) after](/x)' ),
			array( '<strong><q>"hi"</q> next</strong>', '**<q>"hi"</q> next**' ),
			array( '<em>First<br>Second</em>', "*First\\\nSecond*" ),
			array( '<a href="/x"><em>A</em> <em>B</em></a>', '[*A* *B*](/x)' ),
			array( '<p><strong><sup></sup>After</strong></p>', '**After**' ),
			array( '<p><a href="/x"><sup></sup>After</a></p>', '[After](/x)' ),
			array( '<code>x</code> tail', '`x` tail' ),
			array( '<q>x</q> tail', '<q>x</q> tail' ),
			array( '<a href="/x">1. item</a>', '[1\\. item](/x)' ),
			array( '<strong><code>x</code> tail</strong>', '**`x` tail**' ),
			array( '<code><a href="/x">x</a></code> tail', '[`x`](/x) tail' ),
			array( '<strong>A</strong>', '**A**' ),
			array( '<em>B</em>', '*B*' ),
			array( '<strong><a href="/x">link</a></strong>', '[**link**](/x)' ),
			array( '<strong>A<strong>B</strong>C</strong>', '**ABC**' ),
			array( '<a href="/x"><img src="/i.png" alt="I"></a>', '[![I](/i.png)](/x)' ),
			array( '<strong><img src="/i.png" alt="I"></strong>', '**![I](/i.png)**' ),
			array( '<code>A<img src="/i.png" alt="I">B</code>', '`AB`' ),
			array( '<p>1. item</p>', '1\\. item' ),
			array( '<p>- item</p>', '\\- item' ),
			array( '<strong><em><a href="/x">x</a></em></strong>', '[***x***](/x)' ),
			array( '<a href="/x"> <img src="/i.png" alt="I"> </a>', '[![I](/i.png) ](/x)' ),
			array( '<strong><q>"hi"</q></strong>', '**<q>"hi"</q>**' ),
			array( '<p><em>1. item</em></p>', '*1\\. item*' ),
		);
	}

	/** A long text node must not require one stored match per character. */
	public function test_direct_pass_large_text_node(): void {
		$text = str_repeat( 'a', 1000000 );
		$this->assertSame( '**' . $text . '**', $this->convert_inline( '<strong>' . $text . '</strong>' ) );
	}

	/** Open formatting is rendered within each completed block. */
	public function test_formatting_spanning_paragraphs(): void {
		$this->assertSame(
			"**A**\n\n**B**",
			( new HTML_To_Markdown_Converter() )->convert( '<strong><p>A</p><p>B</p></strong>' )
		);
	}

	/** A break at a format edge keeps the HTML wrapper valid. */
	public function test_line_break_before_formatted_content(): void {
		$converter = new HTML_To_Markdown_Converter();
		$this->assertSame( 'ab<em><br>cd</em>', $converter->convert( '<p>ab<em><br>cd</em></p>' ) );
		$this->assertSame( 'ab<strong><br>cd</strong>', $converter->convert( '<p>ab<strong><br>cd</strong></p>' ) );
		$this->assertSame( 'ab[<br>cd](/x)', $converter->convert( '<p>ab<a href="/x"><br>cd</a></p>' ) );
		$this->assertSame( "*First\\\n\\\nSecond*", $converter->convert( '<p><em>First<br><br>Second</em></p>' ) );
	}

	/** A link keeps its content and breaks in one Markdown span. */
	public function test_line_breaks_stay_inside_link(): void {
		$converter = new HTML_To_Markdown_Converter();
		$this->assertSame( "[A\\\n\\\nB](/x)", $converter->convert( '<p><a href="/x">A<br><br>B</a></p>' ) );
		$this->assertSame( "[**A\\\n\\\nB**](/x)", $converter->convert( '<p><a href="/x"><strong>A<br><br>B</strong></a></p>' ) );
		$this->assertSame( "**A[B\\\nC](/x)D**", $converter->convert( '<p><strong>A<a href="/x">B<br>C</a>D</strong></p>' ) );
		$this->assertSame( "**<q>A\\\n\\\nB</q>**", $converter->convert( '<p><strong><q>A<br><br>B</q></strong></p>' ) );
		$this->assertSame( "[<q>A\\\n\\\nB</q>](/x)", $converter->convert( '<p><a href="/x"><q>A<br><br>B</q></a></p>' ) );
	}

	/** Breaks at block edges and inside code need HTML syntax. */
	public function test_breaks_at_edges_and_inside_code(): void {
		$converter = new HTML_To_Markdown_Converter();
		$this->assertSame( "\\\nA", $converter->convert( '<p><br>A</p>' ) );
		$this->assertSame( 'A<br>', $converter->convert( '<p>A<br></p>' ) );
		$this->assertSame( '<br>', $converter->convert( '<p><br></p>' ) );
		$this->assertSame( '[A<br>](/x)', $converter->convert( '<p><a href="/x">A<br></a></p>' ) );
		$this->assertSame( '<code>&lt;x&gt;&amp;<br>y</code>', $converter->convert( '<p><code>&lt;x&gt;&amp;<br>y</code></p>' ) );
	}

	/** Source-token fallback recovers when inline tags close out of order. */
	public function test_misnested_format_and_link_keep_visible_content(): void {
		$converter = new HTML_To_Markdown_Converter();
		$bold      = $converter->convert( '<p><b>Bold<i>italic</b>after</i></p><p>Tail</p>' );
		$link      = $converter->convert( '<p><a href="/x">link<strong>bold</a>tail</strong></p><p>End</p>' );
		$this->assertSame( "<strong>Bold<em>italic</em></strong><em>after</em>\n\nTail", $bold );
		$this->assertSame( "[link<strong>bold</strong>](/x)<strong>tail</strong>\n\nEnd", $link );
	}

	/** Feed the production inline buffer from the same HTML API token stream. */
	private function convert_inline( string $html ): string {
		$processor = WP_HTML_Processor::create_fragment( $html );
		$buffer    = new Markdown_Inline_Buffer();
		while ( $processor->next_token() ) {
			$tag = $processor->get_token_name();
			if ( '#text' === $tag ) {
				$buffer->append_text( $processor->get_modifiable_text() );
			} elseif ( null !== $tag ) {
				$buffer->append_tag( $processor, $tag, $processor->is_tag_closer() );
			}
		}
		return trim( $buffer->drain( true ) );
	}
}

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
	/** Compare inline fragments through the buffer and full converter. */
	public function test_deferred_inline_output_matches_current_converter(): void {
		$cases     = array(
			'<strong>A</strong><strong>B</strong>',
			'<strong>A </strong><strong>B</strong>',
			'<p>Before<strong><a href="/x">link</a></strong>after</p>',
			'<p><strong>Label: </strong>Text</p>',
			'<p>Before<strong> label</strong> after</p>',
			'<p><b>“</b>This post follows.</p>',
			'<p><strong>First<br>Second</strong></p>',
			'<p>Use <code>a`b</code>.</p>',
			'<a href="/x"><em>A</em><em>B</em></a>',
			'<em>A</em><a href="/x"></a><em>B</em>',
			'<p>Before<em> label </em>after</p>',
			'<p>Meanwhile<strong>, Related Top-Performing Posts</strong> lists results.</p>',
			'<p>embeddings<em>.</em></p>',
			'<p><em>Caption <span>test</span>(</em></p>',
			'<p>Use <code><a href="https://example.com/option">option="true"</a></code> and <code>prefix<a href="https://example.com/value">value</a>suffix</code>.</p>',
			'<p><q>quoted</q> <sub>low</sub> <sup>high</sup></p>',
			'<p><sub></sub><sup></sup>Text</p>',
			'<p><a href="/x">Link</a> <img src="/x.png" alt="Image" title="Title"></p>',
			'<p><strong>Bold <em>and italic</em></strong>.</p>',
			'<p><strong><a>Plain label</a></strong></p>',
			'<strong>A <a href="/x">linked</a> B</strong>',
			'<a href="/x"><strong>Bold</strong> text</a>',
			'<strong><a href="/x">Link</a> tail</strong>',
			'<strong>A</strong><em>B</em>',
			'<em>A</em><strong>B</strong>',
			'<strong>A</strong><strong><em>B</em></strong>',
			'<a href="/x"><strong>A</strong><strong>B</strong></a>',
			'<strong><a href="/x"><img src="/i.png" alt="I"></a></strong>',
			'<strong>A<img src="/i.png" alt="I">B</strong>',
			'<a href="/x">Before <img src="/i.png" alt="I"> after</a>',
			'<strong><q>"hi"</q> next</strong>',
			'<em>First<br>Second</em>',
			'<a href="/x"><em>A</em> <em>B</em></a>',
			'<p><strong><sup></sup>After</strong></p>',
			'<p><a href="/x"><sup></sup>After</a></p>',
			'<code>x</code> tail',
			'<q>x</q> tail',
			'<a href="/x">1. item</a>',
			'<strong><code>x</code> tail</strong>',
			'<code><a href="/x">x</a></code> tail',
		);
		$converter = new HTML_To_Markdown_Converter();
		foreach ( $cases as $html ) {
			$this->assertSame( $converter->convert( $html ), $this->convert_inline( $html ), $html );
		}
		$this->assertSame( '**A**', $this->convert_inline( '<strong>A</strong>' ) );
		$this->assertSame( '*B*', $this->convert_inline( '<em>B</em>' ) );
	}

	/** Links and adjacent formatting share visible boundaries. */
	public function test_direct_pass_nesting_and_adjacency(): void {
		$this->assertSame( '[**link**](/x)', $this->convert_inline( '<strong><a href="/x">link</a></strong>' ) );
		$this->assertSame( '**AB**', $this->convert_inline( '<strong>A</strong><strong>B</strong>' ) );
		$this->assertSame( '**A** **B**', $this->convert_inline( '<strong>A </strong><strong>B</strong>' ) );
		$this->assertSame( '*AB*', $this->convert_inline( '<em>A</em><a href="/x"></a><em>B</em>' ) );
	}

	/** Review regressions keep visible items and nested effects intact. */
	public function test_direct_pass_review_regressions(): void {
		$cases = array(
			'<strong>A<strong>B</strong>C</strong>'        => '**ABC**',
			'<a href="/x"><img src="/i.png" alt="I"></a>'  => '[![I](/i.png)](/x)',
			'<strong><img src="/i.png" alt="I"></strong>'  => '**![I](/i.png)**',
			'<code>A<img src="/i.png" alt="I">B</code>'    => '`AB`',
			'<p>1. item</p>'                               => '1\\. item',
			'<p>- item</p>'                                => '\\- item',
			'<strong><em><a href="/x">x</a></em></strong>' => '[***x***](/x)',
			'<a href="/x"> <img src="/i.png" alt="I"> </a>' => '[![I](/i.png) ](/x)',
			'<strong><q>"hi"</q></strong>'                 => '**<q>"hi"</q>**',
			'<p><em>1. item</em></p>'                      => '*1\\. item*',
			'<code>x</code> tail'                          => '`x` tail',
			'<code><a href="/x">x</a></code> tail'         => '[`x`](/x) tail',
		);
		foreach ( $cases as $html => $expected ) {
			$this->assertSame( $expected, $this->convert_inline( $html ), $html );
		}
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

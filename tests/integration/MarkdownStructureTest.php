<?php

/**
 * Checks how a Markdown reader interprets conversion fixtures.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents\Tests\Integration;

use DOMDocument;
use DOMNode;
use DOMXPath;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/** Checks document structure in addition to the exact Markdown output tests. */
class MarkdownStructureTest extends \WP_UnitTestCase {
	/** Parse Markdown with a reader independent of the plugin's writer. */
	private function parse( string $markdown ): DOMXPath {
		$html     = (string) ( new GithubFlavoredMarkdownConverter() )->convert( $markdown );
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return new DOMXPath( $document );
	}

	/** Read a fixture without changing its exact-output contract. */
	private function fixture( string $name ): DOMXPath {
		$markdown = file_get_contents( __DIR__ . '/../fixtures/' . $name );
		$this->assertIsString( $markdown );
		return $this->parse( $markdown );
	}

	/** Find the text node holding a unique fixture marker. */
	private function text_node( DOMXPath $xpath, string $text ): DOMNode {
		foreach ( $xpath->query( '//text()' ) as $node ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property.
			if ( str_contains( $node->nodeValue, $text ) ) {
				return $node;
			}
		}

		$this->fail( 'Missing content: ' . $text );
	}

	/** Find the nearest containing element of a given type. */
	private function ancestor( DOMNode $node, string $tag ): ?DOMNode {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property.
		for ( $node = $node->parentNode; null !== $node; $node = $node->parentNode ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property.
			if ( $tag === $node->nodeName ) {
				return $node;
			}
		}

		return null;
	}

	public function test_rich_classic_content_stays_in_its_list_item(): void {
		$xpath   = $this->fixture( 'block-gauntlet.md' );
		$intro   = $this->ancestor( $this->text_node( $xpath, 'SENTINEL-RICH-LIST-INTRO' ), 'li' );
		$sibling = $this->ancestor( $this->text_node( $xpath, 'SENTINEL-RICH-LIST-SIBLING' ), 'li' );

		$this->assertNotNull( $intro );
		$this->assertSame( $intro, $this->ancestor( $this->text_node( $xpath, 'SENTINEL-RICH-LIST-FOLLOWUP' ), 'li' ) );
		$this->assertSame( $intro, $this->ancestor( $this->text_node( $xpath, 'SENTINEL-RICH-LIST-END' ), 'li' ) );
		$nested = $this->ancestor( $this->text_node( $xpath, 'SENTINEL-RICH-LIST-NESTED' ), 'li' );
		$this->assertNotSame( $intro, $nested );
		$this->assertSame( $intro, $this->ancestor( $nested, 'li' ) );
		$this->assertNotSame( $intro, $sibling );
		$this->assertNull( $this->ancestor( $sibling, 'li' ) );
	}

	public function test_block_gauntlet_has_expected_markdown_structure(): void {
		$xpath = $this->fixture( 'block-gauntlet.md' );
		foreach ( array( 'SENTINEL-START', 'SENTINEL-ACCORDION-QUESTION', 'SENTINEL-MEDIA-TEXT' ) as $text ) {
			$this->assertSame( 1, $xpath->query( '//h2[contains(., "' . $text . '")] | //h3[contains(., "' . $text . '")]' )->length, $text );
		}
		foreach ( array( 'SENTINEL-QUOTE', 'SENTINEL-PULLQUOTE', 'SENTINEL-CUSTOM-QUOTE' ) as $text ) {
			$this->assertNotNull( $this->ancestor( $this->text_node( $xpath, $text ), 'blockquote' ), $text );
		}
		$this->assertNotNull( $this->ancestor( $this->text_node( $xpath, 'SENTINEL-TABLE' ), 'table' ) );
		$this->assertNotNull( $this->ancestor( $this->text_node( $xpath, 'SENTINEL-CODE' ), 'pre' ) );
		$link = $this->ancestor( $this->text_node( $xpath, 'SENTINEL-CTA-LINK' ), 'a' );
		$this->assertSame( 'https://example.com/action', $link->attributes->getNamedItem( 'href' )->nodeValue );
		$image = $xpath->query( '//img[@alt="Media text image"]' )->item( 0 );
		$this->assertNotNull( $image );
		$this->assertSame( 'https://example.com/media-text.jpg', $image->attributes->getNamedItem( 'src' )->nodeValue );
		$this->assertSame( 1, $xpath->query( '//br[preceding-sibling::text()[contains(., "First line")]]' )->length );
	}

	public function test_nested_quote_and_code_stay_in_first_list_item(): void {
		$xpath = $this->fixture( 'renderer-list-blocks.md' );
		$lead  = $this->ancestor( $this->text_node( $xpath, 'Lead' ), 'li' );
		foreach ( array( 'Quote', 'echo 1;', 'Tail' ) as $text ) {
			$this->assertSame( $lead, $this->ancestor( $this->text_node( $xpath, $text ), 'li' ), $text );
		}
		$this->assertNotNull( $this->ancestor( $this->text_node( $xpath, 'Quote' ), 'blockquote' ) );
		$this->assertNotNull( $this->ancestor( $this->text_node( $xpath, 'echo 1;' ), 'pre' ) );
		$this->assertNotSame( $lead, $this->ancestor( $this->text_node( $xpath, 'Next' ), 'li' ) );
	}

	public function test_explicit_breaks_stay_inside_emphasis_and_links(): void {
		$xpath = $this->parse( "**First\\\nSecond**\n\n[A\\\n\\\nB](/x)\n\n**A[B\\\nC](/x)D**" );
		$this->assertSame( 1, $xpath->query( '//strong[starts-with(., "First")]/br' )->length );
		$this->assertSame( 2, $xpath->query( '//a[@href="/x" and starts-with(., "A")]/br' )->length );
		$this->assertSame( 1, $xpath->query( '//strong[a[@href="/x"]]/a/br' )->length );
	}

	public function test_breaks_at_format_edges_and_in_code_preserve_html_structure(): void {
		$xpath = $this->parse( "ab<em><br>cd</em>\n\n[A<br>](/x)\n\nA<br>\n\n\\\nA\n\n<code>&lt;x&gt;&amp;<br>y</code>\n\n## A<br>B" );
		$this->assertSame( 1, $xpath->query( '//em[.="cd"]/br' )->length );
		$this->assertSame( 1, $xpath->query( '//a[@href="/x"]/br' )->length );
		$this->assertSame( 1, $xpath->query( '//code[contains(., "<x>&")]/br' )->length );
		$this->assertSame( 1, $xpath->query( '//h2/br' )->length );
		$this->assertSame( 2, $xpath->query( '//p/br' )->length );
	}

	public function test_repeated_hard_breaks_remain_in_one_list_item(): void {
		$xpath = $this->parse( "1. Definitions\\\n   \\\n   Analytics report\n2. Licenses" );
		$this->assertSame( 2, $xpath->query( '//ol/li' )->length );
		$this->assertSame( 2, $xpath->query( '(//ol/li)[1]/br' )->length );
		$this->assertSame( 0, $xpath->query( '(//ol/li)[2]/br' )->length );
	}

	public function test_misnested_html_keeps_link_and_format_boundaries_balanced(): void {
		$xpath = $this->parse( '<strong>Bold<em>italic</em></strong><em>after</em>' . "\n\n" . '[link<strong>bold</strong>](/x)<strong>tail</strong>' );
		$this->assertSame( 1, $xpath->query( '//p[1]/strong/em[.="italic"]' )->length );
		$this->assertSame( 1, $xpath->query( '//p[1]/em[.="after"]' )->length );
		$this->assertSame( 1, $xpath->query( '//p[2]/a[@href="/x"]/strong[.="bold"]' )->length );
		$this->assertSame( 1, $xpath->query( '//p[2]/strong[.="tail"]' )->length );
	}
}

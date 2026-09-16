<?php

/**
 * Content negotiation unit tests.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents\Tests\Unit;

use Content_For_Agents\Content_Negotiation;
use PHPUnit\Framework\TestCase;

/**
 * Verifies Accept header preference handling.
 */
class ContentNegotiationTest extends TestCase {

	/**
	 * Markdown must be explicitly preferred over HTML.
	 *
	 * @dataProvider accept_header_provider
	 *
	 * @param string $accept   Accept header value.
	 * @param bool   $expected Expected result.
	 */
	public function test_accept_preference( string $accept, bool $expected ): void {
		$this->assertSame( $expected, Content_Negotiation::accept_prefers_markdown( $accept ) );
	}

	/**
	 * Accept header scenarios.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public function accept_header_provider(): array {
		return array(
			'explicit markdown'        => array( 'text/markdown', true ),
			'markdown preferred'       => array( 'text/html;q=0.5, text/markdown;q=0.9', true ),
			'equal preference'         => array( 'text/html, text/markdown', false ),
			'html preferred'           => array( 'text/html;q=0.9, text/markdown;q=0.5', false ),
			'wildcard is insufficient' => array( 'text/*, */*', false ),
		);
	}
}

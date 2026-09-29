<?php

/**
 * Content-Signal integration tests.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents\Tests\Integration;

use Content_For_Agents\Loader;
use Content_For_Agents\Markdown_Response;
use Content_For_Agents\Robots_Txt;

/**
 * Covers site-wide Content-Signal overrides shared by Markdown and robots.txt.
 */
class ContentSignalTest extends \WP_UnitTestCase {

	/**
	 * A code-level filter overrides the stored values in both output paths.
	 */
	public function test_filter_overrides_option_for_markdown_and_robots(): void {
		update_option(
			'content_for_agents_content_signal',
			array(
				'ai-train' => 'yes',
				'search'   => 'no',
				'ai-input' => 'yes',
			)
		);

		$override = static function ( $values ): array {
			return array(
				'ai-train' => 'no',
				'search'   => $values['search'],
				'ai-input' => true,
			);
		};
		add_filter( 'content_for_agents_content_signal_values', $override );
		try {
			$this->assertSame( 'ai-train=no, search=no, ai-input=yes', Markdown_Response::get_content_signal_header() );

			$robots = new Robots_Txt( new Loader() );
			$this->assertSame(
				"User-agent: *\nContent-Signal: ai-train=no, search=no, ai-input=yes\nDisallow:\n",
				$robots->add_content_signal_directive( "User-agent: *\nDisallow:\n", 1 )
			);
		} finally {
			remove_filter( 'content_for_agents_content_signal_values', $override );
		}
	}

	/**
	 * An empty override omits Content-Signal from both output paths.
	 */
	public function test_empty_filter_value_omits_signal(): void {
		$override = static function (): array {
			return array();
		};
		add_filter( 'content_for_agents_content_signal_values', $override );
		try {
			$this->assertSame( '', Markdown_Response::get_content_signal_header() );
			$robots = new Robots_Txt( new Loader() );
			$this->assertSame(
				"User-agent: *\nDisallow:\n",
				$robots->add_content_signal_directive( "User-agent: *\nDisallow:\n", 1 )
			);
		} finally {
			remove_filter( 'content_for_agents_content_signal_values', $override );
		}
	}
}

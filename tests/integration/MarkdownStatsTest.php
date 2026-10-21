<?php
/**
 * MC Stats integration tests.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents\Tests\Integration;

use Content_For_Agents\Block_Markdown_Registry;
use Content_For_Agents\Markdown_Converter;
use Content_For_Agents\Markdown_Response;

require_once __DIR__ . '/support/class-testable-markdown-stats.php';

if ( ! defined( 'WPCOM_IS_VIP_ENV' ) ) {
	define( 'WPCOM_IS_VIP_ENV', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- VIP runtime constant.
}
if ( ! defined( 'WPCOM_SANDBOXED' ) ) {
	define( 'WPCOM_SANDBOXED', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- VIP runtime constant.
}
if ( ! defined( 'FILES_CLIENT_SITE_ID' ) ) {
	define( 'FILES_CLIENT_SITE_ID', 123 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- VIP runtime constant.
}

/** Tests sampled response counters without sending a pixel. */
class MarkdownStatsTest extends \WP_UnitTestCase {
	/**
	 * Several callback invocations produce one counter for the response.
	 */
	public function test_sampled_response_aggregates_nested_callbacks(): void {
		$post = self::factory()->post->create_and_get(
			array(
				'post_content' => '<!-- wp:group --><!-- wp:content-for-agents/stats-covered /--><!-- wp:content-for-agents/stats-covered /--><!-- /wp:group --><!-- wp:content-for-agents/stats-covered /-->',
			)
		);
		Block_Markdown_Registry::register( 'content-for-agents/stats-covered', static fn(): string => 'Covered' );
		$converter = new Markdown_Converter();
		$build     = new \ReflectionMethod( Markdown_Response::class, 'build_content' );
		$build->invoke( null, $post, 'Covered post', $converter );
		$this->assertTrue( $converter->used_block_callback() );

		$pixels = array();
		$stats  = new Testable_Markdown_Stats(
			true,
			static function ( array $counters ) use ( &$pixels ): void {
				$pixels[] = $counters;
			}
		);
		$stats->record_served( $converter->used_block_callback() );
		$stats->send();
		remove_action( 'shutdown', array( $stats, 'send' ) );

		$this->assertSame(
			array(
				array(
					'content-for-agents-markdown-served' => (int) FILES_CLIENT_SITE_ID,
					'content-for-agents-block-callback-used' => (int) FILES_CLIENT_SITE_ID,
				),
			),
			$pixels
		);
	}

	/** A post without registered callbacks sends only the served counter. */
	public function test_response_without_callback_has_one_counter(): void {
		$post      = self::factory()->post->create_and_get( array( 'post_content' => '<!-- wp:paragraph --><p>Text.</p><!-- /wp:paragraph -->' ) );
		$converter = new Markdown_Converter();
		$converter->post_to_markdown( $post );
		$this->assertFalse( $converter->used_block_callback() );
		$pixels = array();
		$stats  = new Testable_Markdown_Stats(
			true,
			static function ( array $counters ) use ( &$pixels ): void {
				$pixels[] = $counters;
			}
		);
		$stats->record_served( $converter->used_block_callback() );
		$stats->send();
		remove_action( 'shutdown', array( $stats, 'send' ) );

		$this->assertSame( array( array( 'content-for-agents-markdown-served' => (int) FILES_CLIENT_SITE_ID ) ), $pixels );
	}

	/** Unsampled responses send nothing. */
	public function test_unsampled_response_sends_nothing(): void {
		$pixels = array();
		$stats  = new Testable_Markdown_Stats(
			false,
			static function ( array $counters ) use ( &$pixels ): void {
				$pixels[] = $counters;
			}
		);
		$stats->record_served( true );
		$stats->send();

		$this->assertSame( array(), $pixels );
	}
}

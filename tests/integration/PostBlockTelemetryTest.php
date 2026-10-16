<?php
/**
 * Published post block telemetry integration tests.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents\Tests\Integration;

use Content_For_Agents\Block_Markdown_Registry;
use Content_For_Agents\Loader;
use Content_For_Agents\Post_Block_Telemetry;
use Content_For_Agents\Telemetry_Service;

/**
 * Tests coverage events with an in-memory log instead of the VIP client.
 */
class PostBlockTelemetryTest extends \WP_UnitTestCase {
	/**
	 * Publication and changed counts record snapshots; other edits do not.
	 */
	public function test_publish_and_coverage_changes_record_block_counts(): void {
		$author_id        = self::factory()->user->create();
		$publisher_id     = self::factory()->user->create();
		$original_content = '<!-- wp:group --><!-- wp:content-for-agents/telemetry-covered /--><!-- wp:paragraph --><p>Text.</p><!-- /wp:paragraph --><!-- /wp:group --><!-- wp:content-for-agents/telemetry-covered /-->Freeform';
		$covered_content  = str_replace( '<!-- wp:paragraph --><p>Text.</p><!-- /wp:paragraph -->', '<!-- wp:content-for-agents/telemetry-covered /-->', $original_content );
		$post_id          = self::factory()->post->create(
			array(
				'post_author'  => $author_id,
				'post_status'  => 'draft',
				'post_content' => $original_content,
			)
		);

		Block_Markdown_Registry::register( 'content-for-agents/telemetry-covered', static fn(): string => 'Covered' );
		$client  = new class() {
			public array $events = array();

			public function record_event( string $event, array $properties ): void {
				$this->events[] = array(
					'event'      => $event,
					'properties' => $properties,
					'user_id'    => get_current_user_id(),
				);
			}
		};
		$handler = new Post_Block_Telemetry( new Loader(), new Telemetry_Service( $client ) );
		add_action( 'wp_after_insert_post', array( $handler, 'record_coverage' ), 10, 4 );

		try {
			wp_set_current_user( $publisher_id );
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				)
			);
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => 'Edited after publish',
				)
			);
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => str_replace( 'Text.', 'Changed text.', $original_content ),
				)
			);
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $covered_content,
				)
			);
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $original_content,
				)
			);
		} finally {
			remove_action( 'wp_after_insert_post', array( $handler, 'record_coverage' ), 10 );
		}

		$this->assertCount( 3, $client->events );
		$this->assertSame( 'post_block_callback_coverage', $client->events[0]['event'] );
		$this->assertSame( $publisher_id, $client->events[0]['user_id'] );
		$this->assertSame(
			array(
				'post_type'                    => 'post',
				'post_id'                      => $post_id,
				'blog_id'                      => get_current_blog_id(),
				'total_blocks'                 => 4,
				'blocks_with_custom_callbacks' => 2,
			),
			$client->events[0]['properties']
		);
		$this->assertSame( 4, $client->events[1]['properties']['total_blocks'] );
		$this->assertSame( 3, $client->events[1]['properties']['blocks_with_custom_callbacks'] );
		$this->assertSame( 4, $client->events[2]['properties']['total_blocks'] );
		$this->assertSame( 2, $client->events[2]['properties']['blocks_with_custom_callbacks'] );
	}

	/**
	 * Scheduled publication can record as the author without retaining that user.
	 */
	public function test_scheduled_publish_uses_author_when_no_user_is_current(): void {
		$author_id = self::factory()->user->create();
		$post_id   = self::factory()->post->create(
			array(
				'post_author'  => $author_id,
				'post_status'  => 'future',
				'post_date'    => '2030-01-01 00:00:00',
				'post_content' => '<!-- wp:paragraph --><p>Scheduled.</p><!-- /wp:paragraph -->',
			)
		);
		$client    = new class() {
			public array $events = array();

			public function record_event( string $event, array $properties ): void {
				$this->events[] = array(
					'event'      => $event,
					'properties' => $properties,
					'user_id'    => get_current_user_id(),
				);
			}
		};
		$handler   = new Post_Block_Telemetry( new Loader(), new Telemetry_Service( $client ) );
		add_action( 'wp_after_insert_post', array( $handler, 'record_coverage' ), 10, 4 );

		try {
			wp_set_current_user( 0 );
			wp_publish_post( $post_id );
		} finally {
			remove_action( 'wp_after_insert_post', array( $handler, 'record_coverage' ), 10 );
		}

		$this->assertCount( 1, $client->events );
		$this->assertSame( $author_id, $client->events[0]['user_id'] );
		$this->assertSame( 1, $client->events[0]['properties']['total_blocks'] );
		$this->assertSame( 0, $client->events[0]['properties']['blocks_with_custom_callbacks'] );
		$this->assertSame( 0, get_current_user_id() );
	}
}

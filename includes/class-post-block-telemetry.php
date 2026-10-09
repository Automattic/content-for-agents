<?php
/**
 * Published post block callback coverage.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents;

/**
 * Records block callback coverage when a supported published post changes.
 */
class Post_Block_Telemetry {
	/**
	 * @var Telemetry_Service
	 */
	private Telemetry_Service $telemetry;

	/**
	 * @param Loader                 $loader    Plugin hook loader.
	 * @param Telemetry_Service|null $telemetry Optional recording service for tests.
	 */
	public function __construct( Loader $loader, ?Telemetry_Service $telemetry = null ) {
		$this->telemetry = $telemetry ?? new Telemetry_Service();
		$loader->add_action( 'wp_after_insert_post', $this, 'record_coverage', 10, 4 );
	}

	/**
	 * Record callback coverage on publication or a meaningful content update.
	 *
	 * @param int           $post_id     Post ID.
	 * @param \WP_Post      $post        Saved post.
	 * @param bool          $_update     Whether the post was updated.
	 * @param \WP_Post|null $post_before Previous post state.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The hook supplies all four arguments.
	public function record_coverage( int $post_id, \WP_Post $post, bool $_update, ?\WP_Post $post_before ): void {
		if ( 'publish' !== $post->post_status
			|| ! post_type_supports( $post->post_type, 'content-for-agents' )
		) {
			return;
		}

		$was_published = $post_before instanceof \WP_Post && 'publish' === $post_before->post_status;
		if ( $was_published && $post_before->post_content === $post->post_content ) {
			return;
		}

		$counts = self::count_blocks( parse_blocks( $post->post_content ) );
		if ( $was_published ) {
			$previous_counts = self::count_blocks( parse_blocks( $post_before->post_content ) );
			if ( $previous_counts === $counts ) {
				return;
			}
		}

		$previous_user = get_current_user_id();
		if ( 0 === $previous_user ) {
			$author_id = (int) $post->post_author;
			if ( 0 === $author_id || ! get_user_by( 'id', $author_id ) ) {
				return;
			}

			wp_set_current_user( $author_id );
		}

		try {
			$this->telemetry->record_event(
				'post_block_callback_coverage',
				array(
					'post_type'                    => $post->post_type,
					'post_id'                      => $post_id,
					'blog_id'                      => get_current_blog_id(),
					'total_blocks'                 => $counts['total'],
					'blocks_with_custom_callbacks' => $counts['with_callbacks'],
				)
			);
		} finally {
			if ( 0 === $previous_user ) {
				wp_set_current_user( $previous_user );
			}
		}
	}

	/**
	 * Count saved named blocks, including nested blocks, without rendering them.
	 *
	 * @param array $blocks Parsed block tree.
	 * @return array{total: int, with_callbacks: int} Block counts.
	 */
	private static function count_blocks( array $blocks ): array {
		$counts = array(
			'total'          => 0,
			'with_callbacks' => 0,
		);

		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			if ( is_string( $name ) && '' !== $name ) {
				++$counts['total'];
				if ( Block_Markdown_Registry::has( $name ) ) {
					++$counts['with_callbacks'];
				}
			}

			$children = $block['innerBlocks'] ?? array();
			if ( is_array( $children ) && ! empty( $children ) ) {
				$child_counts              = self::count_blocks( $children );
				$counts['total']          += $child_counts['total'];
				$counts['with_callbacks'] += $child_counts['with_callbacks'];
			}
		}

		return $counts;
	}
}

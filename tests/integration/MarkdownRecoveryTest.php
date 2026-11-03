<?php
/**
 * Recovery across independent Markdown conversion phases.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents\Tests\Integration;

use Content_For_Agents\Block_Markdown_Registry;
use Content_For_Agents\Markdown_Converter;
use Content_For_Agents\Markdown_Response;
use Content_For_Agents\Post_Context;

/** Verify recovered documents retain defaults, neighboring content, and context. */
class MarkdownRecoveryTest extends \WP_UnitTestCase {
	/**
	 * Recovery must not render another post after an extension changes globals.
	 *
	 * @dataProvider context_failure_provider
	 * @param string $stage Failing extension point.
	 * @param bool   $throws Whether the extension throws instead of returning WP_Error.
	 */
	public function test_failed_extensions_do_not_expose_another_post( string $stage, bool $throws ): void {
		$name  = 'content-for-agents/context-' . str_replace( '_', '-', $stage ) . ( $throws ? '-throw' : '-error' );
		$other = self::factory()->post->create_and_get(
			array(
				'post_title'  => 'PRIVATE OTHER MARKER',
				'post_status' => 'private',
			)
		);
		register_block_type( $name, array( 'render_callback' => static fn(): string => '<h2>' . get_the_title() . '</h2>' ) );
		$content = '<!-- wp:' . $name . ' /-->';
		if ( 'shortcode' === $stage ) {
			$content = '<!-- wp:shortcode -->[cfa_context_failure]<!-- /wp:shortcode -->' . $content;
		}
		$target   = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'PUBLIC TARGET MARKER',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
		$markdown = false;
		$fail     = static function () use ( $other, $throws, &$markdown ) {
			if ( ! $markdown ) {
				return '';
			}
			$GLOBALS['post'] = $other;
			$GLOBALS['id']   = $other->ID;
			if ( $throws ) {
				throw new \RuntimeException( 'Extension failed before resetting its query' );
			}
			return new \WP_Error( 'context_failed', 'Extension failed before resetting its query' );
		};
		$hook     = 'content_for_agents_' . $stage;
		if ( 'callback' === $stage ) {
			Block_Markdown_Registry::register( $name, $fail );
		} elseif ( 'block-filter' === $stage ) {
			Block_Markdown_Registry::register( $name, static fn(): string => get_the_title() );
			$hook = 'content_for_agents_block_' . $name;
			add_filter( $hook, $fail );
		} elseif ( 'shortcode' === $stage ) {
			add_shortcode( 'cfa_context_failure', $fail );
		} else {
			add_filter( $hook, $fail );
		}
		$prefix = static fn( string $value ): string => $value . get_the_title() . "\n\n";
		add_filter( 'content_for_agents_markdown_prefix', $prefix );
		$caller = new Post_Context( $other );
		try {
			$control = new Post_Context( $target );
			try {
				$html = apply_filters( 'the_content', $target->post_content );
			} finally {
				$control->restore();
			}
			$this->assertStringContainsString( 'PUBLIC TARGET MARKER', $html );
			$this->assertStringNotContainsString( 'PRIVATE OTHER MARKER', $html );
			$markdown = true;
			$output   = ( new \ReflectionMethod( Markdown_Response::class, 'build_content' ) )->invoke( null, $target, $target->post_title );
			$this->assertStringContainsString( 'PUBLIC TARGET MARKER', $output );
			$this->assertStringNotContainsString( 'PRIVATE OTHER MARKER', $output );
			$this->assertSame( $other, $GLOBALS['post'] );
			$this->assertSame( $other->ID, $GLOBALS['id'] );
		} finally {
			$caller->restore();
			remove_filter( $hook, $fail );
			remove_filter( 'content_for_agents_markdown_prefix', $prefix );
			remove_shortcode( 'cfa_context_failure' );
			unregister_block_type( $name );
		}
	}

	/** @return array Distinct recovery entry points and both primary error forms. */
	public function context_failure_provider(): array {
		return array(
			'pre-exception'      => array( 'pre_markdown', true ),
			'pre-error'          => array( 'pre_markdown', false ),
			'callback-exception' => array( 'callback', true ),
			'callback-error'     => array( 'callback', false ),
			'block-filter'       => array( 'block-filter', true ),
			'body-filter'        => array( 'after_markdown', true ),
			'frontmatter'        => array( 'frontmatter', true ),
			'shortcode'          => array( 'shortcode', true ),
		);
	}

	/**
	 * Failed parent callbacks must preserve the HTML renderer's visibility rules.
	 *
	 * @dataProvider parent_visibility_provider
	 * @param bool $hidden Whether the HTML parent hides its children.
	 */
	public function test_failed_parent_callback_uses_parent_html( bool $hidden ): void {
		$parent = 'content-for-agents/failing-parent';
		$child  = 'content-for-agents/visible-child';
		$calls  = 0;
		register_block_type( $parent, array( 'render_callback' => static fn( $attrs, $content ): string => $hidden ? '' : $content ) );
		Block_Markdown_Registry::register( $parent, static fn() => new \WP_Error( 'parent_failed', 'Parent Markdown failed' ) );
		Block_Markdown_Registry::register(
			$child,
			static function () use ( &$calls ): string {
				++$calls;
				return 'Custom child Markdown.';
			}
		);
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '<!-- wp:paragraph --><p>Before.</p><!-- /wp:paragraph --><!-- wp:content-for-agents/failing-parent --><!-- wp:content-for-agents/visible-child --><p>HTML child.</p><!-- /wp:content-for-agents/visible-child --><!-- /wp:content-for-agents/failing-parent --><!-- wp:paragraph --><p>After.</p><!-- /wp:paragraph -->' )
		);
		try {
			$html = apply_filters( 'the_content', $post->post_content );
			$this->assertSame( ! $hidden, str_contains( $html, 'HTML child.' ) );
			$this->assertSame( $hidden ? "Before.\n\nAfter." : "Before.\n\nHTML child.\n\nAfter.", ( new Markdown_Converter() )->post_to_markdown( $post ) );
			$this->assertSame( 0, $calls );
		} finally {
			unregister_block_type( $parent );
		}
	}

	/** @return array Parent visibility decisions. */
	public function parent_visibility_provider(): array {
		return array(
			'hidden'  => array( true ),
			'visible' => array( false ),
		);
	}

	/**
	 * Each extension failure leaves the same document as its unfiltered default.
	 *
	 * @dataProvider filter_failure_provider
	 * @param string $hook Filter name.
	 * @param string $failure Failure mode.
	 */
	public function test_filter_failure_keeps_default_document( string $hook, string $failure ): void {
		$post     = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Default title',
				'post_content' => '<!-- wp:paragraph --><p>Default body.</p><!-- /wp:paragraph -->',
			)
		);
		$method   = new \ReflectionMethod( Markdown_Response::class, 'build_content' );
		$expected = $method->invoke( null, $post, 'Default title' );
		$filter   = static function () use ( $failure ) {
			if ( 'throw' === $failure ) {
				throw new \TypeError( 'Private recovery diagnostic' );
			}
			return 'error' === $failure ? new \WP_Error( 'recovery_test', 'Private recovery diagnostic' ) : false;
		};
		add_filter( $hook, $filter );
		try {
			$this->assertSame( $expected, $method->invoke( null, $post, 'Default title' ) );
		} finally {
			remove_filter( $hook, $filter );
		}
	}

	/** @return array Filter hooks and failure modes. */
	public function filter_failure_provider(): array {
		$cases = array();
		foreach ( array( 'pre_markdown', 'after_markdown', 'markdown_prefix', 'frontmatter' ) as $hook ) {
			foreach ( array( 'throw', 'error', 'invalid' ) as $failure ) {
				$cases[ $hook . '-' . $failure ] = array( 'content_for_agents_' . $hook, $failure );
			}
		}
		return $cases;
	}

	/** A nested dynamic callback is omitted without bypassing parent rendering. */
	public function test_nested_dynamic_failure_keeps_parent_rendering_and_siblings(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		$child    = 'content-for-agents/recovery-child';
		$parent   = 'content-for-agents/recovery-parent';
		$throw    = static function (): void {
			throw new \RuntimeException( 'Nested render failed' );
		};
		$render   = static fn( $attrs, $content ): string => '<div><p>Parent prefix.</p>' . $content . '<p>Parent suffix.</p></div>';
		register_block_type( $child, array( 'render_callback' => $throw ) );
		register_block_type( $parent, array( 'render_callback' => $render ) );
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '<!-- wp:content-for-agents/recovery-parent --><!-- wp:paragraph --><p>Before.</p><!-- /wp:paragraph --><!-- wp:content-for-agents/recovery-child /--><!-- wp:paragraph --><p>After.</p><!-- /wp:paragraph --><!-- /wp:content-for-agents/recovery-parent -->' )
		);
		try {
			$output = ( new Markdown_Converter() )->post_to_markdown( $post );
			$this->assertSame( "Parent prefix.\n\nBefore.\n\nAfter.\n\nParent suffix.", $output );
			$this->assertSame( $throw, $registry->get_registered( $child )->render_callback );
			$this->assertSame( $render, $registry->get_registered( $parent )->render_callback );
		} finally {
			unregister_block_type( $parent );
			unregister_block_type( $child );
		}
	}

	/**
	 * Callback failures render fallback HTML once and keep surrounding content.
	 *
	 * @dataProvider callback_failure_provider
	 * @param string $failure Failure mode.
	 * @param bool   $dynamic Whether the fallback uses a dynamic HTML renderer.
	 */
	public function test_callback_failure_uses_html_and_continues( string $failure, bool $dynamic ): void {
		$name  = 'content-for-agents/recovery-fallback';
		$calls = 0;
		$html  = '<p><strong>Fallback.</strong></p>';
		if ( $dynamic ) {
			register_block_type(
				$name,
				array(
					'render_callback' => static function () use ( &$calls, $html ): string {
						++$calls;
						return $html;
					},
				)
			);
		}
		Block_Markdown_Registry::register(
			$name,
			static function () use ( $failure ) {
				if ( 'throw' === $failure ) {
					throw new \Error( 'Callback failed' );
				}
				return 'error' === $failure ? new \WP_Error( 'callback_failed' ) : false;
			}
		);
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '<!-- wp:paragraph --><p>Before.</p><!-- /wp:paragraph --><!-- wp:content-for-agents/recovery-fallback -->' . $html . '<!-- /wp:content-for-agents/recovery-fallback --><!-- wp:paragraph --><p>After.</p><!-- /wp:paragraph -->' )
		);
		try {
			$this->assertSame( "Before.\n\n**Fallback.**\n\nAfter.", ( new Markdown_Converter() )->post_to_markdown( $post ) );
			$this->assertSame( $dynamic ? 1 : 0, $calls );
		} finally {
			if ( $dynamic ) {
				unregister_block_type( $name );
			}
		}
	}

	/** @return array Callback failures and distinct HTML fallback paths. */
	public function callback_failure_provider(): array {
		return array(
			'exception' => array( 'throw', false ),
			'wp-error'  => array( 'error', false ),
			'invalid'   => array( 'invalid', false ),
			'dynamic'   => array( 'throw', true ),
		);
	}

	/** Bad filtered metadata is discarded while default frontmatter and body survive. */
	public function test_unserializable_frontmatter_uses_default_metadata(): void {
		$post   = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Default title',
				'post_content' => 'Body.',
			)
		);
		$method = new \ReflectionMethod( Markdown_Response::class, 'build_content' );
		$before = $method->invoke( null, $post, 'Default title' );
		$filter = static function ( array $data ): array {
			$data['invalid'] = new \stdClass();
			return $data;
		};
		add_filter( 'content_for_agents_frontmatter', $filter );
		try {
			$this->assertSame( $before, $method->invoke( null, $post, 'Default title' ) );
		} finally {
			remove_filter( 'content_for_agents_frontmatter', $filter );
		}
	}

	/**
	 * Failed title or excerpt generation omits metadata, preserving the heading/body.
	 *
	 * @dataProvider metadata_failure_provider
	 * @param string $hook Metadata source hook.
	 */
	public function test_metadata_generation_failure_keeps_heading_and_body( string $hook ): void {
		$post   = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Stored title',
				'post_content' => 'Body.',
			)
		);
		$filter = static fn() => throw new \RuntimeException( 'Metadata source failed' );
		add_filter( $hook, $filter );
		try {
			$title  = ( new \ReflectionMethod( Markdown_Response::class, 'get_response_title' ) )->invoke( null, $post );
			$output = ( new \ReflectionMethod( Markdown_Response::class, 'build_content' ) )->invoke( null, $post, $title );
			$this->assertSame( "---\n---\n\n# Stored title\n\nBody.", $output );
		} finally {
			remove_filter( $hook, $filter );
		}
	}

	/** @return array Metadata source hooks. */
	public function metadata_failure_provider(): array {
		return array(
			'title'   => array( 'the_title' ),
			'excerpt' => array( 'get_the_excerpt' ),
		);
	}

	/**
	 * Shared WordPress processing failures affect both HTML and Markdown.
	 *
	 * @dataProvider post_failure_provider
	 * @param string $hook Core processing hook.
	 */
	public function test_post_processing_failure_returns_an_error( string $hook ): void {
		$post   = self::factory()->post->create_and_get( array( 'post_content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->' ) );
		$filter = static function (): void {
			throw new \RuntimeException( 'Core processing failed' );
		};
		add_filter( $hook, $filter );
		try {
			try {
				if ( 'the_post' === $hook ) {
					setup_postdata( $post );
				} else {
					apply_filters( 'the_content', $post->post_content );
				}
				$this->fail( 'Expected normal HTML rendering to fail too.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Core processing failed', $error->getMessage() );
			}
			$error = ( new Markdown_Converter() )->post_to_markdown( $post );
			$this->assertWPError( $error );
			$this->assertSame( 'Core processing failed', $error->get_error_data()->getMessage() );
		} finally {
			remove_filter( $hook, $filter );
		}
	}

	/** @return array Shared core processing hooks. */
	public function post_failure_provider(): array {
		return array(
			'parser'            => array( 'block_parser_class' ),
			'url-normalization' => array( 'wp_should_replace_insecure_home_url' ),
			'post-setup'        => array( 'the_post' ),
		);
	}

	/**
	 * Both wrapper traversal and callbacks delegating to children retain good content.
	 *
	 * @dataProvider child_conversion_provider
	 * @param bool $delegates Whether a parent callback delegates child conversion.
	 */
	public function test_failed_child_keeps_surrounding_content( bool $delegates ): void {
		$parent = $delegates ? 'content-for-agents/delegating-wrapper' : 'core/group';
		$child  = 'content-for-agents/failing-child';
		Block_Markdown_Registry::register( $child, static fn(): \WP_Error => new \WP_Error( 'child_failed' ) );
		if ( $delegates ) {
			Block_Markdown_Registry::register( $parent, static fn( array $block, \WP_Post $post ): string => ( new Markdown_Converter() )->blocks_to_markdown( $block['innerBlocks'], $post ) );
		}
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '<!-- wp:' . $parent . ' --><div><!-- wp:paragraph --><p>Before.</p><!-- /wp:paragraph --><!-- wp:content-for-agents/failing-child /--><!-- wp:paragraph --><p>After.</p><!-- /wp:paragraph --></div><!-- /wp:' . $parent . ' -->' )
		);
		$this->assertSame( "Before.\n\nAfter.", ( new Markdown_Converter() )->post_to_markdown( $post ) );
	}

	/** @return array Parent conversion strategies. */
	public function child_conversion_provider(): array {
		return array(
			'wrapper'             => array( false ),
			'delegating-callback' => array( true ),
		);
	}

	/** A failed wrapper fragment does not discard separately converted children. */
	public function test_failed_html_fragment_keeps_child_callback_and_later_content(): void {
		$name = 'content-for-agents/recovery-marker';
		Block_Markdown_Registry::register( $name, static fn(): string => 'Child.' );
		add_shortcode(
			'cfa_fragment_failure',
			static function (): void {
				throw new \RuntimeException( 'Fragment failed' );
			}
		);
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '<!-- wp:group --><div>[cfa_fragment_failure]<!-- wp:content-for-agents/recovery-marker /-->Tail.</div><!-- /wp:group -->' )
		);
		try {
			$this->assertSame( "Child.\n\nTail.", ( new Markdown_Converter() )->post_to_markdown( $post ) );
		} finally {
			remove_shortcode( 'cfa_fragment_failure' );
		}
	}
}

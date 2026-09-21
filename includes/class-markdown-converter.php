<?php

/**
 * Markdown converter for block content.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents;

/**
 * Converts WordPress post content to Markdown.
 *
 * Walks the parsed block tree for each post. Blocks with a registered
 * callback in Block_Markdown_Registry produce their own markdown; all
 * other blocks fall back to render_block() → HTML_To_Markdown_Converter.
 *
 * @package Content_For_Agents
 */
class Markdown_Converter {

	/**
	 * Convert post content to Markdown.
	 *
	 * @param int|\WP_Post $post Post ID or post object.
	 * @return string Markdown content.
	 */
	public function post_to_markdown( $post ) {
		$post_object = get_post( $post );
		if ( ! $post_object || ! post_type_supports( $post_object->post_type, 'content-for-agents' ) ) {
			return '';
		}

		/**
		 * Allow plugins to supply pre-built Markdown, bypassing block conversion.
		 *
		 * Return a non-null string to short-circuit conversion. Return null to fall
		 * through to the standard pipeline. Useful for post types that store Markdown
		 * directly in meta (e.g. OCR-extracted content).
		 *
		 * @param string|null $pre         Pre-built Markdown string, or null to use default conversion.
		 * @param \WP_Post    $post_object The post being converted.
		 */
		$pre = apply_filters( 'content_for_agents_pre_markdown', null, $post_object );
		if ( null !== $pre ) {
			return (string) $pre;
		}

		// Dynamic blocks may read the global post, which setup_postdata() does not
		// assign. Temporarily set it, then restore the caller's exact state.
		$keys  = array( 'post', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages' );
		$saved = array();
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $GLOBALS ) ) {
				$saved[ $key ] = $GLOBALS[ $key ];
			}
		}

		try {
			$GLOBALS['post'] = $post_object; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			setup_postdata( $post_object );
			return trim( $this->blocks_to_markdown( parse_blocks( $post_object->post_content ), $post_object ) );
		} finally {
			foreach ( $keys as $key ) {
				if ( array_key_exists( $key, $saved ) ) {
					$GLOBALS[ $key ] = $saved[ $key ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restoring core globals by name.
				} else {
					unset( $GLOBALS[ $key ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restoring core globals by name.
				}
			}
		}
	}

	/**
	 * Convert a flat array of parsed blocks to markdown.
	 *
	 * @param array    $blocks   Array of parsed block arrays from parse_blocks().
	 * @param \WP_Post $post     The post being converted.
	 * @return string
	 */
	public function blocks_to_markdown( array $blocks, \WP_Post $post ): string {
		$parts     = array();
		$converter = new HTML_To_Markdown_Converter();

		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? null;

			// Null blockName = freeform/classic content between blocks.
			if ( null === $block_name ) {
				$trimmed = trim( $block['innerHTML'] ?? '' );
				if ( '' !== $trimmed ) {
					$parts[] = $converter->convert( $trimmed );
				}
				continue;
			}

			$resolved = Block_Markdown_Resolver::resolve_strategy( $block_name, $block, $post );
			if ( true === $resolved['handled'] ) {
				if ( true === $resolved['recurse'] ) {
					$inner = $block['innerBlocks'] ?? array();
					if ( ! empty( $inner ) ) {
						$inner_md = $this->blocks_to_markdown( $inner, $post );
						if ( '' !== trim( $inner_md ) ) {
							$parts[] = $inner_md;
						}
					}
					continue;
				}

				if ( '' !== trim( (string) $resolved['markdown'] ) ) {
					$parts[] = (string) $resolved['markdown'];
				}
				continue;
			}

			// Dispatch to registered block-level markdown callback.
			$callback = Block_Markdown_Registry::get( $block_name );
			if ( $callback ) {
				$block_md = call_user_func( $callback, $block, $post );

				/**
				 * Filter markdown output for a specific block type.
				 *
				 * The dynamic portion of the hook name, `$block_name`, is the
				 * fully-qualified block name (e.g. 'my-plugin/chart').
				 *
				 * @param string   $block_md Markdown produced by the block's callback.
				 * @param array    $block    Parsed block array.
				 * @param \WP_Post $post     The post being converted.
				 */
				$block_md = apply_filters(
					'content_for_agents_block_' . $block_name,
					$block_md,
					$block,
					$post
				);

				if ( '' !== trim( (string) $block_md ) ) {
					$parts[] = (string) $block_md;
				}
				continue;
			}

			$inner = $block['innerBlocks'] ?? array();
			if ( ! empty( $inner ) ) {
				if ( ! $this->descendants_require_zipping( $inner ) ) {
					$md = $converter->convert( render_block( $block ) );
					if ( '' !== trim( $md ) ) {
						$parts[] = $md;
					}
					continue;
				}

				if ( 'core/quote' === $block_name ) {
					$inner_md = $this->blocks_to_markdown( $inner, $post );

					// Child placeholders are absent from innerHTML; remaining text is
					// the quote's citation. Keep child callbacks and nested quotes intact.
					$citation = trim( html_entity_decode( wp_strip_all_tags( $block['innerHTML'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
					if ( '' !== $citation ) {
						$inner_md = trim( $inner_md ) . "\n\n" . $citation;
					}
					if ( '' !== trim( $inner_md ) ) {
						// Prefix blank lines too, so multiple paragraphs form one quote.
						$inner_md = '> ' . str_replace( "\n", "\n> ", trim( $inner_md ) );
					}
					if ( '' !== trim( $inner_md ) ) {
						$parts[] = $inner_md;
					}
					continue;
				}

				$zipped_md = $this->zip_inner_content( $block, $post, $converter );
				if ( '' !== trim( $zipped_md ) ) {
					$parts[] = $zipped_md;
				}
				continue;
			}

			// Default: render block to HTML, then convert to markdown.
			$html = render_block( $block );
			$md   = $converter->convert( $html );
			if ( '' !== trim( $md ) ) {
				$parts[] = $md;
			}
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Whether any descendant needs custom Markdown handling.
	 *
	 * Detection does not execute callbacks. It only inspects registry entries and
	 * registered block metadata, then recurses through the parsed block tree.
	 *
	 * @param array $blocks Descendant blocks.
	 * @return bool Whether an innerContent zipper is required.
	 */
	private function descendants_require_zipping( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? null;
			if ( is_string( $block_name ) ) {
				if ( Block_Markdown_Registry::has( $block_name ) ) {
					return true;
				}

				$config = Block_Markdown_Resolver::get_block_config( $block_name );
				if ( null !== $config ) {
					$mode = isset( $config['mode'] ) ? sanitize_key( (string) $config['mode'] ) : 'html-fallback';
					if ( in_array( $mode, array( 'strip', 'children-only' ), true ) ) {
						return true;
					}

					$callback = $config['callback'] ?? null;
					if ( is_string( $callback ) && is_callable( $callback ) ) {
						return true;
					}
				}
			}

			if ( $this->descendants_require_zipping( $block['innerBlocks'] ?? array() ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Converts wrapper-owned HTML and child blocks in innerContent order.
	 *
	 * @param array                      $block     Parent block.
	 * @param \WP_Post                   $post      Post being converted.
	 * @param HTML_To_Markdown_Converter $converter HTML converter.
	 * @return string Zipped Markdown.
	 */
	private function zip_inner_content( array $block, \WP_Post $post, HTML_To_Markdown_Converter $converter ): string {
		$parts        = array();
		$inner_blocks = $block['innerBlocks'] ?? array();
		$inner_index  = 0;

		foreach ( $block['innerContent'] ?? array() as $fragment ) {
			if ( null === $fragment ) {
				if ( isset( $inner_blocks[ $inner_index ] ) ) {
					$child_md = $this->blocks_to_markdown( array( $inner_blocks[ $inner_index ] ), $post );
					if ( '' !== trim( $child_md ) ) {
						$parts[] = $child_md;
					}
				}
				++$inner_index;
				continue;
			}

			$owned_md = $converter->convert( (string) $fragment );
			if ( '' !== trim( $owned_md ) ) {
				$parts[] = $owned_md;
			}
		}

		while ( isset( $inner_blocks[ $inner_index ] ) ) {
			$child_md = $this->blocks_to_markdown( array( $inner_blocks[ $inner_index ] ), $post );
			if ( '' !== trim( $child_md ) ) {
				$parts[] = $child_md;
			}
			++$inner_index;
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Estimate token count for a string (rough: ~4 chars per token for English).
	 *
	 * @param string $text The text to estimate.
	 * @return int Estimated token count.
	 */
	public static function estimate_tokens( $text ) {
		return (int) ceil( strlen( $text ) / 4 );
	}
}

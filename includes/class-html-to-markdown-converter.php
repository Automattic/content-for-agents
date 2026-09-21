<?php

/**
 * HTML-to-Markdown converter.
 *
 * This is a local copy of the converter developed in the WordPress/ai
 * Markdown Feeds experiment (PR #194):
 *
 * @see https://github.com/WordPress/ai/pull/194
 *
 * Upstream source:
 * includes/Experiments/Markdown_Feeds/HTML_To_Markdown_Converter.php
 * at commit 3504700a6cec86dce5280683a58baf405cef3099
 *
 * PR #194 closed without merging. Retain this tested derivative for now.
 * dmsnell/html-to-md is a candidate replacement, but currently has no tagged
 * release and documents escaping, table and image/link limitations. Evaluate
 * it against the supported content before changing conversion behavior.
 *
 * @see https://github.com/dmsnell/html-to-md
 *
 * This is intentionally small and conservative: it focuses on producing a
 * readable Markdown representation of typical WordPress post content without
 * introducing external parsing dependencies. Uses WordPress core's HTML API
 * (WP_HTML_Processor, with fallback to WP_HTML_Tag_Processor).
 *
 * @package Content_For_Agents
 */

declare( strict_types=1 );

namespace Content_For_Agents;

use WP_HTML_Processor;
use WP_HTML_Tag_Processor;

/**
 * Converts HTML fragments into Markdown.
 *
 * @package Content_For_Agents
 */
final class HTML_To_Markdown_Converter {

	/**
	 * Converts HTML to Markdown.
	 *
	 * @param string $html HTML to convert.
	 * @return string Markdown output.
	 */
	public function convert( string $html ): string {
		$processor = $this->create_processor( $html );
		if ( ! $processor ) {
			return trim( wp_strip_all_tags( $html ) );
		}

		$markdown = $this->convert_with_processor( $processor );

		if (
			$processor instanceof WP_HTML_Processor
			&& WP_HTML_Processor::ERROR_UNSUPPORTED === $processor->get_last_error()
			&& class_exists( WP_HTML_Tag_Processor::class )
		) {
			$markdown = $this->convert_with_processor( new WP_HTML_Tag_Processor( $html ) );
		}

		return trim( $this->cleanup( $markdown ) );
	}

	/**
	 * Creates the best available HTML processor for conversion.
	 *
	 * Uses the HTML Processor in fragment mode when available, and falls back
	 * to the Tag Processor for broader tag tolerance.
	 *
	 * @param string $html HTML string.
	 * @return WP_HTML_Tag_Processor|WP_HTML_Processor|null Processor instance.
	 */
	private function create_processor( string $html ) {
		$processor = null;

		if ( class_exists( WP_HTML_Processor::class ) ) {
			$processor = WP_HTML_Processor::create_fragment( $html );

			if ( ! $processor ) {
				$processor = new WP_HTML_Processor( $html );
			}
		} elseif ( class_exists( WP_HTML_Tag_Processor::class ) ) {
			$processor = new WP_HTML_Tag_Processor( $html );
		}

		return $processor;
	}

	/**
	 * Converts HTML into Markdown using a provided HTML API processor.
	 *
	 * @param WP_HTML_Tag_Processor|WP_HTML_Processor $processor Processor instance.
	 * @return string Markdown output.
	 */
	private function convert_with_processor( $processor ): string {
		$document        = $this->create_context();
		$cell            = null;
		$table_depth     = 0;
		$current_row     = array();
		$is_header_row   = false;
		$header_row_done = false;
		$hidden_stack    = array();

		while ( $processor->next_token() ) {
			$token_name = $processor->get_token_name();
			$is_tag     = '#tag' === $processor->get_token_type();

			if ( ! empty( $hidden_stack ) ) {
				// The Tag Processor exposes source tokens rather than repaired HTML
				// structure, so balance its tags until the hidden element closes.
				if ( $is_tag && $token_name ) {
					if ( $processor->is_tag_closer() ) {
						$matching_index = array_search( $token_name, array_reverse( $hidden_stack, true ), true );
						if ( false !== $matching_index ) {
							$hidden_stack = array_slice( $hidden_stack, 0, $matching_index );
						}
					} elseif ( $this->element_expects_closer( $processor, $token_name ) ) {
						$hidden_stack[] = $token_name;
					}
				}
				continue;
			}

			if (
				$is_tag
				&& $token_name
				&& ! $processor->is_tag_closer()
				&& (
					in_array( $token_name, array( 'SCRIPT', 'STYLE' ), true )
					|| 'true' === strtolower( trim( (string) $processor->get_attribute( 'aria-hidden' ) ) )
				)
			) {
				if ( $processor instanceof WP_HTML_Processor ) {
					$this->skip_processor_element( $processor );
				} elseif ( $this->element_expects_closer( $processor, $token_name ) ) {
					$hidden_stack[] = $token_name;
				}
				continue;
			}

			$is_closer = $processor->is_tag_closer();

			if ( 'TABLE' === $token_name ) {
				if ( ! $is_closer ) {
					if ( 0 === $table_depth ) {
						$this->ensure_blank_line( $document['output'], $document['at_line_start'], $document['blockquote_depth'] );
					} elseif ( null !== $cell ) {
						$this->append_newline( $cell['output'], $cell['at_line_start'] );
					}
					++$table_depth;
				} elseif ( 1 < $table_depth ) {
					--$table_depth;
					if ( null !== $cell ) {
						$this->append_newline( $cell['output'], $cell['at_line_start'] );
					}
				} elseif ( 1 === $table_depth ) {
					$this->close_table_cell( $cell, $current_row );
					$this->emit_table_row( $document, $current_row, $is_header_row, $header_row_done );
					$table_depth     = 0;
					$header_row_done = false;
					$this->ensure_blank_line( $document['output'], $document['at_line_start'], $document['blockquote_depth'] );
				}
				continue;
			}

			$is_table_structure = in_array( $token_name, array( 'THEAD', 'TBODY', 'TFOOT', 'TR', 'TH', 'TD' ), true );
			if ( 1 < $table_depth && $is_table_structure ) {
				// Flatten nested tables into the outer cell. Nested cell and row
				// boundaries become line boundaries in that cell.
				if ( $is_closer && null !== $cell && in_array( $token_name, array( 'TH', 'TD', 'TR' ), true ) ) {
					$this->append_newline( $cell['output'], $cell['at_line_start'] );
				}
				continue;
			}

			if ( 1 === $table_depth && 'TR' === $token_name ) {
				if ( $is_closer ) {
					$this->close_table_cell( $cell, $current_row );
					$this->emit_table_row( $document, $current_row, $is_header_row, $header_row_done );
				}
				continue;
			}

			if ( 1 === $table_depth && ( 'TH' === $token_name || 'TD' === $token_name ) ) {
				if ( ! $is_closer ) {
					$this->close_table_cell( $cell, $current_row );
					$cell = $this->create_context();
					if ( 'TH' === $token_name ) {
						$is_header_row = true;
					}
					// Colspan and rowspan are intentionally unsupported. Each TH or
					// TD produces exactly one Markdown cell.
				} else {
					$this->close_table_cell( $cell, $current_row );
				}
				continue;
			}

			if ( 1 === $table_depth && $is_table_structure ) {
				// THEAD, TBODY, and TFOOT only group rows.
				continue;
			}

			if ( 0 < $table_depth && null === $cell ) {
				// Ignore whitespace and unsupported content outside table cells.
				continue;
			}

			if ( null !== $cell ) {
				$this->convert_token( $processor, $token_name, $cell );
			} else {
				$this->convert_token( $processor, $token_name, $document );
			}
		}

		return $document['output'];
	}

	/**
	 * Creates an isolated Markdown conversion context.
	 *
	 * @return array Conversion context.
	 */
	private function create_context(): array {
		return array(
			'output'           => '',
			'at_line_start'    => true,
			'blockquote_depth' => 0,
			'in_pre'           => false,
			'link_stack'       => array(),
			'list_stack'       => array(),
			'media_stack'      => array(),
		);
	}

	/**
	 * Converts a non-table-structural token into a context.
	 *
	 * @param WP_HTML_Tag_Processor|WP_HTML_Processor $processor  Processor instance.
	 * @param string|null                            $token_name Current token name.
	 * @param array                                  $context    Conversion context (by reference).
	 */
	private function convert_token( $processor, ?string $token_name, array &$context ): void {
		if ( '#text' === $token_name ) {
			$this->append_text(
				$context['output'],
				(string) $processor->get_modifiable_text(),
				$context['at_line_start'],
				$context['blockquote_depth'],
				$context['in_pre']
			);
			return;
		}

		// Skip script/style tokens entirely.
		if ( 'SCRIPT' === $token_name || 'STYLE' === $token_name ) {
			return;
		}

		$is_closer = $processor->is_tag_closer();

		if ( 'BR' === $token_name ) {
			$this->append_newline( $context['output'], $context['at_line_start'] );
			return;
		}

		if ( 'HR' === $token_name && ! $is_closer ) {
			$this->ensure_blank_line( $context['output'], $context['at_line_start'], $context['blockquote_depth'] );
			$this->append_line( $context['output'], '---', $context['at_line_start'], $context['blockquote_depth'] );
			$this->ensure_blank_line( $context['output'], $context['at_line_start'], $context['blockquote_depth'] );
			return;
		}

		if ( ( 'P' === $token_name || 'DIV' === $token_name ) && $is_closer ) {
			if ( ! $context['in_pre'] ) {
				$this->ensure_blank_line( $context['output'], $context['at_line_start'], $context['blockquote_depth'] );
			}
			return;
		}

		if ( 'BLOCKQUOTE' === $token_name ) {
			if ( $is_closer ) {
				$context['blockquote_depth'] = max( 0, $context['blockquote_depth'] - 1 );
				$this->ensure_blank_line( $context['output'], $context['at_line_start'], $context['blockquote_depth'] );
			} else {
				$this->ensure_blank_line( $context['output'], $context['at_line_start'], $context['blockquote_depth'] );
				++$context['blockquote_depth'];
			}
			return;
		}

		if ( 'PRE' === $token_name ) {
			if ( $is_closer ) {
				if ( ! $context['at_line_start'] ) {
					$this->append_newline( $context['output'], $context['at_line_start'] );
				}
				$this->append_line( $context['output'], '```', $context['at_line_start'], $context['blockquote_depth'] );
				$this->ensure_blank_line( $context['output'], $context['at_line_start'], $context['blockquote_depth'] );
				$context['in_pre'] = false;
			} else {
				$this->ensure_blank_line( $context['output'], $context['at_line_start'], $context['blockquote_depth'] );
				$this->append_line( $context['output'], '```', $context['at_line_start'], $context['blockquote_depth'] );
				$context['in_pre'] = true;
			}
			return;
		}

		if ( 'CODE' === $token_name && ! $context['in_pre'] ) {
			$this->append_text( $context['output'], '`', $context['at_line_start'], $context['blockquote_depth'], true );
			return;
		}

		if ( 'STRONG' === $token_name || 'B' === $token_name ) {
			$this->append_text( $context['output'], '**', $context['at_line_start'], $context['blockquote_depth'], true );
			return;
		}

		if ( 'EM' === $token_name || 'I' === $token_name ) {
			$this->append_text( $context['output'], '*', $context['at_line_start'], $context['blockquote_depth'], true );
			return;
		}

		if ( 'A' === $token_name ) {
			if ( $is_closer ) {
				$href = array_pop( $context['link_stack'] );
				$this->append_text( $context['output'], $href ? '](' . $href . ')' : ']', $context['at_line_start'], $context['blockquote_depth'], true );
			} else {
				$context['link_stack'][] = (string) $processor->get_attribute( 'href' );
				$this->append_text( $context['output'], '[', $context['at_line_start'], $context['blockquote_depth'], true );
			}
			return;
		}

		if ( 'IMG' === $token_name && ! $is_closer ) {
			$src = (string) $processor->get_attribute( 'src' );
			if ( '' !== $src ) {
				$alt = (string) $processor->get_attribute( 'alt' );
				$this->append_text( $context['output'], '![' . $alt . '](' . $src . ')', $context['at_line_start'], $context['blockquote_depth'], true );
			}
			return;
		}

		if ( 'AUDIO' === $token_name || 'VIDEO' === $token_name ) {
			if ( $is_closer ) {
				array_pop( $context['media_stack'] );
			} else {
				$src = (string) $processor->get_attribute( 'src' );

				$context['media_stack'][] = array(
					'type'    => $token_name,
					'has_src' => '' !== $src,
				);
				if ( '' !== $src ) {
					$this->append_text( $context['output'], '[' . ucfirst( strtolower( $token_name ) ) . '](' . $src . ')', $context['at_line_start'], $context['blockquote_depth'], true );
				}
			}
			return;
		}

		if ( 'SOURCE' === $token_name && ! $is_closer && ! empty( $context['media_stack'] ) ) {
			$index = count( $context['media_stack'] ) - 1;
			$src   = (string) $processor->get_attribute( 'src' );
			if ( ! $context['media_stack'][ $index ]['has_src'] && '' !== $src ) {
				$this->append_text( $context['output'], '[' . ucfirst( strtolower( $context['media_stack'][ $index ]['type'] ) ) . '](' . $src . ')', $context['at_line_start'], $context['blockquote_depth'], true );
				$context['media_stack'][ $index ]['has_src'] = true;
			}
			return;
		}

		if ( 'FIGURE' === $token_name ) {
			$this->ensure_blank_line( $context['output'], $context['at_line_start'], $context['blockquote_depth'] );
			return;
		}

		if ( 'FIGCAPTION' === $token_name ) {
			if ( ! $is_closer ) {
				$this->ensure_newline( $context['output'], $context['at_line_start'] );
				$this->append_text( $context['output'], '*', $context['at_line_start'], $context['blockquote_depth'], true );
			} else {
				$context['output'] .= '*';
			}
			return;
		}

		if ( 'CITE' === $token_name ) {
			if ( ! $is_closer ) {
				if ( 0 < $context['blockquote_depth'] ) {
					$this->ensure_newline( $context['output'], $context['at_line_start'] );
				}
				$this->append_text( $context['output'], '— ', $context['at_line_start'], $context['blockquote_depth'], true );
			}
			return;
		}

		if ( 'UL' === $token_name || 'OL' === $token_name ) {
			if ( $is_closer ) {
				array_pop( $context['list_stack'] );
				$this->ensure_blank_line( $context['output'], $context['at_line_start'], $context['blockquote_depth'] );
			} else {
				$context['list_stack'][] = array(
					'type'  => $token_name,
					'index' => 0,
				);
				$this->ensure_blank_line( $context['output'], $context['at_line_start'], $context['blockquote_depth'] );
			}
			return;
		}

		if ( 'LI' === $token_name && ! $is_closer ) {
			$this->ensure_newline( $context['output'], $context['at_line_start'] );

			$depth  = count( $context['list_stack'] );
			$indent = str_repeat( '  ', max( 0, $depth - 1 ) );
			$marker = '-';
			if ( 0 < $depth && 'OL' === $context['list_stack'][ $depth - 1 ]['type'] ) {
				++$context['list_stack'][ $depth - 1 ]['index'];
				$marker = (string) $context['list_stack'][ $depth - 1 ]['index'] . '.';
			}

			$this->append_text( $context['output'], $indent . $marker . ' ', $context['at_line_start'], $context['blockquote_depth'], true );
			return;
		}

		if ( ! $token_name || ! preg_match( '/^H([1-6])$/', $token_name, $matches ) ) {
			return;
		}

		if ( $is_closer ) {
			$this->ensure_blank_line( $context['output'], $context['at_line_start'], $context['blockquote_depth'] );
		} else {
			$this->ensure_blank_line( $context['output'], $context['at_line_start'], $context['blockquote_depth'] );
			$this->append_text( $context['output'], str_repeat( '#', (int) $matches[1] ) . ' ', $context['at_line_start'], $context['blockquote_depth'], true );
		}
	}

	/**
	 * Finalizes the active table cell into the current row.
	 *
	 * @param array|null $cell        Active cell context (by reference).
	 * @param array      $current_row Current table row (by reference).
	 */
	private function close_table_cell( ?array &$cell, array &$current_row ): void {
		if ( null === $cell ) {
			return;
		}

		$current_row[] = $this->format_table_cell( $cell['output'] );
		$cell          = null;
	}

	/**
	 * Emits the current table row and an optional header separator.
	 *
	 * @param array $document        Document conversion context (by reference).
	 * @param array $current_row     Current table row (by reference).
	 * @param bool  $is_header_row   Whether the row contains header cells (by reference).
	 * @param bool  $header_row_done Whether a header row has been emitted (by reference).
	 */
	private function emit_table_row( array &$document, array &$current_row, bool &$is_header_row, bool &$header_row_done ): void {
		if ( ! empty( $current_row ) ) {
			$this->append_line(
				$document['output'],
				'| ' . implode( ' | ', $current_row ) . ' |',
				$document['at_line_start'],
				$document['blockquote_depth']
			);

			if ( $is_header_row && ! $header_row_done ) {
				$this->append_line(
					$document['output'],
					'|' . str_repeat( ' --- |', count( $current_row ) ),
					$document['at_line_start'],
					$document['blockquote_depth']
				);
				$header_row_done = true;
			}
		}

		$current_row   = array();
		$is_header_row = false;
	}

	/**
	 * Formats buffered table-cell content for a Markdown row.
	 *
	 * @param string $cell Buffered table-cell content.
	 * @return string Formatted table-cell content.
	 */
	private function format_table_cell( string $cell ): string {
		$cell = trim( $this->cleanup( $cell ) );
		$cell = (string) preg_replace( '/[ \t]*\n+[ \t]*/', '<br>', $cell );

		return str_replace( '|', '\\|', $cell );
	}

	/**
	 * Advances the HTML Processor past the current element and its descendants.
	 *
	 * @param WP_HTML_Processor $processor Processor positioned on an opening tag.
	 */
	private function skip_processor_element( WP_HTML_Processor $processor ): void {
		if ( ! $processor->expects_closer() ) {
			return;
		}

		$depth = $processor->get_current_depth();
		while ( $processor->next_token() && $depth <= $processor->get_current_depth() ) {
			continue;
		}
	}

	/**
	 * Whether the current element expects a closing token.
	 *
	 * @param WP_HTML_Tag_Processor|WP_HTML_Processor $processor Processor instance.
	 * @param string                                  $token_name Current token name.
	 * @return bool Whether the element expects a closing token.
	 */
	private function element_expects_closer( $processor, string $token_name ): bool {
		if ( $processor->has_self_closing_flag() ) {
			return false;
		}

		return ! in_array(
			$token_name,
			array( 'AREA', 'BASE', 'BR', 'COL', 'EMBED', 'HR', 'IMG', 'INPUT', 'LINK', 'META', 'PARAM', 'SOURCE', 'TRACK', 'WBR' ),
			true
		);
	}

	/**
	 * Appends plain text to the Markdown output.
	 *
	 * @param string $markdown            Markdown buffer (by reference).
	 * @param string $text                Text to append.
	 * @param bool   $at_line_start       Whether output is at the start of a line (by reference).
	 * @param int    $blockquote_depth    Current blockquote depth.
	 * @param bool   $preserve_whitespace Whether to preserve whitespace.
	 */
	private function append_text( string &$markdown, string $text, bool &$at_line_start, int $blockquote_depth, bool $preserve_whitespace = false ): void {
		if ( '' === $text ) {
			return;
		}

		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		if ( ! $preserve_whitespace ) {
			$text = preg_replace( '/\s+/u', ' ', $text );
			if ( 0 < $blockquote_depth && $at_line_start && '' === trim( $text ) ) {
				return;
			}
		}

		if ( $at_line_start && 0 < $blockquote_depth ) {
			$markdown .= str_repeat( '> ', $blockquote_depth );
		}

		if ( $preserve_whitespace && 0 < $blockquote_depth ) {
			// Prefix each code line, but leave a final newline for the next token.
			$prefix = str_repeat( '> ', $blockquote_depth );
			$text   = str_replace( "\n", "\n" . $prefix, $text );
			if ( str_ends_with( $text, "\n" . $prefix ) ) {
				$text = substr( $text, 0, -strlen( $prefix ) );
			}
		}

		$markdown     .= $text;
		$at_line_start = false;
		if ( 0 < $blockquote_depth && str_ends_with( $text, "\n" ) ) {
			$at_line_start = true;
		}
	}

	/**
	 * Appends a newline.
	 *
	 * @param string $markdown      Markdown buffer (by reference).
	 * @param bool   $at_line_start Whether output is at the start of a line (by reference).
	 */
	private function append_newline( string &$markdown, bool &$at_line_start ): void {
		$markdown     .= "\n";
		$at_line_start = true;
	}

	/**
	 * Appends a full line and ensures the buffer ends at a new line.
	 *
	 * @param string $markdown         Markdown buffer (by reference).
	 * @param string $line             Line content.
	 * @param bool   $at_line_start    Whether output is at the start of a line (by reference).
	 * @param int    $blockquote_depth Current blockquote depth.
	 */
	private function append_line( string &$markdown, string $line, bool &$at_line_start, int $blockquote_depth ): void {
		$this->ensure_newline( $markdown, $at_line_start );
		$this->append_text( $markdown, $line, $at_line_start, $blockquote_depth, true );
		$this->append_newline( $markdown, $at_line_start );
	}

	/**
	 * Ensures output starts on a new line.
	 *
	 * @param string $markdown      Markdown buffer (by reference).
	 * @param bool   $at_line_start Whether output is at the start of a line (by reference).
	 */
	private function ensure_newline( string &$markdown, bool &$at_line_start ): void {
		if ( $at_line_start ) {
			return;
		}

		$this->append_newline( $markdown, $at_line_start );
	}

	/**
	 * Ensures output ends with a blank line.
	 *
	 * @param string $markdown      Markdown buffer (by reference).
	 * @param bool   $at_line_start Whether output is at the start of a line (by reference).
	 * @param int    $blockquote_depth Current blockquote depth.
	 */
	private function ensure_blank_line( string &$markdown, bool &$at_line_start, int $blockquote_depth = 0 ): void {
		if ( $blockquote_depth > 0 ) {
			$separator = "\n" . str_repeat( '> ', $blockquote_depth ) . "\n";
			if ( ! str_ends_with( $markdown, $separator ) ) {
				$markdown = rtrim( $markdown, "\n" ) . $separator;
			}
			$at_line_start = true;
			return;
		}

		$markdown      = rtrim( $markdown, "\n" );
		$markdown     .= "\n\n";
		$at_line_start = true;
	}

	/**
	 * Cleans up excessive whitespace and newlines.
	 *
	 * @param string $markdown Markdown buffer.
	 * @return string Cleaned buffer.
	 */
	private function cleanup( string $markdown ): string {
		// Remove trailing whitespace from lines.
		$markdown = preg_replace( "/[ \t]+\n/", "\n", $markdown );
		// Remove leading whitespace from lines (except in code blocks).
		$markdown = preg_replace( "/\n[ \t]+(?!\s*```)/", "\n", (string) $markdown );
		// Collapse multiple blank lines.
		$markdown = preg_replace( "/\n{3,}/", "\n\n", (string) $markdown );
		return (string) $markdown;
	}
}

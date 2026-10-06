<?php

/**
 * Markdown output and whitespace formatting.
 *
 * @package Content_For_Agents
 */

declare( strict_types=1 );

namespace Content_For_Agents;

/**
 * Applies line, quote, and whitespace rules to caller-owned output state.
 */
final class Markdown_Output_Writer {
	/** Separate visible block content and discard separators around empty blocks. */
	public function block_boundary( Markdown_Conversion_Context $context, bool $is_closer, bool $separate_on_open = false ): void {
		if ( ! $is_closer ) {
			$start = $this->container_snapshot( $context );
			if ( $context->at_line_start && $this->has_active_list_item( $context ) ) {
				$this->append_list_continuation( $context );
			} elseif ( $separate_on_open && ! $context->at_line_start && ! $this->has_active_list_item( $context ) ) {
				$this->ensure_blank_line( $context->output, $context->at_line_start, $context->blockquote_depth );
			}
			$start['content_offset'] = strlen( $context->output );
			$context->block_starts[] = $start;
			return;
		}
		$start = array_pop( $context->block_starts );
		if ( null !== $start && ! $this->has_content_since( $context, $start['content_offset'] ) ) {
			$this->restore_container( $context, $start );
			return;
		}
		if ( ! $context->in_pre ) {
			$this->ensure_blank_line( $context->output, $context->at_line_start, $context->blockquote_depth );
		}
	}

	/** Start or finish a quote container. */
	public function quote_boundary( Markdown_Conversion_Context $context, bool $is_closer ): void {
		if ( $is_closer ) {
			$context->blockquote_depth = max( 0, $context->blockquote_depth - 1 );
			$start                     = array_pop( $context->quote_starts );
			if ( null !== $start && ! $this->has_content_since( $context, $start['content_offset'] ) ) {
				$this->restore_container( $context, $start );
				return;
			}
		} else {
			$start = $this->container_snapshot( $context );
			$this->ensure_blank_line( $context->output, $context->at_line_start, $context->blockquote_depth );
			$start['content_offset'] = strlen( $context->output );
			$context->quote_starts[] = $start;
			++$context->blockquote_depth;
			return;
		}
		$this->ensure_blank_line( $context->output, $context->at_line_start, $context->blockquote_depth );
	}

	/** Start or finish a list container. */
	public function list_boundary( Markdown_Conversion_Context $context, string $type, bool $is_closer, ?int $start = null ): void {
		if ( $is_closer ) {
			$list = array_pop( $context->list_stack );
			if ( null !== $list && ! $this->has_content_since( $context, $list['content_offset'] ) ) {
				$this->restore_container( $context, $list );
				return;
			}
		} else {
			$list  = array(
				'type'                => $type,
				'index'               => null !== $start ? $start - 1 : 0,
				'continuation_indent' => null,
			);
			$list += $this->container_snapshot( $context );
			$this->ensure_blank_line( $context->output, $context->at_line_start, $context->blockquote_depth );
			$list['content_offset'] = strlen( $context->output );
			$context->list_stack[]  = $list;
			return;
		}
		$this->ensure_blank_line( $context->output, $context->at_line_start, $context->blockquote_depth );
	}

	/** Write a list item marker or finish its continuation. */
	public function list_item_boundary( Markdown_Conversion_Context $context, bool $is_closer ): void {
		$depth = count( $context->list_stack );
		if ( $is_closer ) {
			if ( 0 < $depth ) {
				$indent = $context->list_stack[ $depth - 1 ]['continuation_indent'];
				if ( null !== $indent && str_ends_with( $context->output, "\n" . $indent ) ) {
					$context->output        = substr( $context->output, 0, -strlen( $indent ) );
					$context->at_line_start = true;
				}
				$context->list_stack[ $depth - 1 ]['continuation_indent'] = null;
			}
			return;
		}

		$this->ensure_newline( $context->output, $context->at_line_start );
		$indent = 1 < $depth && null !== $context->list_stack[ $depth - 2 ]['continuation_indent']
			? $context->list_stack[ $depth - 2 ]['continuation_indent']
			: str_repeat( '  ', max( 0, $depth - 1 ) );
		$marker = '-';
		if ( 0 < $depth && 'OL' === $context->list_stack[ $depth - 1 ]['type'] ) {
			++$context->list_stack[ $depth - 1 ]['index'];
			$marker = (string) $context->list_stack[ $depth - 1 ]['index'] . '.';
		}
		$this->append_text( $context->output, $indent . $marker . ' ', $context->at_line_start, $context->blockquote_depth, true );
		if ( 0 < $depth ) {
			$context->list_stack[ $depth - 1 ]['continuation_indent'] = str_repeat( ' ', strlen( $indent . $marker . ' ' ) );
		}
	}

	/** Whether the current list has an open item. */
	public function has_active_list_item( Markdown_Conversion_Context $context ): bool {
		if ( empty( $context->list_stack ) ) {
			return false;
		}
		$last = $context->list_stack[ count( $context->list_stack ) - 1 ];
		return null !== $last['continuation_indent'];
	}

	/** Continue a new line inside the current list item. */
	public function append_list_continuation( Markdown_Conversion_Context $context ): void {
		$last = $context->list_stack[ count( $context->list_stack ) - 1 ];
		$this->ensure_newline( $context->output, $context->at_line_start );
		$this->append_text( $context->output, $last['continuation_indent'], $context->at_line_start, $context->blockquote_depth, true );
	}

	/** Whether output after an offset contains more than whitespace or quote prefixes. */
	private function has_content_since( Markdown_Conversion_Context $context, int $offset ): bool {
		$body = substr( $context->output, $offset );
		$body = (string) preg_replace( '/^(?:>[ \t]*)*$/m', '', $body );
		return '' !== trim( $body );
	}

	/** Save the trailing bytes that a container's opening separator may replace. */
	private function container_snapshot( Markdown_Conversion_Context $context ): array {
		$rollback_offset = strlen( $context->output );
		while ( 0 < $rollback_offset && str_contains( " \t\n", $context->output[ $rollback_offset - 1 ] ) ) {
			--$rollback_offset;
		}
		return array(
			'rollback_offset' => $rollback_offset,
			'rollback_suffix' => substr( $context->output, $rollback_offset ),
			'content_offset'  => 0,
			'at_line_start'   => $context->at_line_start,
		);
	}

	/** Remove an empty container's separator and restore the prior line state. */
	private function restore_container( Markdown_Conversion_Context $context, array $snapshot ): void {
		$context->output        = substr( $context->output, 0, $snapshot['rollback_offset'] ) . $snapshot['rollback_suffix'];
		$context->at_line_start = $snapshot['at_line_start'];
	}
	/**
	 * Append text to Markdown output.
	 *
	 * @param string $markdown            Markdown buffer (by reference).
	 * @param string $text                Text to append.
	 * @param bool   $at_line_start       Whether output is at the start of a line (by reference).
	 * @param int    $blockquote_depth    Current blockquote depth.
	 * @param bool   $preserve_whitespace Whether to preserve whitespace.
	 */
	public function append_text( string &$markdown, string $text, bool &$at_line_start, int $blockquote_depth, bool $preserve_whitespace = false ): void {
		if ( '' === $text ) {
			return;
		}

		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		if ( ! $preserve_whitespace ) {
			$text = preg_replace( '/\s+/u', ' ', $text );
			if ( $at_line_start ) {
				$text = ltrim( (string) $text );
			}
			if ( '' === $text ) {
				return;
			}
		}

		if ( $at_line_start && 0 < $blockquote_depth ) {
			$markdown .= str_repeat( '> ', $blockquote_depth );
		}

		if ( $preserve_whitespace && 0 < $blockquote_depth ) {
			$prefix = str_repeat( '> ', $blockquote_depth );
			$text   = str_replace( "\n", "\n" . $prefix, $text );
			if ( str_ends_with( $text, "\n" . $prefix ) ) {
				$text = substr( $text, 0, -strlen( $prefix ) );
			}
		}

		$markdown     .= $text;
		$at_line_start = str_ends_with( $text, "\n" );
	}

	/**
	 * Append a newline.
	 *
	 * @param string $markdown            Markdown buffer (by reference).
	 * @param bool   $at_line_start       Whether output is at the start of a line (by reference).
	 * @param bool   $preserve_whitespace Whether code whitespace must be preserved.
	 */
	public function append_newline( string &$markdown, bool &$at_line_start, bool $preserve_whitespace = false ): void {
		if ( ! $preserve_whitespace ) {
			$markdown = rtrim( $markdown, " \t" );
			if ( str_ends_with( $markdown, "\n\n" ) ) {
				$at_line_start = true;
				return;
			}
		}
		$markdown     .= "\n";
		$at_line_start = true;
	}

	/**
	 * Append a full line.
	 *
	 * @param string $markdown         Markdown buffer (by reference).
	 * @param string $line             Line content.
	 * @param bool   $at_line_start    Whether output is at the start of a line (by reference).
	 * @param int    $blockquote_depth Current blockquote depth.
	 */
	public function append_line( string &$markdown, string $line, bool &$at_line_start, int $blockquote_depth ): void {
		$this->ensure_newline( $markdown, $at_line_start );
		$this->append_text( $markdown, $line, $at_line_start, $blockquote_depth, true );
		$this->append_newline( $markdown, $at_line_start );
	}

	/**
	 * Ensure output starts on a new line.
	 *
	 * @param string $markdown      Markdown buffer (by reference).
	 * @param bool   $at_line_start Whether output is at the start of a line (by reference).
	 */
	public function ensure_newline( string &$markdown, bool &$at_line_start ): void {
		if ( $at_line_start ) {
			return;
		}

		$this->append_newline( $markdown, $at_line_start );
	}

	/**
	 * Ensure output ends with a blank line.
	 *
	 * @param string $markdown         Markdown buffer (by reference).
	 * @param bool   $at_line_start    Whether output is at the start of a line (by reference).
	 * @param int    $blockquote_depth Current blockquote depth.
	 */
	public function ensure_blank_line( string &$markdown, bool &$at_line_start, int $blockquote_depth = 0 ): void {
		$markdown = rtrim( $markdown, " \t" );
		if ( $blockquote_depth > 0 ) {
			$separator = "\n" . rtrim( str_repeat( '> ', $blockquote_depth ) ) . "\n";
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
	 * Format one run of inline code with a safe delimiter.
	 *
	 * @param string $content Code text.
	 * @return string Markdown code span.
	 */
	public function inline_code_span( string $content ): string {
		$content       = str_replace( array( "\r\n", "\r", "\n" ), ' ', $content );
		$fence         = $this->code_delimiter( $content, 1 );
		$needs_padding = str_starts_with( $content, '`' ) || str_ends_with( $content, '`' )
			|| ( str_starts_with( $content, ' ' ) && str_ends_with( $content, ' ' ) && '' !== trim( $content ) );
		$space         = $needs_padding ? ' ' : '';
		return $fence . $space . $content . $space . $fence;
	}

	/**
	 * Escape plain HTML attribute text used as a Markdown link label.
	 *
	 * @param string $text Visible label text.
	 * @return string Markdown-safe label.
	 */
	public function escape_markdown_link_text( string $text ): string {
		$text = (string) preg_replace( '/\s+/u', ' ', $text );
		return str_replace(
			array( '\\', '[', ']', '*', '_', '~', '`' ),
			array( '\\\\', '\\[', '\\]', '\\*', '\\_', '\\~', '\\`' ),
			$text
		);
	}

	/**
	 * Choose a code delimiter longer than any backtick run in decoded content.
	 *
	 * @param string $content Decoded code content.
	 * @param int    $minimum Minimum delimiter length.
	 * @return string Backtick delimiter.
	 */
	public function code_delimiter( string $content, int $minimum ): string {
		$longest_run = 0;
		if ( str_contains( $content, '`' ) ) {
			preg_match_all( '/`+/', $content, $runs );
			foreach ( $runs[0] as $run ) {
				$longest_run = max( $longest_run, strlen( $run ) );
			}
		}
		return str_repeat( '`', max( $minimum, $longest_run + 1 ) );
	}

	/** Format an optional HTML title attribute for a Markdown image. */
	public function format_markdown_title( string $title ): string {
		$title = trim( (string) preg_replace( '/\s+/u', ' ', $title ) );
		if ( '' === $title ) {
			return '';
		}
		return ' "' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $title ) . '"';
	}

	/**
	 * Escape characters that would terminate a Markdown link destination.
	 *
	 * @param string $url URL from an HTML attribute.
	 * @return string Markdown-safe destination.
	 */
	public function escape_markdown_destination( string $url ): string {
		$url = (string) preg_replace_callback(
			'/[\s<>]/u',
			static function ( array $matches ): string {
				return rawurlencode( $matches[0] );
			},
			$url
		);
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $url );
	}
}

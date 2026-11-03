<?php

/**
 * Markdown conversion logger integration tests.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents\Tests\Integration;

use Content_For_Agents\Block_Markdown_Registry;
use Content_For_Agents\Markdown_Converter;

/** Verify conversion failures log diagnostics, including repeated failures. */
class MarkdownConversionLoggerTest extends \WP_UnitTestCase {
	/**
	 * Repeated returned errors and exceptions are logged on each conversion.
	 *
	 * @dataProvider failure_provider
	 * @param bool $throws Whether the callback throws instead of returning WP_Error.
	 */
	public function test_each_failure_logs_its_diagnostic( bool $throws ): void {
		$block_name = 'content-for-agents/logger-test';
		$attempts   = 0;
		Block_Markdown_Registry::register(
			$block_name,
			static function () use ( $throws, &$attempts ) {
				++$attempts;
				if ( $throws ) {
					throw new \RuntimeException( "Sensitive detail\nSecond line " . $attempts );
				}
				$error = new \WP_Error( 'private_code', "Sensitive detail\nSecond line " . $attempts, 'Secret metadata' );
				$error->add( 'second_code', 'Another sensitive detail' );
				return $error;
			}
		);
		$content  = '<!-- wp:content-for-agents/logger-test --><p>Fallback.</p><!-- /wp:content-for-agents/logger-test -->';
		$post_id  = self::factory()->post->create( array( 'post_content' => $content ) );
		$log_file = tempnam( get_temp_dir(), 'cfa-log-' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_tempnam -- Test-only local log file.
		$this->assertNotFalse( $log_file );
		$previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test-only capture of diagnostics.
		try {
			$converter = new Markdown_Converter();
			$this->assertSame( 'Fallback.', $converter->post_to_markdown( $post_id ) );
			$this->assertSame( 'Fallback.', $converter->post_to_markdown( $post_id ) );
			$logged = file_get_contents( $log_file ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local test file.
			$this->assertSame( 2, substr_count( $logged, 'content-for-agents: Markdown conversion failed;' ) );
			$this->assertSame( 2, substr_count( $logged, "\n" ) );
			$this->assertStringContainsString( 'site_id=' . get_current_blog_id() . '; post_id=' . $post_id . '; block=' . $block_name, $logged );
			$this->assertStringContainsString( 'Sensitive detail Second line 1', $logged );
			$this->assertStringContainsString( 'Sensitive detail Second line 2', $logged );
			$this->assertStringNotContainsString( 'Secret metadata', $logged );
			$this->assertStringNotContainsString( 'Another sensitive detail', $logged );
		} finally {
			ini_set( 'error_log', $previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore test configuration.
			unlink( $log_file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Remove test-only log file.
		}
	}

	/** @return array Callback failure modes. */
	public function failure_provider(): array {
		return array(
			'wp-error'  => array( false ),
			'exception' => array( true ),
		);
	}
}

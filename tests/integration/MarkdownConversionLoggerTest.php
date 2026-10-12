<?php

/**
 * Markdown conversion logger integration tests.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents\Tests\Integration;

use Content_For_Agents\Block_Markdown_Registry;
use Content_For_Agents\Markdown_Conversion_Logger;
use Content_For_Agents\Markdown_Converter;

/**
 * Verify conversion diagnostics without leaking error details.
 */
class MarkdownConversionLoggerTest extends \WP_UnitTestCase {

	/**
	 * Returned WP_Error details stay private and repeat failures are deduplicated.
	 */
	public function test_wp_error_is_logged_once_without_its_message(): void {
		$block_name = 'content-for-agents/logger-test';
		Block_Markdown_Registry::register( $block_name, static fn(): \WP_Error => new \WP_Error( 'private_code', 'Sensitive error detail' ) );
		$post_id  = self::factory()->post->create(
			array( 'post_content' => '<!-- wp:content-for-agents/logger-test /-->' )
		);
		$site_id  = get_current_blog_id();
		$log_file = tempnam( get_temp_dir(), 'cfa-log-' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_tempnam -- Test-only local log file.
		$this->assertNotFalse( $log_file );

		$previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test-only capture of diagnostic output.
		try {
			$error = ( new Markdown_Converter() )->post_to_markdown( $post_id );
			$this->assertWPError( $error );
			Markdown_Conversion_Logger::log( $error, $post_id );
			Markdown_Conversion_Logger::log( $error, $post_id );

			$logged = file_get_contents( $log_file ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local test file.
			$this->assertSame( 1, substr_count( $logged, 'Markdown conversion failed' ) );
			$this->assertStringContainsString( 'site_id=' . $site_id . '; post_id=' . $post_id, $logged );
			$this->assertStringContainsString( 'block=' . $block_name . '; error=WP_Error', $logged );
			$this->assertStringNotContainsString( 'Sensitive error detail', $logged );
			$this->assertStringContainsString( 'code=private_code', $logged );
		} finally {
			ini_set( 'error_log', $previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore test configuration.
			wp_cache_delete( 'conversion_error_' . $site_id . '_' . $post_id, Markdown_Conversion_Logger::CACHE_GROUP );
			unlink( $log_file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Remove test-only local log file.
		}
	}

	/**
	 * A normalized exception logs its type and location without its message.
	 */
	public function test_wrapped_exception_is_logged_without_its_message(): void {
		$post_id  = self::factory()->post->create();
		$site_id  = get_current_blog_id();
		$log_file = tempnam( get_temp_dir(), 'cfa-log-' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_tempnam -- Test-only local log file.
		$this->assertNotFalse( $log_file );
		$error        = new \WP_Error( 'content_for_agents_response_exception', 'Document assembly failed.', new \RuntimeException( 'Sensitive response detail' ) );
		$previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test-only capture of diagnostic output.
		try {
			Markdown_Conversion_Logger::log( $error, $post_id );
			$logged = file_get_contents( $log_file ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local test file.
			$this->assertStringContainsString( 'error=RuntimeException; code=content_for_agents_response_exception', $logged );
			$this->assertStringContainsString( 'MarkdownConversionLoggerTest.php:', $logged );
			$this->assertStringNotContainsString( 'Sensitive response detail', $logged );
		} finally {
			ini_set( 'error_log', $previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore test configuration.
			wp_cache_delete( 'conversion_error_' . $site_id . '_' . $post_id, Markdown_Conversion_Logger::CACHE_GROUP );
			unlink( $log_file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Remove test-only local log file.
		}
	}
}

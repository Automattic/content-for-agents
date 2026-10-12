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
 * Verify conversion messages and repeat-failure suppression.
 */
class MarkdownConversionLoggerTest extends \WP_UnitTestCase {

	/**
	 * The first underlying message is logged while repeats are deduplicated.
	 */
	public function test_underlying_wp_error_message_is_logged_once(): void {
		$block_name     = 'content-for-agents/logger-test';
		$callback_error = new \WP_Error( 'private_code', 'Sensitive error detail', array( 'step' => 'first' ) );
		$callback_error->add_data( array( 'step' => 'second' ), 'private_code' );
		$callback_error->add( 'second_code', 'Another sensitive detail' );
		Block_Markdown_Registry::register( $block_name, static fn(): \WP_Error => $callback_error );
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
			$this->assertSame( 1, substr_count( $logged, 'content-for-agents: Markdown conversion failed;' ) );
			$this->assertStringContainsString( 'site_id=' . $site_id . '; post_id=' . $post_id . '; block=' . $block_name . '; message=Sensitive error detail', $logged );
			$this->assertStringNotContainsString( 'private_code', $logged );
			$this->assertStringNotContainsString( 'Another sensitive detail', $logged );
			$this->assertStringNotContainsString( '[step]', $logged );
		} finally {
			ini_set( 'error_log', $previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore test configuration.
			wp_cache_delete( 'conversion_error_' . $site_id . '_' . $post_id, Markdown_Conversion_Logger::CACHE_GROUP );
			unlink( $log_file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Remove test-only local log file.
		}
	}

	/**
	 * An exception logs the WP_Error message without its data.
	 */
	public function test_wrapped_exception_logs_only_its_error_message(): void {
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
			$this->assertStringContainsString( 'post_id=' . $post_id . '; message=Document assembly failed.', $logged );
			$this->assertStringNotContainsString( 'Sensitive response detail', $logged );
			$this->assertStringNotContainsString( 'RuntimeException', $logged );
		} finally {
			ini_set( 'error_log', $previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore test configuration.
			wp_cache_delete( 'conversion_error_' . $site_id . '_' . $post_id, Markdown_Conversion_Logger::CACHE_GROUP );
			unlink( $log_file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Remove test-only local log file.
		}
	}

	/**
	 * A returned filter error is logged with its message.
	 */
	public function test_filter_error_logs_its_message(): void {
		$block_name = 'content-for-agents/filter-logger-test';
		Block_Markdown_Registry::register( $block_name, static fn(): string => 'Callback succeeded.' );
		$filter   = static fn(): \WP_Error => new \WP_Error( 'filter_failure', 'Sensitive filter detail' );
		add_filter( 'content_for_agents_block_' . $block_name, $filter );
		$post_id  = self::factory()->post->create(
			array( 'post_content' => '<!-- wp:content-for-agents/filter-logger-test /-->' )
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

			$logged = file_get_contents( $log_file ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local test file.
			$this->assertStringContainsString( '; block=' . $block_name . '; message=Sensitive filter detail', $logged );
			$this->assertStringNotContainsString( 'filter_failure', $logged );
		} finally {
			remove_filter( 'content_for_agents_block_' . $block_name, $filter );
			ini_set( 'error_log', $previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore test configuration.
			wp_cache_delete( 'conversion_error_' . $site_id . '_' . $post_id, Markdown_Conversion_Logger::CACHE_GROUP );
			unlink( $log_file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Remove test-only local log file.
		}
	}
}

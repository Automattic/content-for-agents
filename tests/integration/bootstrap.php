<?php

/**
 * WordPress integration test bootstrap.
 *
 * @package Content_For_Agents
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$content_for_agents_tests_dir = (string) getenv( 'WP_TESTS_DIR' );
if ( '' === $content_for_agents_tests_dir ) {
	$content_for_agents_tests_dir = (string) getenv( 'WP_PHPUNIT__DIR' );
}

if ( ! file_exists( $content_for_agents_tests_dir . '/includes/functions.php' ) ) {
	echo 'Could not find the WordPress test library.' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

$content_for_agents_polyfills = __DIR__ . '/../../vendor/yoast/phpunit-polyfills';
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) && is_dir( $content_for_agents_polyfills ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Required by the WordPress test library.
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $content_for_agents_polyfills );
}

require_once $content_for_agents_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require __DIR__ . '/../../content-for-agents.php';

		add_action(
			'content_for_agents_register_block_callbacks',
			static function (): void {
				\Content_For_Agents\Block_Markdown_Registry::register(
					'content-for-agents/test-block',
					static function ( array $block ): string {
						return '**' . ( $block['attrs']['text'] ?? '' ) . '**';
					}
				);
			}
		);
	}
);

require $content_for_agents_tests_dir . '/includes/bootstrap.php';

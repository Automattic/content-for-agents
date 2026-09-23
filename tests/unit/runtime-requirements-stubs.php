<?php
/**
 * WordPress function doubles for runtime requirement tests.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents;

// Test doubles intentionally shadow WordPress functions in the plugin namespace.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function is_php_version_compatible( $required ): bool {
	$GLOBALS['content_for_agents_required_php'] = $required;
	return $GLOBALS['content_for_agents_test_php_compatible'];
}

function is_wp_version_compatible( $required ): bool {
	$GLOBALS['content_for_agents_required_wp'] = $required;
	return $GLOBALS['content_for_agents_test_wp_compatible'];
}

function plugin_dir_path( $file ): string {
	return dirname( $file ) . '/';
}

// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Matches the WordPress function signature.
function plugin_dir_url( $file ): string {
	return 'https://example.test/plugins/content-for-agents/';
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

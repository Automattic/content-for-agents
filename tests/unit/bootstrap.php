<?php

// Test doubles intentionally use WordPress function names.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

require_once __DIR__ . '/../../vendor/autoload.php';

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['content_for_agents_test_actions'][] = compact( 'hook', 'callback', 'priority', 'accepted_args' );
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['content_for_agents_test_filters'][] = compact( 'hook', 'callback', 'priority', 'accepted_args' );
}

require_once __DIR__ . '/../../includes/class-loader.php';
require_once __DIR__ . '/../../includes/class-content-negotiation.php';

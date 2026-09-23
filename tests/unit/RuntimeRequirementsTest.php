<?php
/**
 * Runtime requirement tests for direct plugin loading.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/runtime-requirements-stubs.php';

/**
 * Checks that application-loader includes obey the plugin requirements.
 */
class RuntimeRequirementsTest extends TestCase {
	/**
	 * @dataProvider runtime_provider
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param bool $php_compatible Whether PHP meets the minimum.
	 * @param bool $wp_compatible  Whether WordPress meets the minimum.
	 * @param bool $loads          Whether the plugin should load.
	 */
	public function test_runtime_gate( bool $php_compatible, bool $wp_compatible, bool $loads ): void {
		define( 'ABSPATH', '/tmp/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress bootstrap sentinel.
		$GLOBALS['content_for_agents_test_php_compatible'] = $php_compatible;
		$GLOBALS['content_for_agents_test_wp_compatible']  = $wp_compatible;
		$GLOBALS['content_for_agents_test_actions']        = array();

		require __DIR__ . '/../../content-for-agents.php';

		$hooks = array_column( $GLOBALS['content_for_agents_test_actions'], 'hook' );
		$this->assertSame( $loads, defined( 'CONTENT_FOR_AGENTS_LOADED' ) );
		$this->assertSame( '8.2', $GLOBALS['content_for_agents_required_php'] );
		if ( $php_compatible ) {
			$this->assertSame( '7.0', $GLOBALS['content_for_agents_required_wp'] );
		}
		$this->assertSame(
			$loads ? array( 'plugins_loaded' ) : array( 'admin_notices', 'network_admin_notices' ),
			$hooks
		);
	}

	/**
	 * Runtime combinations cover each failed requirement and the happy path.
	 *
	 * @return array<string, array{bool, bool, bool}>
	 */
	public function runtime_provider(): array {
		return array(
			'old PHP'       => array( false, true, false ),
			'old WordPress' => array( true, false, false ),
			'supported'     => array( true, true, true ),
		);
	}
}

<?php

/**
 * Loader unit tests.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents\Tests\Unit;

use Content_For_Agents\Loader;
use PHPUnit\Framework\TestCase;

/**
 * Verifies queued hooks are registered with WordPress.
 */
class LoaderTest extends TestCase {

	/**
	 * Clear recorded hook calls between tests.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['content_for_agents_test_actions'] = array();
		$GLOBALS['content_for_agents_test_filters'] = array();
	}

	/**
	 * The loader preserves hook registration arguments.
	 */
	public function test_run_registers_queued_actions_and_filters(): void {
		$component = $this->getMockBuilder( \stdClass::class )
			->addMethods( array( 'handle_action', 'handle_filter' ) )
			->getMock();
		$loader    = new Loader();

		$loader->add_action( 'content_for_agents_test_action', $component, 'handle_action', 7, 2 );
		$loader->add_filter( 'content_for_agents_test_filter', $component, 'handle_filter', 12, 3 );
		$loader->run();

		$this->assertSame( 'content_for_agents_test_action', $GLOBALS['content_for_agents_test_actions'][0]['hook'] );
		$this->assertSame( array( $component, 'handle_action' ), $GLOBALS['content_for_agents_test_actions'][0]['callback'] );
		$this->assertSame( 7, $GLOBALS['content_for_agents_test_actions'][0]['priority'] );
		$this->assertSame( 2, $GLOBALS['content_for_agents_test_actions'][0]['accepted_args'] );

		$this->assertSame( 'content_for_agents_test_filter', $GLOBALS['content_for_agents_test_filters'][0]['hook'] );
		$this->assertSame( array( $component, 'handle_filter' ), $GLOBALS['content_for_agents_test_filters'][0]['callback'] );
		$this->assertSame( 12, $GLOBALS['content_for_agents_test_filters'][0]['priority'] );
		$this->assertSame( 3, $GLOBALS['content_for_agents_test_filters'][0]['accepted_args'] );
	}
}

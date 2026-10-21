<?php
/**
 * Test helper for sampled MC Stats.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents\Tests\Integration;

use Content_For_Agents\Markdown_Stats;

/** Controls sampling for deterministic tests. */
class Testable_Markdown_Stats extends Markdown_Stats {
	private bool $sampled;

	public function __construct( bool $sampled, callable $sender ) {
		parent::__construct( $sender );
		$this->sampled = $sampled;
	}

	protected function is_sampled(): bool {
		return $this->sampled;
	}
}

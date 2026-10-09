<?php
/**
 * VIP telemetry client for Content for Agents.
 *
 * @package Content_For_Agents
 */

namespace Content_For_Agents;

/**
 * Records events through the VIP mu-plugin library when it is available.
 */
class Telemetry_Service {
	private const EVENT_PREFIX = 'contentforagents_';
	private const VIP_CLIENT   = '\\Automattic\\VIP\\Telemetry\\Telemetry';

	/**
	 * VIP client, or an injected recording client for tests.
	 *
	 * @var object|null
	 */
	private ?object $client;

	/**
	 * @param object|null $client Recording client for tests.
	 */
	public function __construct( ?object $client = null ) {
		$this->client = $client;
	}

	/**
	 * Queue an event without allowing telemetry failures to interrupt publishing.
	 *
	 * @param string $event      Event name without the prefix.
	 * @param array  $properties Event properties.
	 */
	public function record_event( string $event, array $properties ): void {
		try {
			if ( null === $this->client && class_exists( self::VIP_CLIENT ) ) {
				$class        = self::VIP_CLIENT;
				$this->client = new $class(
					self::EVENT_PREFIX,
					array( 'plugin_version' => CONTENT_FOR_AGENTS_VERSION )
				);
			}

			if ( null !== $this->client ) {
				$this->client->record_event( $event, $properties );
			}
		} catch ( \Throwable ) {
			// Telemetry must not prevent a post from being published.
			return;
		}
	}
}

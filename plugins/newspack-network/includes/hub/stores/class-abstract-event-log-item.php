<?php
/**
 * Newspack Abtract Event Log Item
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub\Stores;

use Newspack_Network\Hub\Node;

/**
 * Abtract Event Log Item
 */
abstract class Abstract_Event_Log_Item {

	/**
	 * The ID of the Event Log Item.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * The Node associated with the Event Log Item.
	 *
	 * @var Node
	 */
	private $node;

	/**
	 * The email associated with the Event Log Item.
	 *
	 * @var string
	 */
	private $email;

	/**
	 * The action associated with the Event Log Item.
	 *
	 * @var string
	 */
	private $action_name;

	/**
	 * The data associated with the Event Log Item.
	 *
	 * @var string
	 */
	private $data;

	/**
	 * The timestamp associated with the Event Log Item.
	 *
	 * @var string
	 */
	private $timestamp;

	/**
	 * Creates an instance of an Event Log Item
	 *
	 * Do not create an object of this class directly. Instead, use the Event_Log Store class.
	 *
	 * @param array $args {
	 *      Array of arguments for creating an Event Log Item.
	 *
	 *      @type int    $id          The ID of the Event Log Item.
	 *      @type Node   $node        The Node associated with the Event Log Item.
	 *      @type string $email       The email associated with the Event Log Item.
	 *      @type string $action_name The action_name associated with the Event Log Item.
	 *      @type string $data        The data associated with the Event Log Item.
	 *      @type string $timestamp   The timestamp associated with the Event Log Item.
	 * }
	 */
	public function __construct( $args ) {
		$this->id          = (int) $args['id'] ?? 0;
		$this->node        = $args['node'] instanceof Node ? $args['node'] : new Node( 0 );
		$this->email       = $args['email'] ?? '';
		$this->action_name = $args['action_name'] ?? '';
		$this->data        = $args['data'] ?? '';
		$this->timestamp   = $args['timestamp'] ?? '';
	}

	/**
	 * Gets the summary of the event. A human readable string summarizing what the event is about.
	 *
	 * @return string
	 */
	abstract public function get_summary();

	/**
	 * Gets the ID of the Event Log Item.
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Gets the Node associated with the Event Log Item.
	 *
	 * @return Node
	 */
	public function get_node() {
		return $this->node;
	}

	/**
	 * Returns the Item's Node Url
	 *
	 * If the Node is not found, returns the local URL.
	 *
	 * @return ?string
	 */
	public function get_node_url() {
		$node = $this->get_node();
		if ( empty( $node->get_id() ) ) {
			return get_bloginfo( 'url' );
		}
		return $node->get_url();
	}

	/**
	 * Gets the Node ID associated with the Event Log Item.
	 *
	 * @return int
	 */
	public function get_node_id() {
		return $this->node->get_id();
	}

	/**
	 *
	 * Gets the email associated with the Event Log Item.
	 *
	 * @return string
	 */
	public function get_email() {
		return $this->email;
	}

	/**
	 * Gets the action_name associated with the Event Log Item.
	 *
	 * @return string
	 */
	public function get_action_name() {
		return $this->action_name;
	}

	/**
	 * Gets the raw data associated with the Event Log Item in json format.
	 *
	 * @return string
	 */
	public function get_raw_data() {
		return $this->data;
	}

	/**
	 * Gets the data associated with the Event Log Item.
	 *
	 * @return array
	 */
	public function get_data() {
		return json_decode( $this->data );
	}

	/**
	 * Gets the timestamp associated with the Event Log Item.
	 *
	 * @return string
	 */
	public function get_timestamp() {
		return $this->timestamp;
	}
}

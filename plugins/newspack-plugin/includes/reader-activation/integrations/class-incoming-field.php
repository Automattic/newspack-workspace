<?php
/**
 * Integrations Incoming Field class
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Incoming Field Class.
 *
 * Represents a field from an external integration.
 */
class Incoming_Field {

	/**
	 * The key for this field.
	 *
	 * @var string
	 */
	protected $key;

	/**
	 * Human-readable name.
	 *
	 * @var string
	 */
	protected $name = '';

	/**
	 * Value type: 'string' or 'boolean'.
	 *
	 * @var string
	 */
	protected $value_type = 'string';

	/**
	 * Matching function: 'default', 'list__in', 'list__not_in', 'range'.
	 *
	 * @var string
	 */
	protected $matching_function = 'default';

	/**
	 * Options for selection UI.
	 *
	 * @var array
	 */
	protected $options = [];

	/**
	 * Help text for the UI.
	 *
	 * @var string
	 */
	protected $description = '';

	/**
	 * Whether to register as a content gate access rule.
	 *
	 * @var bool
	 */
	protected $is_access_rule = false;

	/**
	 * Whether to register as a popups segmentation criterion.
	 *
	 * @var bool
	 */
	protected $is_segment_criteria = false;

	/**
	 * Custom callback for access rule evaluation.
	 *
	 * If set, takes precedence over the matching_function for access rules.
	 * Receives ( $user_id, $args ) and should return bool.
	 *
	 * @var callable|null
	 */
	protected $access_rule_callback = null;

	/**
	 * Raw field data from the integration API.
	 *
	 * @var array
	 */
	protected $raw_data = [];

	/**
	 * Constructor.
	 *
	 * @param string $key      The key for this field.
	 * @param array  $raw_data Optional. Raw field data from the integration API.
	 */
	public function __construct( $key, $raw_data = [] ) {
		$this->key      = $key;
		$this->name     = $key;
		$this->raw_data = $raw_data;
	}

	/**
	 * Get the field key.
	 *
	 * @return string
	 */
	public function get_key() {
		return $this->key;
	}

	/**
	 * Get the human-readable name.
	 *
	 * @return string
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Set the human-readable name.
	 *
	 * @param string $name The name.
	 * @return self
	 */
	public function set_name( $name ) {
		$this->name = $name;
		return $this;
	}

	/**
	 * Get the value type.
	 *
	 * @return string
	 */
	public function get_value_type() {
		return $this->value_type;
	}

	/**
	 * Set the value type.
	 *
	 * @param string $value_type 'string' or 'boolean'.
	 * @return self
	 */
	public function set_value_type( $value_type ) {
		$this->value_type = $value_type;
		return $this;
	}

	/**
	 * Get the matching function.
	 *
	 * @return string
	 */
	public function get_matching_function() {
		return $this->matching_function;
	}

	/**
	 * Set the matching function.
	 *
	 * @param string $matching_function One of 'default', 'list__in', 'list__not_in'.
	 *                                  Note: 'range' is supported in evaluation but has no UI yet.
	 * @return self
	 */
	public function set_matching_function( $matching_function ) {
		$this->matching_function = $matching_function;
		return $this;
	}

	/**
	 * Get the options.
	 *
	 * @return array
	 */
	public function get_options() {
		return $this->options;
	}

	/**
	 * Set the options.
	 *
	 * @param array $options Array of [ 'value' => ..., 'label' => ... ].
	 * @return self
	 */
	public function set_options( $options ) {
		$this->options = $options;
		return $this;
	}

	/**
	 * Get the description.
	 *
	 * @return string
	 */
	public function get_description() {
		return $this->description;
	}

	/**
	 * Set the description.
	 *
	 * @param string $description Help text for the UI.
	 * @return self
	 */
	public function set_description( $description ) {
		$this->description = $description;
		return $this;
	}

	/**
	 * Whether this field is registered as an access rule.
	 *
	 * @return bool
	 */
	public function is_access_rule() {
		return $this->is_access_rule;
	}

	/**
	 * Set whether to register as an access rule.
	 *
	 * @param bool $is_access_rule Whether to register.
	 * @return self
	 */
	public function set_is_access_rule( $is_access_rule ) {
		$this->is_access_rule = (bool) $is_access_rule;
		return $this;
	}

	/**
	 * Whether this field is registered as a segmentation criterion.
	 *
	 * @return bool
	 */
	public function is_segment_criteria() {
		return $this->is_segment_criteria;
	}

	/**
	 * Set whether to register as a segmentation criterion.
	 *
	 * @param bool $is_segment_criteria Whether to register.
	 * @return self
	 */
	public function set_is_segment_criteria( $is_segment_criteria ) {
		$this->is_segment_criteria = (bool) $is_segment_criteria;
		return $this;
	}

	/**
	 * Get the custom access rule callback.
	 *
	 * @return callable|null
	 */
	public function get_access_rule_callback() {
		return $this->access_rule_callback;
	}

	/**
	 * Set a custom callback for access rule evaluation.
	 *
	 * The callback receives ( $user_id, $args ) and should return bool.
	 * When set, it takes precedence over the matching_function for access rules.
	 *
	 * @param callable $callback The callback.
	 * @return self
	 */
	public function set_access_rule_callback( $callback ) {
		$this->access_rule_callback = $callback;
		return $this;
	}

	/**
	 * Get the raw field data from the integration API.
	 *
	 * @return array
	 */
	public function get_raw_data() {
		return $this->raw_data;
	}
}

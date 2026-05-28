<?php
/**
 * Newspack Newsletters Send List
 *
 * @package Newspack
 */

namespace Newspack\Newsletters;

use Newspack_Newsletters;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Class used to represent one Send_List.
 *
 * A Send List is any entity that can be used as a collection of contacts
 * that you can send a Newsletter campaign to via a connected ESP.
 * The difference between Send_List and Subscription_List objects is that Send Lists
 * are all the existing lists, audiences, tags, groups, segments, etc in the provider.
 * We don't store Send Lists locally. Some Send Lists are also Subscription Lists, but not all are.
 *
 * A Send_List can be either a top-level list or a sublist.
 * A sublist can optionally specify a parent list ID (required for Mailchimp).
 */
class Send_List {
	/**
	 * The configuration associated with this Send_List.
	 *
	 * @var array
	 */
	protected $config = [];

	/**
	 * Initializes a new Send_List.
	 *
	 * @param array $config {
	 *   The configuration for the Send_list.
	 *
	 *   @type string $provider    The slug of the ESP for which this list or sublist originates (required).
	 *   @type string $type        The type of list. Can be either 'list' or 'sublist'. If the latter, must specify a `parent` property (required).
	 *   @type string $entity_type The type of entity this list or sublist is associated with in the ESP. Controls which ESP API endpoints to use to fetch/update (required).
	 *   @type string $id          The ID of the list or sublist in the ESP (required).
	 *   @type string $parent_id   If this is a sublist of another list, the ID of the parent list.
	 *   @type string $name        The name of the list or sublist as identified in the ESP (required).
	 *   @type string $local_name  If the list is also a Subscription List, it could have a locally edited name.
	 *   @type int    $count       If available, the number of contacts associated with this list or sublist.
	 *   @type string $edit_link   If available, the URL to edit this list or sublist in the ESP.
	 *   @type string $label       The label to use for autocomplete inputs. If not set, will be generated from other properties.
	 *   @type string $value       The value to use for autocomplete inputs. If not passed, will match `id`.
	 * }
	 *
	 * @throws \InvalidArgumentException In case the Send_List object can't be built from the given config data.
	 */
	public function __construct( $config ) {
		$errors = new WP_Error();
		$schema = self::get_config_schema();

		foreach ( $schema['properties'] as $key => $property ) {
			// If the property is required but not set, throw an error.
			if ( ! empty( $property['required'] ) && ! isset( $config[ $key ] ) ) {
				$errors->add( 'newspack_newsletters_send_list_invalid_config', __( 'Missing required property: ', 'newspack-newsletters' ) . $key );
				continue;
			}

			// No need to continue if an optional key isn't set.
			if ( ! isset( $config[ $key ] ) ) {
				continue;
			}

			// Set the property.
			$result = $this->set( $key, $config[ $key ] );
			if ( \is_wp_error( $result ) ) {
				$result->export_to( $errors );
			}
		}

		// Throw an exception if there are errors.
		if ( $errors->has_errors() ) {
			throw new \InvalidArgumentException( esc_html( __( 'Error creating send list: ', 'newspack-newsletters' ) . implode( '. ', $errors->get_error_messages() ) ) );
		}
	}

	/**
	 * Get the config data schema for a single Send_List.
	 * See __construct() method docblock for details.
	 *
	 * @return array
	 */
	public static function get_config_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'provider'    => [
					'name'     => 'provider',
					'type'     => 'string',
					'required' => true,
					'enum'     => Newspack_Newsletters::get_supported_providers(),
				],
				'type'        => [
					'name'     => 'type',
					'type'     => 'string',
					'required' => true,
					'enum'     => [
						'list',
						'sublist',
					],
				],
				'entity_type' => [
					'name'     => 'entity_type',
					'type'     => 'string',
					'required' => true,
				],
				'id'          => [
					'name'     => 'id',
					'type'     => 'string',
					'required' => true,
				],
				'value'       => [
					'name'     => 'value',
					'type'     => 'string',
					'required' => false,
				],
				'name'        => [
					'name'     => 'name',
					'type'     => 'string',
					'required' => true,
				],
				'label'       => [
					'name'     => 'label',
					'type'     => 'string',
					'required' => false,
				],
				'local_name'  => [
					'name'     => 'local_name',
					'type'     => 'string',
					'required' => false,
				],
				'count'       => [
					'name'     => 'count',
					'type'     => 'integer',
					'required' => false,
				],
				'parent_id'   => [
					'name'     => 'parent_id',
					'type'     => 'string',
					'required' => false,
				],
				'edit_link'   => [
					'name'     => 'edit_link',
					'type'     => 'string',
					'required' => false,
				],
			],
		];
	}

	/**
	 * Helper method to get a specific property's value.
	 * Not for public use. Use specific property getters instead.
	 *
	 * @param string $key The property to get.
	 *
	 * @return mixed|WP_Error The property value or null if not set/not a supported property.
	 */
	private function get( $key ) {
		$value  = $this->config[ $key ] ?? null;
		$schema = $this->get_config_schema();
		if ( ! empty( $schema['properties'][ $key ]['required'] ) && empty( $this->config[ $key ] ) ) {
			return new WP_Error( 'newspack_newsletters_send_list_missing_required_property_' . $key, __( 'Could not get required property: ', 'newspack-newsletters' ) . $key );
		}

		return $value;
	}

	/**
	 * Helper method to set a property's value.
	 * Not for public use. Use specific property setters instead.
	 *
	 * @param string $key The property to get.
	 * @param mixed  $value The value to set.
	 *
	 * @return mixed|WP_Error The property value or WP_Error if not set/not a supported property.
	 */
	private function set( $key, $value ) {
		$schema = $this->get_config_schema();
		if ( ! isset( $schema['properties'][ $key ] ) ) {
			return new WP_Error( 'newspack_newsletters_send_list_invalid_property_' . $key, __( 'Could not set invalid property: ', 'newspack-newsletters' ) . $key );
		}

		// If the passed value isn't in the enum, throw an error.
		$property = $schema['properties'][ $key ];
		if ( isset( $property['enum'] ) && ! in_array( $value, $property['enum'], true ) ) {
			return new WP_Error( 'newspack_newsletters_send_list_invalid_property_value_' . $key, __( 'Invalid value for property: ', 'newspack-newsletters' ) . $key );
		}

		// Cast value to the expected type.
		settype( $value, $property['type'] );

		$this->config[ $key ] = $value;
		return $this->get( $key );
	}

	/**
	 * Get the provider for this Send_List.
	 */
	public function get_provider() {
		return $this->get( 'provider' );
	}

	/**
	 * Get the ID for this Send_List.
	 */
	public function get_id() {
		return $this->get( 'id' );
	}

	/**
	 * Get the parent list's ID for this Send_List.
	 */
	public function get_parent_id() {
		return $this->get( 'parent_id' );
	}

	/**
	 * Get the type for this Send_List: list or sublist.
	 */
	public function get_type() {
		return $this->get( 'type' );
	}

	/**
	 * Get the entity type for this Send_List.
	 */
	public function get_entity_type() {
		return $this->get( 'entity_type' );
	}

	/**
	 * Get the name for this Send_List.
	 */
	public function get_name() {
		return $this->get( 'name' );
	}

	/**
	 * Update the name for this Send_List.
	 *
	 * @param string $value The new name.
	 */
	public function set_name( $value ) {
		return $this->set( 'name', $value );
	}

	/**
	 * Get the local name for this Send_List, if the entity is also a Subscription_List.
	 */
	public function get_local_name() {
		return $this->get( 'local_name' );
	}

	/**
	 * Update the local name for this Send_List, if the entity is also a Subscription_List.
	 *
	 * @param string $value The new name.
	 */
	public function set_local_name( $value ) {
		return $this->set( 'local_name', $value );
	}

	/**
	 * Get the contact count for this send list.
	 */
	public function get_count() {
		return $this->get( 'count' );
	}

	/**
	 * Update the contact count for this send list.
	 *
	 * @param string $value The new name.
	 */
	public function set_count( $value ) {
		return $this->set( 'count', $value );
	}

	/**
	 * Get a manually set or dynamic label for autocomplete inputs.
	 * If not passed via __construct(), will be generated from entity_type, name, and count.
	 *
	 * @return string
	 */
	public function get_label() {
		$stored_label = $this->get( 'label' );
		if ( ! empty( $stored_label ) ) {
			return $stored_label;
		}

		$entity_type = '[' . strtoupper( $this->get( 'entity_type' ) ) . ']';
		$count       = $this->get( 'count' );
		$name        = $this->get( 'name' );

		$contact_count = null !== $count ?
			sprintf(
				// Translators: If available, show a contact count alongside the suggested item. %d is the number of contacts in the suggested item.
				_n( '(%s contact)', '(%s contacts)', $count, 'newspack-newsletters' ),
				number_format( $count )
			) : '';

		return trim( "$entity_type $name $contact_count" );
	}

	/**
	 * Get the value for autocomplete inputs.
	 * If not passed via __construct(), defaults to the id.
	 *
	 * @return string
	 */
	public function get_value() {
		return $this->get( 'value' ) ?? $this->get( 'id' );
	}

	/**
	 * Convert the Send_List to an array for use with the REST API.
	 *
	 * @return array
	 */
	public function to_array() {
		$config = $this->config;

		// Ensure label + value properties are set for JS components.
		$config['label'] = $this->get_label();
		$config['value'] = $this->get_value();

		return $config;
	}
}

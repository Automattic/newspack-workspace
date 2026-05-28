<?php
/**
 * ESP integration
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Integrations;

use Newspack\Reader_Activation\Integration;
use Newspack\Reader_Activation\Sync;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation;
use Newspack_Newsletters_Contacts;
use Newspack_Newsletters_Subscription;
use Newspack\Configuration_Managers;

defined( 'ABSPATH' ) || exit;

/**
 * ESP Integration Class.
 *
 * Generic integration for ESPs using Newspack Newsletters plugin.
 */
class ESP extends Integration {
	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'esp',
			__( 'Newsletter ESP', 'newspack-plugin' ),
			__( 'Syncs reader data with your Newspack Newsletters email service provider.', 'newspack-plugin' )
		);
	}

	/**
	 * Whether the ESP integration is ready to sync.
	 *
	 * Mirrors the readiness gate used by get_settings_config() so the configure
	 * UI never advertises a card as set up while the underlying settings call
	 * short-circuits to an empty config.
	 *
	 * @return bool True if an ESP provider is selected and at least one list is active.
	 */
	public function is_set_up() {
		return Reader_Activation::is_esp_configured();
	}

	/**
	 * Get the URL where the user can set up the ESP.
	 *
	 * Delegates to the Newsletters configuration manager so the page slug
	 * lives in one place. Falls back to the same hardcoded URL when the
	 * configuration manager isn't resolvable yet.
	 *
	 * @return string The Newspack Newsletters settings page URL.
	 */
	public function get_setup_url() {
		$newsletters_configuration_manager = Configuration_Managers::configuration_manager_class_for_plugin_slug( 'newspack-newsletters' );
		if ( is_wp_error( $newsletters_configuration_manager ) ) {
			return admin_url( 'edit.php?post_type=newspack_nl_cpt&page=newspack-newsletters' );
		}
		return $newsletters_configuration_manager->get_settings_url();
	}

	/**
	 * Get the plugins this integration depends on, with their install/active status.
	 *
	 * @return array List of associative arrays with keys `slug`, `name`, `is_active`, `is_installed`.
	 */
	public function get_required_plugins() {
		$status = \Newspack\Plugin_Manager::get_managed_plugin_status( 'newspack-newsletters' );
		return [
			[
				'slug'         => 'newspack-newsletters',
				'name'         => __( 'Newspack Newsletters', 'newspack-plugin' ),
				'is_active'    => 'active' === $status,
				'is_installed' => 'uninstalled' !== $status,
			],
		];
	}

	/**
	 * Register the settings fields declared by this integration.
	 *
	 * Returns ALL possible ESP fields unconditionally as static
	 * declarations. No provider check, no API calls. Provider options
	 * are added in get_settings_config().
	 *
	 * @return array Array of settings field declarations.
	 */
	public function register_settings_fields() {
		return [
			[
				'key'         => 'mailchimp_audience_id',
				'type'        => 'select',
				'default'     => '',
				'label'       => __( 'Mailchimp Audience', 'newspack-plugin' ),
				'description' => __( 'Choose an audience to receive reader activity data.', 'newspack-plugin' ),
			],
			[
				'key'         => 'mailchimp_reader_default_status',
				'type'        => 'select',
				'default'     => 'transactional',
				'label'       => __( 'Default reader status', 'newspack-plugin' ),
				'description' => __( 'Choose which Mailchimp status readers should have by default if they are not subscribed to any newsletters.', 'newspack-plugin' ),
				'options'     => [
					[
						'label' => __( 'Transactional/Non-Subscribed', 'newspack-plugin' ),
						'value' => 'transactional',
					],
					[
						'label' => __( 'Subscribed', 'newspack-plugin' ),
						'value' => 'subscribed',
					],
				],
			],
			[
				'key'         => 'active_campaign_master_list',
				'type'        => 'select',
				'default'     => '',
				'label'       => __( 'ActiveCampaign Master List', 'newspack-plugin' ),
				'description' => __( 'Choose a master list to which all registered readers will be added.', 'newspack-plugin' ),
			],
			[
				'key'         => 'constant_contact_list_id',
				'type'        => 'select',
				'default'     => '',
				'label'       => __( 'Constant Contact Master List', 'newspack-plugin' ),
				'description' => __( 'Choose a master list to which all registered readers will be added.', 'newspack-plugin' ),
			],
			[
				'key'         => 'sync_esp_delete',
				'type'        => 'checkbox',
				'default'     => true,
				'label'       => __( 'Sync user account deletion', 'newspack-plugin' ),
				'description' => __( 'When a reader account is deleted, also remove the contact from the ESP.', 'newspack-plugin' ),
			],
		];
	}

	/**
	 * Get settings config with current values, labels, descriptions, and options.
	 *
	 * Filters fields to the active provider and enriches them with
	 * expensive data (API-fetched list options).
	 * Only called when serving the settings UI.
	 *
	 * @return array Array of field declarations with current values.
	 */
	public function get_settings_config() {
		if ( ! Reader_Activation::is_esp_configured() ) {
			return [];
		}
		$provider = $this->get_provider();
		if ( ! $provider ) {
			return [];
		}

		$enriched     = [];
		$config       = parent::get_settings_config();
		$config       = array_combine(
			array_column( $config, 'key' ),
			$config
		);
		$list_options = [
			'options' => $this->get_list_options(),
		];

		switch ( $provider->service ) {
			case 'mailchimp':
				$enriched[] = array_merge(
					$config['mailchimp_audience_id'],
					$list_options
				);
				$enriched[] = $config['mailchimp_reader_default_status'];
				break;
			case 'active_campaign':
				$enriched[] = array_merge(
					$config['active_campaign_master_list'],
					$list_options
				);
				break;
			case 'constant_contact':
				$enriched[] = array_merge(
					$config['constant_contact_list_id'],
					$list_options
				);
				break;
		}
		$enriched[]    = $config['sync_esp_delete'];
		$metadata_keys = array_column( $this->get_metadata_fields(), 'key' );
		foreach ( $config as $field ) {
			if ( in_array( $field['key'], $metadata_keys ) ) {
				$enriched[] = $config[ $field['key'] ];
			}
		}
		return $enriched;
	}

	/**
	 * Get the active ESP provider name.
	 *
	 * @return Newspack_Newsletters_Service_Provider|null The service provider object or null if not available.
	 */
	private function get_provider() {
		if ( class_exists( 'Newspack_Newsletters' ) ) {
			return \Newspack_Newsletters::get_service_provider();
		}
		return null;
	}

	/**
	 * Get list options from the Newsletters API for select fields.
	 *
	 * @return array Array of options with label and value keys.
	 */
	private function get_list_options() {
		if ( ! method_exists( 'Newspack_Newsletters_Subscription', 'get_lists' ) ) {
			return [];
		}

		$lists = Newspack_Newsletters_Subscription::get_lists();
		if ( is_wp_error( $lists ) || ! is_array( $lists ) ) {
			return [];
		}

		$provider = $this->get_provider();

		// For Mailchimp, filter out groups and tags, only include remote lists.
		if ( 'mailchimp' === $provider->service ) {
			$lists = $provider->get_lists( true );
		}

		$options = [
			[
				'label' => __( 'None', 'newspack-plugin' ),
				'value' => '',
			],
		];
		foreach ( $lists as $list ) {
			$options[] = [
				'label' => $list['name'] ?? $list['id'],
				'value' => $list['id'],
			];
		}

		return $options;
	}

	/**
	 * Get the master list ID from integration settings.
	 *
	 * @return string|false The master list ID or false.
	 */
	public function get_master_list_id() {
		$provider = $this->get_provider();
		if ( ! $provider ) {
			return false;
		}
		switch ( $provider->service ) {
			case 'mailchimp':
				$audience_id = $this->get_settings_field_value( 'mailchimp_audience_id' );
				return ! empty( $audience_id ) ? $audience_id : false;
			case 'active_campaign':
				$list_id = $this->get_settings_field_value( 'active_campaign_master_list' );
				return ! empty( $list_id ) ? $list_id : false;
			case 'constant_contact':
				$list_id = $this->get_settings_field_value( 'constant_contact_list_id' );
				return ! empty( $list_id ) ? $list_id : false;
			default:
				return false;
		}
	}

	/**
	 * Get the enabled outgoing metadata fields for the ESP integration.
	 *
	 * Overrides the parent to provide lazy migration from the legacy global
	 * option (Metadata::FIELDS_OPTION) to the per-integration option.
	 *
	 * @return string[] List of enabled field names.
	 */
	public function get_enabled_outgoing_fields() {
		$fields = \get_option( self::OUTGOING_FIELDS_OPTION_PREFIX . $this->id, null );
		if ( null !== $fields && is_array( $fields ) ) {
			return $fields;
		}

		// Migrate from legacy global option.
		$legacy = \get_option( Sync\Metadata::FIELDS_OPTION, null );
		if ( null !== $legacy && is_array( $legacy ) ) {
			$this->update_enabled_outgoing_fields( $legacy );
			return $legacy;
		}

		return Sync\Metadata::get_default_fields();
	}

	/**
	 * Whether contacts can be synced to the ESP.
	 *
	 * @param bool $return_errors Optional. Whether to return a WP_Error object. Default false.
	 *
	 * @return bool|\WP_Error True if contacts can be synced, false otherwise. WP_Error if return_errors is true.
	 */
	public function can_sync( $return_errors = false ) {
		$errors = new \WP_Error();

		/**
		 * Forces ESP sync to be allowed, bypassing all validation checks.
		 * Use with caution - may sync data to production ESP from staging.
		 *
		 * @constant NEWSPACK_FORCE_ALLOW_ESP_SYNC
		 * @type     bool
		 * @default  ESP sync follows normal validation rules
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_FORCE_ALLOW_ESP_SYNC', true );
		 */
		if ( defined( 'NEWSPACK_FORCE_ALLOW_ESP_SYNC' ) && NEWSPACK_FORCE_ALLOW_ESP_SYNC ) {
			return $return_errors ? $errors : true;
		}

		if ( ! class_exists( 'Newspack_Newsletters_Contacts' ) ) {
			$errors->add(
				'newspack_newsletters_contacts_not_found',
				__( 'Newspack Newsletters is not available.', 'newspack-plugin' )
			);
		}

		if ( ! Integrations::is_enabled( $this->get_id() ) ) {
			$errors->add(
				'ras_esp_sync_not_enabled',
				__( 'ESP sync is not enabled.', 'newspack-plugin' )
			);
		}
		if ( ! $this->get_master_list_id() ) {
			$errors->add(
				'ras_esp_master_list_id_not_found',
				__( 'ESP master list ID is not set.', 'newspack-plugin' )
			);
		}

		if ( $return_errors ) {
			return $errors;
		}

		if ( $errors->has_errors() ) {
			return false;
		}

		return true;
	}

	/**
	 * Push contact data to the integration destination.
	 *
	 * @param array      $contact The contact data to push.
	 * @param string     $context Optional. The context of the sync.
	 * @param array|null $existing_contact Optional. Existing contact data if available.
	 *
	 * @return true|\WP_Error True on success or WP_Error on failure.
	 */
	public function push_contact_data( $contact, $context = '', $existing_contact = null ) {
		$can_sync = $this->can_sync( true );
		if ( $can_sync->has_errors() ) {
			return $can_sync;
		}

		$master_list_id = $this->get_master_list_id();

		return Newspack_Newsletters_Contacts::upsert( $contact, $master_list_id, $context, $existing_contact );
	}

	/**
	 * Pull contact data from the ESP for a given user.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return array|\WP_Error Associative array of field_key => value pairs on success, WP_Error on failure.
	 */
	public function pull_contact_data( $user_id ) {
		$can_sync = $this->can_sync( true );
		if ( $can_sync->has_errors() ) {
			return $can_sync;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'user_not_found', __( 'User not found.', 'newspack-plugin' ) );
		}

		$contact_data = Newspack_Newsletters_Subscription::get_contact_data( $user->user_email, true );

		if ( is_wp_error( $contact_data ) ) {
			return $contact_data;
		}

		if ( ! empty( $contact_data['metadata'] ) ) {
			return $contact_data['metadata'];
		}

		return [];
	}

	/**
	 * Test the live connection to the ESP.
	 *
	 * Delegates to Newspack_Newsletters::test_connection() if available.
	 * By the time this runs, can_sync() has already passed.
	 *
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function test_connection() {
		if ( ! method_exists( 'Newspack_Newsletters', 'test_connection' ) ) {
			return true;
		}
		return \Newspack_Newsletters::test_connection();
	}

	/**
	 * Get incoming available contact fields from the integration.
	 *
	 * @return Incoming_Field[]|\WP_Error Array of incoming contact field objects or WP_Error on failure.
	 */
	public function get_available_incoming_fields() {
		if ( ! class_exists( 'Newspack_Newsletters_Contacts' ) ) {
			return new \WP_Error(
				'newspack_newsletters_contacts_not_found',
				__( 'Newspack Newsletters is not available.', 'newspack-plugin' )
			);
		}

		$master_list_id = $this->get_master_list_id();

		if ( empty( $master_list_id ) ) {
			return new \WP_Error(
				'ras_esp_master_list_id_not_found',
				__( 'ESP master list ID is not set.', 'newspack-plugin' )
			);
		}

		$fields = Newspack_Newsletters_Contacts::get_fields( $master_list_id );

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$incoming_fields = [];
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) || ! is_string( $field['key'] ) ) {
				continue;
			}
			$incoming_fields[] = $this->configure_incoming_field( new Incoming_Field( $field['key'], $field ) );
		}
		return $incoming_fields;
	}

	/**
	 * Apply defaults from the provider schema to the Incoming_Field.
	 *
	 * The raw data comes from Newspack_Newsletters_Contacts::get_fields(), which delegates to the
	 * provider's get_contact_fields_for_integrations() method. That method returns a schema whose
	 * `key` is the provider's stable machine identifier (e.g. Mailchimp merge-field `tag`,
	 * ActiveCampaign `perstag`) — used as the Reader_Data attribute and segmentation matching key —
	 * and whose remaining keys mirror Incoming_Field setters so we can configure each field
	 * mechanically.
	 *
	 * @param Incoming_Field $field The field to configure.
	 * @return Incoming_Field
	 */
	protected function configure_incoming_field( $field ) {
		$raw = $field->get_raw_data();
		if ( ! is_array( $raw ) ) {
			return $field;
		}
		if ( isset( $raw['name'] ) && is_scalar( $raw['name'] ) && '' !== (string) $raw['name'] ) {
			$field->set_name( (string) $raw['name'] );
		}
		if ( isset( $raw['value_type'] ) && is_scalar( $raw['value_type'] ) && '' !== (string) $raw['value_type'] ) {
			$field->set_value_type( (string) $raw['value_type'] );
		}
		if ( isset( $raw['matching_function'] ) && is_scalar( $raw['matching_function'] ) && '' !== (string) $raw['matching_function'] ) {
			$field->set_matching_function( (string) $raw['matching_function'] );
		}
		if ( isset( $raw['options'] ) && is_array( $raw['options'] ) ) {
			$field->set_options( $raw['options'] );
		}
		// Description is the only optional-and-clearable scalar field — allow `''` to overwrite
		// (so a provider can drop a previously-set description), unlike name / value_type /
		// matching_function where an empty value would be a malformed schema.
		if ( isset( $raw['description'] ) && is_scalar( $raw['description'] ) ) {
			$field->set_description( (string) $raw['description'] );
		}
		// Symmetric assignment: present-but-falsy can reset the flag, not just present-and-truthy.
		if ( isset( $raw['is_access_rule'] ) ) {
			$field->set_is_access_rule( \wp_validate_boolean( $raw['is_access_rule'] ) );
		}
		if ( isset( $raw['is_segment_criteria'] ) ) {
			$field->set_is_segment_criteria( \wp_validate_boolean( $raw['is_segment_criteria'] ) );
		}
		return $field;
	}
}

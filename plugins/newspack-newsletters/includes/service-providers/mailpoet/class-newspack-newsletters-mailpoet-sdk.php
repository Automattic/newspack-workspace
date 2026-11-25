<?php
/**
 * Service Provider: MailPoet SDK
 *
 * Thin wrapper over MailPoet's `MP('v1')` API. Every call into MailPoet goes
 * through here so the provider itself stays free of MailPoet types, and so the
 * one place we depend on MailPoet's surface is easy to audit when they change it.
 *
 * MailPoet's API throws on failure; this wrapper converts throwables into
 * WP_Error so the provider can follow the ESP interface contract.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * MailPoet SDK.
 */
class Newspack_Newsletters_Mailpoet_SDK {

	/**
	 * The MailPoet API version this wrapper targets.
	 */
	const API_VERSION = 'v1';

	/**
	 * Memoized API instance.
	 *
	 * @var \MailPoet\API\MP\v1\API|null
	 */
	private $api = null;

	/**
	 * Memoized map of Newspack metadata key => MailPoet custom field id (`cf_N`).
	 *
	 * @var array|null
	 */
	private $field_map = null;

	/**
	 * Whether MailPoet is installed and its API is reachable.
	 *
	 * @return boolean
	 */
	public static function is_available() {
		return class_exists( '\MailPoet\API\API' );
	}

	/**
	 * Get the MailPoet API instance.
	 *
	 * @return \MailPoet\API\MP\v1\API|WP_Error
	 */
	public function api() {
		if ( $this->api ) {
			return $this->api;
		}
		if ( ! self::is_available() ) {
			return new WP_Error(
				'newspack_newsletters_mailpoet_unavailable',
				__( 'MailPoet is not installed or activated.', 'newspack-newsletters' )
			);
		}
		try {
			$this->api = \MailPoet\API\API::MP( self::API_VERSION );
		} catch ( \Throwable $e ) {
			return self::error( $e, 'newspack_newsletters_mailpoet_api_unavailable' );
		}
		return $this->api;
	}

	/**
	 * Convert a throwable from MailPoet into a WP_Error.
	 *
	 * @param \Throwable $e    The throwable.
	 * @param string     $code Error code to use.
	 *
	 * @return WP_Error
	 */
	private static function error( $e, $code = 'newspack_newsletters_mailpoet_error' ) {
		return new WP_Error( $code, $e->getMessage() );
	}

	/**
	 * Run a callable against the API, converting throwables to WP_Error.
	 *
	 * @param callable $fn   Receives the API instance.
	 * @param string   $code Error code to use on failure.
	 *
	 * @return mixed|WP_Error
	 */
	private function call( callable $fn, $code = 'newspack_newsletters_mailpoet_error' ) {
		$api = $this->api();
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		try {
			return $fn( $api );
		} catch ( \Throwable $e ) {
			return self::error( $e, $code );
		}
	}

	// Lists.

	/**
	 * Get all MailPoet lists (segments).
	 *
	 * @return array|WP_Error
	 */
	public function get_lists() {
		return $this->call(
			function ( $api ) {
				return $api->getLists();
			},
			'newspack_newsletters_mailpoet_get_lists_failed'
		);
	}

	// Tags.

	/**
	 * Get all tags.
	 *
	 * @return array|WP_Error
	 */
	public function get_tags() {
		return $this->call(
			function ( $api ) {
				return $api->getTags();
			},
			'newspack_newsletters_mailpoet_get_tags_failed'
		);
	}

	/**
	 * Get a tag by ID or name.
	 *
	 * MailPoet throws when the tag is absent; callers that treat "missing" as a
	 * normal outcome should check for WP_Error rather than relying on null.
	 *
	 * @param string|int $id_or_name Tag ID or name.
	 *
	 * @return array|WP_Error
	 */
	public function get_tag( $id_or_name ) {
		return $this->call(
			function ( $api ) use ( $id_or_name ) {
				return $api->getTag( $id_or_name );
			},
			'newspack_newsletters_mailpoet_tag_not_found'
		);
	}

	/**
	 * Create a tag.
	 *
	 * @param string $name Tag name.
	 *
	 * @return array|WP_Error
	 */
	public function add_tag( $name ) {
		return $this->call(
			function ( $api ) use ( $name ) {
				return $api->addTag( [ 'name' => $name ] );
			},
			'newspack_newsletters_mailpoet_add_tag_failed'
		);
	}

	/**
	 * Rename a tag.
	 *
	 * @param string|int $tag_id Tag ID.
	 * @param string     $name   New name.
	 *
	 * @return array|WP_Error
	 */
	public function update_tag( $tag_id, $name ) {
		return $this->call(
			function ( $api ) use ( $tag_id, $name ) {
				return $api->updateTag(
					[
						'id'   => $tag_id,
						'name' => $name,
					]
				);
			},
			'newspack_newsletters_mailpoet_update_tag_failed'
		);
	}

	/**
	 * Add a tag to a subscriber.
	 *
	 * @param string     $email      Subscriber email.
	 * @param string|int $id_or_name Tag ID or name.
	 *
	 * @return array|WP_Error
	 */
	public function tag_subscriber( $email, $id_or_name ) {
		return $this->call(
			function ( $api ) use ( $email, $id_or_name ) {
				return $api->tagSubscriber( $email, $id_or_name );
			},
			'newspack_newsletters_mailpoet_tag_subscriber_failed'
		);
	}

	/**
	 * Remove a tag from a subscriber.
	 *
	 * @param string     $email      Subscriber email.
	 * @param string|int $id_or_name Tag ID or name.
	 *
	 * @return array|WP_Error
	 */
	public function untag_subscriber( $email, $id_or_name ) {
		return $this->call(
			function ( $api ) use ( $email, $id_or_name ) {
				return $api->untagSubscriber( $email, $id_or_name );
			},
			'newspack_newsletters_mailpoet_untag_subscriber_failed'
		);
	}

	// Subscribers.

	/**
	 * Get a subscriber by email.
	 *
	 * The returned array carries `subscriptions` (per-list status) and `tags` in
	 * one payload, so callers needing lists, tags and profile data can share a
	 * single fetch.
	 *
	 * @param string $email Subscriber email.
	 *
	 * @return array|WP_Error
	 */
	public function get_subscriber( $email ) {
		return $this->call(
			function ( $api ) use ( $email ) {
				return $api->getSubscriber( $email );
			},
			'newspack_newsletters_mailpoet_subscriber_not_found'
		);
	}

	/**
	 * Add a subscriber, optionally subscribing them to lists.
	 *
	 * Note that MailPoet decides the resulting subscriber status itself: with
	 * signup confirmation enabled (its default) a subscriber added here lands as
	 * `unconfirmed` regardless of the status requested, and cannot be promoted
	 * afterwards through the public API. That is MailPoet's double opt-in, and it
	 * is equivalent to Mailchimp's `pending`.
	 *
	 * @param array $subscriber Subscriber data (`email`, `first_name`, `last_name`, custom fields).
	 * @param array $list_ids   List IDs to subscribe to.
	 * @param array $options    MailPoet options (`send_confirmation_email`, `schedule_welcome_email`).
	 *
	 * @return array|WP_Error
	 */
	public function add_subscriber( $subscriber, $list_ids = [], $options = [] ) {
		$options = wp_parse_args(
			$options,
			[
				'send_confirmation_email' => false,
				'schedule_welcome_email'  => false,
			]
		);
		return $this->call(
			function ( $api ) use ( $subscriber, $list_ids, $options ) {
				return $api->addSubscriber( $subscriber, $list_ids, $options );
			},
			'newspack_newsletters_mailpoet_add_subscriber_failed'
		);
	}

	/**
	 * Update a subscriber.
	 *
	 * @param string $email      Subscriber email.
	 * @param array  $subscriber Fields to update.
	 *
	 * @return array|WP_Error
	 */
	public function update_subscriber( $email, $subscriber ) {
		return $this->call(
			function ( $api ) use ( $email, $subscriber ) {
				return $api->updateSubscriber( $email, $subscriber );
			},
			'newspack_newsletters_mailpoet_update_subscriber_failed'
		);
	}

	/**
	 * Subscribe a subscriber to lists.
	 *
	 * @param string|int $subscriber_id Subscriber ID.
	 * @param array      $list_ids      List IDs.
	 *
	 * @return array|WP_Error
	 */
	public function subscribe_to_lists( $subscriber_id, $list_ids ) {
		return $this->call(
			function ( $api ) use ( $subscriber_id, $list_ids ) {
				return $api->subscribeToLists(
					$subscriber_id,
					$list_ids,
					[
						'send_confirmation_email' => false,
						'schedule_welcome_email'  => false,
					]
				);
			},
			'newspack_newsletters_mailpoet_subscribe_failed'
		);
	}

	/**
	 * Unsubscribe a subscriber from lists.
	 *
	 * @param string|int $subscriber_id Subscriber ID.
	 * @param array      $list_ids      List IDs.
	 *
	 * @return array|WP_Error
	 */
	public function unsubscribe_from_lists( $subscriber_id, $list_ids ) {
		return $this->call(
			function ( $api ) use ( $subscriber_id, $list_ids ) {
				return $api->unsubscribeFromLists( $subscriber_id, $list_ids );
			},
			'newspack_newsletters_mailpoet_unsubscribe_failed'
		);
	}

	/**
	 * Count subscribers, optionally filtered.
	 *
	 * @param array $filter MailPoet filter args.
	 *
	 * @return int|WP_Error
	 */
	public function get_subscribers_count( $filter = [] ) {
		return $this->call(
			function ( $api ) use ( $filter ) {
				return $api->getSubscribersCount( $filter );
			},
			'newspack_newsletters_mailpoet_count_failed'
		);
	}

	// Custom fields (contact metadata).

	/**
	 * Get MailPoet's subscriber fields, both built-in and custom.
	 *
	 * @return array|WP_Error
	 */
	public function get_subscriber_fields() {
		return $this->call(
			function ( $api ) {
				return $api->getSubscriberFields();
			},
			'newspack_newsletters_mailpoet_get_fields_failed'
		);
	}

	/**
	 * Map Newspack metadata keys onto MailPoet custom field IDs, creating any
	 * field that doesn't exist yet.
	 *
	 * MailPoet silently discards writes to an undeclared field, so metadata must
	 * be resolved to a real `cf_N` id before `update_subscriber()` is called.
	 *
	 * @param array $keys Newspack metadata keys (used as the MailPoet field name).
	 *
	 * @return array|WP_Error Map of metadata key => `cf_N` id.
	 */
	public function resolve_field_ids( $keys ) {
		if ( empty( $keys ) ) {
			return [];
		}

		if ( null === $this->field_map ) {
			$fields = $this->get_subscriber_fields();
			if ( is_wp_error( $fields ) ) {
				return $fields;
			}
			$this->field_map = [];
			foreach ( $fields as $field ) {
				if ( isset( $field['name'], $field['id'] ) ) {
					$this->field_map[ $field['name'] ] = $field['id'];
				}
			}
		}

		$map = [];
		foreach ( $keys as $key ) {
			if ( isset( $this->field_map[ $key ] ) ) {
				$map[ $key ] = $this->field_map[ $key ];
				continue;
			}
			$created = $this->add_subscriber_field( $key );
			if ( is_wp_error( $created ) ) {
				// A field we can't create is metadata we can't sync; skip it rather.
				// than failing the whole contact update.
				continue;
			}
			$this->field_map[ $key ] = $created['id'];
			$map[ $key ]             = $created['id'];
		}
		return $map;
	}

	/**
	 * Create a custom subscriber field.
	 *
	 * @param string $name Field name.
	 * @param string $type Field type.
	 *
	 * @return array|WP_Error
	 */
	public function add_subscriber_field( $name, $type = 'text' ) {
		return $this->call(
			function ( $api ) use ( $name, $type ) {
				return $api->addSubscriberField(
					[
						'name' => $name,
						'type' => $type,
					]
				);
			},
			'newspack_newsletters_mailpoet_add_field_failed'
		);
	}

	/**
	 * Whether MailPoet's signup confirmation (double opt-in) is enabled.
	 *
	 * This is a MailPoet-owned, site-wide setting that also governs MailPoet's own
	 * signup forms. It decides the status a subscriber added through the API
	 * receives, so surfaces that explain reader status need to read it.
	 *
	 * @return boolean
	 */
	public function is_signup_confirmation_enabled() {
		if ( ! class_exists( '\MailPoet\Settings\SettingsController' ) ) {
			return true;
		}
		try {
			$settings = \MailPoet\Settings\SettingsController::getInstance();
			return (bool) $settings->get( 'signup_confirmation.enabled' );
		} catch ( \Throwable $e ) {
			return true;
		}
	}
}

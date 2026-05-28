<?php
/**
 * Mandatory API definition for an ESP Service.
 *
 * @package Newspack
 */

/**
 * ESP API.
 */
interface Newspack_Newsletters_ESP_API_Interface {
	/**
	 * Get API credentials for service provider.
	 *
	 * @return Object Stored API credentials for the service provider.
	 */
	public function api_credentials();

	/**
	 * Set the API credentials for the service provider.
	 *
	 * @param object $credentials API credentials.
	 */
	public function set_api_credentials( $credentials );

	/**
	 * Check if provider has all necessary credentials set.
	 *
	 * @return Boolean Result.
	 */
	public function has_api_credentials();

	/**
	 * Get the provider specific labels
	 *
	 * This allows us to make reference to provider specific features in the way the user is used to see them in the provider's UI
	 *
	 * This methos must return an array with localized labels forfollowing keys:
	 * - name: The provider name.
	 * - list: "list" in lower case singular format.
	 * - lists: "list" in lower case plural format.
	 * - sublist: Sublist entities in lowercase singular format.
	 * - List: "list" in uppercase case singular format.
	 * - Lists: "list" in uppercase case plural format.
	 * - Sublist: Sublist entities in uppercase singular format.
	 * - tag_prefix: The prefix to be used in tags.
	 * - tag_metabox_before_save: The message to show before saving a list that will create a tag.
	 * - tag_metabox_after_save: The message to show after saving a list that created a tag.
	 *
	 * @param mixed $context The context in which the labels are being applied. Either list_explanation or local_list_explanation.
	 * @return array
	 */
	public static function get_labels( $context = '' );

	/**
	 * Get configuration for conditional tag support in case the ESP supports it.
	 *
	 * Returns an array with two keys:
	 * - support_url: URL to the ESP's documentation on conditional tags.
	 * - example: Array with two keys, 'before' and 'after', containing example code snippets with opening and closing tags.
	 *
	 * @return array
	 */
	public static function get_conditional_tag_support();

	/**
	 * Set list for a campaign.
	 *
	 * @param string $post_id Campaign Id.
	 * @param string $list_id ID of the list.
	 *
	 * @return array|WP_Error API Response or error.
	 */
	public function list( $post_id, $list_id );

	/**
	 * Retrieve a campaign.
	 *
	 * @param integer $post_id Numeric ID of the Newsletter post.
	 *
	 * @return array|WP_Error API Response or error.
	 */
	public function retrieve( $post_id );

	/**
	 * Send test email or emails.
	 *
	 * @param integer $post_id Numeric ID of the Newsletter post.
	 * @param array   $emails  Array of email addresses to send to.
	 *
	 * @return array|WP_Error API Response or error.
	 */
	public function test( $post_id, $emails );

	/**
	 * Synchronize post with corresponding ESP campaign.
	 *
	 * @param WP_Post $post Post to synchronize.
	 *
	 * @return array|WP_Error API Response or error.
	 */
	public function sync( $post );

	/**
	 * List the ESP's contact lists.
	 *
	 * @return array|WP_Error API Response or error.
	 */
	public function get_lists();

	/**
	 * Get the ESP's available lists and sublists, reformatted as Send_List items or an array of config data.
	 *
	 * @param array   $args Array of search args. See Send_Lists::get_default_args() for supported params and default values.
	 * @param boolean $to_array If true, convert Send_List objects to arrays before returning.
	 *
	 * @return Send_List[]|array|WP_Error Array of Send_List objects or arrays on success, or WP_Error object on failure.
	 */
	public function get_send_lists( $args, $to_array = false );

	/**
	 * Add contact to a list.
	 *
	 * @param array  $contact      {
	 *    Contact data.
	 *
	 *    @type string   $email    Contact email address.
	 *    @type string   $name     Contact name. Optional.
	 *    @type string[] $metadata Contact additional metadata. Optional.
	 * }
	 * @param string $list_id      List to add the contact to.
	 *
	 * @return array|WP_Error Contact data if it was added, or error otherwise.
	 */
	public function add_contact( $contact, $list_id = false );

	/**
	 * Get contact data by email.
	 *
	 * @param string $email Email address.
	 * @param bool   $return_details Fetch full contact data.
	 *
	 * @return array|WP_Error Response or error if contact was not found.
	 */
	public function get_contact_data( $email, $return_details = false );

	/**
	 * Get the lists a contact is subscribed to.
	 *
	 * @param string $email The contact email.
	 *
	 * @return string[] Contact subscribed lists IDs.
	 */
	public function get_contact_lists( $email );

	/**
	 * Update a contact lists subscription.
	 *
	 * @param string   $email           Contact email address.
	 * @param string[] $lists_to_add    Array of list IDs to subscribe the contact to.
	 * @param string[] $lists_to_remove Array of list IDs to remove the contact from.
	 *
	 * @return true|WP_Error True if the contact was updated or error.
	 */
	public function update_contact_lists( $email, $lists_to_add = [], $lists_to_remove = [] );

	/**
	 * Retrieve the ESP's tag ID from its name
	 *
	 * @param string  $tag_name The tag.
	 * @param boolean $create_if_not_found Whether to create a new tag if not found. Default to true.
	 * @param string  $list_id The List ID.
	 * @return int|WP_Error The tag ID on success. WP_Error on failure.
	 */
	public function get_tag_id( $tag_name, $create_if_not_found = true, $list_id = null );

	/**
	 * Retrieve the ESP's tag name from its ID
	 *
	 * @param int    $tag_id The tag ID.
	 * @param string $list_id The List ID.
	 * @return string|WP_Error The tag name on success. WP_Error on failure.
	 */
	public function get_tag_by_id( $tag_id, $list_id = null );

	/**
	 * Create a Tag on the provider
	 *
	 * @param string $tag The Tag name.
	 * @param string $list_id The List ID.
	 * @return array|WP_Error The tag representation with at least 'id' and 'name' keys on succes. WP_Error on failure.
	 */
	public function create_tag( $tag, $list_id = null );

	/**
	 * Updates a Tag name on the provider
	 *
	 * @param string|int $tag_id The tag ID.
	 * @param string     $tag The Tag new name.
	 * @param string     $list_id The List ID.
	 * @return array|WP_Error The tag representation with at least 'id' and 'name' keys on succes. WP_Error on failure.
	 */
	public function update_tag( $tag_id, $tag, $list_id = null );

	/**
	 * Add a tag to a contact
	 *
	 * @param string     $email The contact email.
	 * @param string|int $tag The tag ID.
	 * @param string     $list_id The List ID.
	 * @return true|WP_Error
	 */
	public function add_tag_to_contact( $email, $tag, $list_id = null );

	/**
	 * Remove a tag from a contact
	 *
	 * @param string     $email The contact email.
	 * @param string|int $tag The tag ID.
	 * @param string     $list_id The List ID.
	 * @return true|WP_Error
	 */
	public function remove_tag_from_contact( $email, $tag, $list_id = null );

	/**
	 * Get the IDs of the tags associated with a contact.
	 *
	 * @param string $email The contact email.
	 * @return array|WP_Error The tag IDs on success. WP_Error on failure.
	 */
	public function get_contact_tags_ids( $email );

	/**
	 * Get a reader-facing error message to be shown when the add_contact method fails.
	 *
	 * @param array $params Additional information about the request that triggered the error.
	 * @param mixed $raw_error Raw error data from the ESP's API. This can vary depending on the provider.
	 *
	 * @return string
	 */
	public function get_reader_error_message( $params = [], $raw_error = null );

	/**
	 * Get usage report for yesterday.
	 *
	 * @return Newspack_Newsletters_Service_Provider_Usage_Report|WP_Error
	 */
	public function get_usage_report();
}

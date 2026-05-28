<?php
/**
 * Service Provider: Letterhead
 *
 * Letterhead is a service that provides - er - self-service ads (called promotions)
 * for news-organization newsletters. We're optimistically organizing this
 * with service providers both because it's a third-party partner integration, but
 * also because eventually we think Newspack writers who use Letterhead may want to
 * have LH handle the sending in the future. This class should make it easier
 * to hook into existing functionality.
 *
 * @package Newspack
 * @version 0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Newspack_Newsletters_Letterhead
 */
final class Newspack_Newsletters_Letterhead extends \Newspack_Newsletters_Service_Provider {
	const LETTERHEAD_WP_OPTION_KEY = 'newspack_newsletters_letterhead_api_key';

	/**
	 * Add a contact to specific list.
	 *
	 * @param array  $contact Contact data.
	 * @param string $list_id List identifier.
	 * @return object|void|null API Response or error
	 */
	public function add_contact( $contact, $list_id = false ) {
		// TODO: Implement add_contact() method.
	}

	/**
	 * Gets the Letterhead API credentials. Right now, that's just a key : ).
	 *
	 * @return string The user's LH API key.
	 */
	public function api_credentials() {
		$letterhead_key = get_option( self::LETTERHEAD_WP_OPTION_KEY, '' );
		return $letterhead_key;
	}

	/**
	 * Fetch the newsletter's promotions from Letterhead and format them for insertion
	 * in Newspack's email template.
	 *
	 * @param string $date The date of the publication.
	 * @param int    $length_of_newsletter The length of the newsletter.
	 * @return array
	 */
	public function get_and_prepare_promotions_for_insertion( $date, $length_of_newsletter ) {
		$promotions_from_api = $this->get_promotions_by_date( $date );

		return array_map(
			function ( Newspack_Newsletters_Letterhead_Promotion $promotion ) use ( $length_of_newsletter ) {
				return $promotion->convert_to_compatible_newspack_ad_array( $length_of_newsletter );
			},
			$promotions_from_api
		);
	}

	/**
	 * Get lists
	 *
	 * @return object|void|null API Response or Error
	 */
	public function get_lists() {
		// TODO: Implement get_lists() method.
	}

	/**
	 * Get send lists
	 *
	 * @param array   $args Array of search args. See Send_Lists::get_default_args() for supported params and default values.
	 * @param boolean $to_array If true, convert Send_List objects to arrays before returning.
	 *
	 * @return void
	 */
	public function get_send_lists( $args = [], $to_array = false ) {} // Not used.

	/**
	 * This will call the Letterhead promotions API with the specific date passed as the
	 * argument and the appropriate credentials. It will return an array.
	 *
	 * @param string $date The date of publication.
	 * @return array
	 */
	public function get_promotions_by_date( $date ) {
		if ( ! $this->has_api_credentials() ) {
			return [];
		}


		$credentials = $this->api_credentials();
		$url         = NEWSPACK_NEWSLETTERS_LETTERHEAD_ENDPOINT . "/api/v2/promotions/?date={$date}&mjml=true";

		$request_headers = [
			'Authorization' => "Bearer {$credentials}",
		];

		$request_arguments = [
			'headers' => $request_headers,
		];

		$response = function_exists( 'vip_safe_wp_remote_get' )
			? vip_safe_wp_remote_get( $url, '', 1, 1, 60, $request_arguments )
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
			: wp_remote_get( $url, $request_arguments );

		/**
		 * Our end users don't really benefit from having a broken experience just because our API call
		 * failed. We can fail silently and return an empty array.
		 */
		if ( is_wp_error( $response ) ) {
			// We should log the error somewhere.
			return [];
		}

		$promotions = wp_remote_retrieve_body( $response );

		return $this->get_promotions_from_json_response( $promotions );
	}

	/**
	 * Pass the json response from, presumably, a Letterhead API call, and return an array of
	 * Promotions.
	 *
	 * @param string $promotions_json The json response from a successful Lettherhead API query.
	 * @return array Newspack_Newsletters_Letterhead_Promotion[]
	 */
	private function get_promotions_from_json_response( $promotions_json ) {
		$promotion_response_object = json_decode( $promotions_json, false );
		$promotion_response_array  = is_null( $promotion_response_object ) ? [] : $promotion_response_object;

		/**
		 * We'll pass the json body through the Dto to normalize and get just the promotion data
		 * we care about.
		 *
		 * @var array Newspack_Newsletters_Letterhead_Promotion_Dto[]
		 */
		$array_of_promotion_dtos = array_map(
			function ( \stdClass $promotion_object ) {
				return new Newspack_Newsletters_Letterhead_Promotion_Dto( $promotion_object );
			},
			$promotion_response_array
		);

		/**
		 * Then we'll get an array of Promotions from these Dtos.
		 *
		 * @var array Newspack_Newsletters_Letterhead_Promotion[]
		 */
		$array_of_promotions = array_map(
			function ( Newspack_Newsletters_Letterhead_Promotion_Dto $dto ) {
				return new Newspack_Newsletters_Letterhead_Promotion( $dto );
			},
			$array_of_promotion_dtos
		);

		return $array_of_promotions;
	}

	/**
	 * Whether we have a complete set of Letterhead credentials. This doesn't presently check
	 * whether the credentials are valid, just that they are present.
	 *
	 * @return bool Whether we have the API credentials we need.
	 */
	public function has_api_credentials() {
		$credentials = $this->api_credentials();

		/**
		 * The main thing we care about is that this isn't empty.
		 */
		return ! empty( $credentials );
	}

	/**
	 * Set the desired audience segment for a given email. LH doesn't do this
	 * at the moment.
	 *
	 * @param string $post_id The WP Post Id.
	 * @param string $list_id The audience segment ID.
	 * @return void
	 */
	public function list( $post_id, $list_id ) {}

	/**
	 * Fetch the email campaign by the WP Post ID - if it exits. Or, at least
	 * in spirit. LH Doesn't do this yet.
	 *
	 * @param int $post_id The WP Post Id.
	 * @return void
	 */
	public function retrieve( $post_id ) {}

	/**
	 * `save` is reserved for updating the corresponding email campaign when the WordPress
	 * post is saved - if LH does this later : ).
	 *
	 * @param int   $meta_id Numeric ID of the meta field being updated.
	 * @param int   $post_id The post ID for the meta field being updated.
	 * @param mixed $meta_key The meta key being updated.
	 */
	public function save( $meta_id, $post_id, $meta_key ) {}

	/**
	 * `send` will send the email - 🤞 one day.
	 *
	 * @param WP_Post $post The WP Post.
	 */
	public function send( $post ) {}

	/**
	 * `sender` is set aside to set an email's sender information - that is, the
	 * name and email address readers see as the source of the email.
	 *
	 * @param string $post_id The WP Post Id.
	 * @param string $from_name The name readers should see with "from" in their email.
	 * @param string $reply_to The corresponding email address.
	 * @return void
	 */
	public function sender( $post_id, $from_name, $reply_to ) {}

	/**
	 * `set_api_credentials` is reserved to allow users to set their
	 * Letterhead keys (etc) over the WP Rest API.
	 *
	 * @param object $credentials The new LH credentials to save.
	 */
	public function set_api_credentials( $credentials ) {}

	/**
	 * `sync` is here for now to satisfy the interface requirement, but it's
	 * in place to handle syncing ads, the post, and the email with the ESP.
	 *
	 * @param WP_POST $post The WP Post.
	 * @return void
	 */
	public function sync( $post ) {}

	/**
	 * We've included `test` optimistically. There is nothing to send test
	 * just yet. Testing of promotions served via Letterhead are in the
	 * email.
	 *
	 * @param int   $post_id the ID of the WP Post.
	 * @param array $emails An array of emails to send the test to.
	 * @return void
	 */
	public function test( $post_id, $emails ) {}

	/**
	 * We've included `trash` here optimistically. There is nothing to trash
	 * just yet :).
	 *
	 * @param int $post_id The Post ID.
	 */
	public function trash( $post_id ) {}

	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

	/**
	 * Get the provider specific labels
	 */
	public static function get_labels( $context = '' ) {}

	/**
	 * Get configuration for conditional tag support in case the ESP supports it.
	 */
	public static function get_conditional_tag_support() {}

	/**
	 * Get contact data by email.
	 */
	public function get_contact_data( $email, $return_details = false ) {}

	/**
	 * Get the lists a contact is subscribed to.
	 */
	public function get_contact_lists( $email ) {}

	/**
	 * Update a contact lists subscription.
	 */
	public function update_contact_lists( $email, $lists_to_add = [], $lists_to_remove = [] ) {}

	/**
	 * Retrieve the ESP's tag ID from its name
	 */
	public function get_tag_id( $tag_name, $create_if_not_found = true, $list_id = null ) {}

	/**
	 * Retrieve the ESP's tag name from its ID
	 */
	public function get_tag_by_id( $tag_id, $list_id = null ) {}

	/**
	 * Create a Tag on the provider
	 */
	public function create_tag( $tag, $list_id = null ) {}

	/**
	 * Updates a Tag name on the provider
	 */
	public function update_tag( $tag_id, $tag, $list_id = null ) {}

	/**
	 * Add a tag to a contact
	 */
	public function add_tag_to_contact( $email, $tag, $list_id = null ) {}

	/**
	 * Remove a tag from a contact
	 */
	public function remove_tag_from_contact( $email, $tag, $list_id = null ) {}

	/**
	 * Get the IDs of the tags associated with a contact.
	 */
	public function get_contact_tags_ids( $email ) {}

	/**
	 * Get a reader-facing error message to be shown when the add_contact method fails.
	 */
	public function get_reader_error_message( $params = [], $raw_error = null ) {}

	/**
	 * Get usage report for yesterday.
	 */
	public function get_usage_report() {}
}

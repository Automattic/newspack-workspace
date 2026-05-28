<?php
/**
 * An interface defining the mandatory abilities for the ESP Services to hook into WP actions.
 *
 * @package Newspack
 */

/**
 * Integration with WP Hooks.
 */
interface Newspack_Newsletters_WP_Hookable_Interface {

	/**
	 * Update ESP campaign after email HTML post meta is saved.
	 *
	 * @param int   $meta_id Numeric ID of the meta field being updated.
	 * @param int   $post_id The post ID for the meta field being updated.
	 * @param mixed $meta_key The meta key being updated.
	 */
	public function save( $meta_id, $post_id, $meta_key );

	/**
	 * Send a campaign.
	 *
	 * @param \WP_Post $post Post to send.
	 */
	public function send( $post );

	/**
	 * After Newsletter post is deleted, clean up by deleting corresponding ESP campaign.
	 *
	 * @param string $post_id Numeric ID of the campaign.
	 */
	public function trash( $post_id );
}

<?php
/**
 * Republication Tracker Tool Media.
 *
 * @since   1.0
 * @package Republication_Tracker_Tool
 */

/**
 * Republication Tracker Tool Media class.
 *
 * @since 1.0
 */
class Republication_Tracker_Tool_Media {
	/**
	 * Initialize the class
	 */
	public static function init() {
		add_action( 'add_attachment', array( __CLASS__, 'apply_default_attachment_distribution' ), 10, 1 );
	}

	/**
	 * Apply default distribution setting to new attachments
	 * 
	 * @param int $attachment_id The attachment ID.
	 */
	public static function apply_default_attachment_distribution( $attachment_id ) {
		$default_attachment_distribution = get_option( 'republication_tracker_tool_default_attachment_distribution', 'off' );

		if ( 'on' === $default_attachment_distribution && class_exists( '\Newspack\Newspack_Image_Credits' ) ) {
			update_post_meta( $attachment_id, \Newspack\Newspack_Image_Credits::MEDIA_CREDIT_CAN_DISTRIBUTE_META, true );
		}
	}

	/**
	 * Should the media element be distributed?
	 *
	 * @param int $media_id ID of the media element.
	 */
	public static function can_distribute( $media_id ) {
		$media_distribution = get_option( 'republication_tracker_tool_media_distribution', 'on' );
		if ( $media_distribution === 'on' ) {
			// All media should be distributed, regardless of the individual setting.
			return true;
		}
		// Otherwise, check the individual setting.
		if ( class_exists( '\Newspack\Newspack_Image_Credits' ) ) {
			return (bool) get_post_meta( $media_id, \Newspack\Newspack_Image_Credits::MEDIA_CREDIT_CAN_DISTRIBUTE_META, true );
		}

		return false;
	}
}

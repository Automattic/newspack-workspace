<?php
/**
 * Newspack Jetpack Gift Article class.
 *
 * @package Newspack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Sharing_Source' ) ) {
	return;
}

/**
 * Newspack Jetpack Gift Article class.
 */
class Newspack_Jetpack_Gift_Article extends Sharing_Source {
	/**
	 * Service short name.
	 *
	 * @var string
	 */
	public $shortname = 'newspack-gift-article';

	/**
	 * Should the sharing link open in a new tab.
	 *
	 * @var bool
	 */
	public $open_link_in_new = false;

	/**
	 * Service name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Gift this article', 'newspack-plugin' );
	}

	/**
	 * Get unique sharing ID. Similar to get_id().
	 *
	 * @return mixed
	 */
	public function get_class() {
		/**
		 * Trick Jetpack into thinking this is a print preview so it doesn't get
		 * removed from the preview when button style is "Official buttons".
		 *
		 * See more at https://github.com/Automattic/jetpack/blob/817c26ec371251dead8428dd43573f955feb9528/projects/plugins/jetpack/modules/sharedaddy/admin-sharing.js#L163-L188
		 */
		if ( is_admin() ) {
			return $this->id . ' preview-print';
		}
		return $this->id;
	}

	/**
	 * Get the markup of the sharing button.
	 *
	 * @param WP_Post $post The post object.
	 *
	 * @return string
	 */
	public function get_display( $post ) {
		if ( ! Newspack\Content_Gifting::can_gift_post( $post->ID ) ) {
			return '';
		}
		return $this->get_link(
			Newspack\Content_Gifting::get_gift_url( $post->ID ),
			_x( 'Gift this article', 'Jetpack sharing source', 'newspack-plugin' ),
			__( 'Click to gift this article', 'newspack-plugin' ),
			'share=newspack-gift-article',
			'newspack-gift-article-' . $post->ID
		);
	}
}

<?php
/**
 * Newspack Woo generic Item for orders and subscriptions
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub\Stores;

use Newspack_Network\Hub\Node;
use WP_Post;

/**
 * Woo generic Item
 */
abstract class Woo_Item {

	/**
	 * The WP_Post object for this Item
	 *
	 * @var WP_Post
	 */
	private $post;

	/**
	 * Gets the post type slug
	 *
	 * @return string
	 */
	abstract protected function get_post_type_slug();

	/**
	 * Constructs a new Item
	 *
	 * @param WP_Post|int $item A WP_Post object or a post ID.
	 */
	public function __construct( $item ) {
		if ( is_numeric( $item ) ) {
			$item = get_post( $item );
		}

		if ( ! $item instanceof WP_Post || $this->get_post_type_slug() !== $item->post_type ) {
			return;
		}

		$this->post = $item;
	}

	/**
	 * Returns the Item's ID
	 *
	 * @return ?int
	 */
	public function get_id() {
		if ( $this->post instanceof WP_Post && ! empty( $this->post->ID ) ) {
			return $this->post->ID;
		}
	}

	/**
	 * Returns the Item's Title
	 *
	 * @return ?string
	 */
	public function get_title() {
		if ( $this->post instanceof WP_Post && ! empty( $this->post->post_title ) ) {
			return $this->post->post_title;
		}
	}

	/**
	 * Returns the Item's Status
	 *
	 * @return ?int
	 */
	public function get_status() {
		if ( $this->post instanceof WP_Post && ! empty( $this->post->post_status ) ) {
			return $this->post->post_status;
		}
	}

	/**
	 * Returns the Item's Status label
	 *
	 * @return ?string
	 */
	public function get_status_label() {
		$status_object = get_post_status_object( $this->get_status() );
		if ( ! is_object( $status_object ) ) {
			return;
		}
		return $status_object->label ?? null;
	}

	/**
	 * Returns the Item's remote ID
	 *
	 * @return ?string
	 */
	public function get_remote_id() {
		return get_post_meta( $this->get_id(), 'remote_id', true );
	}

	/**
	 * Returns the Item's Node
	 *
	 * @return ?string
	 */
	public function get_node() {
		return new Node( get_post_meta( $this->get_id(), 'node_id', true ) );
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
	 * Returns the Item's user_email
	 *
	 * @return ?string
	 */
	public function get_user_email() {
		return get_post_meta( $this->get_id(), 'user_email', true );
	}

	/**
	 * Returns the Item's user_name
	 *
	 * @return ?string
	 */
	public function get_user_name() {
		return get_post_meta( $this->get_id(), 'user_name', true );
	}

	/**
	 * Returns the Item's payment_count
	 *
	 * @return ?string
	 */
	public function get_payment_count() {
		return get_post_meta( $this->get_id(), 'payment_count', true );
	}

	/**
	 * Returns the Item's formatted_total
	 *
	 * @return ?string
	 */
	public function get_formatted_total() {
		return get_post_meta( $this->get_id(), 'formatted_total', true );
	}

	/**
	 * Returns the Item's currency
	 *
	 * @return ?string
	 */
	public function get_currency() {
		return get_post_meta( $this->get_id(), 'currency', true );
	}

	/**
	 * Returns the Item's total
	 *
	 * @return ?string
	 */
	public function get_total() {
		return get_post_meta( $this->get_id(), 'total', true );
	}

	/**
	 * Returns the Item's edit link
	 *
	 * @return ?string
	 */
	public function get_edit_link() {
		$remote_id = $this->get_remote_id();
		$node_url  = $this->get_node_url();
		if ( ! $remote_id || ! $node_url ) {
			return;
		}
		return $node_url . '/wp-admin/post.php?post=' . $remote_id . '&action=edit';
	}
}

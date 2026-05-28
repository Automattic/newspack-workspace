<?php
/**
 * Newspack Popups Merge Tag
 *
 * @package Newspack
 */

namespace Newspack\Campaigns;

defined( 'ABSPATH' ) || exit;

/**
 * Merge Tag class.
 */
final class Merge_Tag {
	/**
	 * Tag name
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Tag title.
	 *
	 * @var string
	 */
	public $title;

	/**
	 * Tag description.
	 *
	 * @var string
	 */
	public $description;

	/**
	 * Tag callback.
	 *
	 * @var callable
	 */
	public $callback;

	/**
	 * The segmentation criteria for the tag.
	 *
	 * @var string
	 */
	public $criteria;

	/**
	 * Constructor.
	 *
	 * @param string $name Tag name.
	 * @param array  $args Tag arguments.
	 */
	public function __construct( $name, $args = [] ) {
		$this->name        = $name;
		$this->title       = $args['title'] ?? '';
		$this->description = $args['description'] ?? '';
		$this->criteria    = $args['criteria'] ?? '';
		$this->callback    = $args['callback'] ?? '__return_empty_string';
	}

	/**
	 * Get tag in array format.
	 *
	 * @return array
	 */
	public function to_array() {
		return [
			'name'        => $this->name,
			'title'       => $this->title,
			'description' => $this->description,
			'criteria'    => $this->criteria,
		];
	}

	/**
	 * Get tag content.
	 *
	 * @return string
	 */
	public function get_content() {
		return sprintf(
			'<span class="merge-tag" data-tag="%1$s" %2$s>%3$s</span>',
			esc_attr( $this->name ),
			$this->criteria ? sprintf( 'data-criteria="%s"', $this->criteria ) : '',
			call_user_func( $this->callback )
		);
	}
}

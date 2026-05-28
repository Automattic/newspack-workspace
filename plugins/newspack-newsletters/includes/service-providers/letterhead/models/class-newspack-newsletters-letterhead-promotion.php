<?php
/**
 * Letterhead Promotion model
 *
 * @package Newspack
 */

/**
 * Class Newspack_Newsletters_Letterhead_Promotion
 *
 * We use this as a model for a Letterhead promotion. We'll use this model to get (or, maybe,
 * in the future) set properties as needed throughout Newspack.
 */
class Newspack_Newsletters_Letterhead_Promotion {
	/**
	 * A string of MJML wrapping the promotion. This is either an <mj-wrapper /> or an <mj-section />,
	 * and is designed to be inserted into a larger <mjml /> body.
	 *
	 * @var string
	 */
	private $mjml;

	/**
	 * An integer representing the percentage of the content where the promotion should be inserted.
	 *
	 * @var int
	 */
	private $positioning;

	/**
	 * Newspack_Newsletters_Letterhead_Promotion constructor.
	 *
	 * @param Newspack_Newsletters_Letterhead_Promotion_Dto $dto The promotion DTO.
	 */
	public function __construct( Newspack_Newsletters_Letterhead_Promotion_Dto $dto ) {
		$this->mjml        = $dto->mjml;
		$this->positioning = $dto->positioning;
	}

	/**
	 * Convert a Letterhead promotion into an array compatible with the way Newspack inserts ads
	 * into email template.
	 *
	 * @param int $content_length The total length of the content.
	 * @return array
	 */
	public function convert_to_compatible_newspack_ad_array( $content_length ) {
		/**
		 * A precise point of insertion for the promotion.
		 *
		 * @var float|int
		 */
		$precise_position = Newspack_Newsletters_Renderer::get_ad_placement_precise_position( $this->get_positioning(), $content_length );
		return [
			'is_inserted'      => false,
			'markup'           => $this->get_mjml(),
			'percentage'       => $this->positioning,
			'precise_position' => $precise_position,
		];
	}

	/**
	 * Get the mjml wrapper of a promotion.
	 *
	 * @return string
	 */
	public function get_mjml() {
		return $this->mjml;
	}

	/**
	 * Get the positioning of a position, a number representing the percentage
	 * from top to bottom where the promotion should be inserted.
	 *
	 * @return int
	 */
	public function get_positioning() {
		return $this->positioning;
	}
}

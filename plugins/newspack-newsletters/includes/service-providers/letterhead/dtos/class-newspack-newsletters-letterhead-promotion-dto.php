<?php
/**
 * Letterhead Promotion Dto (Data Transfer Object).
 *
 * @package Newspack
 */

/**
 * Class Newspack_Newsletters_Letterhead_Promotion_Dto
 *
 * This is a barebones object we use to make it a tad more
 * convenient to work the Promotion data from Letterhead. There are a lot more
 * available properties, but we only include those we want to use in Newspack.
 */
class Newspack_Newsletters_Letterhead_Promotion_Dto {
	/**
	 * A string of MJML - either an <mj-wrapper /> or <mj-section /> - wrapping the
	 * promotion.
	 *
	 * @var string
	 */
	public $mjml;

	/**
	 * An integer from 0 - 100 representing the percentage down a piece of content (from
	 * top to bottom) where the promotion should appear.
	 *
	 * @var int
	 */
	public $positioning;

	/**
	 * Newspack_Newsletters_Letterhead_Promotion_Dto constructor.
	 *
	 * @param \stdClass $promotion A promotion object from the Letterhead APi.
	 */
	public function __construct( \stdClass $promotion ) {
		$this->mjml        = isset( $promotion->mjml ) ? $promotion->mjml : '';
		$this->positioning = isset( $promotion->positioning ) ? $promotion->positioning : 0;
	}
}

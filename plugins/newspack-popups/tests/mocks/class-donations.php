<?php
/**
 * Minimal mock of \Newspack\Donations for the popups data-api tests.
 *
 * The popups test suite does not load newspack-plugin, so \Newspack\Donations
 * is absent. get_site_conversion_urls() only needs the option-name constant to
 * read the configured donation page; that is all this mock provides.
 *
 * @package Newspack_Popups
 */

namespace Newspack;

/**
 * Donations mock.
 */
class Donations {
	const DONATION_PAGE_ID_OPTION = 'newspack_donation_page_id';
}

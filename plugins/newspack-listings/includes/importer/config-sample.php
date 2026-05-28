<?php
/**
 * Sample config for importing listings from a CSV file.
 *
 * The following constants must be defined for the importer script to work:
 * NEWSPACK_LISTINGS_IMPORT_MAPPING
 * NEWSPACK_LISTINGS_IMPORT_SEPARATOR
 *
 * The following constant is optional. If not defined or not a valid Listing CPT post type,
 * and the CSV data lacks post type info, all rows will be imported as generic listings by default.
 * NEWSPACK_LISTINGS_IMPORT_DEFAULT_POST_TYPE
 *
 * @package Newspack_Listings
 */

/**
 * Define the mapping of WP fields to CSV header names.
 */
define(
	'NEWSPACK_LISTINGS_IMPORT_MAPPING',
	[
		'post_category'      => 'directory_category',
		'post_author'        => 'post_author',
		'post_content'       => 'post_content',
		'post_date'          => 'post_published',
		'post_excerpt'       => 'field_business_label',
		'post_title'         => 'post_title',
		'tags_input'         => 'directory_tag',
		'_thumbnail_id'      => 'directory_photos',
		'post_type'          => 'post_type',

		// Mappings for the values of the `post_type` field defined above.
		'post_types'         => [
			'event'       => [ 'event', 'date' ],
			'generic'     => [ 'item', 'listing' ],
			'marketplace' => [ 'classified', 'obituary', 'promo' ],
			'place'       => [ 'business', 'location', 'place' ],
		],

		// Location fields.
		'location_address'   => 'location_address__address', // Full address of map marker.

		// Contact info fields.
		'contact_email'      => 'field_email',
		'contact_phone'      => 'field_phone',
		'contact_street_1'   => 'location_address__street',
		'contact_street_2'   => 'location_address__street_2',
		'contact_city'       => 'location_address__city',
		'contact_region'     => 'location_address__province',
		'contact_postal'     => 'location_address__zip',

		// Social media accounts.
		'facebook'           => 'field_social_accounts__facebook',
		'twitter'            => 'field_social_accounts__twitter',
		'instagram'          => 'field_social_accounts__instagram',

		// Additional fields which should be appended to post content.
		'additional_content' => [ 'field_gen_hours' ],
	]
);

/**
 * The separator character used in the CSV file to separate multiple values in a single field.
 */
define( 'NEWSPACK_LISTINGS_IMPORT_SEPARATOR', ';' );

/**
 * Default listing post type to import as, if we can't determine the type from CSV data.
 */
define( 'NEWSPACK_LISTINGS_IMPORT_DEFAULT_POST_TYPE', 'newspack_lst_place' );

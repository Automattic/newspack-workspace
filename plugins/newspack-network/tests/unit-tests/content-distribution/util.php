<?php
/**
 * Utility to test Content Distribution
 *
 * @package Newspack
 */

namespace Test\Content_Distribution;

/**
 * Get a sample distributed post payload for testing.
 *
 * @param string $origin Origin site URL.
 * @param string $destination Destination site URL.
 *
 * @return array
 */
function get_sample_payload( $origin = '', $destination = '' ) {
	if ( empty( $origin ) ) {
		$origin = 'https://origin.test';
	}
	if ( empty( $destination ) ) {
		$destination = 'https://destination.test';
	}

	return [
		'site_url'          => $origin,
		'post_id'           => 1,
		'post_url'          => $origin . '/2021/01/slug',
		'network_post_id'   => '1234567890abcdef1234567890abcdef',
		'sites'             => [ $destination ],
		'status_on_publish' => 'draft',
		'post_data'         => [
			'title'          => 'Title',
			'author'         => [],
			'post_status'    => 'publish',
			'date_gmt'       => '2021-01-01 00:00:00',
			'modified_gmt'   => '2021-01-01 00:00:00',
			'slug'           => 'slug',
			'post_type'      => 'post',
			'raw_content'    => '<!-- wp:paragraph --><p>Content</p><!-- /wp:paragraph -->',
			'content'        => '<p>Content</p>',
			'excerpt'        => 'Excerpt',
			'thumbnail_url'  => 'https://picsum.photos/id/1/300/300.jpg',
			'comment_status' => 'open',
			'ping_status'    => 'open',
			'taxonomy'       => [
				'category' => [
					[
						'name' => 'Category 1',
						'slug' => 'category-1',
					],
					[
						'name' => 'Category 2',
						'slug' => 'category-2',
					],
				],
				'post_tag' => [
					[
						'name' => 'Tag 1',
						'slug' => 'tag-1',
					],
					[
						'name' => 'Tag 2',
						'slug' => 'tag-2',
					],
				],
			],
			'post_meta'      => [
				'single'   => [ 'value' ],
				'array'    => [ [ 'a' => 'b', 'c' => 'd' ] ], // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				'multiple' => [ 'value 1', 'value 2' ],
			],
			'media_data'     => [
				[
					'url'        => 'https://picsum.photos/id/1/300/300.jpg',
					'caption'    => 'Caption',
					'credit'     => 'Credit',
					'credit_url' => 'https://credit.url',
					'alt'        => 'Alt',
					'featured'   => true,
				],
			],
		],
	];
}

<?php
/**
 * Newspack Listings Block Patterns.
 *
 * Custom Gutenberg Block Patterns for Newspack Listings.
 *
 * @package Newspack_Listings
 */

namespace Newspack_Listings;

use Newspack_Listings\Core;
use Newspack_Listings\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks class.
 * Sets up custom blocks for listings.
 */
final class Block_Patterns {
	/**
	 * Slug for the block pattern category.
	 */
	const NEWSPACK_LISTINGS_BLOCK_PATTERN_CATEGORY = 'newspack-listings__business-patterns';

	/**
	 * The single instance of the class.
	 *
	 * @var Newspack_Listings_Block_Patterns
	 */
	protected static $instance = null;

	/**
	 * Main Newspack_Listings_Block_Patterns instance.
	 * Ensures only one instance of Newspack_Listings_Block_Patterns is loaded or can be loaded.
	 *
	 * @return Newspack_Listings_Block_Patterns - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', [ __CLASS__, 'register_block_pattern_category' ], 10 );
		add_action( 'init', [ __CLASS__, 'register_block_patterns' ], 11 );
	}

	/**
	 * Register custom block pattern category for Newspack Listings.
	 */
	public static function register_block_pattern_category() {
		return register_block_pattern_category(
			self::NEWSPACK_LISTINGS_BLOCK_PATTERN_CATEGORY,
			[ 'label' => __( 'Newspack Listings', 'newspack-listings' ) ]
		);
	}

	/**
	 * Get block patterns. If given a slug, return only the matching pattern.
	 *
	 * @param string $slug Optional. If given, return only the pattern that matches.
	 *
	 * @return array Array of block patterns, or the single block pattern config matching $slug.
	 */
	public static function get_block_patterns( $slug = null ) {
		// Block pattern config.
		$block_patterns = [
			'business_1'    => [
				'post_types' => [ Core::NEWSPACK_LISTINGS_POST_TYPES['place'] ],
				'settings'   => [
					'title'       => __( 'Business Listing 1', 'newspack-listings' ),
					'categories'  => [ self::NEWSPACK_LISTINGS_BLOCK_PATTERN_CATEGORY ],
					'description' => _x(
						'Business description, map, contact info, and hours of operation.',
						'Block pattern description',
						'newspack-listings'
					),
					'content'     => '<!-- wp:group {"className":"newspack-listings__business-pattern-1"} --><div class="wp-block-group newspack-listings__business-pattern-1"><div class="wp-block-group__inner-container"><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column {"width":"66.66%"} --><div class="wp-block-column" style="flex-basis:66.66%"><!-- wp:paragraph --><p>This is the description of your listing. It can be some sort of introduction, a quick overview, or anything you want to tell your visitors about this listing.</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column {"width":"33.33%"} --><div class="wp-block-column" style="flex-basis:33.33%"><!-- wp:jetpack/map {"mapCenter":{"lng":-122.41941550000001,"lat":37.7749295}} --><div class="wp-block-jetpack-map" data-map-style="default" data-map-details="true" data-points="[]" data-zoom="13" data-map-center="{&quot;lng&quot;:-122.41941550000001,&quot;lat&quot;:37.7749295}" data-marker-color="red" data-show-fullscreen-button="true"></div><!-- /wp:jetpack/map --><!-- wp:jetpack/contact-info --><div class="wp-block-jetpack-contact-info"><!-- wp:jetpack/email {"email":"email@yourgroovydomain.com"} --><div class="wp-block-jetpack-email"><a href="mailto:email@yourgroovydomain.com">email@yourgroovydomain.com</a></div><!-- /wp:jetpack/email --><!-- wp:jetpack/phone {"phone":"1-877 273-3049"} --><div class="wp-block-jetpack-phone"><a href="tel:18772733049">1-877 273-3049</a></div><!-- /wp:jetpack/phone --><!-- wp:jetpack/address {"address":"60 29th Street #343","city":"San Francisco","region":"CA","postal":"94110"} --><div class="wp-block-jetpack-address"><div class="jetpack-address__address jetpack-address__address1">60 29th Street #343</div><div><span class="jetpack-address__city">San Francisco</span>, <span class="jetpack-address__region">CA</span> <span class="jetpack-address__postal">94110</span></div></div><!-- /wp:jetpack/address --></div><!-- /wp:jetpack/contact-info --><!-- wp:separator {"className":"is-style-wide"} --><hr class="wp-block-separator is-style-wide"/><!-- /wp:separator --><!-- wp:jetpack/business-hours /--></div><!-- /wp:column --></div><!-- /wp:columns --></div></div><!-- /wp:group -->',
				],
			],
			'business_2'    => [
				'post_types' => [ Core::NEWSPACK_LISTINGS_POST_TYPES['place'] ],
				'settings'   => [
					'title'       => __( 'Business Listing 2', 'newspack-listings' ),
					'categories'  => [ self::NEWSPACK_LISTINGS_BLOCK_PATTERN_CATEGORY ],
					'description' => _x(
						'Business description, map, contact info, and hours of operation.',
						'Block pattern description',
						'newspack-listings'
					),
					'content'     => '<!-- wp:group {"className":"newspack-listings__business-pattern-2"} --><div class="wp-block-group newspack-listings__business-pattern-2"><div class="wp-block-group__inner-container"><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column {"width":"50%"} --><div class="wp-block-column" style="flex-basis:50%"><!-- wp:paragraph --><p>This is the description of your listing. It can be some sort of introduction, a quick overview, or anything you want to tell your visitors about this listing.</p><!-- /wp:paragraph --><!-- wp:jetpack/contact-info --><div class="wp-block-jetpack-contact-info"><!-- wp:jetpack/email {"email":"email@yourgroovydomain.com"} --><div class="wp-block-jetpack-email"><a href="mailto:email@yourgroovydomain.com">email@yourgroovydomain.com</a></div><!-- /wp:jetpack/email --><!-- wp:jetpack/phone {"phone":"1-877 273-3049"} --><div class="wp-block-jetpack-phone"><a href="tel:18772733049">1-877 273-3049</a></div><!-- /wp:jetpack/phone --><!-- wp:jetpack/address {"address":"60 29th Street #343","city":"San Francisco","region":"CA","postal":"94110"} --><div class="wp-block-jetpack-address"><div class="jetpack-address__address jetpack-address__address1">60 29th Street #343</div><div><span class="jetpack-address__city">San Francisco</span>, <span class="jetpack-address__region">CA</span> <span class="jetpack-address__postal">94110</span></div></div><!-- /wp:jetpack/address --></div><!-- /wp:jetpack/contact-info --><!-- wp:jetpack/business-hours /--></div><!-- /wp:column --><!-- wp:column {"width":"50%"} --><div class="wp-block-column" style="flex-basis:50%"><!-- wp:jetpack/map {"mapCenter":{"lng":-122.41941550000001,"lat":37.7749295}} --><div class="wp-block-jetpack-map" data-map-style="default" data-map-details="true" data-points="[]" data-zoom="13" data-map-center="{&quot;lng&quot;:-122.41941550000001,&quot;lat&quot;:37.7749295}" data-marker-color="red" data-show-fullscreen-button="true"></div>
					<!-- /wp:jetpack/map --></div><!-- /wp:column --></div><!-- /wp:columns --></div></div><!-- /wp:group -->',
				],
			],
			'business_3'    => [
				'post_types' => [ Core::NEWSPACK_LISTINGS_POST_TYPES['place'] ],
				'settings'   => [
					'title'       => __( 'Business Listing 3', 'newspack-listings' ),
					'categories'  => [ self::NEWSPACK_LISTINGS_BLOCK_PATTERN_CATEGORY ],
					'description' => _x(
						'Business description, map, contact info, and hours of operation.',
						'Block pattern description',
						'newspack-listings'
					),
					'content'     => '<!-- wp:group {"className":"newspack-listings__business-pattern-3"} --><div class="wp-block-group newspack-listings__business-pattern-3"><div class="wp-block-group__inner-container"><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column {"width":"66.66%"} --><div class="wp-block-column" style="flex-basis:66.66%"><!-- wp:jetpack/map {"mapCenter":{"lng":-122.41941550000001,"lat":37.7749295}} --><div class="wp-block-jetpack-map" data-map-style="default" data-map-details="true" data-points="[]" data-zoom="13" data-map-center="{&quot;lng&quot;:-122.41941550000001,&quot;lat&quot;:37.7749295}" data-marker-color="red" data-show-fullscreen-button="true"></div><!-- /wp:jetpack/map --></div><!-- /wp:column --><!-- wp:column {"width":"33.33%"} --><div class="wp-block-column" style="flex-basis:33.33%"><!-- wp:jetpack/contact-info --><div class="wp-block-jetpack-contact-info"><!-- wp:jetpack/email {"email":"email@yourgroovydomain.com"} --><div class="wp-block-jetpack-email"><a href="mailto:email@yourgroovydomain.com">email@yourgroovydomain.com</a></div><!-- /wp:jetpack/email --><!-- wp:jetpack/phone {"phone":"1-877 273-3049"} --><div class="wp-block-jetpack-phone"><a href="tel:18772733049">1-877 273-3049</a></div><!-- /wp:jetpack/phone --><!-- wp:jetpack/address {"address":"60 29th Street #343","city":"San Francisco","region":"CA","postal":"94110"} --><div class="wp-block-jetpack-address"><div class="jetpack-address__address jetpack-address__address1">60 29th Street #343</div><div><span class="jetpack-address__city">San Francisco</span>, <span class="jetpack-address__region">CA</span> <span class="jetpack-address__postal">94110</span></div></div><!-- /wp:jetpack/address --></div><!-- /wp:jetpack/contact-info --><!-- wp:separator {"className":"is-style-wide"} --><hr class="wp-block-separator is-style-wide"/><!-- /wp:separator --><!-- wp:jetpack/business-hours /--></div><!-- /wp:column --></div><!-- /wp:columns --><!-- wp:separator {"className":"is-style-wide"} --><hr class="wp-block-separator is-style-wide"/><!-- /wp:separator --><!-- wp:paragraph --><p>This is the description of your listing. It can be some sort of introduction, a quick overview, or anything you want to tell your visitors about this listing.</p><!-- /wp:paragraph --></div></div><!-- /wp:group -->',
				],
			],
			'business_4'    => [
				'post_types' => [ Core::NEWSPACK_LISTINGS_POST_TYPES['place'] ],
				'settings'   => [
					'title'       => __( 'Business Listing 4', 'newspack-listings' ),
					'categories'  => [ self::NEWSPACK_LISTINGS_BLOCK_PATTERN_CATEGORY ],
					'description' => _x(
						'Business description, map, contact info, and hours of operation.',
						'Block pattern description',
						'newspack-listings'
					),
					'content'     => '<!-- wp:group {"className":"newspack-listings__business-pattern-4"} --><div class="wp-block-group newspack-listings__business-pattern-4"><div class="wp-block-group__inner-container"><!-- wp:jetpack/map {"mapCenter":{"lng":-122.41941550000001,"lat":37.7749295}} --><div class="wp-block-jetpack-map" data-map-style="default" data-map-details="true" data-points="[]" data-zoom="13" data-map-center="{&quot;lng&quot;:-122.41941550000001,&quot;lat&quot;:37.7749295}" data-marker-color="red" data-show-fullscreen-button="true"></div><!-- /wp:jetpack/map --><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column {"width":"66.66%"} --><div class="wp-block-column" style="flex-basis:66.66%"><!-- wp:paragraph --><p>This is the description of your listing. It can be some sort of introduction, a quick overview, or anything you want to tell your visitors about this listing.</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column {"width":"33.33%"} --><div class="wp-block-column" style="flex-basis:33.33%"><!-- wp:group {"style":{"color":{"background":"#fafafa"}}} --><div class="wp-block-group has-background" style="background-color:#fafafa"><div class="wp-block-group__inner-container"><!-- wp:jetpack/contact-info --><div class="wp-block-jetpack-contact-info"><!-- wp:jetpack/email {"email":"email@yourgroovydomain.com"} --><div class="wp-block-jetpack-email"><a href="mailto:email@yourgroovydomain.com">email@yourgroovydomain.com</a></div><!-- /wp:jetpack/email --><!-- wp:jetpack/phone {"phone":"1-877 273-3049"} --><div class="wp-block-jetpack-phone"><a href="tel:18772733049">1-877 273-3049</a></div><!-- /wp:jetpack/phone --><!-- wp:jetpack/address {"address":"60 29th Street #343","city":"San Francisco","region":"CA","postal":"94110"} --><div class="wp-block-jetpack-address"><div class="jetpack-address__address jetpack-address__address1">60 29th Street #343</div><div><span class="jetpack-address__city">San Francisco</span>, <span class="jetpack-address__region">CA</span> <span class="jetpack-address__postal">94110</span></div></div><!-- /wp:jetpack/address --></div><!-- /wp:jetpack/contact-info --><!-- wp:jetpack/business-hours /--></div></div><!-- /wp:group --></div><!-- /wp:column --></div><!-- /wp:columns --></div></div><!-- /wp:group -->', // phpcs:ignore WordPressVIPMinimum.Security.Mustache.OutputNotation
				],
			],
			'business_5'    => [
				'post_types' => [ Core::NEWSPACK_LISTINGS_POST_TYPES['place'] ],
				'settings'   => [
					'title'       => __( 'Business Listing 5', 'newspack-listings' ),
					'categories'  => [ self::NEWSPACK_LISTINGS_BLOCK_PATTERN_CATEGORY ],
					'description' => _x(
						'Business description, map, contact info, and hours of operation.',
						'Block pattern description',
						'newspack-listings'
					),
					'content'     => '<!-- wp:group {"className":"newspack-listings__business-pattern-5"} --><div class="wp-block-group newspack-listings__business-pattern-5"><div class="wp-block-group__inner-container"><!-- wp:columns {"className":"is-style-default"} --><div class="wp-block-columns is-style-default"><!-- wp:column --><div class="wp-block-column"><!-- wp:jetpack/map {"mapCenter":{"lng":-122.41941550000001,"lat":37.7749295}} --><div class="wp-block-jetpack-map" data-map-style="default" data-map-details="true" data-points="[]" data-zoom="13" data-map-center="{&quot;lng&quot;:-122.41941550000001,&quot;lat&quot;:37.7749295}" data-marker-color="red" data-show-fullscreen-button="true"></div><!-- /wp:jetpack/map --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>This is the description of your listing. It can be some sort of introduction, a quick overview, or anything you want to tell your visitors about this listing.</p><!-- /wp:paragraph --><!-- wp:jetpack/contact-info --><div class="wp-block-jetpack-contact-info"><!-- wp:jetpack/email {"email":"email@yourgroovydomain.com"} --><div class="wp-block-jetpack-email"><a href="mailto:email@yourgroovydomain.com">email@yourgroovydomain.com</a></div><!-- /wp:jetpack/email --><!-- wp:jetpack/phone {"phone":"1-877 273-3049"} --><div class="wp-block-jetpack-phone"><a href="tel:18772733049">1-877 273-3049</a></div><!-- /wp:jetpack/phone --><!-- wp:jetpack/address {"address":"60 29th Street #343","city":"San Francisco","region":"CA","postal":"94110"} --><div class="wp-block-jetpack-address"><div class="jetpack-address__address jetpack-address__address1">60 29th Street #343</div><div><span class="jetpack-address__city">San Francisco</span>, <span class="jetpack-address__region">CA</span> <span class="jetpack-address__postal">94110</span></div></div><!-- /wp:jetpack/address --></div><!-- /wp:jetpack/contact-info --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:jetpack/business-hours /--></div><!-- /wp:column --></div><!-- /wp:columns --></div></div><!-- /wp:group -->',
				],
			],
			'real_estate_1' => [
				'post_types' => [ Core::NEWSPACK_LISTINGS_POST_TYPES['marketplace'] ],
				'settings'   => [
					'title'       => __( 'Real Estate Listing 1', 'newspack-listings' ),
					'categories'  => [ self::NEWSPACK_LISTINGS_BLOCK_PATTERN_CATEGORY ],
					'description' => _x(
						'Real estate listing with price, address, image slideshow, property info, description, and map.',
						'Block pattern description',
						'newspack-listings'
					),
					'content'     => '<!-- wp:group {"className":"newspack-listings__real-estate-pattern-1"} --> <div class="wp-block-group newspack-listings__real-estate-pattern-1"><div class="wp-block-group__inner-container"><!-- wp:media-text {"align":"","linkDestination":"none","mediaType":"image","mediaWidth":66,"imageFill":true} --> <div class="wp-block-media-text is-stacked-on-mobile is-image-fill" style="grid-template-columns:66% auto"><figure class="wp-block-media-text__media"><img alt=""/></figure><div class="wp-block-media-text__content"><!-- wp:jetpack/tiled-gallery {"className":"is-style-square","ids":[]} /--></div></div> <!-- /wp:media-text -->  <!-- wp:columns --> <div class="wp-block-columns"><!-- wp:column {"width":"66.66%"} --> <div class="wp-block-column" style="flex-basis:66.66%"><!-- wp:separator {"className":"is-style-wide"} --> <hr class="wp-block-separator is-style-wide"/> <!-- /wp:separator -->  <!-- wp:columns {"className":"newspack-listings__real-estate-pattern-1__details"} --> <div class="wp-block-columns newspack-listings__real-estate-pattern-1__details"><!-- wp:column {"width":"40%"} --> <div class="wp-block-column" style="flex-basis:40%"><!-- wp:newspack-listings/price {"price":999999,"formattedPrice":"$999,999","showDecimals":false} /-->  <!-- wp:paragraph {"fontSize":"small"} --> <p class="has-small-font-size">Price</p> <!-- /wp:paragraph --></div> <!-- /wp:column -->  <!-- wp:column {"width":"20%"} --> <div class="wp-block-column" style="flex-basis:20%"><!-- wp:paragraph {"fontSize":"large"} --> <p class="has-large-font-size"><strong>4</strong></p> <!-- /wp:paragraph -->  <!-- wp:paragraph {"fontSize":"small"} --> <p class="has-small-font-size">Bedrooms</p> <!-- /wp:paragraph --></div> <!-- /wp:column -->  <!-- wp:column {"width":"20%"} --> <div class="wp-block-column" style="flex-basis:20%"><!-- wp:paragraph {"fontSize":"large"} --> <p class="has-large-font-size"><strong>3</strong></p> <!-- /wp:paragraph -->  <!-- wp:paragraph {"fontSize":"small"} --> <p class="has-small-font-size">Bathrooms</p> <!-- /wp:paragraph --></div> <!-- /wp:column -->  <!-- wp:column {"width":"20%"} --> <div class="wp-block-column" style="flex-basis:20%"><!-- wp:paragraph {"fontSize":"large"} --> <p class="has-large-font-size"><strong>3250</strong></p> <!-- /wp:paragraph -->  <!-- wp:paragraph {"fontSize":"small"} --> <p class="has-small-font-size">Sq Ft</p> <!-- /wp:paragraph --></div> <!-- /wp:column --></div> <!-- /wp:columns -->  <!-- wp:separator {"className":"is-style-wide"} --> <hr class="wp-block-separator is-style-wide"/> <!-- /wp:separator -->  <!-- wp:paragraph --> <p>This is the description of your listing. It can be some sort of introduction, a quick overview, or anything you want to tell your visitors about this listing.</p> <!-- /wp:paragraph -->  <!-- wp:heading {"level":3} --> <h3>Property Details</h3> <!-- /wp:heading -->  <!-- wp:paragraph --> <p>This is a good place to add any additional details about your listing.</p> <!-- /wp:paragraph -->  <!-- wp:list --> <ul><li><strong>Year built:</strong> 2001</li><li><strong>Garage:</strong> 3 cars</li><li><strong>Basement:</strong> Unfinished</li><li><strong>Heating:</strong> Natural gas</li><li><strong>Cooling:</strong> Central AC</li><li><strong>Appliances included:</strong> Gas water heater, refrigerator, gas oven + range</li></ul> <!-- /wp:list --></div> <!-- /wp:column -->  <!-- wp:column {"width":"33.33%"} --> <div class="wp-block-column" style="flex-basis:33.33%"><!-- wp:jetpack/map {"points":[{"placeTitle":"West Hill Court","title":"West Hill Court","caption":"West Hill Court, Cupertino, California 95014, United States","id":"address.958477920958366","coordinates":{"longitude":-122.0404674,"latitude":37.3072975}}],"zoom":14,"mapCenter":{"lng":-122.0404674,"lat":37.3072975},"markerColor":"#3366ff","mapHeight":400,"className":"is-style-default"} --> <div class="wp-block-jetpack-map is-style-default" data-map-style="default" data-map-details="true" data-points="[{&quot;placeTitle&quot;:&quot;West Hill Court&quot;,&quot;title&quot;:&quot;West Hill Court&quot;,&quot;caption&quot;:&quot;West Hill Court, Cupertino, California 95014, United States&quot;,&quot;id&quot;:&quot;address.958477920958366&quot;,&quot;coordinates&quot;:{&quot;longitude&quot;:-122.0404674,&quot;latitude&quot;:37.3072975}}]" data-zoom="14" data-map-center="{&quot;lng&quot;:-122.0404674,&quot;lat&quot;:37.3072975}" data-marker-color="#3366ff" data-map-height="400" data-show-fullscreen-button="true"><ul><li><a href="https://www.google.com/maps/search/?api=1&amp;query=37.3072975,-122.0404674">West Hill Court</a></li></ul></div> <!-- /wp:jetpack/map -->  <!-- wp:heading {"level":3} --> <h3>Schedule a Private Showing</h3> <!-- /wp:heading -->  <!-- wp:jetpack/contact-info --> <div class="wp-block-jetpack-contact-info"><!-- wp:jetpack/email {"email":"email@yourgroovydomain.com"} --> <div class="wp-block-jetpack-email"><a href="mailto:email@yourgroovydomain.com">email@yourgroovydomain.com</a></div> <!-- /wp:jetpack/email -->  <!-- wp:jetpack/phone {"phone":"(123)-456-789"} --> <div class="wp-block-jetpack-phone"><a href="tel:123456789">(123)-456-789</a></div> <!-- /wp:jetpack/phone -->  <!-- wp:jetpack/address {"address":"1 Example Street","city":"Anytown","postal":"10100","country":"USA"} --> <div class="wp-block-jetpack-address"><div class="jetpack-address__address jetpack-address__address1">1 Example Street</div><div><span class="jetpack-address__city">Anytown</span>, <span class="jetpack-address__region"></span> <span class="jetpack-address__postal">10100</span></div><div class="jetpack-address__country">USA</div></div> <!-- /wp:jetpack/address --></div> <!-- /wp:jetpack/contact-info --></div> <!-- /wp:column --></div> <!-- /wp:columns --></div></div> <!-- /wp:group -->',
				],
			],
			'real_estate_2' => [
				'post_types' => [ Core::NEWSPACK_LISTINGS_POST_TYPES['marketplace'] ],
				'settings'   => [
					'title'       => __( 'Real Estate Listing 2', 'newspack-listings' ),
					'categories'  => [ self::NEWSPACK_LISTINGS_BLOCK_PATTERN_CATEGORY ],
					'description' => _x(
						'Real estate listing with price, address, image slideshow, property info, description, and map.',
						'Block pattern description',
						'newspack-listings'
					),
					'content'     => '<!-- wp:group {"className":"newspack-listings__real-estate-pattern-2"} --> <div class="wp-block-group newspack-listings__real-estate-pattern-2"><div class="wp-block-group__inner-container"><!-- wp:columns --> <div class="wp-block-columns"><!-- wp:column {"width":"50%","className":"newspack-listings__real-estate-pattern-2__gallery"} --> <div class="wp-block-column newspack-listings__real-estate-pattern-2__gallery" style="flex-basis:50%"><!-- wp:image {"sizeSlug":"large","linkDestination":"none"} --> <figure class="wp-block-image size-large"><img alt=""/></figure> <!-- /wp:image -->  <!-- wp:jetpack/tiled-gallery {"columns":0,"columnWidths":[["100.00000"]],"ids":[]} /-->  <!-- wp:heading {"level":3} --> <h3>Schedule a Private Showing</h3> <!-- /wp:heading -->  <!-- wp:jetpack/contact-info --> <div class="wp-block-jetpack-contact-info"><!-- wp:jetpack/email {"email":"email@yourgroovydomain.com"} --> <div class="wp-block-jetpack-email"><a href="mailto:email@yourgroovydomain.com">email@yourgroovydomain.com</a></div> <!-- /wp:jetpack/email -->  <!-- wp:jetpack/phone {"phone":"(123)-456-789"} --> <div class="wp-block-jetpack-phone"><a href="tel:123456789">(123)-456-789</a></div> <!-- /wp:jetpack/phone -->  <!-- wp:jetpack/address {"address":"1 Example Street","city":"Anytown","postal":"10100","country":"USA"} --> <div class="wp-block-jetpack-address"><div class="jetpack-address__address jetpack-address__address1">1 Example Street</div><div><span class="jetpack-address__city">Anytown</span>, <span class="jetpack-address__region"></span> <span class="jetpack-address__postal">10100</span></div><div class="jetpack-address__country">USA</div></div> <!-- /wp:jetpack/address --></div> <!-- /wp:jetpack/contact-info --></div> <!-- /wp:column -->  <!-- wp:column {"width":"50%"} --> <div class="wp-block-column" style="flex-basis:50%"><!-- wp:newspack-listings/price {"price":999999,"formattedPrice":"$999,999","showDecimals":false} /-->  <!-- wp:paragraph --> <p>4 Bedrooms / 3 Bathrooms / 3250 Sq Ft</p> <!-- /wp:paragraph -->  <!-- wp:separator {"className":"is-style-wide"} --> <hr class="wp-block-separator is-style-wide"/> <!-- /wp:separator -->  <!-- wp:paragraph --> <p>This is the description of your listing. It can be some sort of introduction, a quick overview, or anything you want to tell your visitors about this listing.</p> <!-- /wp:paragraph -->  <!-- wp:separator {"className":"is-style-wide"} --> <hr class="wp-block-separator is-style-wide"/> <!-- /wp:separator -->  <!-- wp:heading {"level":3} --> <h3>Property Details</h3> <!-- /wp:heading -->  <!-- wp:paragraph --> <p>This is a good place to add any additional details about your listing.</p> <!-- /wp:paragraph -->  <!-- wp:list --> <ul><li><strong>Year built:</strong> 2001</li><li><strong>Garage:</strong> 3 cars</li><li><strong>Basement:</strong> Unfinished</li><li><strong>Heating:</strong> Natural gas</li><li><strong>Cooling:</strong> Central AC</li><li><strong>Appliances included:</strong> Gas water heater, refrigerator, gas oven + range</li></ul> <!-- /wp:list -->  <!-- wp:jetpack/map {"points":[{"placeTitle":"Acorn Street","title":"Acorn Street","caption":"Acorn Street, Staten Island, New York 10306, United States","id":"address.2027762924269836","coordinates":{"longitude":-74.1256664,"latitude":40.5640121}}],"zoom":14,"mapCenter":{"lng":-74.1256664,"lat":40.5640121},"markerColor":"#3366ff","mapHeight":400,"className":"is-style-default"} --> <div class="wp-block-jetpack-map is-style-default" data-map-style="default" data-map-details="true" data-points="[{&quot;placeTitle&quot;:&quot;Acorn Street&quot;,&quot;title&quot;:&quot;Acorn Street&quot;,&quot;caption&quot;:&quot;Acorn Street, Staten Island, New York 10306, United States&quot;,&quot;id&quot;:&quot;address.2027762924269836&quot;,&quot;coordinates&quot;:{&quot;longitude&quot;:-74.1256664,&quot;latitude&quot;:40.5640121}}]" data-zoom="14" data-map-center="{&quot;lng&quot;:-74.1256664,&quot;lat&quot;:40.5640121}" data-marker-color="#3366ff" data-map-height="400" data-show-fullscreen-button="true"><ul><li><a href="https://www.google.com/maps/search/?api=1&amp;query=40.5640121,-74.1256664">Acorn Street</a></li></ul></div> <!-- /wp:jetpack/map --></div> <!-- /wp:column --></div> <!-- /wp:columns --></div></div> <!-- /wp:group -->',
				],
			],
			'real_estate_3' => [
				'post_types' => [ Core::NEWSPACK_LISTINGS_POST_TYPES['marketplace'] ],
				'settings'   => [
					'title'       => __( 'Real Estate Listing 3', 'newspack-listings' ),
					'categories'  => [ self::NEWSPACK_LISTINGS_BLOCK_PATTERN_CATEGORY ],
					'description' => _x(
						'Real estate listing with price, address, image slideshow, property info, description, and map.',
						'Block pattern description',
						'newspack-listings'
					),
					'content'     => '<!-- wp:group {"className":"newspack-listings__real-estate-pattern-3"} --> <div class="wp-block-group newspack-listings__real-estate-pattern-3"><div class="wp-block-group__inner-container"><!-- wp:jetpack/slideshow {"align":"","sizeSlug":"large"} /-->  <!-- wp:columns --> <div class="wp-block-columns"><!-- wp:column {"width":"66.66%"} --> <div class="wp-block-column" style="flex-basis:66.66%"><!-- wp:newspack-listings/price {"price":999999,"formattedPrice":"$999,999.00"} /-->  <!-- wp:paragraph --> <p>1234 Willow Grove Lane, Fuquay-Varina, NC 27526</p> <!-- /wp:paragraph -->  <!-- wp:columns {"className":"is-style-default"} --> <div class="wp-block-columns is-style-default"><!-- wp:column --> <div class="wp-block-column"><!-- wp:paragraph --> <p><strong>4</strong><br>Bed</p> <!-- /wp:paragraph -->  <!-- wp:paragraph --> <p><strong>3</strong><br>Bath</p> <!-- /wp:paragraph --></div> <!-- /wp:column -->  <!-- wp:column --> <div class="wp-block-column"><!-- wp:paragraph --> <p><strong><strong>3250</strong></strong><br>Sq Ft</p> <!-- /wp:paragraph -->  <!-- wp:paragraph --> <p><strong>$307k</strong><br>Price/Sq Ft</p> <!-- /wp:paragraph --></div> <!-- /wp:column -->  <!-- wp:column --> <div class="wp-block-column"><!-- wp:paragraph --> <p><strong>2006</strong><br>Year Built</p> <!-- /wp:paragraph -->  <!-- wp:paragraph --> <p><strong>3 cars<br></strong>Garage</p> <!-- /wp:paragraph --></div> <!-- /wp:column --></div> <!-- /wp:columns -->  <!-- wp:separator {"className":"is-style-wide"} --> <hr class="wp-block-separator is-style-wide"/> <!-- /wp:separator -->  <!-- wp:paragraph --> <p>This is the description of your listing. It can be some sort of introduction, a quick overview, or anything you want to tell your visitors about this listing.</p> <!-- /wp:paragraph -->  <!-- wp:heading {"level":3} --> <h3>Property Details</h3> <!-- /wp:heading -->  <!-- wp:paragraph --> <p>This is a good place to add any additional details about your listing.</p> <!-- /wp:paragraph -->  <!-- wp:list --> <ul><li><strong>Basement:</strong>&nbsp;Unfinished</li><li><strong>Heating:</strong>&nbsp;Natural gas</li><li><strong>Cooling:</strong>&nbsp;Central AC</li><li><strong>Appliances included:</strong>&nbsp;Gas water heater, refrigerator, gas oven + range</li></ul> <!-- /wp:list --></div> <!-- /wp:column -->  <!-- wp:column {"width":"33.33%"} --> <div class="wp-block-column" style="flex-basis:33.33%"><!-- wp:jetpack/map {"points":[{"placeTitle":"Willow Grove Lane","title":"Willow Grove Lane","caption":"Willow Grove Lane, Fuquay Varina, North Carolina 27526, United States","id":"address.532419435285432","coordinates":{"longitude":-78.7447645619509,"latitude":35.58795985}}],"zoom":14,"mapCenter":{"lng":-78.7447645619509,"lat":35.58795985},"markerColor":"#3366ff"} --> <div class="wp-block-jetpack-map" data-map-style="default" data-map-details="true" data-points="[{&quot;placeTitle&quot;:&quot;Willow Grove Lane&quot;,&quot;title&quot;:&quot;Willow Grove Lane&quot;,&quot;caption&quot;:&quot;Willow Grove Lane, Fuquay Varina, North Carolina 27526, United States&quot;,&quot;id&quot;:&quot;address.532419435285432&quot;,&quot;coordinates&quot;:{&quot;longitude&quot;:-78.7447645619509,&quot;latitude&quot;:35.58795985}}]" data-zoom="14" data-map-center="{&quot;lng&quot;:-78.7447645619509,&quot;lat&quot;:35.58795985}" data-marker-color="#3366ff" data-show-fullscreen-button="true"><ul><li><a href="https://www.google.com/maps/search/?api=1&amp;query=35.58795985,-78.7447645619509">Willow Grove Lane</a></li></ul></div> <!-- /wp:jetpack/map -->  <!-- wp:heading {"level":3} --> <h3>Schedule a Private Showing</h3> <!-- /wp:heading -->  <!-- wp:jetpack/contact-info --> <div class="wp-block-jetpack-contact-info"><!-- wp:jetpack/email {"email":"email@yourgroovydomain.com"} --> <div class="wp-block-jetpack-email"><a href="mailto:email@yourgroovydomain.com">email@yourgroovydomain.com</a></div> <!-- /wp:jetpack/email -->  <!-- wp:jetpack/phone {"phone":"(123)-456-789"} --> <div class="wp-block-jetpack-phone"><a href="tel:123456789">(123)-456-789</a></div> <!-- /wp:jetpack/phone -->  <!-- wp:jetpack/address {"address":"1 Example Street","city":"Anytown","postal":"10100","country":"USA"} --> <div class="wp-block-jetpack-address"><div class="jetpack-address__address jetpack-address__address1">1 Example Street</div><div><span class="jetpack-address__city">Anytown</span>, <span class="jetpack-address__region"></span> <span class="jetpack-address__postal">10100</span></div><div class="jetpack-address__country">USA</div></div> <!-- /wp:jetpack/address --></div> <!-- /wp:jetpack/contact-info --></div> <!-- /wp:column --></div> <!-- /wp:columns --></div></div> <!-- /wp:group -->',
				],
			],
			'classified_1'  => [
				'post_types' => [ Core::NEWSPACK_LISTINGS_POST_TYPES['marketplace'] ],
				'settings'   => [
					'title'       => __( 'Classified Ad 1', 'newspack-listings' ),
					'categories'  => [ self::NEWSPACK_LISTINGS_BLOCK_PATTERN_CATEGORY ],
					'description' => _x(
						'Classified ad with images, description and price.',
						'Block pattern description',
						'newspack-listings'
					),
					'content'     => '<!-- wp:group {"className":"newspack-listings__classified-ads-1"} --> <div class="wp-block-group newspack-listings__classified-ads-1"><div class="wp-block-group__inner-container"><!-- wp:columns --> <div class="wp-block-columns"><!-- wp:column {"width":"66.66%","className":"newspack-listings__classified-ads-1__images"} --> <div class="wp-block-column newspack-listings__classified-ads-1__images" style="flex-basis:66.66%"><!-- wp:image {"sizeSlug":"large","linkDestination":"none"} --> <figure class="wp-block-image size-large"><img alt=""/></figure> <!-- /wp:image -->  <!-- wp:jetpack/tiled-gallery {"className":"is-style-square","columns":0,"columnWidths":[["66.75138","33.24862"],["50.00000","50.00000"]],"ids":[]} /--></div> <!-- /wp:column -->  <!-- wp:column {"width":"33.33%"} --> <div class="wp-block-column" style="flex-basis:33.33%"><!-- wp:newspack-listings/price {"price":29.99,"formattedPrice":"$29.99"} /-->  <!-- wp:paragraph --> <p>This is the description of your listing. It can be some sort of introduction, a quick overview, or anything you want to tell your visitors about this listing.</p> <!-- /wp:paragraph --></div> <!-- /wp:column --></div> <!-- /wp:columns --></div></div> <!-- /wp:group -->',
				],
			],
			'classified_2'  => [
				'post_types' => [ Core::NEWSPACK_LISTINGS_POST_TYPES['marketplace'] ],
				'settings'   => [
					'title'       => __( 'Classified Ad 2', 'newspack-listings' ),
					'categories'  => [ self::NEWSPACK_LISTINGS_BLOCK_PATTERN_CATEGORY ],
					'description' => _x(
						'Classified ad with images, description and price.',
						'Block pattern description',
						'newspack-listings'
					),
					'content'     => '<!-- wp:group {"className":"newspack-listings__classified-ads-2"} --> <div class="wp-block-group newspack-listings__classified-ads-2"><div class="wp-block-group__inner-container"><!-- wp:columns --> <div class="wp-block-columns"><!-- wp:column {"width":"8%","className":"newspack-listings__classified-ads-2__gallery"} --> <div class="wp-block-column newspack-listings__classified-ads-2__gallery" style="flex-basis:8%"><!-- wp:jetpack/tiled-gallery {"className":"is-style-square","columns":0,"ids":[]} /--></div> <!-- /wp:column -->  <!-- wp:column {"width":"42%","className":"newspack-listings__classified-ads-2__image"} --> <div class="wp-block-column newspack-listings__classified-ads-2__image" style="flex-basis:42%"><!-- wp:image {"sizeSlug":"large","linkDestination":"none"} --> <figure class="wp-block-image size-large"><img alt=""/></figure> <!-- /wp:image --></div> <!-- /wp:column -->  <!-- wp:column {"width":"50%"} --> <div class="wp-block-column" style="flex-basis:50%"><!-- wp:newspack-listings/price {"price":29.99,"formattedPrice":"$29.99"} /-->  <!-- wp:paragraph --> <p>This is the description of your listing. It can be some sort of introduction, a quick overview, or anything you want to tell your visitors about this listing.</p> <!-- /wp:paragraph --></div> <!-- /wp:column --></div> <!-- /wp:columns --></div></div> <!-- /wp:group -->',
				],
			],
		];

		// If $slug matches a block pattern config, return just that one.
		if ( $slug && isset( $block_patterns[ $slug ] ) ) {
			return $block_patterns[ $slug ];
		}

		return $block_patterns;
	}

	/**
	 * Register custom block patterns for Newspack Listings.
	 * These patterns should only be available for certain CPTs.
	 */
	public static function register_block_patterns() {
		$block_patterns    = self::get_block_patterns();
		$current_post_type = Utils\get_post_type();

		foreach ( $block_patterns as $pattern_name => $config ) {
			if ( empty( $current_post_type ) || in_array( $current_post_type, $config['post_types'] ) ) {
				$pattern = register_block_pattern(
					'newspack-listings/' . $pattern_name,
					$config['settings']
				);
			}
		}
	}
}

Block_Patterns::instance();

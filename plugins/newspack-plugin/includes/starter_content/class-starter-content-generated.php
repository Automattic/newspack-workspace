<?php
/**
 * Randomly generated starter content.
 *
 * @package Newspack
 */

namespace Newspack;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Randomly generated Lorem Ipsum starter content.
 */
class Starter_Content_Generated extends Starter_Content_Provider {
	/**
	 * Starter categories.
	 *
	 * @var array A set of starter categories.
	 */
	protected static $starter_categories = [ 'Featured', 'Sports', 'Entertainment', 'Opinion', 'News', 'Events', 'Longform', 'Arts', 'Politics', 'Science', 'Tech', 'Health' ];

	/**
	 * Prefix for starter categories.
	 *
	 * @var string
	 */
	private static $starter_category_prefix = '_newspack_';

	/**
	 * Initialize starter content provider.
	 *
	 * @param array $args Extra params. Currently unused in this provider.
	 * @return bool True.
	 */
	public static function initialize( $args = [] ) {
		self::create_categories();
		return true;
	}

	/**
	 * Create the Xth starter post.
	 *
	 * @param int $post_index Index of the post to create.
	 * @return int Post ID of existing post at that index or newly created post at that index.
	 */
	public static function create_post( $post_index ) {
		$existing_post_id = self::get_starter_post( $post_index );
		if ( $existing_post_id ) {
			wp_trash_post( $existing_post_id );
		}

		if ( ! function_exists( 'wp_insert_post' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$page_templates = [ '', 'single-feature.php' ];
		$paragraphs     = explode( PHP_EOL, self::get_lipsum( 'paras', 5 ) );
		$title          = self::generate_title( $post_index );
		$post_data      = [
			'post_title'   => $title,
			'post_name'    => sanitize_title_with_dashes( $title, '', 'save' ),
			'post_status'  => 'publish',
			'post_content' => html_entity_decode(
				implode(
					'',
					array_map(
						function( $paragraph ) {
							return strlen( trim( $paragraph ) ) ?
								self::create_block( 'paragraph', null, '<p>' . $paragraph . '</p>' ) :
								'';
						},
						$paragraphs
					)
				)
			),
			'post_excerpt' => ucfirst( self::get_lipsum( 'words', wp_rand( 20, 30 ) ) ) . '.',
			'meta_input'   => [
				'newspack_post_subtitle' => self::generate_title() . '.',
			],
		];

		if ( Starter_Content::is_e2e() ) {
			$post_data['post_date'] = '2020-03-03 10:00:00';
		}

		$post_id = wp_insert_post( $post_data );

		$page_template_id = Starter_Content::is_e2e() ? ( $post_index % 2 ) : wp_rand( 0, 1 );

		$page_template = $page_templates[ $page_template_id ];

		$update = [
			'ID'            => $post_id,
			'page_template' => $page_template,
		];

		wp_update_post( $update );

		$newspack_featured_image_positions = [ null, 'behind', 'beside' ];

		$newspack_featured_image_index = Starter_Content::is_e2e() ? ( $post_index % 3 ) : wp_rand( 0, 2 );

		$newspack_featured_image_position = $newspack_featured_image_positions[ $newspack_featured_image_index ];

		if ( $newspack_featured_image_position ) {
			add_post_meta( $post_id, 'newspack_featured_image_position', $newspack_featured_image_position );
		}

		$attachment_id = self::add_featured_image( $post_id, $post_index );

		$categories = get_categories(
			[
				'orderby'    => 'count',
				'order'      => 'ASC',
				'hide_empty' => false,
				// Exclude 'Uncategorized' which has ID of 1.
				'exclude'    => '1', // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
				'number'     => 1,
			]
		);

		$category_ids = self::get_starter_categories_ids();
		$category_id = Starter_Content::is_e2e() ? $category_ids[ $post_index ] : $categories[0];

		wp_set_post_categories( $post_id, $category_id );

		// Set Yoast primary category.
		update_post_meta( $post_id, '_yoast_wpseo_primary_category', $category_id->term_id );

		wp_publish_post( $post_id );

		self::mark_starter_post( $post_id, $post_index );
		return $post_id;
	}

	/**
	 * Create starter categories.
	 *
	 * @return array Array of category IDs.
	 */
	public static function create_categories() {
		if ( ! function_exists( 'wp_create_category' ) ) {
			require_once ABSPATH . 'wp-admin/includes/taxonomy.php';
		}
		self::remove_starter_categories();
		$category_ids = array_map(
			function( $category ) {
				$created_category = wp_insert_term( $category, 'category', [ 'slug' => self::$starter_category_prefix . $category ] );
				return $created_category['term_id'];
			},
			self::$starter_categories
		);
		return $category_ids;
	}

	/**
	 * Create the starter homepage.
	 *
	 * @return int Post ID of existing starter homepage or newly created starter homepage.
	 */
	public static function create_homepage() {
		$existing_homepage_id = self::get_starter_homepage();
		if ( $existing_homepage_id ) {
			wp_delete_post( $existing_homepage_id, true );
		}

		if ( ! function_exists( 'wp_insert_post' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$categories = [];
		foreach ( self::get_starter_categories_ids() as $category_id ) {
			$categories[] = (object) [
				'term_id' => $category_id,
				'name'    => get_term( $category_id )->name,
			];
		}
		ob_start();
		?>
		<!-- wp:columns {"className":"is-style-borders"} --><div class="wp-block-columns is-style-borders">

			<!-- wp:column {"width":66.66} --><div class="wp-block-column" style="flex-basis:66.66%">

				<!-- wp:newspack-blocks/homepage-articles {"postsToShow":1,"categories":[<?php echo esc_attr( $categories[1]->term_id ); ?>],"sectionHeader":"<?php echo esc_html( $categories[1]->name ); ?>"} /-->

			</div><!-- /wp:column -->
			<!-- wp:column {"width":33.33} --><div class="wp-block-column" style="flex-basis:33.33%">

				<!-- wp:newspack-blocks/homepage-articles {"showExcerpt":false,"imageShape":"square","showAvatar":false,"postsToShow":3,"mediaPosition":"left","categories":[<?php echo esc_attr( $categories[2]->term_id ); ?>],"typeScale":2,"imageScale":1,"sectionHeader":"<?php echo esc_html( $categories[2]->name ); ?>"} /-->

				<!-- wp:separator {"className":"is-style-wide"} --><hr class="wp-block-separator is-style-wide"/><!-- /wp:separator -->

				<!-- wp:newspack-blocks/homepage-articles {"showExcerpt":false,"imageShape":"square","postsToShow":3, "mediaPosition":"left","categories":[<?php echo esc_attr( $categories[3]->term_id ); ?>],"typeScale":2,"imageScale":1,"sectionHeader":"<?php echo esc_html( $categories[3]->name ); ?>"} /-->

			</div><!-- /wp:column -->

		</div><!-- /wp:columns -->

		<!-- wp:separator {"className":"is-style-wide"} --><hr class="wp-block-separator is-style-wide"/><!-- /wp:separator -->

		<!-- wp:columns {"className":"is-style-borders"} --><div class="wp-block-columns is-style-borders">

			<!-- wp:column --><div class="wp-block-column">

				<!-- wp:newspack-blocks/homepage-articles {"className":"is-style-borders","showExcerpt":false,"showAuthor":false,"postsToShow":1,"categories":[<?php echo esc_attr( $categories[4]->term_id ); ?>],"typeScale":3,"imageScale":1,"sectionHeader":"<?php echo esc_html( $categories[4]->name ); ?>"} /-->

				<!-- wp:newspack-blocks/homepage-articles {"className":"is-style-borders","showExcerpt":false,"showAuthor":false,"showAvatar":false,"postsToShow":2,"mediaPosition":"left","categories":[<?php echo esc_attr( $categories[5]->term_id ); ?>],"typeScale":2,"imageScale":1} /-->

			</div><!-- /wp:column -->

			<!-- wp:column --><div class="wp-block-column">

				<!-- wp:newspack-blocks/homepage-articles {"className":"is-style-borders","showExcerpt":false,"showAuthor":false,"postsToShow":1,"categories":[<?php echo esc_attr( $categories[6]->term_id ); ?>],"typeScale":3,"imageScale":1,"sectionHeader":"<?php echo esc_html( $categories[6]->name ); ?>"} /-->

				<!-- wp:newspack-blocks/homepage-articles {"className":"is-style-borders","showExcerpt":false,"showAuthor":false,"showAvatar":false,"postsToShow":2,"mediaPosition":"left","categories":[<?php echo esc_attr( $categories[7]->term_id ); ?>],"typeScale":2,"imageScale":1} /-->

			</div><!-- /wp:column -->

			<!-- wp:column --><div class="wp-block-column">

				<!-- wp:newspack-blocks/homepage-articles {"className":"is-style-borders","showExcerpt":false,"showImage":false,"showAuthor":false,"showAvatar":false,"postsToShow":1,"mediaPosition":"left","categories":[<?php echo esc_attr( $categories[8]->term_id ); ?>],"imageScale":1,"sectionHeader":"<?php echo esc_html( $categories[8]->name ); ?>"} /-->

				<!-- wp:newspack-blocks/homepage-articles {"className":"is-style-borders","showExcerpt":false,"showImage":false,"showAuthor":false,"showAvatar":false,"postsToShow":4,"mediaPosition":"left","categories":[<?php echo esc_attr( $categories[9]->term_id ); ?>],"typeScale":3,"imageScale":1} /-->

			</div><!-- /wp:column -->

		</div><!-- /wp:columns -->
		<?php
		$content = ob_get_clean();

		$post_data = [
			'post_title'   => 'Homepage',
			'post_type'    => 'page',
			'post_content' => $content,
		];
		$page_id   = wp_insert_post( $post_data );
		wp_publish_post( $page_id );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );
		set_theme_mod( 'hide_front_page_title', true );
		self::mark_starter_homepage( $page_id );

		return $page_id;
	}

	/**
	 * Generate a post title
	 *
	 * @param int $post_index The index of the created post.
	 * @return string The title.
	 */
	public static function generate_title( $post_index = 0 ) {
		if ( Starter_Content::is_e2e() ) {
			return $post_index . ' ' . file_get_contents( NEWSPACK_ABSPATH . 'includes/raw_assets/markup/title.txt' );
		}
		$title = self::get_lipsum( 'words', wp_rand( 7, 14 ) );
		$title = ucfirst( strtolower( str_replace( '.', '', $title ) ) ); // Remove periods, convert to sentence case.
		return $title;
	}

	/**
	 * Lorem ipsum word list used by {@see self::get_lipsum()}.
	 *
	 * @var string[]
	 */
	private const LIPSUM_WORDS = [
		'lorem',
		'ipsum',
		'dolor',
		'sit',
		'amet',
		'consectetur',
		'adipiscing',
		'elit',
		'sed',
		'do',
		'eiusmod',
		'tempor',
		'incididunt',
		'ut',
		'labore',
		'et',
		'dolore',
		'magna',
		'aliqua',
		'enim',
		'ad',
		'minim',
		'veniam',
		'quis',
		'nostrud',
		'exercitation',
		'ullamco',
		'laboris',
		'nisi',
		'aliquip',
		'ex',
		'ea',
		'commodo',
		'consequat',
		'duis',
		'aute',
		'irure',
		'in',
		'reprehenderit',
		'voluptate',
		'velit',
		'esse',
		'cillum',
		'eu',
		'fugiat',
		'nulla',
		'pariatur',
		'excepteur',
		'sint',
		'occaecat',
		'cupidatat',
		'non',
		'proident',
		'sunt',
		'culpa',
		'qui',
		'officia',
		'deserunt',
		'mollit',
		'anim',
		'id',
		'est',
		'laborum',
		'at',
		'vero',
		'eos',
		'accusamus',
		'accusantium',
		'doloremque',
		'laudantium',
		'totam',
		'rem',
		'aperiam',
		'eaque',
		'ipsa',
		'quae',
		'ab',
		'illo',
		'inventore',
		'veritatis',
		'quasi',
		'architecto',
		'beatae',
		'vitae',
		'dicta',
		'explicabo',
		'nemo',
		'ipsam',
		'voluptas',
		'aspernatur',
		'odit',
		'aut',
		'sequi',
		'nesciunt',
		'neque',
		'porro',
		'quisquam',
		'dolorem',
		'adipisci',
		'numquam',
		'eius',
		'modi',
		'tempora',
		'incidunt',
		'magnam',
		'quaerat',
		'etiam',
		'minima',
		'nostrum',
		'exercitationem',
		'corporis',
		'suscipit',
		'laboriosam',
		'aliquid',
		'commodi',
		'consequatur',
		'quo',
		'vel',
		'illum',
		'eum',
		'iure',
	];

	/**
	 * Generate dummy text.
	 *
	 * @param string $type The type of Lorem Ipsum to retrieve: paras|words.
	 * @param int    $amount The number of items to retrieve.
	 * @return string Lorem Ipsum.
	 */
	public static function get_lipsum( $type, $amount ) {
		if ( Starter_Content::is_e2e() ) {
			return file_get_contents( NEWSPACK_ABSPATH . 'includes/raw_assets/markup/body.txt' );
		}
		if ( 'paras' === $type ) {
			return implode( PHP_EOL, self::lipsum_paragraphs( $amount ) );
		}
		return implode( ' ', self::lipsum_words( $amount ) );
	}

	/**
	 * Pick $count random words from the lorem ipsum pool.
	 *
	 * @param int $count Number of words to return.
	 * @return string[]
	 */
	private static function lipsum_words( $count ) {
		$max    = count( self::LIPSUM_WORDS ) - 1;
		$output = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$output[] = self::LIPSUM_WORDS[ wp_rand( 0, $max ) ];
		}
		return $output;
	}

	/**
	 * Build $count lorem ipsum paragraphs.
	 *
	 * @param int $count Number of paragraphs to return.
	 * @return string[]
	 */
	private static function lipsum_paragraphs( $count ) {
		$paragraphs = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$sentences = [];
			$num       = wp_rand( 4, 7 );
			for ( $j = 0; $j < $num; $j++ ) {
				$words       = self::lipsum_words( wp_rand( 6, 14 ) );
				$words[0]    = ucfirst( $words[0] );
				$sentences[] = implode( ' ', $words ) . '.';
			}
			$paragraphs[] = implode( ' ', $sentences );
		}
		return $paragraphs;
	}

	/**
	 * Create a Gutenberg Block
	 *
	 * @param string $block_type The block type.
	 * @param array  $attributes The attributes of the block.
	 * @param string $content The content of the block.
	 *
	 * @return string Block markup.
	 */
	private static function create_block( $block_type = null, $attributes = null, $content = '' ) {
		return sprintf(
			'<!-- wp:%s %s -->%s<!-- /wp:%s -->',
			$block_type,
			$attributes ? wp_json_encode( $attributes ) : '',
			$content,
			$block_type
		);
	}

	/**
	 * Download and add a featured image to a post.
	 *
	 * @param int $post_id The post ID.
	 * @param int $post_index The index of the post within the set of starter content posts.
	 *
	 * @return string Block markup.
	 */
	private static function add_featured_image( $post_id, $post_index ) {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_crop_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Use same image everywhere for e2e.
		$url = Starter_Content::is_e2e() ? 'https://picsum.photos/id/424/1200/800' : 'https://picsum.photos/1200/800';

		$temp_file = download_url( $url );

		if ( is_wp_error( $temp_file ) ) {
			return false;
		}

		$mime_type = mime_content_type( $temp_file );

		$file = array(
			'name'     => 'automated_upload.jpg',
			'type'     => $mime_type,
			'tmp_name' => $temp_file,
			'error'    => 0,
			'size'     => filesize( $temp_file ),
		);

		$overrides = array(
			'test_form'   => false,
			'test_size'   => true,
			'test_upload' => true,
		);

		$file_attributes = wp_handle_sideload( $file, $overrides );
		if ( is_wp_error( $file_attributes ) || ! empty( $file_attributes['error'] ) ) {
			return null;
		}
		$filename      = $file_attributes['file'];
		$filetype      = wp_check_filetype( basename( $filename ), null );
		$wp_upload_dir = wp_upload_dir();

		// Prepare an array of post data for the attachment.
		$attachment = array(
			'guid'           => $wp_upload_dir['url'] . '/' . basename( $filename ),
			'post_mime_type' => $filetype['type'],
			'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $filename ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id   = wp_insert_attachment( $attachment, $filename, $post_id );
		$attach_data = wp_generate_attachment_metadata( $attach_id, $filename );
		wp_update_attachment_metadata( $attach_id, $attach_data );

		set_post_thumbnail( $post_id, $attach_id );
	}

	/**
	 * Remove all starter content.
	 */
	public static function remove_starter_content() {
		self::remove_starter_categories();
		parent::remove_starter_content();
	}

	/**
	 * Get starter categories IDs.
	 */
	private static function get_starter_categories_ids() {
		global $wpdb;
		$category_ids = array_column(
			$wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT term_id FROM $wpdb->terms WHERE slug LIKE %s",
					self::$starter_category_prefix . '%'
				)
			),
			'term_id'
		);
		return $category_ids;
	}

	/**
	 * Removes starter categories.
	 */
	public static function remove_starter_categories() {
		foreach ( self::get_starter_categories_ids() as $category_id ) {
			wp_delete_category( $category_id );
		}
	}
}

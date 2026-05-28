<?php
/**
 * Newspack block caching layer
 *
 * @package Newspack_Blocks
 */

/**
 * Cache dynamic blocks for improved performance.
 */
class Newspack_Blocks_Caching {

	const CACHE_GROUP = 'newspack_blocks';

	/**
	 * Store the cache status for all blocks for this request.
	 *
	 * @var bool
	 */
	private static $can_serve_all_blocks_from_cache = true;

	/**
	 * Track visited reusable block IDs to spot recursion.
	 *
	 * @var array<int, bool>
	 */
	private static $visited_reusable_blocks = [];

	/**
	 * Store the current block index. This will be incremented with each cache reading,
	 * in order to add specificity to the cache key. The cache key consists of the
	 * hashed block attributes – which may be duplicated on a page – and a unique index.
	 * With index only, replacing the block would *not* invalidate cache, which is undesired.
	 * With hashed block attributes only, duplicated block configurations would result in
	 * duplication of rendered posts.
	 *
	 * @var int
	 */
	private static $current_block_index = 0;

	/**
	 * Add hooks and filters.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'setup_block_caching' ] );
	}

	/**
	 * Initialize block caching if needed.
	 */
	public static function setup_block_caching() {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			add_action( 'template_redirect', [ __CLASS__, 'check_all_blocks_cache_status' ] );
			add_filter( 'pre_render_block', [ __CLASS__, 'maybe_serve_cached_block' ], 10, 2 );
			add_filter( 'render_block', [ __CLASS__, 'maybe_cache_block' ], 9999, 2 );

			/**
			 * Cache duration in seconds for Newspack blocks (Homepage Posts, etc.).
			 * Blocks are cached for non-logged-in users to improve performance.
			 * Set to 0 to disable caching.
			 *
			 * @constant NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME
			 * @type     int
			 * @default  120 (two minutes)
			 * @status   draft
			 *
			 * @example define( 'NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME', 300 );
			 */
			if ( ! defined( 'NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME' ) ) {
				define( 'NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME', 120 );
			}
		}
	}

	/**
	 * Traverse all blocks on a page to check their cache status.
	 * This has to happen before any blocks are rendered.
	 */
	public static function check_all_blocks_cache_status() {
		if ( is_singular() ) {
			$post = get_post();
			if ( $post && property_exists( $post, 'post_content' ) ) {
				self::$visited_reusable_blocks = [];
				self::check_block_cache_status( parse_blocks( $post->post_content ) );
				// Reset the index after initial checks.
				self::$current_block_index = 0;
			}
		}
	}

	/**
	 * Check if a block can be cached, recursively.
	 *
	 * @param array $blocks Array of block data.
	 */
	private static function check_block_cache_status( $blocks ) {
		$cacheable_block_names = self::get_cacheable_blocks_names();
		foreach ( $blocks as $block_data ) {
			// Special treatment for reusable blocks, which are blocks stored in the posts table.
			if ( $block_data['blockName'] === 'core/block' && ! empty( $block_data['attrs']['ref'] ) ) {
				$ref = $block_data['attrs']['ref'];
				// Skip blocks we've seen before to avoid infinite recursion.
				// Based on core's render_block_core_block().
				if ( isset( self::$visited_reusable_blocks[ $ref ] ) ) {
					continue;
				}
				self::$visited_reusable_blocks[ $ref ] = true;
				$reusable_block_post = get_post( $ref );
				if ( $reusable_block_post && property_exists( $reusable_block_post, 'post_content' ) ) {
					self::check_block_cache_status( parse_blocks( $reusable_block_post->post_content ) );
				}
				unset( self::$visited_reusable_blocks[ $ref ] );
			}
			if ( in_array( $block_data['blockName'], $cacheable_block_names, true ) ) {
				if ( ! self::get_cached_block_data( $block_data ) ) {
					self::$can_serve_all_blocks_from_cache = false;
				}
			}
			if ( ! empty( $block_data['innerBlocks'] ) ) {
				self::check_block_cache_status( $block_data['innerBlocks'] );
			}
		}
	}

	/**
	 * Get cacheable blocks' names.
	 */
	public static function get_cacheable_blocks_names() {
		$cacheable_blocks = [
			'newspack-blocks/homepage-articles',
			'newspack-blocks/carousel',
		];
		return apply_filters( 'newspack_blocks_cacheable_blocks', $cacheable_blocks );
	}

	/**
	 * Determine whether a block should be cached.
	 *
	 * @param array $block_data Parsed block data.
	 * @return bool True if block should be cached. False otherwise.
	 */
	protected static function should_cache_block( $block_data ) {
		return in_array( $block_data['blockName'], self::get_cacheable_blocks_names(), true );
	}

	/**
	 * Get the cache key for a block's cache.
	 *
	 * @param array $block_data Parsed block data.
	 * @return string Cache key.
	 */
	protected static function get_cache_key( $block_data ) {
		$block_attributes = $block_data['attrs'];
		$cache_key        = 'np_cached_block_' . md5( wp_json_encode( $block_attributes ) ) . '_' . self::$current_block_index;
		self::$current_block_index++;
		return $cache_key;
	}

	/**
	 * Get the cache group for cached data.
	 *
	 * We're using a heuristic here to increase the rate of cache hits with very limited downside.
	 * Pages should each have their own cache group, because they are likely a landing page with various article blocks.
	 * Posts and other publicly_queryable post types should all share a cache group, because 99% of the time article blocks
	 * are in the sidebar, below-content, or (if within content) fetching specific posts. We want an article block in
	 * the e.g. sidebar to be served from cache across all posts.
	 *
	 * The tradeoff is that occasionally the current post may show up in an article block on a post.
	 * Archives should all use a global cache group, because there is nothing that would need de-duplication.
	 * Feeds have their own cache group because some blocks have (e.g. content loop) have different markup in feeds vs. site frontend.
	 *
	 * @return string Cache group.
	 */
	protected static function get_cache_group() {
		if ( is_singular() || is_front_page() ) {
			$post_type        = get_post_type();
			$post_type_object = get_post_type_object( $post_type );
			if ( ! $post_type_object->publicly_queryable ) {
				return sprintf( self::CACHE_GROUP . '-post-%d', get_the_ID() );
			}
			return self::CACHE_GROUP;
		} elseif ( is_feed() ) {
			return self::CACHE_GROUP . '-feed';
		} else {
			return self::CACHE_GROUP;
		}
	}

	/**
	 * Debug logging for observing cache behavior.
	 *
	 * @param string $message Message to log.
	 */
	protected static function debug_log( $message ) {
		if ( defined( 'NEWSPACK_LOG_LEVEL' ) && (int) NEWSPACK_LOG_LEVEL >= 4 && class_exists( 'Newspack\Logger' ) ) {
			Newspack\Logger::log( $message );
		}
	}

	/**
	 * Is the block available in the cache?
	 *
	 * @param array $block_data Parsed block data.
	 */
	public static function get_cached_block_data( $block_data ) {
		if ( ! self::should_cache_block( $block_data ) ) {
			return false;
		}

		$cache_key   = self::get_cache_key( $block_data );
		$cache_group = self::get_cache_group();
		self::debug_log( sprintf( 'Checking cache for item %s in group %s', $cache_key, $cache_group ) );

		$cached_data = wp_cache_get( $cache_key, $cache_group );
		if ( ! is_array( $cached_data ) || ! isset( $cached_data['timestamp_generated'], $cached_data['cached_content'] ) || empty( $cached_data['cached_content'] ) ) {
			self::debug_log( sprintf( 'Cached data not found for item %s in group %s', $cache_key, $cache_group ) );
			return false;
		}

		// Double-check to make sure cached data is still valid.
		if ( $cached_data['timestamp_generated'] + NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME < time() ) {
			if ( class_exists( 'Newspack\Logger' ) ) {
				Newspack\Logger::log( sprintf( 'Flushing cache for item %s in group %s because it expired', $cache_key, $cache_group ) );
			}
			wp_cache_delete( $cache_key, $cache_group );
			return false;
		}
		self::debug_log( sprintf( 'Found cached block: item %s in group %s', $cache_key, $cache_group ) );
		return $cached_data;
	}

	/**
	 * Serve a cached block if a valid one exists.
	 *
	 * @param string|null $block_html Block HTML. If you return something non-null here it will short-circuit block rendering.
	 * @param array       $block_data Parsed block data.
	 * @return string|null Block markup if served from cache. Default (usually null), otherwise.
	 */
	public static function maybe_serve_cached_block( $block_html, $block_data ) {
		if ( ! self::should_cache_block( $block_data ) ) {
			return $block_html;
		}

		// Equeue the styles needed to render the blocks.
		newspack_blocks_enqueue_block_homepage_articles_styles();

		if ( ! self::$can_serve_all_blocks_from_cache ) {
			return $block_html;
		}
		$cached_data = self::get_cached_block_data( $block_data );
		if ( ! $cached_data ) {
			return $block_html;
		}

		if ( 'newspack-blocks/homepage-articles' === $block_data['blockName'] ) {
			Newspack_Blocks::enqueue_view_assets( 'homepage-articles' );
		} elseif ( 'newspack-blocks/carousel' === $block_data['blockName'] ) {
			Newspack_Blocks::enqueue_view_assets( 'carousel' );
		}

		return $cached_data['cached_content'];
	}

	/**
	 * Save the block markup to cache. If we've reached this function, that means that the
	 * rendering wasn't short-circuited by a cached version, so we always cache here.
	 *
	 * @param string $block_html Block markup ready for rendering.
	 * @param array  $block_data Parsed block data.
	 * @return string Unmodified $block_html.
	 */
	public static function maybe_cache_block( $block_html, $block_data ) {
		if ( ! self::should_cache_block( $block_data ) ) {
			return $block_html;
		}

		$cache_key   = self::get_cache_key( $block_data );
		$cache_group = self::get_cache_group();

		$cache_data = [
			'timestamp_generated' => time(),
			'cached_content'      => $block_html,
		];
		wp_cache_set( $cache_key, $cache_data, $cache_group, NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined

		self::debug_log( sprintf( 'Caching block: item %s in group %s', $cache_key, $cache_group ) );

		return $block_html;
	}
}
Newspack_Blocks_Caching::init();

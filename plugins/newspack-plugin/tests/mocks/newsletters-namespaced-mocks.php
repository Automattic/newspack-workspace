<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound

namespace Newspack\Newsletters;

if ( ! class_exists( 'Newspack\Newsletters\Subscription_List' ) ) {
	class Subscription_List {
		// The real class's own prefix and format: a local list's public ID is the
		// prefix plus its post ID, and that ID is the join key a stored
		// `newsletter_subscribed_lists` entry is matched against. A remote-only list
		// carries the ESP's own ID instead; from_public_id() finds it by the post meta
		// the real class reads.
		const PUBLIC_ID_PREFIX = 'newspack-';

		const REMOTE_ID_META = '_remote_id';

		private $id;

		public function __construct( int $id ) {
			$this->id = $id;
		}

		public function get_id(): int {
			return $this->id;
		}

		public function get_public_id(): string {
			return self::PUBLIC_ID_PREFIX . $this->id;
		}

		public function get_title(): string {
			// The raw post title, as the real class returns it. get_the_title() would
			// texturize quotes and dashes, which the production path does not.
			$post = get_post( $this->id );
			return $post ? (string) $post->post_title : '';
		}

		/**
		 * Look up a list by its public ID. Like the real class, a public ID of the
		 * prefix plus a post ID resolves for ANY existing post, whatever its type;
		 * callers that need a list must check the post type themselves. Any other ID
		 * is looked up as an ESP-side remote ID, among list posts only.
		 *
		 * @param string $public_id The list's public ID.
		 *
		 * @return static|null
		 */
		public static function from_public_id( $public_id ) {
			if ( preg_match( '/^' . self::PUBLIC_ID_PREFIX . '([0-9]+)$/', (string) $public_id, $matches ) ) {
				return get_post( (int) $matches[1] ) ? new self( (int) $matches[1] ) : null;
			}
			$list_ids = get_posts(
				[
					'post_type'      => Subscription_Lists::CPT,
					'posts_per_page' => 1,
					'post_status'    => 'any',
					'fields'         => 'ids',
					'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						[
							'key'   => self::REMOTE_ID_META,
							'value' => (string) $public_id,
						],
					],
					'orderby'        => 'ID',
					'order'          => 'ASC',
				]
			);
			return 1 === count( $list_ids ) ? new self( (int) $list_ids[0] ) : null;
		}

		/**
		 * Look up a list by its ESP-side remote ID.
		 *
		 * The real implementation returns a Subscription_List when exactly one list
		 * carries the remote ID, and null otherwise. This stub always reports "not
		 * found", which is what callers in this harness need: Newsletters_Access
		 * treats a null return as "this send list is not in the registry". Tests that
		 * need a positive match should short-circuit the check with the
		 * `newspack_newsletters_access_is_valid_send_list_id` filter, which runs
		 * before this lookup. from_public_id() does its own remote lookup and does
		 * not call this.
		 *
		 * @param string $remote_id The ESP-side list ID.
		 *
		 * @return static|null Always null in this stub.
		 */
		public static function from_remote_id( $remote_id ) {
			return null;
		}
	}
}

if ( ! class_exists( 'Newspack\Newsletters\Subscription_Lists' ) ) {
	class Subscription_Lists {
		const CPT = 'newspack_nl_list';

		public static function get_all(): array {
			// Mirrors the real implementation's shape: a post query per call, whose
			// results prime the post cache the Subscription_List getters read from.
			// Calls are counted in $newsletter_lists_query_count so a test can assert
			// that a caller resolves the list registry once rather than per row.
			global $newsletter_lists_query_count;
			++$newsletter_lists_query_count;
			$posts = get_posts(
				[
					'post_type'      => self::CPT,
					'posts_per_page' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging -- Mirrors the real Subscription_Lists query; the test seeds a handful of lists.
					'post_status'    => 'any',
				]
			);
			return array_map(
				function ( $post ) {
					return new Subscription_List( $post->ID );
				},
				$posts
			);
		}
	}
}

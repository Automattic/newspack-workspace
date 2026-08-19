<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound

namespace Newspack\Newsletters;

if ( ! class_exists( 'Newspack\Newsletters\Subscription_List' ) ) {
	class Subscription_List {
		private $id;

		public function __construct( int $id ) {
			$this->id = $id;
		}

		public function get_id(): int {
			return $this->id;
		}

		public function get_public_id(): string {
			return 'list-' . $this->id;
		}

		public function get_title(): string {
			// Reads the list post, which get_all() has already primed into the post cache.
			return (string) get_the_title( $this->id );
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
		 * before this lookup.
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
		const CPT = 'np_newsletter_list';

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

<?php
/**
 * WP-CLI command to compare Access Control premium newsletter gates against the
 * ESP, and report readers whose subscription state disagrees with their
 * entitlement.
 *
 * Runs after a premium newsletter migration and after WooCommerce Memberships is
 * deactivated. Its purpose is evidence: a run with no leaks is what tells an
 * operator that cutover left nobody reading a paid newsletter they no longer pay
 * for.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Premium newsletter expected-vs-actual verification CLI command.
 */
class Premium_Newsletters_Verify {

	/**
	 * The newsletter list post type, used when Newspack Newsletters is not loaded.
	 *
	 * Mirrors Premium_Newsletters_Migration::NEWSLETTER_LIST_CPT_FALLBACK.
	 */
	const NEWSLETTER_LIST_CPT_FALLBACK = 'newspack_nl_list';

	/**
	 * Compare premium newsletter gates against the ESP and report the difference.
	 *
	 * For each gate with an active paid access mode, collects every reader who
	 * holds or has ever held one of its products, asks the gate whether each is
	 * entitled to its restricted lists, asks the ESP whether each is subscribed,
	 * and reports where the two disagree.
	 *
	 * Run this after WooCommerce Memberships is deactivated. Before that the
	 * evaluator hands restriction decisions back to Memberships, so every list
	 * reads as unrestricted and the comparison is meaningless — the command
	 * refuses rather than producing a misleading clean result.
	 *
	 * Exits non-zero while any leak or unresolved row remains, so a cutover script
	 * can gate on it.
	 *
	 * ## OPTIONS
	 *
	 * [--live]
	 * : Remove readers from lists they are not entitled to. Without this flag the command reports and writes nothing. It never adds a reader to a list: an on-demand reconcile cannot tell a reader who never subscribed from one who deliberately unsubscribed, so additions are left to the auto-signup flow, where the renewal snapshot protects an opt-out.
	 *
	 * [--gate=<id>]
	 * : Only verify this gate.
	 *
	 * [--batch-size=<number>]
	 * : Readers to check between pauses. Default 100.
	 *
	 * [--max-batches=<number>]
	 * : Stop after this many batches. Useful for sampling a large site before committing to a full run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack verify-premium-newsletters
	 *     wp newspack verify-premium-newsletters --live
	 *     wp newspack verify-premium-newsletters --gate=90
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 *
	 * @return void
	 */
	public function verify_premium_newsletters( $args, $assoc_args ) {
		$live = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'live', false );

		if ( ! class_exists( 'Newspack\Content_Gate' ) ) {
			WP_CLI::error( 'Newspack\Content_Gate class not found. Is newspack-plugin active? Aborting.' );
		}
		if ( ! class_exists( 'Newspack_Newsletters_Subscription' ) ) {
			WP_CLI::error( 'Newspack Newsletters is not active, so the ESP cannot be read. Aborting.' );
		}

		$blocked = self::describe_blocking_preflight(
			\Newspack\Memberships::is_active(),
			\Newspack\Content_Gate::is_gating_active(),
			function_exists( 'wcs_get_subscriptions' )
		);
		if ( null !== $blocked ) {
			WP_CLI::error( $blocked );
		}

		$gate_arg = \WP_CLI\Utils\get_flag_value( $assoc_args, 'gate', null );
		$gates    = \Newspack\Content_Gate::get_gates( \Newspack\Content_Gate::GATE_CPT, 'publish', true );
		if ( null !== $gate_arg ) {
			$gate_id = (int) $gate_arg;
			$gates   = array_values( array_filter( $gates, fn( $g ) => (int) $g['id'] === $gate_id ) );
			if ( empty( $gates ) ) {
				WP_CLI::error( sprintf( 'No published premium newsletter gate found with ID %s.', $gate_arg ) );
			}
		}

		$partitioned = self::partition_gates( $gates );

		foreach ( $partitioned['registration_only'] as $gate ) {
			WP_CLI::line(
				sprintf(
					'"%s" (gate %d): nothing to verify. It has no paid access rules, so every registered reader is entitled and no reader can be wrongly subscribed.',
					$gate['title'],
					$gate['id']
				)
			);
		}

		if ( empty( $partitioned['verifiable'] ) ) {
			WP_CLI::success( 'No premium newsletter gate restricts a list behind a product, so there is nothing to compare.' );
			return;
		}

		$auto_signup = (bool) \get_option( 'newspack_premium_newsletters_auto_signup', 1 );
		$batch_size  = max( 1, (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'batch-size', 100 ) );
		$max_batches = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'max-batches', 0 );

		$rows = [];
		foreach ( $partitioned['verifiable'] as $gate ) {
			$rows = array_merge( $rows, self::verify_gate( $gate, $auto_signup, $live, $batch_size, $max_batches ) );
		}

		$summary = self::summarize_rows( $rows );
		self::report( $rows, $summary, $auto_signup, $live );

		if ( self::verification_failed( $summary ) ) {
			WP_CLI::error(
				sprintf(
					'Verification failed: %d leak(s), %d unresolved. Do not treat this site as reconciled.',
					$summary['leak'],
					$summary['unresolved']
				)
			);
		}
		WP_CLI::success( 'Verification passed: no reader is on a premium list they are not entitled to.' );
	}

	/**
	 * Classify one reader's state on one restricted list.
	 *
	 * The asymmetry is deliberate. A restricted reader who is subscribed is always
	 * wrong: they are receiving paid content without entitlement. An entitled
	 * reader who is not subscribed is only wrong when auto-signup is on, because
	 * with it off the site never promised to subscribe them — they opt in
	 * themselves, and reporting that as a defect would fail every such site.
	 *
	 * @param bool $is_restricted Whether the gate restricts this list for this reader.
	 * @param bool $is_subscribed Whether the ESP has the reader on the list.
	 * @param bool $auto_signup   Whether the site auto-subscribes entitled readers.
	 *
	 * @return string One of 'leak', 'gap', 'ok', 'not_asserted'.
	 */
	private static function classify_reader( bool $is_restricted, bool $is_subscribed, bool $auto_signup ): string {
		if ( $is_restricted ) {
			return $is_subscribed ? 'leak' : 'ok';
		}
		if ( $is_subscribed ) {
			return 'ok';
		}
		return $auto_signup ? 'gap' : 'not_asserted';
	}

	/**
	 * Count each outcome across a run's rows.
	 *
	 * Every bucket is present even at zero so callers can read one without first
	 * checking it exists.
	 *
	 * @param array[] $rows Result rows, each carrying a 'status' key.
	 *
	 * @return array<string,int> Counts keyed by status.
	 */
	private static function summarize_rows( array $rows ): array {
		$summary = [
			'leak'         => 0,
			'gap'          => 0,
			'ok'           => 0,
			'not_asserted' => 0,
			'unresolved'   => 0,
		];
		foreach ( $rows as $row ) {
			$status = $row['status'] ?? '';
			if ( isset( $summary[ $status ] ) ) {
				++$summary[ $status ];
			}
		}
		return $summary;
	}

	/**
	 * Whether the run should report failure.
	 *
	 * Leaks fail because they are the defect this command looks for. Unresolved
	 * rows fail because an unread contact is not evidence of safety — without
	 * this, a provider outage would report a site as ready to flip. Gaps do not
	 * fail: nothing is leaking, and this command never writes an addition.
	 *
	 * @param array<string,int> $summary Counts from summarize_rows().
	 *
	 * @return bool
	 */
	private static function verification_failed( array $summary ): bool {
		return 0 < ( $summary['leak'] ?? 0 ) || 0 < ( $summary['unresolved'] ?? 0 );
	}

	/**
	 * The product IDs a gate's access rules require.
	 *
	 * Only `subscription` rules name products; other rule types carry values of a
	 * different kind (institution IDs, for instance) that must not be read as
	 * products. Values arrive as strings on some write paths, so they are cast.
	 *
	 * @param array $access_rules Grouped access rules.
	 *
	 * @return int[] Deduplicated product IDs.
	 */
	private static function product_ids_from_access_rules( array $access_rules ): array {
		$product_ids = [];
		foreach ( $access_rules as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			foreach ( $group as $rule ) {
				if ( 'subscription' !== ( $rule['slug'] ?? '' ) ) {
					continue;
				}
				foreach ( (array) ( $rule['value'] ?? [] ) as $product_id ) {
					$product_ids[] = (int) $product_id;
				}
			}
		}
		return array_values( array_unique( array_filter( $product_ids ) ) );
	}

	/**
	 * Split gates into the ones this command can check and the ones it cannot.
	 *
	 * A gate is verifiable when its paid access mode is active and names products:
	 * that gives both an exclusion to test and a bounded population to test it
	 * against. A registration-only gate has neither — every registered reader is
	 * entitled, so no reader can be wrongly subscribed, and the only possible gap
	 * spans the whole reader base. A paid gate with no products constrains nothing
	 * and lands in the same bucket.
	 *
	 * Unverifiable gates are returned rather than dropped so the report can name
	 * them and say why.
	 *
	 * @param array[] $gates Gate arrays as Content_Gate::get_gate() returns them.
	 *
	 * @return array{verifiable: array[], registration_only: array[]}
	 */
	private static function partition_gates( array $gates ): array {
		$verifiable        = [];
		$registration_only = [];
		foreach ( $gates as $gate ) {
			$is_paid     = ! empty( $gate['custom_access']['active'] );
			$product_ids = $is_paid
				? self::product_ids_from_access_rules( $gate['custom_access']['access_rules'] ?? [] )
				: [];
			if ( $is_paid && ! empty( $product_ids ) ) {
				$gate['product_ids'] = $product_ids;
				$verifiable[]        = $gate;
			} else {
				$registration_only[] = $gate;
			}
		}
		return [
			'verifiable'        => $verifiable,
			'registration_only' => $registration_only,
		];
	}

	/**
	 * Why this run must not proceed, or null when it may.
	 *
	 * Taking all three conditions as parameters keeps the decision testable without
	 * WooCommerce Memberships or WooCommerce Subscriptions, neither of which the
	 * unit-test harness loads.
	 *
	 * @param bool $memberships_active        Whether WooCommerce Memberships is active.
	 * @param bool $gating_active             Whether content gating is active.
	 * @param bool $subscriptions_available   Whether WooCommerce Subscriptions is active.
	 *
	 * @return string|null The reason to stop, or null to proceed.
	 */
	private static function describe_blocking_preflight( bool $memberships_active, bool $gating_active, bool $subscriptions_available ): ?string {
		if ( $memberships_active ) {
			return 'WooCommerce Memberships is still active, so every restricted list reads as unrestricted and this comparison would be meaningless. Run this after deactivating it.';
		}
		if ( ! $gating_active ) {
			return 'Content gating is inactive, so no gate enforces anything and there is no expected state to compare against. Enable Audience Management and Reader Activation first.';
		}
		if ( ! $subscriptions_available ) {
			return 'WooCommerce Subscriptions is not active, so the command cannot enumerate who holds a gate\'s products and would silently check nobody. Activate it and run this again.';
		}
		return null;
	}

	/**
	 * The newsletter list IDs a gate restricts.
	 *
	 * @param array $gate Gate array as Content_Gate::get_gate() returns it.
	 *
	 * @return int[] List post IDs.
	 */
	private static function restricted_list_ids_for_gate( array $gate ): array {
		$list_ids = [];
		foreach ( $gate['content_rules'] ?? [] as $content_rule ) {
			if ( 'newsletters' !== ( $content_rule['slug'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $content_rule['value'] ?? [] ) as $list_id ) {
				$list_ids[] = (int) $list_id;
			}
		}
		return array_values( array_unique( array_filter( $list_ids ) ) );
	}

	/**
	 * The readers whose state on this gate's lists is worth checking.
	 *
	 * Every subscription to one of the gate's products, in any status. Status
	 * `any` is the point: a cancelled or expired subscriber is exactly the reader
	 * who may still be on a paid list, so filtering to active would hide every
	 * leak this command exists to find.
	 *
	 * @param array $gate Gate array carrying a 'product_ids' key.
	 *
	 * @return int[] Distinct user IDs.
	 */
	private static function population_for_gate( array $gate ): array {
		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			return [];
		}
		$user_ids = [];
		foreach ( $gate['product_ids'] as $product_id ) {
			$subscriptions = \wcs_get_subscriptions(
				[
					'product_id'             => $product_id,
					'subscription_status'    => 'any',
					'subscriptions_per_page' => -1,
				]
			);
			foreach ( $subscriptions as $subscription ) {
				$user_id = (int) $subscription->get_user_id();
				if ( $user_id ) {
					$user_ids[ $user_id ] = true;
				}
			}
		}
		return array_map( 'intval', array_keys( $user_ids ) );
	}

	/**
	 * Check one gate's population against the ESP.
	 *
	 * @param array $gate        Gate array carrying 'product_ids'.
	 * @param bool  $auto_signup Whether the site auto-subscribes entitled readers.
	 * @param bool  $live        Whether to remove readers from lists they are not entitled to.
	 * @param int   $batch_size  Readers to check between pauses.
	 * @param int   $max_batches Stop after this many batches; 0 for no limit.
	 *
	 * @return array[] Result rows.
	 */
	private static function verify_gate( array $gate, bool $auto_signup, bool $live, int $batch_size, int $max_batches ): array {
		$list_ids = self::restricted_list_ids_for_gate( $gate );
		if ( empty( $list_ids ) ) {
			return [];
		}
		$population = self::population_for_gate( $gate );
		if ( empty( $population ) ) {
			WP_CLI::line( sprintf( '"%s" (gate %d): no reader holds or has held its products, so there is nobody to check.', $gate['title'], $gate['id'] ) );
			return [];
		}

		WP_CLI::line( sprintf( '"%s" (gate %d): checking %d reader(s) against %d list(s)…', $gate['title'], $gate['id'], count( $population ), count( $list_ids ) ) );

		$rows      = [];
		$batches   = 0;
		$in_batch  = 0;
		foreach ( $population as $user_id ) {
			$user = \get_user_by( 'id', $user_id );
			if ( ! $user ) {
				continue;
			}
			$contact_lists = \Newspack_Newsletters_Subscription::get_contact_lists( $user->user_email );
			$unresolved    = \is_wp_error( $contact_lists ) || ! is_array( $contact_lists );

			foreach ( $list_ids as $list_id ) {
				$public_id = self::public_id_for_list( $list_id );
				if ( $unresolved || null === $public_id ) {
					$rows[] = self::make_row( $gate, $list_id, $user, 'unresolved' );
					continue;
				}
				$is_restricted = \Newspack\Content_Restriction_Control::is_post_restricted( false, $list_id, $user_id );
				$is_subscribed = in_array( $public_id, $contact_lists, true );
				$status        = self::classify_reader( $is_restricted, $is_subscribed, $auto_signup );

				if ( 'leak' === $status && $live ) {
					$removed = \Newspack_Newsletters_Contacts::add_and_remove_lists( $user->user_email, [], [ $public_id ], 'Verifying premium newsletter lists' );
					$status  = \is_wp_error( $removed ) ? 'unresolved' : 'ok';
				}
				$rows[] = self::make_row( $gate, $list_id, $user, $status );
			}

			if ( ++$in_batch >= $batch_size ) {
				$in_batch = 0;
				++$batches;
				if ( $max_batches && $batches >= $max_batches ) {
					WP_CLI::warning( sprintf( 'Stopped after %d batch(es) because of --max-batches. This run does not cover the whole population.', $batches ) );
					break;
				}
				sleep( 1 );
			}
		}
		return $rows;
	}

	/**
	 * The newsletter list post type.
	 *
	 * Read from Newspack Newsletters when it is loaded so the two stay in step. The
	 * literal fallback is unreachable in practice — the command's preflight hard-errors
	 * when Newspack_Newsletters_Subscription is missing — but it keeps the helper
	 * correct on its own terms for any caller that reaches it outside that flow.
	 *
	 * @return string The list post type.
	 */
	private static function get_list_cpt(): string {
		if ( class_exists( 'Newspack\Newsletters\Subscription_Lists' ) ) {
			$cpt = \Newspack\Newsletters\Subscription_Lists::CPT;
			if ( $cpt ) {
				return $cpt;
			}
		}
		return self::NEWSLETTER_LIST_CPT_FALLBACK;
	}

	/**
	 * A list's public (ESP) ID, or null when it cannot be resolved.
	 *
	 * The post type is checked first because Subscription_List's constructor does not
	 * check it: it throws only when the post does not exist, so a live post of any
	 * other type would construct and hand back a bogus public ID. The guard is what
	 * makes a stale or mistyped ID return null instead of silently passing as a list.
	 *
	 * @param int $list_id The list post ID.
	 *
	 * @return string|null
	 */
	private static function public_id_for_list( int $list_id ): ?string {
		if ( \get_post_type( $list_id ) !== self::get_list_cpt() ) {
			return null;
		}
		if ( ! class_exists( 'Newspack\Newsletters\Subscription_List' ) ) {
			return null;
		}
		try {
			$list = new \Newspack\Newsletters\Subscription_List( $list_id );
		} catch ( \Throwable $e ) {
			return null;
		}
		$public_id = $list->get_public_id();
		return $public_id ? (string) $public_id : null;
	}

	/**
	 * Build one result row.
	 *
	 * @param array    $gate    Gate array.
	 * @param int      $list_id List post ID.
	 * @param \WP_User $user    The reader.
	 * @param string   $status  One of the classify_reader() statuses, or 'unresolved'.
	 *
	 * @return array
	 */
	private static function make_row( array $gate, int $list_id, \WP_User $user, string $status ): array {
		return [
			'gate'    => $gate['title'],
			'gate_id' => $gate['id'],
			'list_id' => $list_id,
			'user_id' => $user->ID,
			'email'   => $user->user_email,
			'status'  => $status,
		];
	}

	/**
	 * Print the summary, then the rows that need attention.
	 *
	 * @param array[]           $rows        Result rows.
	 * @param array<string,int> $summary     Counts from summarize_rows().
	 * @param bool              $auto_signup Whether auto-signup is on.
	 * @param bool              $live        Whether removals were written.
	 *
	 * @return void
	 */
	private static function report( array $rows, array $summary, bool $auto_signup, bool $live ): void {
		WP_CLI::line( '' );
		WP_CLI::line( $live ? '=== VERIFICATION SUMMARY (--live: leaks removed) ===' : '=== VERIFICATION SUMMARY (report only) ===' );
		WP_CLI::line( '' );
		\WP_CLI\Utils\format_items(
			'table',
			[
				[
					'Checked'      => count( $rows ),
					'Leaks'        => $summary['leak'],
					'Gaps'         => $summary['gap'],
					'OK'           => $summary['ok'],
					'Not asserted' => $summary['not_asserted'],
					'Unresolved'   => $summary['unresolved'],
				],
			],
			[ 'Checked', 'Leaks', 'Gaps', 'OK', 'Not asserted', 'Unresolved' ]
		);

		$attention = array_values( array_filter( $rows, fn( $r ) => in_array( $r['status'], [ 'leak', 'gap', 'unresolved' ], true ) ) );
		if ( ! empty( $attention ) ) {
			WP_CLI::line( '' );
			\WP_CLI\Utils\format_items(
				'table',
				array_map(
					fn( $r ) => [
						'Gate'   => $r['gate'],
						'List'   => $r['list_id'],
						'Reader' => $r['email'],
						'Status' => $r['status'],
					],
					$attention
				),
				[ 'Gate', 'List', 'Reader', 'Status' ]
			);
		}

		WP_CLI::line( '' );
		if ( ! $auto_signup ) {
			WP_CLI::line( 'Auto-signup is off, so an entitled reader who is not subscribed is counted as "not asserted" rather than a gap: they opt in themselves.' );
		}
		if ( 0 < $summary['gap'] ) {
			WP_CLI::line( 'Gaps are reported but never written. An on-demand run cannot tell a reader who never subscribed from one who unsubscribed on purpose, so additions are left to the auto-signup flow, where the renewal snapshot protects an opt-out.' );
		}
	}
}

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
	 * Each reader costs two ESP calls, not one: a contact lookup before the list
	 * read, because every shipped provider's list read swallows a failed request
	 * into an empty array and cannot itself tell a reader with no contact apart
	 * from one the provider simply failed to answer for. The contact lookup
	 * recovers that distinction for a reader who has no contact at all — but not
	 * past it: a reader whose contact lookup succeeds and whose list read then
	 * fails still reads as subscribed to nothing, the same as a genuinely
	 * unsubscribed reader. That window is narrow, and an outage large enough to
	 * matter trips the contact lookup for everyone, so it is left as is.
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
	 * : Readers to check between pauses. Default 100. Each reader costs two ESP calls, so a large batch size is a large burst of API traffic.
	 *
	 * [--max-batches=<number>]
	 * : Stop after roughly this many batches, across the whole run. Useful for sampling a large site before committing to a full run. Must be a positive integer; omit it for no limit. Not an exact cap: a gate's last batch is never counted against it, so a run spanning several gates can check somewhat more readers than batch-size times max-batches implies.
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

		// A bare --gate, --batch-size or --max-batches never reaches the validation
		// below: WP-CLI warns and strips it, so the command sees no value at all and
		// runs against every gate on the site. Under --live that means ESP removals
		// across the whole site rather than the one gate the operator named. The raw
		// command line is the only place the mistake is still visible.
		$bare_flags = self::get_valueless_value_flags();
		if ( ! empty( $bare_flags ) ) {
			WP_CLI::error( sprintf( 'The following flag(s) require a value but arrived without one: %s. WP-CLI strips a bare flag before the command runs, so the run would widen to every gate on the site — fix the invocation and re-run.', implode( ', ', $bare_flags ) ) );
		}

		// Validated here, alongside the bare-flag guard above and before any
		// class or preflight dependency, rather than down near its only use beside
		// $batch_size: a mistyped or non-positive --max-batches must never silently
		// produce a run that checks nobody, and this is where the command already
		// settles bad arguments before doing anything else. 0 remains the "no limit"
		// default when the flag is omitted entirely. An explicit negative value is
		// worse than that default: (int) casts it to a truthy number, so
		// verify_gate()'s `$max_batches && $batches >= $max_batches` guard is
		// satisfied on the very first gate (0 >= a negative number), and the whole
		// run skips every gate's population without checking a single reader. An
		// explicit 0 is rejected too, rather than silently reinterpreted as
		// "unlimited" — a value the operator typed should mean what it says.
		$max_batches_arg = \WP_CLI\Utils\get_flag_value( $assoc_args, 'max-batches', null );
		$max_batches     = 0;
		if ( null !== $max_batches_arg ) {
			$max_batches = (int) $max_batches_arg;
			if ( $max_batches <= 0 ) {
				WP_CLI::error( sprintf( 'Invalid --max-batches value "%s". Pass a positive integer.', $max_batches_arg ) );
			}
		}

		if ( ! class_exists( 'Newspack\Content_Gate' ) ) {
			WP_CLI::error( 'Newspack\Content_Gate class not found. Is newspack-plugin active? Aborting.' );
		}
		if ( ! class_exists( 'Newspack_Newsletters_Subscription' ) ) {
			WP_CLI::error( 'Newspack Newsletters is not active, so the ESP cannot be read. Aborting.' );
		}
		if ( $live && ! class_exists( 'Newspack_Newsletters_Contacts' ) ) {
			WP_CLI::error( 'Newspack_Newsletters_Contacts class not found, so --live cannot write removals. Aborting.' );
		}

		$blocked = self::describe_blocking_preflight(
			\Newspack\Memberships::is_active(),
			\Newspack\Content_Gate::is_gating_active(),
			function_exists( 'wcs_get_subscriptions' ),
			\Newspack_Newsletters_Subscription::has_subscription_management()
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

		// Shared across every gate below, by reference, so --max-batches caps the
		// whole run as its help text promises rather than resetting per gate.
		$batches = 0;

		$rows = [];
		foreach ( $partitioned['verifiable'] as $gate ) {
			$rows = array_merge( $rows, self::verify_gate( $gate, $auto_signup, $live, $batch_size, $max_batches, $batches ) );
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
	 * Value-requiring verify-premium-newsletters flags found bare (no `=value`) on
	 * the raw command line.
	 *
	 * WP-CLI validates flags against the command synopsis before invoking the command:
	 * a bare `--gate` (or `--batch-size` / `--max-batches`) draws only a warning, then
	 * the flag is stripped and the command receives the flag's default — so a run the
	 * operator scoped to one gate would silently widen to every gate on the site, and
	 * under --live that means ESP removals across the whole site rather than the one
	 * gate named. Reading the raw argv is the only place the mistake is still visible.
	 *
	 * Copied from Premium_Newsletters_Migration::get_valueless_value_flags()
	 * (includes/cli/class-premium-newsletters-migration.php) rather than reused: that
	 * method's value-flag list is hardcoded to `--plan`, so calling it here would mean
	 * adding a parameter to a sibling command's method for this command's sake. The
	 * logic below is otherwise identical.
	 *
	 * @param string[]|null $argv Raw argument vector; defaults to $_SERVER['argv'].
	 *
	 * @return string[] The value-requiring flags present without a value.
	 */
	private static function get_valueless_value_flags( $argv = null ): array {
		if ( null === $argv ) {
			$argv = isset( $_SERVER['argv'] ) ? (array) $_SERVER['argv'] : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		$value_flags = [ '--gate', '--batch-size', '--max-batches' ];
		$bare_flags  = [];
		foreach ( $argv as $token ) {
			if ( in_array( $token, $value_flags, true ) ) {
				$bare_flags[] = $token;
			}
		}
		return array_values( array_unique( $bare_flags ) );
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
	 * Taking all four conditions as parameters keeps the decision testable without
	 * WooCommerce Memberships, WooCommerce Subscriptions or a configured ESP, none of
	 * which the unit-test harness loads.
	 *
	 * @param bool $memberships_active                 Whether WooCommerce Memberships is active.
	 * @param bool $gating_active                      Whether content gating is active.
	 * @param bool $subscriptions_available             Whether WooCommerce Subscriptions is active.
	 * @param bool $subscription_management_available  Whether the active ESP supports subscription
	 *                                                  management (Newspack_Newsletters_Subscription::has_subscription_management()).
	 *
	 * @return string|null The reason to stop, or null to proceed.
	 */
	private static function describe_blocking_preflight( bool $memberships_active, bool $gating_active, bool $subscriptions_available, bool $subscription_management_available ): ?string {
		if ( $memberships_active ) {
			return 'WooCommerce Memberships is still active, so every restricted list reads as unrestricted and this comparison would be meaningless. Run this after deactivating it.';
		}
		if ( ! $gating_active ) {
			return 'Content gating is inactive, so no gate enforces anything and there is no expected state to compare against. Enable Audience Management and Reader Activation first.';
		}
		if ( ! $subscriptions_available ) {
			return 'WooCommerce Subscriptions is not active, so the command cannot enumerate who holds a gate\'s products and would silently check nobody. Activate it and run this again.';
		}
		if ( ! $subscription_management_available ) {
			return 'The active ESP does not support subscription management (no get_contact_lists()/update_contact_lists()), so every reader\'s list read would come back an error and the run would report the entire population as unresolved rather than comparing anything. Switch to a provider that supports subscription management before running this.';
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
	 * Whether a WP_Error from get_contact_data() means "no such contact" rather
	 * than a failed lookup.
	 *
	 * Mailchimp, Active Campaign and Constant Contact each expose a dedicated
	 * not-found code alongside their failure codes — `newspack_newsletters_mailchimp_contact_not_found`,
	 * `newspack_newsletters_contact_not_found` and
	 * `newspack_newsletters_constant_contact_contact_not_found` respectively — so
	 * matching the shared `_contact_not_found` suffix tells the two apart on any of
	 * the three. A provider with no such code would have every error here treated
	 * as unresolved rather than as an empty list, which is the same safe default
	 * this command already applies to any lookup it cannot trust.
	 *
	 * This duplicates the provider knowledge kept in
	 * Newspack\Reader_Activation\Integrations\ESP::pull_contact_data()'s
	 * `$not_found_codes` allowlist (includes/reader-activation/integrations/class-esp.php).
	 * The two already disagree — that allowlist predates Constant Contact's
	 * dedicated code and still names only Mailchimp's and Active Campaign's, so a
	 * Constant Contact miss reaches it as a hard error rather than being
	 * normalized. Out of scope here; noted so whoever closes that gap, or adds a
	 * fourth provider, finds both places.
	 *
	 * @param \WP_Error $error The error get_contact_data() returned.
	 *
	 * @return bool
	 */
	private static function is_contact_not_found_error( \WP_Error $error ): bool {
		return str_ends_with( $error->get_error_code(), '_contact_not_found' );
	}

	/**
	 * Whether a reader is subscribed to a list, per the ESP's raw contact-lists data.
	 *
	 * Mailchimp's get_contact_lists() builds its audience IDs with array_keys() over
	 * the contact's raw list data, and PHP silently casts an all-digit array key to
	 * int — so a numeric list's public ID would never strict-match here even though
	 * the reader really is subscribed, reading a leak as clean.
	 * Premium_Newsletters::add_and_remove_lists()
	 * (includes/content-gate/class-premium-newsletters.php), the runtime this
	 * command mirrors, compares the same two value spaces with array_intersect(),
	 * which string-casts both sides before comparing. Normalizing here keeps this
	 * command in agreement with it, without loosening the comparison below to loose
	 * in_array().
	 *
	 * @param string $public_id     The list's public (ESP) ID, as public_id_for_list() returns it.
	 * @param array  $contact_lists Raw contact-lists array, as get_contact_lists() returns it.
	 *
	 * @return bool
	 */
	private static function is_subscribed_to_list( string $public_id, array $contact_lists ): bool {
		$contact_lists = array_map( 'strval', $contact_lists );
		return in_array( $public_id, $contact_lists, true );
	}

	/**
	 * What the reader loop should do next, after checking one more reader.
	 *
	 * A pure question-and-answer seam over the batch bookkeeping verify_gate() used
	 * to do inline, so it can be tested without a WooCommerce Subscriptions
	 * population or an ESP to read. It only answers; it does not mutate $in_batch or
	 * $batches itself — the caller still owns stepping those, exactly as before.
	 *
	 * 'continue' covers two different situations identically: not yet at a batch
	 * boundary, and at a boundary with no more work left in this gate's population.
	 * The two are conflated deliberately because they are handled identically by the
	 * caller today — neither counts a batch nor sleeps — not because they mean the
	 * same thing.
	 *
	 * @param int  $in_batch          Readers checked since the last batch boundary, including
	 *                                the reader just checked.
	 * @param int  $batch_size        Readers to check between pauses.
	 * @param int  $batches           Batches completed so far, before this boundary is counted.
	 * @param int  $max_batches       Stop after this many batches, across the whole run; 0 for no limit.
	 * @param bool $more_work_remains Whether this gate's population has a reader left to check
	 *                                after the one just checked.
	 *
	 * @return string One of 'continue', 'pause', 'stop'.
	 */
	private static function next_batch_action( int $in_batch, int $batch_size, int $batches, int $max_batches, bool $more_work_remains ): string {
		if ( $in_batch < $batch_size ) {
			return 'continue';
		}
		if ( ! $more_work_remains ) {
			return 'continue';
		}
		$next_batches = $batches + 1;
		if ( $max_batches && $next_batches >= $max_batches ) {
			return 'stop';
		}
		return 'pause';
	}

	/**
	 * Check one gate's population against the ESP.
	 *
	 * @param array $gate        Gate array carrying 'product_ids'.
	 * @param bool  $auto_signup Whether the site auto-subscribes entitled readers.
	 * @param bool  $live        Whether to remove readers from lists they are not entitled to.
	 * @param int   $batch_size  Readers to check between pauses.
	 * @param int   $max_batches Stop after this many batches, across the whole run; 0 for no limit.
	 * @param int   $batches     Batches completed so far in this run, by reference. Shared across
	 *                           every gate's call so the cap in $max_batches means the whole run,
	 *                           not this gate alone.
	 *
	 * @return array[] Result rows.
	 */
	private static function verify_gate( array $gate, bool $auto_signup, bool $live, int $batch_size, int $max_batches, int &$batches ): array {
		$list_ids = self::restricted_list_ids_for_gate( $gate );
		if ( empty( $list_ids ) ) {
			return [];
		}
		if ( $max_batches && $batches >= $max_batches ) {
			WP_CLI::warning( sprintf( '"%s" (gate %d): skipped entirely because --max-batches was already reached by an earlier gate.', $gate['title'], $gate['id'] ) );
			return [];
		}
		$population = self::population_for_gate( $gate );
		if ( empty( $population ) ) {
			WP_CLI::line( sprintf( '"%s" (gate %d): no reader holds or has held its products, so there is nobody to check.', $gate['title'], $gate['id'] ) );
			return [];
		}

		WP_CLI::line( sprintf( '"%s" (gate %d): checking %d reader(s) against %d list(s)…', $gate['title'], $gate['id'], count( $population ), count( $list_ids ) ) );

		// Resolved once per gate rather than once per reader: a list ID's public ID
		// does not vary by reader, and every reader checks the same $list_ids.
		$public_ids = [];
		foreach ( $list_ids as $list_id ) {
			$public_ids[ $list_id ] = self::public_id_for_list( $list_id );
		}

		$rows              = [];
		$in_batch          = 0;
		$population_count  = count( $population );
		foreach ( $population as $index => $user_id ) {
			$user = \get_user_by( 'id', $user_id );
			if ( ! $user ) {
				// The subscription still names a list to check, but there is no WP_User
				// to build a make_row() from — no email, and nothing to ask the ESP
				// about. Leaving this reader out of the rows entirely would mean an
				// email still on a restricted list at the ESP could never be reported as
				// a leak, so it is recorded as unresolved instead, the same status a
				// failed ESP lookup gets.
				foreach ( $list_ids as $list_id ) {
					$rows[] = self::make_missing_user_row( $gate, $list_id, $user_id );
				}
				continue;
			}

			// Every shipped provider's get_contact_lists() swallows a failed API
			// call into an empty array rather than a WP_Error, so it cannot tell
			// "no contact" from "could not ask". Reading get_contact_data() first
			// recovers that distinction: its WP_Error code names a genuine miss on
			// all three providers (is_contact_not_found_error()), so that case
			// still counts as "no lists" rather than failing the reader.
			$contact_data = \Newspack_Newsletters_Subscription::get_contact_data( $user->user_email );
			if ( \is_wp_error( $contact_data ) && ! self::is_contact_not_found_error( $contact_data ) ) {
				foreach ( $list_ids as $list_id ) {
					$rows[] = self::make_row( $gate, $list_id, $user, 'unresolved' );
				}
			} else {
				$contact_lists = \is_wp_error( $contact_data ) ? [] : \Newspack_Newsletters_Subscription::get_contact_lists( $user->user_email );
				$unresolved    = \is_wp_error( $contact_lists ) || ! is_array( $contact_lists );

				foreach ( $list_ids as $list_id ) {
					$public_id = $public_ids[ $list_id ];
					if ( $unresolved || null === $public_id ) {
						$rows[] = self::make_row( $gate, $list_id, $user, 'unresolved' );
						continue;
					}
					$is_restricted = \Newspack\Content_Restriction_Control::is_post_restricted( false, $list_id, $user_id );
					$is_subscribed = self::is_subscribed_to_list( $public_id, $contact_lists );
					$status        = self::classify_reader( $is_restricted, $is_subscribed, $auto_signup );

					if ( 'leak' === $status && $live ) {
						$removed = \Newspack_Newsletters_Contacts::add_and_remove_lists( $user->user_email, [], [ $public_id ], 'Verifying premium newsletter lists' );
						$status  = \is_wp_error( $removed ) ? 'unresolved' : 'ok';
					}
					$rows[] = self::make_row( $gate, $list_id, $user, $status );
				}
			}

			$more_work_remains = ( $index + 1 ) < $population_count;
			++$in_batch;
			$batch_action = self::next_batch_action( $in_batch, $batch_size, $batches, $max_batches, $more_work_remains );
			if ( $in_batch >= $batch_size ) {
				$in_batch = 0;
			}
			if ( 'pause' === $batch_action ) {
				++$batches;
				sleep( 1 );
			} elseif ( 'stop' === $batch_action ) {
				++$batches;
				WP_CLI::warning( sprintf( 'Stopped after %d batch(es) total because of --max-batches. This run does not cover the whole population.', $batches ) );
				break;
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
	 * Build one result row for a subscription whose WP user no longer exists.
	 *
	 * There is no \WP_User to build a make_row() row from, so there is no email to
	 * show either — only the ID the subscription still carries. Always 'unresolved':
	 * the ESP was never asked, so this is not evidence the reader is clean, the same
	 * as a failed lookup.
	 *
	 * @param array $gate    Gate array.
	 * @param int   $list_id List post ID.
	 * @param int   $user_id The subscription's user ID, which get_user_by() could not resolve.
	 *
	 * @return array
	 */
	private static function make_missing_user_row( array $gate, int $list_id, int $user_id ): array {
		return [
			'gate'    => $gate['title'],
			'gate_id' => $gate['id'],
			'list_id' => $list_id,
			'user_id' => $user_id,
			'email'   => sprintf( '(no WP user found for ID %d)', $user_id ),
			'status'  => 'unresolved',
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

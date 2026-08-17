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
	 * How many subscriptions to hydrate at a time while walking a gate's
	 * population.
	 *
	 * Not exposed as a flag, and unrelated to --batch-size: this paces database
	 * reads, which are local and cheap, while --batch-size paces ESP calls, which
	 * are neither. An operator has no reason to tune it, and tying the two together
	 * would make a small --batch-size mean a needlessly chatty walk.
	 */
	const POPULATION_CHUNK_SIZE = 200;

	/**
	 * A gate whose population the batch cap cut short mid-walk.
	 */
	const COVERAGE_TRUNCATED = 'truncated';

	/**
	 * A gate never walked at all, because an earlier gate had already spent the
	 * batch cap.
	 */
	const COVERAGE_CAP_EXHAUSTED = 'cap_exhausted';

	/**
	 * A verifiable gate whose population query returned nobody.
	 */
	const COVERAGE_EMPTY_POPULATION = 'empty_population';

	/**
	 * A gate paywalled entirely by access rules whose holders this command cannot
	 * enumerate, so none of its readers were checked.
	 */
	const COVERAGE_UNENUMERABLE_PAYWALL = 'unenumerable_paywall';

	/**
	 * A gate whose subscription population was walked, but which also grants
	 * access through a rule whose holders were never enumerated.
	 */
	const COVERAGE_PARTIAL_POPULATION = 'partial_population';

	/**
	 * Why each incompleteness reason means the run cannot be read as a pass.
	 *
	 * Keyed by the COVERAGE_* reason constants; each value is a sprintf template
	 * taking the gate title, the gate ID, and a per-gap detail string, in that
	 * order. A template is free to use only the first two — sprintf ignores
	 * arguments it has no placeholder for — and the detail is '' for every reason
	 * that does not record one. Adding a reason means adding a constant and an
	 * entry here — describe_coverage_gaps() renders whatever is in this map, and
	 * coverage_is_complete() does not care which reason it is looking at.
	 */
	const COVERAGE_REASON_MESSAGES = [
		self::COVERAGE_TRUNCATED            => '"%1$s" (gate %2$d): --max-batches stopped this gate part-way, so some of its readers were never checked.',
		self::COVERAGE_CAP_EXHAUSTED        => '"%1$s" (gate %2$d): skipped entirely, because an earlier gate had already spent --max-batches.',
		self::COVERAGE_EMPTY_POPULATION     => '"%1$s" (gate %2$d): no reader holds or has held its products, so nobody was checked. A gate whose products no subscription can match yields an empty population and a clean-looking run, which is indistinguishable from a gate that is genuinely unused.',
		self::COVERAGE_UNENUMERABLE_PAYWALL => '"%1$s" (gate %2$d): paywalled by %3$s. This command has no way to list who holds or has held that access, so not one of its readers was checked and a leak behind it would go unreported.',
		self::COVERAGE_PARTIAL_POPULATION   => '"%1$s" (gate %2$d): its subscription products were walked, but it also grants access through %3$s. Readers who reached its lists that way were never enumerated, so this gate is only partly checked.',
	];

	/**
	 * How each shipped access rule is named in operator-facing output.
	 *
	 * A slug with no entry here is printed as itself: third-party rules are
	 * registerable through Access_Rules::register_rule(), and naming one wrongly
	 * would be worse than naming it plainly.
	 */
	const RULE_LABELS = [
		'subscription'      => 'a subscription rule naming no product (any active subscription)',
		'one_time_purchase' => 'a one-time purchase rule',
		'institution'       => 'an institutional access rule',
		'email_domain'      => 'an email domain rule',
		'reader_data'       => 'a reader data rule',
	];

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
	 * Exits non-zero while any leak or unresolved row remains, and while the run's
	 * coverage is incomplete, so a cutover script can gate on it. A clean result
	 * over a population the run never finished walking is not evidence of anything,
	 * and must not read the same as a full pass.
	 *
	 * What a passing run claims is bounded by that population: readers who hold or
	 * have held one of a gate's products. It cannot see a reader who satisfied the
	 * source Memberships plan by buying a non-subscription product (the migration
	 * writes those product IDs into a `subscription` access rule, which a
	 * subscription lookup can never match), one who joined a list before it
	 * became premium, one whose membership was granted by hand, or a
	 * group-subscription member — entitled through someone else's subscription
	 * rather than holding one themselves, per Access_Rules::has_active_subscription()
	 * (includes/content-gate/class-access-rules.php). That population query keys on
	 * who holds a subscription, so a current group member missing from a list is
	 * never reported as a gap, and a lapsed group member still on a paid list is a
	 * leak this command cannot see either. Widening the population would not reach
	 * any of them — there is no provider-agnostic bulk read of ESP list membership
	 * to widen it with — so the terminal message names the population it checked
	 * rather than claiming the site as a whole.
	 *
	 * The same boundary decides which gates it can speak for at all. A gate
	 * paywalled by a rule other than `subscription` — a one-time purchase, an
	 * institution, an email domain, reader data — restricts readers this command
	 * cannot list, so it is named in the output and fails the run rather than
	 * passing as "nothing to verify". See unenumerable_paid_rules().
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
	 * A --live removal costs more again, on the write path only: the removal itself
	 * re-reads the contact before writing, and this command then re-reads the
	 * contact's lists to confirm the list is actually gone.
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
	 * : Readers to check between pauses. Default 100. A dry run costs two ESP calls per reader — a contact lookup and a list read. Under --live each removal adds more on top: the removal itself re-reads the contact before writing, and this command re-reads the contact's lists afterwards to confirm the removal landed. So a large batch size is a large burst of API traffic, and larger still on a run that is removing.
	 *
	 * [--max-batches=<number>]
	 * : Stop after roughly this many batches, across the whole run. Useful for sampling a large site before committing to a full run: the population is walked lazily, so a run that stops early never reads the rest of it. Must be a positive integer; omit it for no limit. Not an exact cap: a gate's last batch is never counted against it, so a run spanning several gates can check somewhat more readers than batch-size times max-batches implies.
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
			// The two functions the population walk actually calls, rather than the
			// wcs_get_subscriptions() it used to: all three ship together in
			// subscriptions-core, but a preflight should test what the run will use.
			function_exists( 'wcs_get_subscriptions_for_product' ) && function_exists( 'wcs_get_subscription' ),
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
		$coverage    = self::new_coverage();

		foreach ( $partitioned['registration_only'] as $gate ) {
			WP_CLI::line(
				sprintf(
					'"%s" (gate %d): nothing to verify. It has no paid access rules, so every registered reader is entitled and no reader can be wrongly subscribed.',
					$gate['title'],
					$gate['id']
				)
			);
		}

		foreach ( $partitioned['unenumerable'] as $gate ) {
			// A paid gate over content other than newsletters restricts nobody's
			// list membership, so its unenumerable rules cost this run nothing.
			if ( empty( self::restricted_list_ids_for_gate( $gate ) ) ) {
				WP_CLI::line( self::describe_gate_without_lists( $gate ) );
				continue;
			}
			$rules    = self::describe_rule_slugs( $gate['unenumerable_rules'] );
			$coverage = self::with_incomplete_gate( $coverage, self::COVERAGE_UNENUMERABLE_PAYWALL, $gate, $rules );
			WP_CLI::warning(
				sprintf(
					'"%s" (gate %d): paywalled by %s, so readers who lack it are restricted — and this command cannot list who holds it, so none of them were checked.',
					$gate['title'],
					$gate['id'],
					$rules
				)
			);
		}

		if ( empty( $partitioned['verifiable'] ) ) {
			// Reached with an incomplete coverage record whenever an unenumerable
			// gate was named above, in which case this run fails without having
			// compared anything. The success line below is only reachable when that
			// list was empty, which is what keeps it true.
			$summary = self::new_summary();
			self::print_coverage_gaps( $coverage );
			if ( self::verification_failed( $summary, $coverage ) ) {
				WP_CLI::error( self::describe_failure( $summary, $coverage ) );
			}
			WP_CLI::success( 'No premium newsletter gate restricts a list behind a product, so there is nothing to compare.' );
			return;
		}

		$auto_signup = (bool) \get_option( 'newspack_premium_newsletters_auto_signup', 1 );
		$batch_size  = max( 1, (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'batch-size', 100 ) );

		// Shared across every gate below, by reference, so --max-batches caps the
		// whole run as its help text promises rather than resetting per gate.
		$batches = 0;

		$rows    = [];
		$summary = self::new_summary();
		foreach ( $partitioned['verifiable'] as $gate ) {
			$rows = array_merge( $rows, self::verify_gate( $gate, $auto_signup, $live, $batch_size, $max_batches, $batches, $coverage, $summary ) );
		}

		self::report( $rows, $summary, $coverage, $auto_signup, $live );

		if ( self::verification_failed( $summary, $coverage ) ) {
			WP_CLI::error( self::describe_failure( $summary, $coverage ) );
		}
		WP_CLI::success( self::describe_success( $coverage ) );
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
	 * What a --live removal attempt actually achieved, per the ESP's own answer
	 * afterwards.
	 *
	 * The removal's return value is not evidence on its own.
	 * Newspack_Newsletters_Contacts::add_and_remove_lists() hands off to the service
	 * provider's update_contact_lists_handling_local()
	 * (newspack-newsletters/includes/service-providers/class-newspack-newsletters-service-provider.php),
	 * which opens by re-reading the contact with get_contact_data(). When *that*
	 * read fails — for any reason, a transient provider failure included, since it
	 * only tests is_wp_error() — it takes its create-contact branch, upserts the
	 * contact with the (here empty) add list, and returns true. So a removal that
	 * never touched the list can still come back true, and treating that as success
	 * would stamp a still-subscribed reader as clean: a real leak converted into a
	 * pass, in the one place this command writes.
	 *
	 * Reading the lists back closes that. Anything short of the list being visibly
	 * gone is 'unresolved' rather than 'removed' — a failed removal, a failed
	 * re-read and a re-read still showing the list all mean the same thing to an
	 * operator, which is that this reader's removal is not confirmed and the run
	 * has to be repeated.
	 *
	 * @param mixed  $removal_result What add_and_remove_lists() returned.
	 * @param mixed  $lists_after    What get_contact_lists() returned afterwards, or [] when the
	 *                               removal itself errored and no re-read was made.
	 * @param string $public_id      The list's public (ESP) ID.
	 *
	 * @return string 'removed' when the list is confirmed gone, 'unresolved' otherwise.
	 */
	private static function classify_removal( $removal_result, $lists_after, string $public_id ): string {
		if ( \is_wp_error( $removal_result ) ) {
			return 'unresolved';
		}
		if ( \is_wp_error( $lists_after ) || ! is_array( $lists_after ) ) {
			return 'unresolved';
		}
		return self::is_subscribed_to_list( $public_id, $lists_after ) ? 'unresolved' : 'removed';
	}

	/**
	 * The statuses whose rows the report lists one by one.
	 *
	 * Everything else is only ever counted, which is why those rows are not kept:
	 * see record_row(). 'removed' is here despite passing — it is the only
	 * irreversible thing this command does, and a run that unsubscribed 500 readers
	 * must not print the same output as one that found nothing.
	 */
	const ATTENTION_STATUSES = [ 'leak', 'removed', 'gap', 'unresolved' ];

	/**
	 * An empty count of outcomes, before any reader has been checked.
	 *
	 * Every bucket is present even at zero so callers can read one without first
	 * checking it exists. 'checked' counts every row the run produced, including
	 * the ones no longer kept.
	 *
	 * @return array<string,int>
	 */
	private static function new_summary(): array {
		return [
			'checked'      => 0,
			'leak'         => 0,
			'gap'          => 0,
			'ok'           => 0,
			'removed'      => 0,
			'not_asserted' => 0,
			'unresolved'   => 0,
		];
	}

	/**
	 * Count one more outcome.
	 *
	 * An unrecognized status still counts as checked, so the total cannot silently
	 * drift from the number of rows the run actually produced.
	 *
	 * @param array<string,int> $summary Counts so far.
	 * @param string            $status  The row's status.
	 *
	 * @return array<string,int> The counts with this outcome added.
	 */
	private static function with_status_counted( array $summary, string $status ): array {
		++$summary['checked'];
		if ( isset( $summary[ $status ] ) ) {
			++$summary[ $status ];
		}
		return $summary;
	}

	/**
	 * Whether the report lists this status row by row, rather than only counting it.
	 *
	 * @param string $status A row status.
	 *
	 * @return bool
	 */
	private static function status_needs_attention( string $status ): bool {
		return in_array( $status, self::ATTENTION_STATUSES, true );
	}

	/**
	 * Count a row, and keep it only if the report will show it.
	 *
	 * A passing row is read by nothing but the counts, so holding it for the length
	 * of the run costs memory to no end: a site with 200,000 clean readers on three
	 * lists would carry 600,000 arrays to print a single number. Counting as we go
	 * keeps that number exact and the memory flat.
	 *
	 * @param array[]           $rows    Retained rows, by reference.
	 * @param array<string,int> $summary Counts, by reference.
	 * @param array             $row     The row just produced.
	 *
	 * @return void
	 */
	private static function record_row( array &$rows, array &$summary, array $row ): void {
		$status  = (string) ( $row['status'] ?? '' );
		$summary = self::with_status_counted( $summary, $status );
		if ( self::status_needs_attention( $status ) ) {
			$rows[] = $row;
		}
	}

	/**
	 * An empty coverage record, before any gate has been walked.
	 *
	 * Coverage answers a question the status counts cannot: how much of the site
	 * this run actually looked at. Without it a run that checked nobody and a run
	 * that checked everyone and found nothing produce the same zero counts and the
	 * same exit status, which is the failure mode this record exists to end.
	 *
	 * Gate and reader IDs are held as array keys rather than values so a reader
	 * appearing under two gates is counted once. The counts are what the terminal
	 * message quotes, so they have to be distinct readers to mean anything.
	 *
	 * @return array{gate_ids: array<int,bool>, reader_ids: array<int,bool>, incomplete: array[]}
	 */
	private static function new_coverage(): array {
		return [
			'gate_ids'   => [],
			'reader_ids' => [],
			'incomplete' => [],
		];
	}

	/**
	 * Record that a gate's population is being walked.
	 *
	 * Called when the walk starts, not when it finishes: a gate the batch cap cuts
	 * short was still partly checked, and the readers it did reach are real
	 * coverage. What makes that run fail is the incompleteness entry recorded
	 * alongside, not the absence of the gate here.
	 *
	 * @param array $coverage Coverage record.
	 * @param array $gate     Gate array carrying an 'id'.
	 *
	 * @return array The coverage record with this gate counted.
	 */
	private static function with_gate_walked( array $coverage, array $gate ): array {
		$coverage['gate_ids'][ (int) ( $gate['id'] ?? 0 ) ] = true;
		return $coverage;
	}

	/**
	 * Record that one reader was checked.
	 *
	 * @param array $coverage Coverage record.
	 * @param int   $user_id  The reader's user ID.
	 *
	 * @return array The coverage record with this reader counted.
	 */
	private static function with_reader_checked( array $coverage, int $user_id ): array {
		$coverage['reader_ids'][ $user_id ] = true;
		return $coverage;
	}

	/**
	 * Record that a gate was not fully checked, and why.
	 *
	 * @param array  $coverage Coverage record.
	 * @param string $reason   One of the COVERAGE_* reason constants.
	 * @param array  $gate     Gate array carrying 'id' and 'title'.
	 * @param string $detail   Extra text the reason's message renders as its third
	 *                         placeholder, for reasons that vary per gate (which
	 *                         access rules could not be enumerated, say). Reasons
	 *                         whose message says the same thing every time leave it
	 *                         empty.
	 *
	 * @return array The coverage record with this gap noted.
	 */
	private static function with_incomplete_gate( array $coverage, string $reason, array $gate, string $detail = '' ): array {
		$coverage['incomplete'][] = [
			'reason'  => $reason,
			'gate'    => (string) ( $gate['title'] ?? '' ),
			'gate_id' => (int) ( $gate['id'] ?? 0 ),
			'detail'  => $detail,
		];
		return $coverage;
	}

	/**
	 * Whether every verifiable gate had its whole population walked.
	 *
	 * Deliberately not inferred from the counts. "Zero readers checked" can mean a
	 * site with no paid subscribers or a run that never got started, and no
	 * arithmetic over the status buckets tells those apart — only a recorded reason
	 * does.
	 *
	 * @param array $coverage Coverage record.
	 *
	 * @return bool
	 */
	private static function coverage_is_complete( array $coverage ): bool {
		return empty( $coverage['incomplete'] );
	}

	/**
	 * One human-readable line per gap in the run's coverage.
	 *
	 * A reason with no entry in COVERAGE_REASON_MESSAGES still produces a line
	 * naming the gate and the raw reason, so a reason added without a message is
	 * visible in the output rather than silently dropped from it.
	 *
	 * @param array $coverage Coverage record.
	 *
	 * @return string[] One line per incomplete gate, in the order they were recorded.
	 */
	private static function describe_coverage_gaps( array $coverage ): array {
		$lines = [];
		foreach ( $coverage['incomplete'] ?? [] as $gap ) {
			$reason   = $gap['reason'] ?? '';
			$template = self::COVERAGE_REASON_MESSAGES[ $reason ] ?? '"%1$s" (gate %2$d): not fully checked (' . $reason . ').';
			$lines[]  = sprintf( $template, $gap['gate'] ?? '', (int) ( $gap['gate_id'] ?? 0 ), (string) ( $gap['detail'] ?? '' ) );
		}
		return $lines;
	}

	/**
	 * How many distinct gates the run failed to check in full.
	 *
	 * Counted by gate rather than by entry: one gate can collect two reasons at
	 * once — a gate paywalled partly by rules this command cannot enumerate and
	 * then cut short by --max-batches records both — and "2 gate(s) not fully
	 * checked" for a run over a single gate would be false.
	 *
	 * @param array $coverage Coverage record.
	 *
	 * @return int
	 */
	private static function count_incomplete_gates( array $coverage ): int {
		$gate_ids = [];
		foreach ( $coverage['incomplete'] ?? [] as $gap ) {
			$gate_ids[ (int) ( $gap['gate_id'] ?? 0 ) ] = true;
		}
		return count( $gate_ids );
	}

	/**
	 * The population this run actually checked, as a phrase.
	 *
	 * @param array $coverage Coverage record.
	 *
	 * @return string For example "7 reader(s) across 2 gate(s)".
	 */
	private static function describe_checked_scope( array $coverage ): string {
		return sprintf(
			'%d reader(s) across %d gate(s)',
			count( $coverage['reader_ids'] ?? [] ),
			count( $coverage['gate_ids'] ?? [] )
		);
	}

	/**
	 * Whether the run should report failure.
	 *
	 * Leaks fail because they are the defect this command looks for. Unresolved
	 * rows fail because an unread contact is not evidence of safety — without this,
	 * a provider outage would report a site as ready to flip. Incomplete coverage
	 * fails for the same reason one step earlier: a population that was never
	 * walked cannot have been found clean, and a truncated run that exits 0 tells a
	 * cutover script the opposite of the truth.
	 *
	 * Gaps do not fail: nothing is leaking, and this command never writes an
	 * addition. Removals do not fail either — a removed leak is a fixed leak, and a
	 * --live run that removed everything it found is exactly the run an operator is
	 * trying to reach.
	 *
	 * @param array<string,int> $summary  Counts from new_summary(), added up by with_status_counted().
	 * @param array             $coverage Coverage record.
	 *
	 * @return bool
	 */
	private static function verification_failed( array $summary, array $coverage ): bool {
		return 0 < ( $summary['leak'] ?? 0 )
			|| 0 < ( $summary['unresolved'] ?? 0 )
			|| ! self::coverage_is_complete( $coverage );
	}

	/**
	 * The message a failing run ends on.
	 *
	 * Only the reasons that actually apply are named, so a run that failed purely
	 * on coverage does not also report "0 leak(s)" and invite the reader to
	 * conclude nothing was wrong.
	 *
	 * @param array<string,int> $summary  Counts from new_summary(), added up by with_status_counted().
	 * @param array             $coverage Coverage record.
	 *
	 * @return string
	 */
	private static function describe_failure( array $summary, array $coverage ): string {
		$reasons = [];
		if ( 0 < ( $summary['leak'] ?? 0 ) ) {
			$reasons[] = sprintf( '%d leak(s)', $summary['leak'] );
		}
		if ( 0 < ( $summary['unresolved'] ?? 0 ) ) {
			$reasons[] = sprintf( '%d unresolved row(s)', $summary['unresolved'] );
		}
		if ( ! self::coverage_is_complete( $coverage ) ) {
			$reasons[] = sprintf( '%d gate(s) not fully checked', self::count_incomplete_gates( $coverage ) );
		}
		return sprintf(
			'Verification failed: %s. Checked %s. Do not treat this site as reconciled.',
			implode( ', ', $reasons ),
			self::describe_checked_scope( $coverage )
		);
	}

	/**
	 * The message a passing run ends on.
	 *
	 * Scoped to the population the run walked, on purpose. The command can only
	 * enumerate readers holding a gate's products, and several reachable
	 * populations sit outside that — see the command docblock — so a claim about
	 * "no reader" would assert more than the evidence carries.
	 *
	 * @param array $coverage Coverage record.
	 *
	 * @return string
	 */
	private static function describe_success( array $coverage ): string {
		return sprintf(
			'Verification passed: of the %s checked, none is on a premium list they are not entitled to.',
			self::describe_checked_scope( $coverage )
		);
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
	 * The slugs of a gate's access rules that paywall it but whose population this
	 * command cannot enumerate.
	 *
	 * A rule's population is enumerable only when the site keeps a record of every
	 * reader who ever satisfied it, reachable from the rule's own value.
	 * `subscription` is the only shipped rule that qualifies: it names product IDs,
	 * and WooCommerce Subscriptions indexes subscriptions by product in any status,
	 * so a reader whose subscription ended is still findable — which is exactly the
	 * reader who leaks. The rest do not:
	 *
	 * - `one_time_purchase` names products too, and WooCommerce does record every
	 *   purchase, but there is no supported reverse lookup from a product to its
	 *   purchasers: wc_customer_bought_product() answers per customer, and
	 *   wc_get_orders() takes no product argument — which is why
	 *   Access_Rules::customer_bought_product_after()
	 *   (includes/content-gate/class-access-rules.php) reads a customer's orders and
	 *   walks their line items. Enumerating it would mean a bespoke order-item
	 *   query this command does not have, so it is named rather than pretended over.
	 * - `email_domain`, `reader_data` and `institution` are decided from the
	 *   reader's attributes at the moment of asking — the email domain on their
	 *   account, their reader-data values, and an institution match that
	 *   Institution::evaluate() resolves partly from the request itself (IP). Nothing
	 *   records who satisfied them yesterday, so the readers who could have been
	 *   subscribed while entitled are indistinguishable from the whole reader base.
	 *
	 * An unconfigured rule is skipped, because it paywalls nothing:
	 * is_email_domain_whitelisted(), has_reader_data() and Institution::evaluate()
	 * each return true on an empty value. Two rules are exceptions, both verified
	 * against their evaluators:
	 *
	 * - `one_time_purchase` counts whatever its value: has_one_time_purchase() fails
	 *   closed on an empty product list, so an unconfigured one restricts everyone.
	 * - `subscription` counts when it names no product: has_active_subscription()
	 *   then passes an empty product filter to
	 *   WooCommerce_Connection::get_active_subscriptions_for_user(), which grants on
	 *   any active subscription at all. That is a real paywall, and not one a
	 *   product-keyed population query can enumerate.
	 *
	 * An unrecognized slug with a value counts too. A third-party rule registered
	 * through Access_Rules::register_rule() can restrict anything it likes, and
	 * assuming otherwise is how a gate ends up reported as safe unchecked.
	 *
	 * @param array $access_rules Grouped access rules.
	 *
	 * @return string[] Deduplicated rule slugs, in the order first seen.
	 */
	private static function unenumerable_paid_rules( array $access_rules ): array {
		$slugs = [];
		foreach ( $access_rules as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			foreach ( $group as $rule ) {
				$slug  = (string) ( $rule['slug'] ?? '' );
				$value = $rule['value'] ?? null;
				if ( '' === $slug ) {
					continue;
				}
				if ( 'subscription' === $slug ) {
					// A subscription rule naming products is the one population this
					// command can walk, so it is not a gap.
					if ( ! empty( array_filter( array_map( 'intval', (array) $value ) ) ) ) {
						continue;
					}
				} elseif ( 'one_time_purchase' !== $slug && empty( $value ) ) {
					continue;
				}
				$slugs[] = $slug;
			}
		}
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Rule slugs as one operator-readable phrase.
	 *
	 * @param string[] $slugs Rule slugs.
	 *
	 * @return string For example "a one-time purchase rule and an institutional access rule".
	 */
	private static function describe_rule_slugs( array $slugs ): string {
		$labels = array_map(
			fn( $slug ) => self::RULE_LABELS[ $slug ] ?? sprintf( 'the "%s" access rule', $slug ),
			$slugs
		);
		if ( empty( $labels ) ) {
			return 'access rules this command cannot enumerate';
		}
		if ( 1 === count( $labels ) ) {
			return $labels[0];
		}
		$last = array_pop( $labels );
		return implode( ', ', $labels ) . ' and ' . $last;
	}

	/**
	 * Split gates three ways: checkable, harmless, and paywalled but unenumerable.
	 *
	 * A gate is verifiable when its paid access mode is active and a subscription
	 * rule names products: that gives both an exclusion to test and a bounded
	 * population to test it against.
	 *
	 * A gate is harmless when nothing about it paywalls anything — the paid mode is
	 * off, or it is on with no rule that restricts anyone. Every registered reader
	 * is then entitled, so no reader can be wrongly subscribed.
	 *
	 * The third bucket is the one that matters: a gate that really is paywalled,
	 * by rules whose holders this command cannot list (see
	 * unenumerable_paid_rules()). Readers behind it are restricted, a leak among
	 * them is possible, and nothing here can look for it — so it is neither
	 * checkable nor harmless, and reporting it as either would be a lie. A
	 * verifiable gate can carry such rules too, alongside its products; its slugs
	 * ride along in 'unenumerable_rules' so the run can say its population was only
	 * part of the story.
	 *
	 * Unverifiable gates are returned rather than dropped so the report can name
	 * them and say why.
	 *
	 * @param array[] $gates Gate arrays as Content_Gate::get_gate() returns them.
	 *
	 * @return array{verifiable: array[], unenumerable: array[], registration_only: array[]}
	 */
	private static function partition_gates( array $gates ): array {
		$verifiable        = [];
		$unenumerable      = [];
		$registration_only = [];
		foreach ( $gates as $gate ) {
			if ( empty( $gate['custom_access']['active'] ) ) {
				$registration_only[] = $gate;
				continue;
			}
			$access_rules               = $gate['custom_access']['access_rules'] ?? [];
			$product_ids                = self::product_ids_from_access_rules( $access_rules );
			$gate['unenumerable_rules'] = self::unenumerable_paid_rules( $access_rules );
			if ( ! empty( $product_ids ) ) {
				$gate['product_ids'] = $product_ids;
				$verifiable[]        = $gate;
			} elseif ( ! empty( $gate['unenumerable_rules'] ) ) {
				$unenumerable[] = $gate;
			} else {
				$registration_only[] = $gate;
			}
		}
		return [
			'verifiable'        => $verifiable,
			'unenumerable'      => $unenumerable,
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
	 * What to print for a gate that restricts no newsletter list.
	 *
	 * Not a coverage gap. Such a gate is a paid gate over some other kind of
	 * content, which get_gates() returns alongside the newsletter ones, so it
	 * contributes no premium list for a reader to be wrongly subscribed to and
	 * there is nothing here for the run's claim to be missing. Said out loud rather
	 * than skipped in silence, so the operator can see it was considered.
	 *
	 * Shared by the two places that reach this conclusion — a verifiable gate and a
	 * gate paywalled by rules this command cannot enumerate — so the two cannot
	 * drift into saying different things about the same situation.
	 *
	 * @param array $gate Gate array carrying 'id' and 'title'.
	 *
	 * @return string
	 */
	private static function describe_gate_without_lists( array $gate ): string {
		return sprintf( '"%s" (gate %d): restricts no newsletter list, so it puts no reader on a premium list. Nothing to check.', $gate['title'], $gate['id'] );
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
	 * The IDs of every subscription to one of a gate's products.
	 *
	 * In any status, which is the point: a cancelled or expired subscriber is
	 * exactly the reader who may still be on a paid list, so filtering to active
	 * would hide every leak this command exists to find.
	 *
	 * wcs_get_subscriptions_for_product() rather than wcs_get_subscriptions(): it
	 * answers for all of a gate's products in one SQL query, returns IDs without
	 * building a WC_Subscription for any of them, and matches a subscription
	 * through either `_product_id` or `_variation_id` — the same two item meta keys
	 * wcs_get_subscriptions() reaches this function to match on when it is given a
	 * product and no customer, so the set of subscriptions is unchanged from what
	 * this command used to walk.
	 *
	 * One difference, in the widening direction: this function applies no status
	 * clause for `any`, so it also returns trashed subscriptions, which
	 * wcs_get_subscriptions()' `any` leaves out. Their holders held the product,
	 * which makes them leak candidates like any other, and every reader is
	 * evaluated against the live gate afterwards — so a wider population produces
	 * more checking, never a false leak.
	 *
	 * @param array $gate Gate array carrying a 'product_ids' key.
	 *
	 * @return int[] Subscription IDs, ascending, deduplicated by the query.
	 */
	private static function subscription_ids_for_gate( array $gate ): array {
		if ( ! function_exists( 'wcs_get_subscriptions_for_product' ) ) {
			return [];
		}
		return array_map( 'intval', array_values( \wcs_get_subscriptions_for_product( $gate['product_ids'], 'ids' ) ) );
	}

	/**
	 * The user IDs behind a chunk of subscriptions.
	 *
	 * Each subscription is hydrated and dropped again within the loop, so the peak
	 * cost is one WC_Subscription rather than one per subscription in the chunk.
	 *
	 * @param int[] $subscription_ids Subscription IDs.
	 *
	 * @return int[] User IDs, in the order the subscriptions were given, including
	 *               repeats and zeros for the caller to filter.
	 */
	private static function user_ids_for_subscriptions( array $subscription_ids ): array {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return [];
		}
		$user_ids = [];
		foreach ( $subscription_ids as $subscription_id ) {
			$subscription = \wcs_get_subscription( $subscription_id );
			if ( ! $subscription ) {
				continue;
			}
			$user_ids[] = (int) $subscription->get_user_id();
		}
		return $user_ids;
	}

	/**
	 * Walk a gate's population, yielding each distinct reader exactly once.
	 *
	 * Lazily, in chunks, which is the whole point. The population query used to
	 * hydrate a WC_Subscription for every match of every product before a single
	 * reader was checked, so a publisher with years of subscription history — the
	 * site where an operator reaches for --max-batches to sample — ran out of
	 * memory before the command printed anything. Neither pacing flag bounded it:
	 * they pace the ESP calls downstream of a population that was already fully
	 * built.
	 *
	 * Resolving a chunk at a time bounds that. A subscription ID costs a machine
	 * word; a hydrated subscription costs kilobytes, and only $chunk_size of them
	 * exist at once. Because this is a generator, a run the batch cap stops walks
	 * no further than the reader it stopped on, give or take the chunk in hand.
	 *
	 * Deduplication is by reader across the whole walk, not within a chunk: one
	 * reader with five subscriptions to a gate's products is one reader to check,
	 * and the seen-set holds integers, so it stays small even where the population
	 * does not.
	 *
	 * The chunk is a database concern and a batch is an ESP concern, so a chunk
	 * boundary is not a batch boundary and neither is a coverage boundary. What
	 * makes a run complete is finishing this walk; stopping part-way is recorded by
	 * the caller as COVERAGE_TRUNCATED, exactly as it was when the population was
	 * an array.
	 *
	 * @param int[]    $subscription_ids  Subscription IDs to resolve readers from.
	 * @param int      $chunk_size        Subscriptions to resolve at a time; forced to at least 1.
	 * @param callable $resolve_user_ids  Given a chunk of subscription IDs, returns their user IDs.
	 *
	 * @return \Generator<int> Distinct, non-zero user IDs.
	 */
	private static function walk_population( array $subscription_ids, int $chunk_size, callable $resolve_user_ids ): \Generator {
		$chunk_size = max( 1, $chunk_size );
		$total      = count( $subscription_ids );
		$seen       = [];
		for ( $offset = 0; $offset < $total; $offset += $chunk_size ) {
			// array_slice() rather than array_chunk(): chunking the whole list up
			// front would hold a second copy of every ID for the length of the walk.
			foreach ( $resolve_user_ids( array_slice( $subscription_ids, $offset, $chunk_size ) ) as $user_id ) {
				$user_id = (int) $user_id;
				// A subscription with no user — a guest checkout, or one whose user
				// row is gone — leaves nobody to ask the ESP about. Readers whose WP
				// user was deleted after the fact are a different case, and are
				// reported as unresolved rows rather than skipped here.
				if ( ! $user_id || isset( $seen[ $user_id ] ) ) {
					continue;
				}
				$seen[ $user_id ] = true;
				yield $user_id;
			}
		}
	}

	/**
	 * The readers whose state on a gate's lists is worth checking.
	 *
	 * @param int[] $subscription_ids Subscription IDs from subscription_ids_for_gate().
	 *
	 * @return \Generator<int> Distinct user IDs.
	 */
	private static function population_walker( array $subscription_ids ): \Generator {
		return self::walk_population( $subscription_ids, self::POPULATION_CHUNK_SIZE, [ __CLASS__, 'user_ids_for_subscriptions' ] );
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
	 * @param array $coverage    Coverage record, by reference. Shared across every gate's call so
	 *                           the run's terminal message can name the whole population it
	 *                           checked, and fail when any part of it went unwalked.
	 * @param array $summary     Outcome counts, by reference. Every row is counted here; only the
	 *                           ones the report lists are returned.
	 *
	 * @return array[] The rows needing attention, per status_needs_attention().
	 */
	private static function verify_gate( array $gate, bool $auto_signup, bool $live, int $batch_size, int $max_batches, int &$batches, array &$coverage, array &$summary ): array {
		$list_ids = self::restricted_list_ids_for_gate( $gate );
		if ( empty( $list_ids ) ) {
			WP_CLI::line( self::describe_gate_without_lists( $gate ) );
			return [];
		}
		if ( ! empty( $gate['unenumerable_rules'] ) ) {
			// This gate's products give a population to walk, but they are not the
			// only way into its lists: another rule grants access to readers this
			// command cannot list. The walk below is real coverage and the gate is
			// still worth checking — it just is not the whole gate, and a run that
			// claimed otherwise would be making the same promise the empty-population
			// case does.
			$rules    = self::describe_rule_slugs( $gate['unenumerable_rules'] );
			$coverage = self::with_incomplete_gate( $coverage, self::COVERAGE_PARTIAL_POPULATION, $gate, $rules );
			WP_CLI::warning( sprintf( '"%s" (gate %d): also grants access through %s, whose holders are not in the population below.', $gate['title'], $gate['id'], $rules ) );
		}
		if ( $max_batches && $batches >= $max_batches ) {
			WP_CLI::warning( sprintf( '"%s" (gate %d): skipped entirely because --max-batches was already reached by an earlier gate.', $gate['title'], $gate['id'] ) );
			$coverage = self::with_incomplete_gate( $coverage, self::COVERAGE_CAP_EXHAUSTED, $gate );
			return [];
		}
		$subscription_ids = self::subscription_ids_for_gate( $gate );
		if ( empty( $subscription_ids ) ) {
			WP_CLI::warning( sprintf( '"%s" (gate %d): no reader holds or has held its products, so there is nobody to check.', $gate['title'], $gate['id'] ) );
			$coverage = self::with_incomplete_gate( $coverage, self::COVERAGE_EMPTY_POPULATION, $gate );
			return [];
		}

		// Subscriptions rather than readers: the readers behind them are resolved a
		// chunk at a time as the walk runs, so their number is not known yet, and one
		// reader can hold several of these. It is an upper bound, and said as one.
		WP_CLI::line( sprintf( '"%s" (gate %d): checking the reader(s) behind %d subscription(s) against %d list(s)…', $gate['title'], $gate['id'], count( $subscription_ids ), count( $list_ids ) ) );
		$coverage = self::with_gate_walked( $coverage, $gate );

		// Resolved once per gate rather than once per reader: a list ID's public ID
		// does not vary by reader, and every reader checks the same $list_ids.
		$public_ids = [];
		foreach ( $list_ids as $list_id ) {
			$public_ids[ $list_id ] = self::public_id_for_list( $list_id );
		}

		$rows       = [];
		$in_batch   = 0;
		$checked    = 0;
		$population = self::population_walker( $subscription_ids );
		// Consumed by hand rather than with foreach, because the batch bookkeeping
		// needs to know whether another reader is coming before it decides to pause
		// or stop. Advancing first and asking valid() afterwards is that lookahead;
		// it resolves at most one chunk further than the reader in hand.
		$population->rewind();
		while ( $population->valid() ) {
			$user_id = (int) $population->current();
			$population->next();
			$more_work_remains = $population->valid();

			++$checked;
			$coverage = self::with_reader_checked( $coverage, $user_id );
			$user     = \get_user_by( 'id', $user_id );
			if ( ! $user ) {
				// The subscription still names a list to check, but there is no WP_User
				// to build a make_row() from — no email, and nothing to ask the ESP
				// about. Leaving this reader out of the rows entirely would mean an
				// email still on a restricted list at the ESP could never be reported as
				// a leak, so it is recorded as unresolved instead, the same status a
				// failed ESP lookup gets.
				foreach ( $list_ids as $list_id ) {
					self::record_row( $rows, $summary, self::make_missing_user_row( $gate, $list_id, $user_id ) );
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
					self::record_row( $rows, $summary, self::make_row( $gate, $list_id, $user, 'unresolved' ) );
				}
			} else {
				$contact_lists = \is_wp_error( $contact_data ) ? [] : \Newspack_Newsletters_Subscription::get_contact_lists( $user->user_email );
				$unresolved    = \is_wp_error( $contact_lists ) || ! is_array( $contact_lists );

				foreach ( $list_ids as $list_id ) {
					$public_id = $public_ids[ $list_id ];
					if ( $unresolved || null === $public_id ) {
						self::record_row( $rows, $summary, self::make_row( $gate, $list_id, $user, 'unresolved' ) );
						continue;
					}
					$is_restricted = \Newspack\Content_Restriction_Control::is_post_restricted( false, $list_id, $user_id );
					$is_subscribed = self::is_subscribed_to_list( $public_id, $contact_lists );
					$status        = self::classify_reader( $is_restricted, $is_subscribed, $auto_signup );

					if ( 'leak' === $status && $live ) {
						$removed = \Newspack_Newsletters_Contacts::add_and_remove_lists( $user->user_email, [], [ $public_id ], 'Verifying premium newsletter lists' );
						// A non-error return is not proof the list is gone, so the lists
						// are read again and the removal is only recorded once it shows.
						$lists_after = \is_wp_error( $removed ) ? [] : \Newspack_Newsletters_Subscription::get_contact_lists( $user->user_email );
						$status      = self::classify_removal( $removed, $lists_after, $public_id );
					}
					self::record_row( $rows, $summary, self::make_row( $gate, $list_id, $user, $status ) );
				}
			}

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
				// The warning above goes to STDERR, which a cutover script gating on
				// $? never sees. Recording the truncation is what actually stops this
				// run being read as a pass.
				$coverage = self::with_incomplete_gate( $coverage, self::COVERAGE_TRUNCATED, $gate );
				break;
			}
		}

		if ( 0 === $checked ) {
			// Subscriptions to the gate's products exist, but not one of them belongs
			// to a reader account: they are guest checkouts, or their customer link is
			// gone. Nobody was checked, and a run that reported that as a clean gate
			// would be making the empty-population claim by another route.
			WP_CLI::warning( sprintf( '"%s" (gate %d): its subscriptions belong to no reader account, so there was nobody to check.', $gate['title'], $gate['id'] ) );
			$coverage = self::with_incomplete_gate( $coverage, self::COVERAGE_EMPTY_POPULATION, $gate );
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
	 * @param string   $status  One of the classify_reader() statuses, 'removed' (a leak this run
	 *                          removed and confirmed gone), or 'unresolved'.
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
	 * Print each gap in the run's coverage, or nothing when there are none.
	 *
	 * Called from the full report and from the early exit a run takes when it has
	 * no verifiable gate to walk, because that exit can now fail — a gate paywalled
	 * by rules this command cannot enumerate produces a gap and no rows at all —
	 * and the operator needs the same explanation either way.
	 *
	 * @param array $coverage Coverage record.
	 *
	 * @return void
	 */
	private static function print_coverage_gaps( array $coverage ): void {
		$coverage_gaps = self::describe_coverage_gaps( $coverage );
		if ( empty( $coverage_gaps ) ) {
			return;
		}
		WP_CLI::line( '' );
		WP_CLI::line( 'Coverage is incomplete, so this run is not a pass whatever it found:' );
		foreach ( $coverage_gaps as $gap_line ) {
			WP_CLI::line( '  - ' . $gap_line );
		}
	}

	/**
	 * Print the summary, then the rows that need attention.
	 *
	 * @param array[]           $rows        The rows needing attention. Passing rows are counted
	 *                                       in $summary and not kept, so this is not every row.
	 * @param array<string,int> $summary     Counts from new_summary(), added up by with_status_counted().
	 * @param array             $coverage    Coverage record.
	 * @param bool              $auto_signup Whether auto-signup is on.
	 * @param bool              $live        Whether removals were written.
	 *
	 * @return void
	 */
	private static function report( array $rows, array $summary, array $coverage, bool $auto_signup, bool $live ): void {
		WP_CLI::line( '' );
		WP_CLI::line( $live ? '=== VERIFICATION SUMMARY (--live: leaks removed) ===' : '=== VERIFICATION SUMMARY (report only) ===' );
		WP_CLI::line( '' );
		\WP_CLI\Utils\format_items(
			'table',
			[
				[
					'Checked'      => $summary['checked'],
					'Leaks'        => $summary['leak'],
					'Removed'      => $summary['removed'],
					'Gaps'         => $summary['gap'],
					'OK'           => $summary['ok'],
					'Not asserted' => $summary['not_asserted'],
					'Unresolved'   => $summary['unresolved'],
				],
			],
			[ 'Checked', 'Leaks', 'Removed', 'Gaps', 'OK', 'Not asserted', 'Unresolved' ]
		);

		// Already only the rows worth listing — record_row() dropped the rest as they
		// were counted — but filtered again rather than trusted, so this stays correct
		// if a caller ever hands it a full set.
		$attention = array_values( array_filter( $rows, fn( $r ) => self::status_needs_attention( $r['status'] ?? '' ) ) );
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
		WP_CLI::line( sprintf( 'Coverage: %s.', self::describe_checked_scope( $coverage ) ) );
		WP_CLI::line( 'This covers readers who hold or have held a gate\'s products, and nobody else. A reader who satisfied the source Memberships plan with a non-subscription product, one who joined a list before it became premium, one whose membership was granted by hand, and a group-subscription member entitled through someone else\'s subscription rather than their own are all restricted after cutover and all invisible here — no provider-agnostic bulk read of ESP list membership exists to reach them. A current group member missing from a list is never reported as a gap, and a lapsed group member still on one is a leak this command cannot see.' );

		self::print_coverage_gaps( $coverage );

		WP_CLI::line( '' );
		if ( 0 < $summary['removed'] ) {
			WP_CLI::line( sprintf( '%d subscription(s) were removed at the ESP and confirmed gone. They are listed above; this is the only record of them.', $summary['removed'] ) );
		}
		if ( ! $auto_signup ) {
			WP_CLI::line( 'Auto-signup is off, so an entitled reader who is not subscribed is counted as "not asserted" rather than a gap: they opt in themselves.' );
		}
		if ( 0 < $summary['gap'] ) {
			WP_CLI::line( 'Gaps are reported but never written. An on-demand run cannot tell a reader who never subscribed from one who unsubscribed on purpose, so additions are left to the auto-signup flow, where the renewal snapshot protects an opt-out.' );
		}
	}
}

<?php
/**
 * Bulk invitations for Newspack Group Subscriptions.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a pasted list or a CSV into invitations.
 *
 * The My Account group page and the admin group view both send the raw input
 * here, so the two surfaces share one parser, one set of skip rules and one seat
 * guard. Each invitation still goes through Group_Subscription_Invite::generate_invite(),
 * so the email, expiry and acceptance are the same as a single invite.
 */
final class Group_Subscription_Bulk_Invite {

	/**
	 * The most new addresses one list may invite. A confirmed list sends one email per
	 * new address inside the request, and an unlimited group has no seat limit to bound
	 * the list, so the cap is what keeps a send short enough to finish. Repeats and
	 * addresses that are skipped send nothing, so they don't count.
	 */
	const MAX_INVITES = 100;

	/**
	 * The most entries, of any kind, one list may carry. Each costs a user lookup.
	 */
	const MAX_ENTRIES = 500;

	/**
	 * The most bytes either input may carry, checked before anything is parsed.
	 * Comfortably above MAX_ENTRIES rows of a CSV with a few extra columns.
	 */
	const MAX_INPUT_BYTES = 262144;

	/**
	 * Keep the raw input intact apart from invalid UTF-8.
	 *
	 * Used as the REST sanitizer for both inputs. sanitize_textarea_field() would strip
	 * `<jane@example.com>` as if it were a tag, losing the address from a list copied out
	 * of a mail client. Every entry is checked with is_email() before use, and the
	 * clients render what comes back as text, so no markup survives either way.
	 *
	 * @param mixed $value The raw request value.
	 *
	 * @return string The value with invalid UTF-8 removed.
	 */
	public static function sanitize_input( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		// Before WordPress 6.9, wp_check_invalid_utf8() in strip mode returns '' for the
		// whole string when it meets one bad byte; mb_scrub() replaces just that byte.
		return function_exists( 'mb_scrub' ) ? mb_scrub( $value, 'UTF-8' ) : wp_check_invalid_utf8( $value, true );
	}

	/**
	 * Split pasted text and a CSV into a flat list of entries, in input order.
	 *
	 * Pasted text splits on commas, semicolons and any whitespace, newlines included. A CSV
	 * contributes its first column only, comma- or semicolon-delimited. Its first
	 * non-blank row is skipped as a header when that cell names an email column and holds
	 * no address, so a header is tolerated without being required, and anything else in
	 * the first row is kept so it can be reported if it is invalid. In both inputs a
	 * `Name <address>` entry, as mail clients copy recipients, becomes the address.
	 * Entries are not validated or de-duplicated here: classify() does that and reports
	 * what it dropped.
	 *
	 * @param string $text Pasted text.
	 * @param string $csv  CSV file contents.
	 *
	 * @return string[] The entries.
	 */
	public static function parse( string $text = '', string $csv = '' ): array {
		$entries = preg_split( '/[\s,;]+/u', self::unwrap_named_addresses( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$entries = false === $entries ? [] : $entries;

		// Spreadsheet apps prefix a UTF-8 BOM, which would otherwise stick to the first cell.
		$csv = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		if ( '' === trim( $csv ) ) {
			return array_values( $entries );
		}
		// Read the CSV as a stream rather than line by line, so a quoted cell holding a
		// newline in a later column doesn't split its row in two.
		// Spreadsheet apps in some locales write semicolons. Decide from the first line that
		// holds either delimiter, since a one-column header holds neither.
		$delimiter = ',';
		if ( preg_match( '/^[^\r\n]*[,;][^\r\n]*$/m', $csv, $line_with_delimiter ) ) {
			$delimiter = str_contains( $line_with_delimiter[0], ';' ) && ! str_contains( $line_with_delimiter[0], ',' ) ? ';' : ',';
		}
		$stream = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $stream, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- php://temp is memory, not the file system.
		rewind( $stream );
		$is_first_row = true;
		while ( false !== ( $row = fgetcsv( $stream, null, $delimiter, '"', '' ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$first_cell = trim( self::unwrap_named_addresses( (string) ( $row[0] ?? '' ) ) );
			if ( '' === $first_cell ) {
				continue;
			}
			if ( $is_first_row ) {
				$is_first_row = false;
				if ( ! str_contains( $first_cell, '@' ) && preg_match( '/e-?mail/i', $first_cell ) ) {
					continue;
				}
			}
			$entries[] = $first_cell;
		}
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return array_values( $entries );
	}

	/**
	 * Replace each `Name <address>` or `"Last, First" <address>` with the bare address.
	 *
	 * A name is words without an `@` that start an entry, so a bare address before it in
	 * a space-separated list is never taken for part of the name. The address comes back
	 * padded with spaces, so two named entries in a row still split apart.
	 *
	 * @param string $text The text to unwrap.
	 *
	 * @return string The text with named addresses unwrapped.
	 */
	private static function unwrap_named_addresses( string $text ): string {
		return (string) preg_replace( '/(?<![^\s,;])(?:"[^"]*"\s*|(?:[^<>@\s,;"]+\s+)*)<([^<>\s,;]+@[^<>\s,;]+)>/u', ' $1 ', $text );
	}

	/**
	 * Sort entries into the ones to invite and the ones to skip.
	 *
	 * Each entry lands in one bucket, checked in this order: not an email address
	 * (listed back), a repeat of an earlier entry (counted), someone already in the
	 * group, owner and managers included (counted), an address with a pending
	 * invitation (counted), an account the group can't hold (listed back). An expired
	 * invitation holds no seat, so its address is invited again.
	 *
	 * The last check runs only when `$check_eligibility` is set. Ineligible accounts are
	 * mostly newsroom staff, and a preview sends nothing, so checking for a group owner
	 * would let them test a list of addresses for staff accounts in silence. Without the
	 * check such an address is sent like any other, and generate_invite() refuses it
	 * then, which is what a single invitation already reveals, at the cost of a real send.
	 * The address also counts against the free seats until then: leaving it out of the
	 * count would reveal it just the same.
	 *
	 * @param \WC_Subscription|int $subscription      The group subscription.
	 * @param string[]             $entries           Entries from parse().
	 * @param bool                 $check_eligibility Whether to sort out ineligible accounts.
	 *
	 * @return array{to_invite: string[], invalid: string[], not_eligible: string[], skipped: array{duplicate: int, member: int, invited: int}, seats_available: int|null}
	 */
	public static function classify( \WC_Subscription|int $subscription, array $entries, bool $check_eligibility = true ): array {
		$result = [
			'to_invite'       => [],
			'invalid'         => [],
			'not_eligible'    => [],
			'skipped'         => [
				'duplicate' => 0,
				'member'    => 0,
				'invited'   => 0,
			],
			'seats_available' => self::seats_available( $subscription ),
		];

		$people  = array_map( 'intval', Group_Subscription::get_all_members( $subscription ) );
		$invited = array_map(
			'strtolower',
			wp_list_pluck( Group_Subscription_Invite::get_invites( $subscription, false ), 'email' )
		);
		$seen    = [];

		foreach ( $entries as $entry ) {
			$entry = (string) $entry;
			if ( ! is_email( $entry ) ) {
				$result['invalid'][] = $entry;
				continue;
			}
			$key = strtolower( $entry );
			if ( isset( $seen[ $key ] ) ) {
				++$result['skipped']['duplicate'];
				continue;
			}
			$seen[ $key ] = true;

			$user = get_user_by( 'email', $entry );
			if ( $user && in_array( (int) $user->ID, $people, true ) ) {
				++$result['skipped']['member'];
				continue;
			}
			if ( in_array( $key, $invited, true ) ) {
				++$result['skipped']['invited'];
				continue;
			}
			if ( $check_eligibility && $user && ! Group_Subscription::is_eligible_member( $user ) ) {
				$result['not_eligible'][] = $entry;
				continue;
			}
			$result['to_invite'][] = $entry;
		}
		return $result;
	}

	/**
	 * Free seats: the seat limit minus members minus pending invitations.
	 *
	 * The same arithmetic generate_invite() checks one invitation at a time, so a list
	 * that fits here is not refused partway for want of a seat unless the group changes
	 * while it is being sent. If it does, generate_invite() still holds the limit, and
	 * the addresses it refuses are reported as failed.
	 *
	 * @param \WC_Subscription|int $subscription The group subscription.
	 *
	 * @return int|null The free seats, or null when the group is unlimited.
	 */
	public static function seats_available( \WC_Subscription|int $subscription ): ?int {
		$seat_limit = Group_Subscription::get_member_seat_limit( $subscription );
		if ( null === $seat_limit ) {
			return null;
		}
		$taken = count( Group_Subscription::get_members( $subscription ) ) + count( Group_Subscription_Invite::get_invites( $subscription, false ) );
		return max( 0, $seat_limit - $taken );
	}

	/**
	 * Sentences describing a classify() or invite() result, for display as they are.
	 *
	 * Built here rather than in each client so the My Account page, which carries no
	 * JS translations, and the admin view report the same list the same way.
	 *
	 * @param array $result A classify() result, or an invite() result, which adds
	 *                      `invited`, `not_sent` and `failed`. Their presence is what
	 *                      makes this an outcome rather than a preview.
	 *
	 * @return string[] One sentence per non-empty bucket.
	 */
	public static function describe( array $result ): array {
		$lines      = [];
		$is_outcome = isset( $result['invited'] );
		if ( $is_outcome ) {
			$sent = count( $result['invited'] );
			if ( $sent ) {
				/* translators: %d: number of invitations sent. */
				$lines[] = sprintf( _n( '%d invitation sent.', '%d invitations sent.', $sent, 'newspack-plugin' ), $sent );
			}
			if ( ! empty( $result['not_sent'] ) ) {
				$lines[] = sprintf(
					/* translators: %s: comma-separated email addresses. */
					__( 'These invitations were created, but the email could not be sent. Each holds a seat until you cancel it: %s', 'newspack-plugin' ),
					implode( ', ', $result['not_sent'] )
				);
			}
			foreach ( $result['failed'] as $failure ) {
				/* translators: 1: email address, 2: why the invitation was refused. */
				$lines[] = sprintf( __( '%1$s: %2$s', 'newspack-plugin' ), $failure['email'], $failure['message'] );
			}
		} else {
			$count = count( $result['to_invite'] );
			/* translators: %d: number of new addresses in the list. */
			$lines[] = sprintf( _n( '%d new address to invite.', '%d new addresses to invite.', $count, 'newspack-plugin' ), $count );
		}
		$skip_counts = $result['skipped'];
		$group_label = Group_Subscription::get_label_lower( 'singular' );
		if ( $skip_counts['duplicate'] ) {
			/* translators: %d: number of repeated addresses. */
			$lines[] = sprintf( _n( 'Skipped %d repeated address.', 'Skipped %d repeated addresses.', $skip_counts['duplicate'], 'newspack-plugin' ), $skip_counts['duplicate'] );
		}
		if ( $skip_counts['member'] ) {
			$lines[] = sprintf(
				/* translators: 1: number of addresses, 2: lowercase singular group label (e.g. "group", "team"). */
				_n( 'Skipped %1$d address already in the %2$s.', 'Skipped %1$d addresses already in the %2$s.', $skip_counts['member'], 'newspack-plugin' ),
				$skip_counts['member'],
				$group_label
			);
		}
		if ( $skip_counts['invited'] ) {
			/* translators: %d: number of addresses with a pending invitation. */
			$lines[] = sprintf( _n( 'Skipped %d address that already has a pending invitation.', 'Skipped %d addresses that already have a pending invitation.', $skip_counts['invited'], 'newspack-plugin' ), $skip_counts['invited'] );
		}
		if ( ! empty( $result['invalid'] ) ) {
			/* translators: %s: comma-separated entries that are not email addresses. */
			$lines[] = sprintf( __( 'Not valid email addresses: %s', 'newspack-plugin' ), implode( ', ', $result['invalid'] ) );
		}
		if ( ! empty( $result['not_eligible'] ) ) {
			$lines[] = sprintf(
				/* translators: 1: lowercase singular group label (e.g. "group", "team"), 2: comma-separated email addresses. */
				__( 'These accounts can\'t join the %1$s: %2$s', 'newspack-plugin' ),
				$group_label,
				implode( ', ', $result['not_eligible'] )
			);
		}
		return $lines;
	}

	/**
	 * The refusal for a list with more new addresses than free seats.
	 *
	 * The same sentence serves the preview and the confirm, so it says what is wrong
	 * rather than what happened.
	 *
	 * @param int $requested The number of new addresses.
	 * @param int $available The free seats.
	 *
	 * @return \WP_Error
	 */
	public static function over_limit_error( int $requested, int $available ): \WP_Error {
		return new \WP_Error(
			'newspack_group_subscription_bulk_invite_over_limit',
			sprintf(
				/* translators: 1: number of new addresses in the list, 2: number of free seats. */
				__( 'There aren\'t enough free seats for this list. New addresses: %1$d. Free seats: %2$d. Remove some addresses or free up seats, then try again.', 'newspack-plugin' ),
				$requested,
				$available
			),
			[
				'status'          => 409,
				'requested'       => $requested,
				'seats_available' => $available,
			]
		);
	}

	/**
	 * The refusal for a list with more new addresses than one send may carry.
	 *
	 * @param int $requested The number of new addresses.
	 *
	 * @return \WP_Error
	 */
	public static function too_many_error( int $requested ): \WP_Error {
		return new \WP_Error(
			'newspack_group_subscription_bulk_invite_too_many',
			sprintf(
				/* translators: 1: the most new addresses one list may invite, 2: number of new addresses in the list. */
				__( 'A list can invite up to %1$d people at a time, and this one has %2$d new addresses. Split it into smaller lists.', 'newspack-plugin' ),
				self::MAX_INVITES,
				$requested
			),
			[
				'status'    => 400,
				'requested' => $requested,
			]
		);
	}

	/**
	 * The refusal that applies to a classify() result, if any: too many new addresses
	 * for one send, or more than the free seats.
	 *
	 * @param array $result A classify() result.
	 *
	 * @return \WP_Error|null The refusal, or null when the list can be sent.
	 */
	public static function refusal( array $result ): ?\WP_Error {
		$requested = count( $result['to_invite'] );
		if ( $requested > self::MAX_INVITES ) {
			return self::too_many_error( $requested );
		}
		if ( null !== $result['seats_available'] && $requested > $result['seats_available'] ) {
			return self::over_limit_error( $requested, $result['seats_available'] );
		}
		return null;
	}

	/**
	 * Invite the new addresses in a list, refusing the whole list if it needs more
	 * seats than are free or holds more than MAX_INVITES new addresses.
	 *
	 * Both checks run before any invitation is created, so a list that fails either is
	 * refused whole rather than filled up to the limit. See
	 * seats_available() for the one case where a list that fit can still end partway.
	 *
	 * @param \WC_Subscription|int $subscription      The group subscription.
	 * @param string[]             $entries           Entries from parse().
	 * @param bool                 $check_eligibility Passed to classify().
	 *
	 * @return array|\WP_Error The classify() result plus `invited` (stored and emailed),
	 *                         `not_sent` (stored, but the email failed; the invitation
	 *                         still holds a seat) and `failed` (`email` and `message` for
	 *                         each address generate_invite() refused), or the refusal.
	 */
	public static function invite( \WC_Subscription|int $subscription, array $entries, bool $check_eligibility = true ): array|\WP_Error {
		$result  = self::classify( $subscription, $entries, $check_eligibility );
		$refusal = self::refusal( $result );
		if ( $refusal ) {
			return $refusal;
		}

		$result['invited']  = [];
		$result['not_sent'] = [];
		$result['failed']   = [];
		foreach ( $result['to_invite'] as $email ) {
			$invite = Group_Subscription_Invite::generate_invite( $subscription, $email );
			if ( is_wp_error( $invite ) ) {
				$result['failed'][] = [
					'email'   => $email,
					'message' => $invite->get_error_message(),
				];
			} elseif ( ! empty( $invite['email_sent'] ) ) {
				$result['invited'][] = $email;
			} else {
				$result['not_sent'][] = $email;
			}
		}
		return $result;
	}
}

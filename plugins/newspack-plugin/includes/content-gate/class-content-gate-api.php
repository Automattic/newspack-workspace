<?php
/**
 * Newspack Content Gate - API methods.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Metering;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Content_Gate_API {
	/**
	 * The capability the gate routes' `permission_callback` requires, and so the
	 * one the reads made while sanitizing a request check for themselves.
	 *
	 * @var string
	 */
	const MANAGE_GATES_CAPABILITY = 'manage_options';

	/**
	 * Gate schema properties.
	 *
	 * @var array
	 */
	public static $gate_properties = [
		'title'               => [ 'type' => 'string' ],
		'status'              => [ 'type' => 'string' ],
		'content_rules_match' => [
			'type' => 'string',
			'enum' => [ 'all', 'any' ],
		],
		'metering'            => [
			'type'       => 'object',
			'properties' => [
				'enabled'          => [ 'type' => 'boolean' ],
				'anonymous_count'  => [ 'type' => 'integer' ],
				'registered_count' => [ 'type' => 'integer' ],
				'period'           => [ 'type' => 'string' ],
			],
		],
		'content_rules'       => [
			'type'  => 'array',
			'items' => [
				'type'       => 'object',
				'properties' => [
					'slug'      => [ 'type' => 'string' ],
					'value'     => [ 'type' => [ 'string', 'array' ] ],
					'exclusion' => [ 'type' => 'boolean' ],
				],
			],
		],
		'registration'        => [
			'type'       => 'object',
			'properties' => [
				'active'               => [ 'type' => 'boolean' ],
				'require_verification' => [ 'type' => 'boolean' ],
				'gate_layout_id'       => [
					'type'     => 'integer',
					'required' => false,
				],
				'metering'             => [
					'type'       => 'object',
					'properties' => [
						'enabled' => [ 'type' => 'boolean' ],
						'count'   => [ 'type' => 'integer' ],
						'period'  => [
							'type' => 'string',
							'enum' => [ 'day', 'week', 'month' ],
						],
						'scope'   => [
							'type' => 'string',
							'enum' => [ 'site', 'gate' ],
						],
					],
				],
			],
		],
		'custom_access'       => [
			'type'       => 'object',
			'properties' => [
				'active'                 => [ 'type' => 'boolean' ],
				'metering'               => [
					'type'       => 'object',
					'properties' => [
						'enabled' => [ 'type' => 'boolean' ],
						'count'   => [ 'type' => 'integer' ],
						'period'  => [
							'type' => 'string',
							'enum' => [ 'day', 'week', 'month' ],
						],
						'scope'   => [
							'type' => 'string',
							'enum' => [ 'site', 'gate' ],
						],
					],
				],
				'gate_layout_id'         => [
					'type'     => 'integer',
					'required' => false,
				],
				'payment_recovery_grace' => [
					'type'     => 'boolean',
					'required' => false,
				],
				'access_rules'           => [
					'type'  => 'array',
					'items' => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'slug'  => [ 'type' => 'string' ],
								'value' => [ 'type' => [ 'string', 'array', 'object' ] ],
							],
						],
					],
				],
			],
		],
	];

	/**
	 * The gate ID the route names.
	 *
	 * Read from the URL parameters rather than through `get_param()`, whose source
	 * order puts the body ahead of the URL: a payload carrying a top-level `id`
	 * would otherwise shadow the route's own capture, and the gate a request is
	 * validated against has to be the gate it reads and writes.
	 *
	 * @param \WP_REST_Request|null $request The request.
	 *
	 * @return int The gate ID, or 0 on a route that names none.
	 */
	public static function get_route_gate_id( $request ) {
		return $request instanceof \WP_REST_Request ? absint( $request->get_url_params()['id'] ?? 0 ) : 0;
	}

	/**
	 * Sanitize the gate.
	 *
	 * TODO: Handle errors from the remaining sanitization methods (content rules,
	 * registration, metering) the way custom access errors already propagate.
	 *
	 * @param array            $gate    The gate.
	 * @param \WP_REST_Request $request Optional. The request being sanitized, as WP passes it
	 *                                  to a `sanitize_callback`. Its URL parameters carry the
	 *                                  route's own gate ID, which is the one to read stored
	 *                                  settings against — `get_param()` would read the body
	 *                                  first, and the ID there is caller-supplied and can name
	 *                                  a different gate.
	 *
	 * @return array|\WP_Error The sanitized gate, or an error when an access rule
	 *                         holds an invalid value.
	 */
	public static function sanitize_gate( $gate, $request = null ) {
		$gate_id   = self::get_route_gate_id( $request );
		$sanitized = [];
		// Only include fields the request explicitly provided, so an omitted
		// field does not clobber an existing gate's stored value on update
		// (a published gate silently reset to draft, or with its rules wiped, stops enforcing).
		if ( isset( $gate['title'] ) ) {
			$sanitized['title'] = sanitize_text_field( $gate['title'] );
		}
		if ( isset( $gate['priority'] ) ) {
			$sanitized['priority'] = intval( $gate['priority'] );
		}
		if ( isset( $gate['status'] ) ) {
			$sanitized['status'] = self::sanitize_status( $gate['status'], $gate_id );
		}
		if ( isset( $gate['content_rules'] ) ) {
			$sanitized['content_rules'] = self::sanitize_rules( $gate['content_rules'], 'content' );
		}
		if ( isset( $gate['registration'] ) ) {
			$sanitized['registration'] = self::sanitize_registration( $gate['registration'] );
		}
		if ( isset( $gate['custom_access'] ) ) {
			$sanitized_custom_access = self::sanitize_custom_access( $gate['custom_access'] );
			$leaves_rules_unenforced = self::save_leaves_rules_unenforced( $gate, $sanitized, $gate_id );
			if ( ! is_wp_error( $sanitized_custom_access ) ) {
				$sanitized_custom_access = self::reject_rules_left_unconstrained( $sanitized_custom_access, $gate_id, $leaves_rules_unenforced );
			}
			if ( is_wp_error( $sanitized_custom_access ) ) {
				if ( ! $leaves_rules_unenforced || ! self::access_rules_are_unchanged( $gate, $gate_id ) ) {
					// As a REST `sanitize_callback` return value, the error fails the
					// request with a 400 rather than silently saving a loosened rule set.
					return self::in_context_error( $sanitized_custom_access, $leaves_rules_unenforced );
				}
				// The save switches the gate off and echoes back the rules it read, rather
				// than introducing new ones: the gates list disables a gate by POSTing the
				// whole gate object. A gate whose stored value is already invalid has to
				// stay switchable, or an operator can't turn off one that is walling off
				// paying readers. Drop the rules from the payload so the stored ones are
				// left alone and the rest of the save goes through.
				unset( $gate['custom_access']['access_rules'] );
				$sanitized_custom_access = self::sanitize_custom_access( $gate['custom_access'] );
			}
			$sanitized['custom_access'] = $sanitized_custom_access;
		} elseif ( ! self::save_leaves_rules_unenforced( $gate, $sanitized, $gate_id ) ) {
			// A save that publishes the gate without naming its custom access section
			// still puts the stored rules live, so they are judged as they are.
			$stored_rules_verdict = self::reject_rules_left_unconstrained( [], $gate_id, false );
			if ( is_wp_error( $stored_rules_verdict ) ) {
				return $stored_rules_verdict;
			}
		}
		if ( isset( $gate['content_rules_match'] ) ) {
			$sanitized['content_rules_match'] = in_array( $gate['content_rules_match'], [ 'all', 'any' ], true ) ? $gate['content_rules_match'] : 'all';
		}
		return $sanitized;
	}

	/**
	 * Reject an active gate holding a rule that has been left granting everyone.
	 *
	 * Scoped to rules registered with `empty_grants_access`, whose callback reads
	 * an empty value as "no constraint" and so evaluates true for every reader.
	 * That state is reachable without typing a character — enabling the rule seeds
	 * it with its empty default, and on a site with no institutions published the
	 * picker has nothing else to offer.
	 *
	 * An empty value is not the same thing on every rule, which is why the
	 * declaration decides and the value's shape does not: `subscription` naming no
	 * product still requires *an* active subscription, and refusing that save
	 * would block a configuration publishers rely on.
	 *
	 * Only while custom access is active. A gate being configured may hold a rule
	 * the operator has not filled in yet.
	 *
	 * @param array $sanitized_custom_access The sanitized custom access settings.
	 * @param int   $gate_id                 The gate's ID, or 0 when it is being created.
	 * @param bool  $leaves_rules_unenforced Whether the save leaves the rules unevaluated.
	 *
	 * @return array|\WP_Error The settings unchanged, or an error naming the rule.
	 */
	private static function reject_rules_left_unconstrained( $sanitized_custom_access, $gate_id, $leaves_rules_unenforced ) {
		$access_rules = $sanitized_custom_access['access_rules'] ?? null;
		if ( null === $access_rules ) {
			// A partial save carries only what it changes, and the settings it omits
			// are merged over the stored ones. So a save that switches the gate on
			// without resending the rules has to be judged against the rules it is
			// switching on — otherwise the granting-everyone state is one such save
			// away. A save that leaves the rules unenforced has nothing to judge yet.
			if ( $leaves_rules_unenforced || ! self::caller_can_save_gate( $gate_id ) ) {
				return $sanitized_custom_access;
			}
			$access_rules = Access_Rules::normalize_rules( Content_Gate::get_custom_access_settings( $gate_id )['access_rules'] ?? [] );
		}
		// A request that doesn't carry `active` isn't changing it, so the stored
		// value decides whether the gate is currently letting readers through.
		// Sanitization runs ahead of the route's `permission_callback`, so that read
		// is guarded the same way access_rules_are_unchanged() guards its own:
		// without it the response code tells a caller who can't edit the gate
		// whether it stores an active rule with nothing selected. Treating the
		// unreadable case as inactive skips the refusal and leaves the request to
		// fail on permissions, which is what it would have done anyway.
		$is_active = $sanitized_custom_access['active'] ?? false;
		if ( ! isset( $sanitized_custom_access['active'] ) && self::caller_can_save_gate( $gate_id ) ) {
			$is_active = Content_Gate::get_custom_access_settings( $gate_id )['active'] ?? false;
		}
		if ( ! $is_active ) {
			return $sanitized_custom_access;
		}

		// No rules at all is the same state as one rule left empty, and the loop below
		// cannot reach it — there is nothing to iterate. Both evaluation sites skip the
		// rule check outright on an empty set, so an active gate holding one admits every
		// reader. `sanitize_access_rules_grouped()` refuses this only where the set was
		// emptied by dropping unknown slugs; a payload sending `[]` arrives here clean,
		// and the wizard allows Save whenever the gate also has a registration wall.
		if ( empty( $access_rules ) ) {
			return new \WP_Error(
				'empty_access_rules',
				__( 'Paid access is on but no access rule is set, so the gate would grant access to everyone. Add a rule, or turn Paid access off.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}

		$registered_rules = Access_Rules::get_registered_rules();
		foreach ( $access_rules as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			foreach ( $group as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$registered = $registered_rules[ $rule['slug'] ?? '' ] ?? null;
				if ( empty( $registered['empty_grants_access'] ) || ! self::rule_value_is_empty( $rule['value'] ?? null ) ) {
					continue;
				}
				return self::empty_access_rule_value_error( $registered );
			}
		}
		return $sanitized_custom_access;
	}

	/**
	 * Whether a rule holds the empty value for its shape.
	 *
	 * Both shapes a rule can take carry the same meaning when empty: an
	 * options-backed rule selects nothing with `[]`, a free-text one with `''`,
	 * and a stored rule can be missing its value altogether. Every rule that
	 * declares `empty_grants_access` grants every reader in that state, whichever
	 * of the two it is.
	 *
	 * @param mixed $value The rule's value.
	 *
	 * @return bool
	 */
	private static function rule_value_is_empty( $value ) {
		return null === $value || [] === $value || '' === $value;
	}

	/**
	 * The error returned when an active gate holds a rule left granting everyone.
	 *
	 * @param array $rule The registered rule.
	 *
	 * @return \WP_Error
	 */
	private static function empty_access_rule_value_error( $rule ) {
		$message = empty( $rule['has_options'] )
			/* translators: %s: the access rule's name, e.g. "Whitelisted email domain". */
			? __( 'Enter a value for the “%s” access rule, or turn the rule off. Left empty, it grants access to everyone.', 'newspack-plugin' )
			/* translators: %s: the access rule's name, e.g. "Institutional access". */
			: __( 'Select at least one option for the “%s” access rule, or turn the rule off. Left empty, it grants access to everyone.', 'newspack-plugin' );
		return new \WP_Error(
			'empty_access_rule_value',
			sprintf( $message, $rule['name'] ),
			[
				'status'    => 400,
				'rule_name' => $rule['name'],
			]
		);
	}

	/**
	 * Reword the refusal for a save that was switching the rules off anyway.
	 *
	 * Such a save has already done what the standard message asks for, so telling
	 * the operator to turn the rule off reads as a refusal to accept the change
	 * they just made. What it is actually refusing is the new empty value, which
	 * has to be fixed before the gate goes live again.
	 *
	 * @param \WP_Error $error                   The error to return.
	 * @param bool      $leaves_rules_unenforced Whether the save leaves the rules unevaluated.
	 *
	 * @return \WP_Error
	 */
	private static function in_context_error( $error, $leaves_rules_unenforced ) {
		if ( ! $leaves_rules_unenforced || 'empty_access_rule_value' !== $error->get_error_code() ) {
			return $error;
		}
		$rule_name = $error->get_error_data()['rule_name'] ?? '';
		return new \WP_Error(
			'empty_access_rule_value',
			sprintf(
				/* translators: %s: the access rule's name, e.g. "Institutional access". */
				__( 'The “%s” access rule is empty, so it grants access to everyone. Give it a value or remove it before this gate is active again.', 'newspack-plugin' ),
				$rule_name
			),
			[
				'status'    => 400,
				'rule_name' => $rule_name,
			]
		);
	}

	/**
	 * Whether the caller could save this gate anyway, and so may have its stored
	 * settings read while their request is sanitized.
	 *
	 * The gate routes' `permission_callback` requires the wizard capability, and
	 * sanitization runs ahead of it — so these reads check the same capability
	 * rather than a per-post one. `edit_post` on a published gate resolves through
	 * `map_meta_cap` to `edit_others_posts` + `edit_published_posts`, which an
	 * Editor holds and the routes do not accept.
	 *
	 * @param int $gate_id The gate ID from the route.
	 *
	 * @return bool
	 */
	private static function caller_can_save_gate( $gate_id ) {
		return (bool) $gate_id && current_user_can( self::MANAGE_GATES_CAPABILITY );
	}

	/**
	 * Whether a save leaves the gate's access rules unevaluated, either by
	 * unpublishing the gate or by switching its custom access section off.
	 *
	 * Only such a save may carry an already-invalid stored value back untouched.
	 * A save that publishes or activates the gate has to fix the value first,
	 * otherwise one click on the gates list's "Set to active" would put a gate
	 * that denies every reader live, under a notice reading "gate enabled".
	 *
	 * @param array $gate           The gate as it arrived in the request.
	 * @param array $sanitized_gate The gate sanitized so far, which carries the resulting status.
	 * @param int   $gate_id        The gate ID from the route.
	 *
	 * @return bool
	 */
	private static function save_leaves_rules_unenforced( $gate, $sanitized_gate, $gate_id ) {
		// Rules are only evaluated while the custom access section is active.
		if ( isset( $gate['custom_access']['active'] ) && ! boolval( $gate['custom_access']['active'] ) ) {
			return true;
		}
		// A save that omits `status` leaves the stored one in place.
		$status = $sanitized_gate['status'] ?? ( $gate_id ? get_post_status( $gate_id ) : 'draft' );
		return 'publish' !== $status;
	}

	/**
	 * Whether a request's access rules are the ones the gate already stores.
	 *
	 * Both sides are cast through the same conversions the sanitizer applies to a
	 * rule value before comparing them as JSON, because the client round-trips the
	 * rules it read and an integer option value can come back as a string. A
	 * loose comparison would go further than that and read `'0'` as equal to
	 * `false`, silently dropping an operator's edit.
	 *
	 * @param array $gate    The gate as it arrived in the request.
	 * @param int   $gate_id The gate ID from the route.
	 *
	 * @return bool
	 */
	private static function access_rules_are_unchanged( $gate, $gate_id ) {
		if ( ! is_array( $gate['custom_access']['access_rules'] ?? null ) ) {
			return false;
		}
		// Sanitization runs ahead of the route's `permission_callback`, so the read is
		// guarded here too: without it the response code tells a caller who can't edit
		// the gate whether it stores exactly the rules they sent.
		if ( ! self::caller_can_save_gate( $gate_id ) ) {
			return false;
		}
		$stored_rules = Content_Gate::get_custom_access_settings( $gate_id )['access_rules'] ?? [];
		return self::access_rules_fingerprint( $gate['custom_access']['access_rules'] ) === self::access_rules_fingerprint( $stored_rules );
	}

	/**
	 * A comparable rendering of a rule set, with each value cast the way
	 * `sanitize_access_rule()` casts it.
	 *
	 * @param array $rules The access rules, flat or grouped.
	 *
	 * @return string
	 */
	private static function access_rules_fingerprint( $rules ) {
		$cast = function ( $value ) use ( &$cast ) {
			if ( is_array( $value ) ) {
				return array_map( $cast, $value );
			}
			if ( ! is_scalar( $value ) ) {
				return $value;
			}
			return is_numeric( $value ) ? intval( $value ) : sanitize_text_field( $value );
		};
		return (string) wp_json_encode( $cast( Access_Rules::normalize_rules( $rules ) ) );
	}

	/**
	 * Sanitize registration settings.
	 *
	 * @param array $registration The registration settings.
	 *
	 * @return array The sanitized registration.
	 */
	public static function sanitize_registration( $registration ) {
		$sanitized = [];
		if ( isset( $registration['active'] ) ) {
			$sanitized['active'] = boolval( $registration['active'] );
		}
		if ( isset( $registration['metering'] ) ) {
			$sanitized['metering'] = self::sanitize_metering( $registration['metering'] );
		}
		if ( isset( $registration['require_verification'] ) ) {
			$sanitized['require_verification'] = boolval( $registration['require_verification'] );
		}
		if ( isset( $registration['gate_layout_id'] ) ) {
			$sanitized['gate_layout_id'] = absint( $registration['gate_layout_id'] );
		}
		return $sanitized;
	}

	/**
	 * Sanitize custom access settings.
	 *
	 * @param array $custom_access The custom access settings.
	 *
	 * @return array|\WP_Error The sanitized custom access, or an error when an
	 *                         access rule holds an invalid value.
	 */
	public static function sanitize_custom_access( $custom_access ) {
		$sanitized = [];
		if ( isset( $custom_access['active'] ) ) {
			$sanitized['active'] = boolval( $custom_access['active'] );
		}
		if ( isset( $custom_access['metering'] ) ) {
			$sanitized['metering'] = self::sanitize_metering( $custom_access['metering'] );
		}
		if ( isset( $custom_access['access_rules'] ) ) {
			$sanitized_rules = self::sanitize_rules( $custom_access['access_rules'], 'access' );
			if ( is_wp_error( $sanitized_rules ) ) {
				return $sanitized_rules;
			}
			$sanitized['access_rules'] = $sanitized_rules;
		}
		if ( isset( $custom_access['gate_layout_id'] ) ) {
			$sanitized['gate_layout_id'] = absint( $custom_access['gate_layout_id'] );
		}
		if ( isset( $custom_access['payment_recovery_grace'] ) ) {
			$sanitized['payment_recovery_grace'] = boolval( $custom_access['payment_recovery_grace'] );
		}
		return $sanitized;
	}

	/**
	 * Sanitize the metering.
	 *
	 * @param array $metering The metering.
	 *
	 * @return array The sanitized metering.
	 */
	public static function sanitize_metering( $metering ) {
		$sanitized = [];
		if ( isset( $metering['enabled'] ) ) {
			$sanitized['enabled'] = boolval( $metering['enabled'] );
		}
		if ( isset( $metering['count'] ) ) {
			// Floor at 0: signed intval() would persist a negative count, which Metering reads
			// back through absint() as a positive free-view allowance.
			$sanitized['count'] = max( 0, intval( $metering['count'] ) );
		}
		if ( isset( $metering['period'] ) ) {
			// Only these three have an expiration, and one period the site meter cannot hold
			// pushes adoption into its conflict branch, pinning every gate on the site.
			$period              = sanitize_text_field( $metering['period'] );
			$sanitized['period'] = in_array( $period, [ 'day', 'week', 'month' ], true ) ? $period : 'month';
		}
		if ( isset( $metering['scope'] ) ) {
			$sanitized['scope'] = Site_Meter::sanitize_scope( $metering['scope'] );
		}
		return $sanitized;
	}

	/**
	 * Sanitize rules.
	 *
	 * @param array  $rules The rules.
	 * @param string $type The type of rules to sanitize.
	 *
	 * @return array|\WP_Error The sanitized rules, or an error when an access rule
	 *                         holds an invalid value.
	 */
	public static function sanitize_rules( $rules, $type = 'access' ) {
		if ( ! is_array( $rules ) ) {
			return [];
		}

		// For access rules, handle grouped format.
		if ( 'access' === $type ) {
			return self::sanitize_access_rules_grouped( $rules );
		}

		// For content rules, use flat format.
		$sanitized_rules = [];
		foreach ( $rules as $rule ) {
			$sanitized = self::sanitize_content_rule( $rule );
			if ( ! is_wp_error( $sanitized ) ) {
				$sanitized_rules[] = $sanitized;
			}
		}
		return $sanitized_rules;
	}

	/**
	 * Sanitize access rules in grouped format.
	 *
	 * Accepts both flat format [ rule1, rule2 ] and grouped format [ [ rule1, rule2 ], [ rule3 ] ].
	 * Always returns grouped format [ [ rule1, rule2 ], [ rule3 ] ].
	 *
	 * @param array $rules The access rules.
	 *
	 * @return array|\WP_Error The sanitized access rules in grouped format, or an
	 *                         error when a rule holds an invalid value, or when
	 *                         dropping unknown rules would leave no rules at all.
	 */
	public static function sanitize_access_rules_grouped( $rules ) {
		if ( empty( $rules ) ) {
			return [];
		}

		// Normalize rules (flat or grouped) to a consistent grouped format.
		$rules = Access_Rules::normalize_rules( $rules );

		// Sanitize each group.
		$sanitized_groups = [];
		$dropped_any_rule = false;
		foreach ( $rules as $group ) {
			$sanitized_group = self::sanitize_access_rules_group( $group );
			if ( is_wp_error( $sanitized_group ) ) {
				return $sanitized_group;
			}
			if ( is_array( $group ) && count( $sanitized_group ) < count( $group ) ) {
				$dropped_any_rule = true;
			}
			if ( ! empty( $sanitized_group ) ) {
				$sanitized_groups[] = $sanitized_group;
			}
		}

		// Dropping unknown rules can empty a group, and an emptied group is skipped.
		// Losing one group among several only tightens the gate, since groups are
		// OR-ed — but losing every group leaves `access_rules => []`, which grants
		// access to everyone. Fail the save rather than silently open the gate.
		if ( $dropped_any_rule && empty( $sanitized_groups ) ) {
			return new \WP_Error(
				'invalid_access_rules',
				__( 'None of this gate’s access rules are available right now, so saving it would grant access to everyone. Restore the integration that provides them, or turn off custom access for this gate.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}

		return $sanitized_groups;
	}

	/**
	 * Sanitize a single group of access rules.
	 *
	 * A rule with an unknown slug is dropped — a gate may hold rules from a since-
	 * deactivated integration, and those must not brick the save. Dropping it doesn't
	 * change effective behavior, because `Access_Rules::evaluate_rule()` already
	 * returns true for an unregistered slug. The exception is a save where nothing
	 * survives across every group, which leaves an empty rule set that grants access
	 * outright; `sanitize_access_rules_grouped()` fails that save. An *invalid value*
	 * on a known rule fails the whole save instead: silently dropping that rule would
	 * flip its group from failing closed at evaluate time to granting access, so the
	 * error must reach the caller for the operator to fix the value.
	 *
	 * @param array $group The group of access rules.
	 *
	 * @return array|\WP_Error The sanitized group, or an error when a rule holds an
	 *                         invalid value.
	 */
	public static function sanitize_access_rules_group( $group ) {
		if ( ! is_array( $group ) ) {
			return [];
		}

		$sanitized_group = [];
		foreach ( $group as $rule ) {
			$sanitized = self::sanitize_access_rule( $rule );
			if ( is_wp_error( $sanitized ) ) {
				if ( 'invalid_access_rule_value' === $sanitized->get_error_code() ) {
					return $sanitized;
				}
				continue;
			}
			$sanitized_group[] = $sanitized;
		}
		return $sanitized_group;
	}

	/**
	 * Sanitize access rule.
	 *
	 * @param array $access_rule The access rule.
	 *
	 * @return mixed|\WP_Error The sanitized access rule or error if invalid.
	 */
	public static function sanitize_access_rule( $access_rule ) {
		// The registered rules, not the resolved ones: sanitizing reads only `is_boolean`,
		// `sanitize_callback` and whether the rule is options-backed at all, none of which
		// an options callback affects. Resolving here would run each rule's full-catalog
		// query once per rule in the payload — six times over on a six-rule gate — and the
		// options-backed test would then turn on whether the shop currently has any
		// products, sending a valid ID list down the plain-string branch on an empty
		// catalog. Values are sanitized off the request and never checked against the list.
		$rules = Access_Rules::get_registered_rules();
		$slug  = sanitize_text_field( $access_rule['slug'] ?? '' );

		if ( empty( $slug ) || ! isset( $rules[ $slug ] ) ) {
			return new \WP_Error( 'invalid_access_rule_slug', __( 'Invalid access rule slug.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}

		$value = null;
		$rule  = $rules[ $slug ];
		// Rules with a composite value shape sanitize it themselves.
		if ( ! empty( $rule['sanitize_callback'] ) && is_callable( $rule['sanitize_callback'] ) ) {
			return [
				'slug'  => $slug,
				'value' => call_user_func( $rule['sanitize_callback'], $access_rule['value'] ?? null ),
			];
		}
		if ( $rule['is_boolean'] ) {
			$value = true; // Boolean rules are always true.
		} elseif ( ! empty( $rule['has_options'] ) ) {
			// Branch on whether the rule declares an options source, not on the
			// resolved list: an options-backed rule whose list is currently empty
			// (no institutions yet, no subscription products) still takes array
			// values only. Free text saved here would evaluate as malformed.
			if ( ! is_array( $access_rule['value'] ?? null ) ) {
				return self::invalid_access_rule_value_error( $rule );
			}
			// Sanitize element by element rather than filtering the mapped list.
			// `array_filter()` with no callback drops every falsy element, so an
			// option value of `0` or `'0'` would vanish, and so would a nested
			// array that `sanitize_text_field()` had already flattened to ''. A
			// populated selection that sanitizes down to an empty list saves as
			// "no constraint", which grants access — so reject it instead.
			$value = [];
			foreach ( $access_rule['value'] as $option_value ) {
				if ( ! is_scalar( $option_value ) ) {
					return self::invalid_access_rule_value_error( $rule );
				}
				$sanitized_option_value = is_numeric( $option_value ) ? intval( $option_value ) : sanitize_text_field( $option_value );
				if ( '' === $sanitized_option_value ) {
					continue;
				}
				$value[] = $sanitized_option_value;
			}
			if ( ! empty( $access_rule['value'] ) && empty( $value ) ) {
				return self::invalid_access_rule_value_error( $rule );
			}
		} else {
			// The mirror shape check: `sanitize_text_field()` silently collapses an
			// array to '', and free-text rules read '' as "no constraint" — so a
			// populated non-scalar value would grant access to everyone.
			if ( ! is_scalar( $access_rule['value'] ?? '' ) ) {
				return self::invalid_access_rule_value_error( $rule );
			}
			$value = sanitize_text_field( $access_rule['value'] ?? '' );
		}

		return [
			'slug'  => $slug,
			'value' => $value,
		];
	}

	/**
	 * The error returned when a rule's value can't be sanitized into the shape its
	 * registration declares.
	 *
	 * @param array $rule The registered rule.
	 *
	 * @return \WP_Error
	 */
	private static function invalid_access_rule_value_error( $rule ) {
		return new \WP_Error(
			'invalid_access_rule_value',
			sprintf(
				// translators: %s is the access rule name.
				__( 'Invalid value for the "%s" access rule.', 'newspack-plugin' ),
				$rule['name']
			),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Sanitize content rule.
	 *
	 * @param array $content_rule The content rule.
	 *
	 * @return mixed|\WP_Error The sanitized content rule or error if invalid.
	 */
	public static function sanitize_content_rule( $content_rule ) {
		$rules                = Content_Rules::get_content_rules();
		$newsletter_rules     = Content_Rules::get_premium_newsletter_rules();
		$newsletter_rules_arr = ( is_array( $newsletter_rules ) && ! is_wp_error( $newsletter_rules ) ) ? $newsletter_rules : [];
		$rules                = array_merge( $rules, $newsletter_rules_arr );
		$slug                 = sanitize_text_field( $content_rule['slug'] );

		if ( empty( $slug ) || ! isset( $rules[ $slug ] ) ) {
			return new \WP_Error( 'invalid_content_rule_slug', __( 'Invalid content rule slug.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}

		$rule = $rules[ $slug ];
		if ( ! empty( $rule['options'] ) ) {
			$allowed = array_column( $rule['options'], 'value' );
			$invalid = array_diff( $content_rule['value'], $allowed );
			if ( ! empty( $invalid ) ) {
				return new \WP_Error( 'invalid_content_rule_value', __( 'Invalid content rule value.', 'newspack-plugin' ), [ 'status' => 400 ] );
			}
		}

		$value     = array_values( array_filter( array_map( 'sanitize_text_field', $content_rule['value'] ) ) );
		$exclusion = isset( $content_rule['exclusion'] ) ? boolval( $content_rule['exclusion'] ) : false;

		$sanitized_rule = [
			'slug'  => $slug,
			'value' => $value,
		];
		if ( $exclusion ) {
			$sanitized_rule['exclusion'] = $exclusion;
		}

		return $sanitized_rule;
	}

	/**
	 * Sanitize the gate post status.
	 *
	 * @param string $status Post status.
	 * @param int    $gate_id Gate ID.
	 *
	 * @return string The sanitized post status.
	 */
	public static function sanitize_status( $status, $gate_id ) {
		$sanitized = sanitize_text_field( $status );
		$valid = in_array( $sanitized, Content_Gate::get_post_statuses(), true );
		if ( ! $valid ) {
			$sanitized = $gate_id ? get_post_status( $gate_id ) : 'draft';
		}
		return $sanitized;
	}
}

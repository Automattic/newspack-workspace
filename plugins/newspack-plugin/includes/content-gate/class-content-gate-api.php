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
						'period'  => [ 'type' => 'string' ],
					],
				],
			],
		],
		'custom_access'       => [
			'type'       => 'object',
			'properties' => [
				'active'         => [ 'type' => 'boolean' ],
				'metering'       => [
					'type'       => 'object',
					'properties' => [
						'enabled' => [ 'type' => 'boolean' ],
						'count'   => [ 'type' => 'integer' ],
						'period'  => [ 'type' => 'string' ],
					],
				],
				'gate_layout_id' => [
					'type'     => 'integer',
					'required' => false,
				],
				'access_rules'   => [
					'type'  => 'array',
					'items' => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'slug'  => [ 'type' => 'string' ],
								'value' => [ 'type' => [ 'string', 'array' ] ],
							],
						],
					],
				],
			],
		],
	];

	/**
	 * Sanitize the gate.
	 *
	 * TODO: Handle errors from each sanitization method.
	 *
	 * @param array $gate The gate.
	 *
	 * @return array The sanitized gate.
	 */
	public static function sanitize_gate( $gate ) {
		$sanitized = [
			'title'         => isset( $gate['title'] ) ? sanitize_text_field( $gate['title'] ) : __( 'Untitled Content Gate', 'newspack-plugin' ),
			'priority'      => isset( $gate['priority'] ) ? intval( $gate['priority'] ) : 0,
			'status'        => isset( $gate['status'] ) && ! empty( $gate['id'] ) ? self::sanitize_status( $gate['status'], $gate['id'] ) : 'draft',
			'content_rules' => isset( $gate['content_rules'] ) ? self::sanitize_rules( $gate['content_rules'], 'content' ) : [],
			'registration'  => isset( $gate['registration'] ) ? self::sanitize_registration( $gate['registration'] ) : [],
			'custom_access' => isset( $gate['custom_access'] ) ? self::sanitize_custom_access( $gate['custom_access'] ) : [],
		];
		// Only include the rule-combination mode when the request explicitly provided it,
		// so an omitted field does not clobber an existing gate's stored mode on update.
		if ( isset( $gate['content_rules_match'] ) ) {
			$sanitized['content_rules_match'] = in_array( $gate['content_rules_match'], [ 'all', 'any' ], true ) ? $gate['content_rules_match'] : 'all';
		}
		return $sanitized;
	}

	/**
	 * Sanitize registration settings.
	 *
	 * @param array $registration The registration settings.
	 *
	 * @return array The sanitized registration.
	 */
	public static function sanitize_registration( $registration ) {
		$registration = [
			'active'               => boolval( $registration['active'] ),
			'metering'             => self::sanitize_metering( $registration['metering'] ),
			'require_verification' => boolval( $registration['require_verification'] ),
		];
		if ( isset( $registration['gate_layout_id'] ) ) {
			$registration['gate_layout_id'] = absint( $registration['gate_layout_id'] );
		}
		return $registration;
	}

	/**
	 * Sanitize custom access settings.
	 *
	 * @param array $custom_access The custom access settings.
	 *
	 * @return array The sanitized custom access.
	 */
	public static function sanitize_custom_access( $custom_access ) {
		$custom_access = [
			'active'       => boolval( $custom_access['active'] ),
			'metering'     => self::sanitize_metering( $custom_access['metering'] ),
			'access_rules' => self::sanitize_rules( $custom_access['access_rules'], 'access' ),
		];
		if ( isset( $custom_access['gate_layout_id'] ) ) {
			$custom_access['gate_layout_id'] = absint( $custom_access['gate_layout_id'] );
		}
		return $custom_access;
	}

	/**
	 * Sanitize the metering.
	 *
	 * @param array $metering The metering.
	 *
	 * @return array The sanitized metering.
	 */
	public static function sanitize_metering( $metering ) {
		$metering = wp_parse_args(
			$metering,
			[
				'enabled' => false,
				'count'   => 0,
				'period'  => 'month',
			]
		);
		return [
			'enabled' => boolval( $metering['enabled'] ),
			'count'   => intval( $metering['count'] ),
			'period'  => sanitize_text_field( $metering['period'] ),
		];
	}

	/**
	 * Sanitize rules.
	 *
	 * @param array  $rules The rules.
	 * @param string $type The type of rules to sanitize.
	 *
	 * @return array The sanitized rules.
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
	 * @return array The sanitized access rules in grouped format.
	 */
	public static function sanitize_access_rules_grouped( $rules ) {
		if ( empty( $rules ) ) {
			return [];
		}

		// Normalize rules (flat or grouped) to a consistent grouped format.
		$rules = Access_Rules::normalize_rules( $rules );

		// Sanitize each group.
		$sanitized_groups = [];
		foreach ( $rules as $group ) {
			$sanitized_group = self::sanitize_access_rules_group( $group );
			if ( ! empty( $sanitized_group ) ) {
				$sanitized_groups[] = $sanitized_group;
			}
		}

		return $sanitized_groups;
	}

	/**
	 * Sanitize a single group of access rules.
	 *
	 * @param array $group The group of access rules.
	 *
	 * @return array The sanitized group.
	 */
	public static function sanitize_access_rules_group( $group ) {
		if ( ! is_array( $group ) ) {
			return [];
		}

		$sanitized_group = [];
		foreach ( $group as $rule ) {
			$sanitized = self::sanitize_access_rule( $rule );
			if ( ! is_wp_error( $sanitized ) ) {
				$sanitized_group[] = $sanitized;
			}
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
		$rules = Access_Rules::get_access_rules();
		$slug  = sanitize_text_field( $access_rule['slug'] );

		if ( empty( $slug ) || ! isset( $rules[ $slug ] ) ) {
			return new \WP_Error( 'invalid_access_rule_slug', __( 'Invalid access rule slug.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}

		$value = null;
		$rule  = $rules[ $slug ];
		if ( $rule['is_boolean'] ) {
			$value = true; // Boolean rules are always true.
		} elseif ( ! empty( $rule['options'] ) ) {
			if ( ! is_array( $access_rule['value'] ) ) {
				return new \WP_Error( 'invalid_access_rule_value', __( 'Invalid access rule value.', 'newspack-plugin' ), [ 'status' => 400 ] );
			}
			$value = array_values(
				array_filter(
					array_map(
						function( $value ) {
							return is_numeric( $value ) ? intval( $value ) : sanitize_text_field( $value );
						},
						$access_rule['value']
					)
				)
			);
		} else {
			$value = sanitize_text_field( $access_rule['value'] );
		}

		return [
			'slug'  => $slug,
			'value' => $value,
		];
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

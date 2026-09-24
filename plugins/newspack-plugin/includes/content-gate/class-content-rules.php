<?php
/**
 * Newspack Content Gate Content Rules
 *
 * @package Newspack
 */

namespace Newspack;

/**
 * Main class.
 */
class Content_Rules {

	/**
	 * Get available content rules.
	 *
	 * @return array The content rules.
	 */
	public static function get_content_rules() {
		$content_rules = [
			'post_types' => [
				'name'        => __( 'Post types', 'newspack-plugin' ),
				'options'     => Content_Restriction_Control::get_available_post_types(),
				'default'     => [ 'post' ],
				'description' => __( 'Content types like posts, pages, or listings.', 'newspack-plugin' ),
			],
		];
		$available_taxonomies = Content_Restriction_Control::get_available_taxonomies();
		foreach ( $available_taxonomies as $taxonomy ) {
			$content_rules[ $taxonomy['slug'] ] = [
				'name'        => $taxonomy['label'],
				'default'     => [],
				'description' => $taxonomy['description'],
			];
		}

		$content_rules['specific_posts'] = [
			'name'         => __( 'Specific posts', 'newspack-plugin' ),
			'default'      => [],
			'description'  => __( 'Restrict specific posts, even if not covered by other rules above.', 'newspack-plugin' ),
			'endpoint'     => '/' . NEWSPACK_API_NAMESPACE . '/wizard/newspack-audience-access-control/posts-search',
			'include_only' => true,
		];

		return $content_rules;
	}

	/**
	 * Get premium newsletter content rules.
	 *
	 * @return array The premium newsletter content rules.
	 */
	public static function get_premium_newsletter_rules() {
		$newsletters_configuration_manager = Configuration_Managers::configuration_manager_class_for_plugin_slug( 'newspack-newsletters' );
		$lists                             = $newsletters_configuration_manager->get_lists();

		if ( is_wp_error( $lists ) || ! is_array( $lists ) ) {
			return [];
		}

		return [
			'newsletters' => [
				'name'         => __( 'Lists', 'newspack-plugin' ),
				'default'      => [],
				'description'  => __( 'Newsletter subscription lists.', 'newspack-plugin' ),
				'endpoint'     => 'newspack-newsletters/v1/lists',
				'include_only' => true,
			],
		];
	}

	/**
	 * Get the content rules for a gate.
	 *
	 * @param int $post_id Gate post ID.
	 *
	 * @return array The content rules.
	 */
	public static function get_gate_content_rules( $post_id ) {
		$rules = \get_post_meta( $post_id, 'content_rules', true );

		// Filter out rules with empty array-like values.
		return is_array( $rules ) ? array_values(
			array_filter(
				$rules,
				function( $rule ) {
					if ( ! isset( $rule['value'] ) || ! is_array( $rule['value'] ) ) {
						return true;
					}
					return count( $rule['value'] ) > 0;
				}
			)
		) : [];
	}

	/**
	 * Update content rules for bypassing a content gate.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $rules   Array of post content rules.
	 *
	 * @return void
	 */
	public static function update_gate_content_rules( $post_id, $rules ) {
		\update_post_meta( $post_id, 'content_rules', $rules );
	}

	/**
	 * Get the rule-combination mode for a gate.
	 *
	 * @param int $post_id Gate post ID.
	 *
	 * @return string 'all' (AND, default) or 'any' (OR).
	 */
	public static function get_gate_content_rules_match( $post_id ) {
		$value = \get_post_meta( $post_id, 'content_rules_match', true );
		return 'any' === $value ? 'any' : 'all';
	}

	/**
	 * Update the rule-combination mode for a gate.
	 *
	 * @param int    $post_id Gate post ID.
	 * @param string $value   'all' or 'any'.
	 *
	 * @return void
	 */
	public static function update_gate_content_rules_match( $post_id, $value ) {
		\update_post_meta( $post_id, 'content_rules_match', 'any' === $value ? 'any' : 'all' );
	}
}

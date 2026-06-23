<?php
/**
 * Class Test_Posts_Inserter_Rest_Fields
 *
 * @package Newspack_Newsletters
 */

/**
 * Tests for Newspack_Newsletters_Editor::add_newspack_extra_info().
 *
 * Regression coverage for NPPM-2756: the Posts Inserter block lets the user
 * pick any post type the REST API exposes (REST-exposed + viewable + show_ui),
 * but the extra REST fields it relies on — most importantly `featured_media_info`
 * — were only registered for `post`. As a result featured images (and author/byline data)
 * silently disappeared for Pages, Newsletters, Events, etc. This locks the
 * fields to every post type the inserter can actually offer.
 */
class Test_Posts_Inserter_Rest_Fields extends WP_UnitTestCase {

	/**
	 * A public, UI-visible CPT that stands in for e.g. The Events Calendar's
	 * `tribe_events` — the inserter offers it, so the fields must cover it.
	 *
	 * @var string
	 */
	const VIEWABLE_CPT = 'nppm2756_event';

	/**
	 * A viewable + REST-exposed CPT that is NOT UI-visible (no show_ui). The
	 * inserter never offers it — isolating the `show_ui` half of the filter
	 * (viewable + show_in_rest both pass, only show_ui fails).
	 *
	 * @var string
	 */
	const HIDDEN_CPT = 'nppm2756_hidden';

	/**
	 * A CPT that is UI-visible (show_ui) but NOT viewable (private, not
	 * publicly queryable). The inserter excludes it, so this isolates the
	 * `is_post_type_viewable` half of the filter — the gate `show_ui` alone
	 * would not catch.
	 *
	 * @var string
	 */
	const UI_ONLY_CPT = 'nppm2756_ui_only';

	/**
	 * A viewable + show_ui CPT that is NOT exposed to REST. The inserter reads
	 * `/wp/v2/types`, which only lists `show_in_rest` types, so it never offers
	 * this — isolating the `show_in_rest` half of the filter.
	 *
	 * @var string
	 */
	const NO_REST_CPT = 'nppm2756_no_rest';

	/**
	 * Snapshot of the REST additional-fields registry, captured before each test
	 * and restored after, so the registrations these tests make don't leak into
	 * other test classes and cause order-dependent failures.
	 *
	 * @var array|null
	 */
	private $rest_fields_backup;

	/**
	 * Register the helper post types before the suite runs.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		register_post_type(
			self::VIEWABLE_CPT,
			[
				'public'       => true,
				'show_ui'      => true,
				'show_in_rest' => true,
			]
		);

		register_post_type(
			self::HIDDEN_CPT,
			[
				'public'       => true,
				'show_ui'      => false,
				'show_in_rest' => true,
			]
		);

		register_post_type(
			self::UI_ONLY_CPT,
			[
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_rest'       => true,
			]
		);

		register_post_type(
			self::NO_REST_CPT,
			[
				'public'       => true,
				'show_ui'      => true,
				'show_in_rest' => false,
			]
		);
	}

	/**
	 * Clean up the helper post types after the suite.
	 */
	public static function tear_down_after_class() {
		unregister_post_type( self::VIEWABLE_CPT );
		unregister_post_type( self::HIDDEN_CPT );
		unregister_post_type( self::UI_ONLY_CPT );
		unregister_post_type( self::NO_REST_CPT );
		parent::tear_down_after_class();
	}

	/**
	 * Snapshot the REST additional-fields registry before each test.
	 */
	public function set_up() {
		parent::set_up();
		global $wp_rest_additional_fields;
		$this->rest_fields_backup = $wp_rest_additional_fields;
	}

	/**
	 * Restore the REST additional-fields registry after each test.
	 */
	public function tear_down() {
		global $wp_rest_additional_fields;
		$wp_rest_additional_fields = $this->rest_fields_backup;
		parent::tear_down();
	}

	/**
	 * Returns the object types `$field` is registered for in the REST schema.
	 *
	 * @param string $field REST field name.
	 * @return string[] Post types the field is registered for.
	 */
	private function registered_post_types_for( $field ) {
		global $wp_rest_additional_fields;
		$types = [];
		foreach ( (array) $wp_rest_additional_fields as $object_type => $fields ) {
			if ( isset( $fields[ $field ] ) ) {
				$types[] = $object_type;
			}
		}
		return $types;
	}

	/**
	 * `featured_media_info` must register for every REST-exposed + viewable +
	 * show_ui post type the inserter can offer — not just `post`.
	 */
	public function test_featured_media_info_registers_for_inserter_post_types() {
		Newspack_Newsletters_Editor::add_newspack_extra_info();

		$types = $this->registered_post_types_for( 'featured_media_info' );

		$this->assertContains( 'post', $types, 'post must keep the field' );
		$this->assertContains( 'page', $types, 'pages are offered by the inserter' );
		$this->assertContains(
			\Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
			$types,
			'newsletters are offered by the inserter'
		);
		$this->assertContains(
			self::VIEWABLE_CPT,
			$types,
			'REST-exposed + viewable + show_ui CPTs (e.g. events) are offered by the inserter'
		);
	}

	/**
	 * The field must NOT register for post types the inserter never offers.
	 * Three distinct exclusion gates, asserted separately so each stays
	 * load-bearing: HIDDEN_CPT pins `show_ui`, UI_ONLY_CPT pins
	 * `is_post_type_viewable` (show_ui true but not viewable), NO_REST_CPT pins
	 * `show_in_rest` (viewable + show_ui but not REST-exposed).
	 */
	public function test_featured_media_info_skips_hidden_post_types() {
		Newspack_Newsletters_Editor::add_newspack_extra_info();

		$types = $this->registered_post_types_for( 'featured_media_info' );

		$this->assertNotContains( self::HIDDEN_CPT, $types, 'no show_ui → excluded' );
		$this->assertNotContains( self::UI_ONLY_CPT, $types, 'show_ui but not viewable → excluded' );
		$this->assertNotContains( self::NO_REST_CPT, $types, 'viewable + show_ui but not REST-exposed → excluded' );
	}

	/**
	 * The sibling fields that are always registered (author info, custom
	 * byline) must follow the same post-type scope.
	 */
	public function test_sibling_fields_share_the_same_scope() {
		Newspack_Newsletters_Editor::add_newspack_extra_info();

		foreach ( [ 'newspack_author_info', 'newspack_custom_byline' ] as $field ) {
			$types = $this->registered_post_types_for( $field );
			$this->assertContains( 'page', $types, "$field must cover pages" );
			$this->assertContains( self::VIEWABLE_CPT, $types, "$field must cover viewable CPTs" );
		}
	}

	/**
	 * The extra fields must stay scoped to the `edit` context. The safety case
	 * for registering them across every viewable post type rests on this:
	 * author/byline/sponsor data is only exposed to authenticated editors, never
	 * in the public (`view`) REST response. A regression dropping `edit` context
	 * would silently widen that exposure, so pin it explicitly.
	 */
	public function test_extra_fields_are_edit_context_only() {
		global $wp_rest_additional_fields;

		Newspack_Newsletters_Editor::add_newspack_extra_info();

		foreach ( [ 'newspack_author_info', 'newspack_custom_byline', 'featured_media_info' ] as $field ) {
			$args = $wp_rest_additional_fields['page'][ $field ] ?? null;
			$this->assertNotNull( $args, "$field must be registered for page" );
			$this->assertSame(
				[ 'edit' ],
				$args['schema']['context'] ?? null,
				"$field must be exposed only in the edit context"
			);
		}
	}

	/**
	 * `featured_media_info` carries keyed URLs (`large_url`/`medium_url`), so its
	 * schema must be `object`, not `array` — the original NPPM-2756 regression.
	 * Pin it so a revert to `array` can't slip through.
	 */
	public function test_featured_media_info_schema_is_object() {
		global $wp_rest_additional_fields;

		Newspack_Newsletters_Editor::add_newspack_extra_info();

		$args = $wp_rest_additional_fields['page']['featured_media_info'] ?? null;
		$this->assertNotNull( $args, 'featured_media_info must be registered for page' );
		$this->assertSame( 'object', $args['schema']['type'] ?? null );
	}
}

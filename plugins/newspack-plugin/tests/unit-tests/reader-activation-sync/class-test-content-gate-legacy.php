<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing
/**
 * Tests Content Gate metadata availability in the legacy sync schema.
 *
 * @package Newspack\Tests
 */

use Newspack\Content_Gate;
use Newspack\Institution;
use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Sync\Metadata;
use Newspack\Reader_Activation\Sync\Contact_Metadata\Content_Gate as Content_Gate_Metadata;

/**
 * Content Gate metadata on the legacy schema.
 *
 * @group Content_Gate_Legacy
 */
class Test_Content_Gate_Legacy extends WP_UnitTestCase {

	/**
	 * Schema version restored in tear_down().
	 *
	 * @var string
	 */
	private static $original_version;

	/**
	 * Enabled outgoing fields restored in tear_down().
	 *
	 * @var array|null
	 */
	private $original_enabled_fields;

	/**
	 * Verified reader user ID.
	 *
	 * @var int
	 */
	private static $user_id;

	/**
	 * Define the Content Gates feature flag for this test class only. PHP
	 * defines cannot be unset, so once defined the flag stays on for the
	 * rest of the PHPUnit process — meaning any future test in the same
	 * process that asserts feature-off behavior would be silently
	 * neutralized. If such a test is added later, it must run in a
	 * separate process (e.g. @runInSeparateProcess). Defining in the
	 * bootstrap would have the same leak across the entire suite.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		self::$original_version = Metadata::$version;
	}

	public function set_up() {
		parent::set_up();
		Content_Gate_Metadata::reset_cache();
		Metadata::$version             = 'legacy';
		$this->original_enabled_fields = Metadata::get_fields();
		self::$user_id                 = $this->factory->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'reader@example.com',
			]
		);
		Reader_Activation::set_reader_verified( self::$user_id );
	}

	public function tear_down() {
		Metadata::update_fields( ! empty( $this->original_enabled_fields ) ? $this->original_enabled_fields : [] );
		Metadata::$version = self::$original_version;
		Content_Gate_Metadata::reset_cache();
		parent::tear_down();
	}

	/**
	 * Create a published gate with active custom access rules.
	 *
	 * @param array  $access_rules Access rules array.
	 * @param string $title        Optional gate title.
	 * @return int Gate post ID.
	 */
	private function create_custom_access_gate( $access_rules, $title = 'Legacy Gate' ) {
		$gate_id = $this->factory->post->create(
			[
				'post_type'   => Content_Gate::GATE_CPT,
				'post_status' => 'publish',
				'post_title'  => $title,
			]
		);
		update_post_meta(
			$gate_id,
			'custom_access',
			[
				'active'       => true,
				'access_rules' => $access_rules,
			]
		);
		return $gate_id;
	}

	/**
	 * Email-domain rule the seeded reader passes (reader@example.com).
	 *
	 * @return array
	 */
	private function passing_email_domain_rules() {
		return [
			[
				[
					'slug'  => 'email_domain',
					'value' => 'example.com',
				],
			],
		];
	}

	/**
	 * Email-domain rule the seeded reader does not pass.
	 *
	 * @return array
	 */
	private function failing_email_domain_rules() {
		return [
			[
				[
					'slug'  => 'email_domain',
					'value' => 'other.com',
				],
			],
		];
	}

	public function test_legacy_schema_exposes_content_access_fields() {
		$fields = Metadata::get_all_fields();
		$this->assertArrayHasKey( 'Content_Access', $fields, 'Legacy schema should expose the Content_Access field.' );
		$this->assertSame( 'Content Access', $fields['Content_Access'] );
		$this->assertArrayHasKey( 'Content_Access_Source', $fields );
		$this->assertArrayHasKey( 'Content_Access_Group', $fields );
	}

	public function test_is_available_follows_content_gate_feature_flag() {
		$this->assertTrue(
			Content_Gate::is_newspack_feature_enabled(),
			'Sanity: the test enables the Content Gate feature.'
		);
		$this->assertSame(
			Content_Gate::is_newspack_feature_enabled(),
			Content_Gate_Metadata::is_available(),
			'Content Gate metadata availability must delegate to the Content Gate feature flag.'
		);
	}

	public function test_legacy_schema_exposes_content_access_group_in_field_selector() {
		$groups   = Metadata::get_grouped_default_fields();
		$sections = array_column( $groups, 'section' );
		$this->assertContains( 'Content Access', $sections, 'The field selector should show a Content Access group on legacy sites.' );
	}

	public function test_legacy_normalize_keeps_content_access_when_enabled() {
		$this->create_custom_access_gate( $this->passing_email_domain_rules() );
		Metadata::update_fields( [ 'Content Access' ] );

		$contact    = Metadata::get_contact_with_metadata( self::$user_id );
		$normalized = Metadata::normalize_contact_data( $contact );

		$this->assertArrayHasKey(
			'NP_Content Access',
			$normalized['metadata'],
			'Enabled Content Access field must survive legacy normalization.'
		);
		$this->assertSame( 'Yes', $normalized['metadata']['NP_Content Access'] );
	}

	public function test_legacy_normalize_drops_content_access_when_not_enabled() {
		$this->create_custom_access_gate( $this->passing_email_domain_rules() );
		Metadata::update_fields( [ 'Account' ] );

		$contact    = Metadata::get_contact_with_metadata( self::$user_id );
		$normalized = Metadata::normalize_contact_data( $contact );

		$this->assertArrayNotHasKey(
			'NP_Content Access',
			$normalized['metadata'],
			'Content Access must be dropped when not enabled for the integration.'
		);
	}

	public function test_content_gate_fields_arrive_prefixed_from_get_contact_with_metadata() {
		$this->create_custom_access_gate( $this->passing_email_domain_rules() );
		Metadata::update_fields( [ 'Content Access' ] );

		// The main legacy sync path feeds get_contact_with_metadata() directly
		// into the integration push without an additional normalize step —
		// metadata classes must return prefixed keys to avoid silent drops.
		$contact = Metadata::get_contact_with_metadata( self::$user_id );

		$this->assertArrayHasKey(
			'NP_Content Access',
			$contact['metadata'],
			'Content_Gate metadata must arrive prefixed from get_contact_with_metadata() in legacy mode.'
		);
		$this->assertSame( 'Yes', $contact['metadata']['NP_Content Access'] );
	}

	public function test_v1_schema_also_exposes_content_access_fields() {
		Metadata::$version = '1.0';
		Content_Gate_Metadata::reset_cache();

		$fields = Metadata::get_all_fields();
		$this->assertArrayHasKey( 'Content_Access', $fields, 'v1 schema should also expose Content_Access.' );
		$this->assertArrayHasKey( 'Content_Access_Source', $fields );
	}

	public function test_no_gate_matches_yields_content_access_no() {
		$this->create_custom_access_gate( $this->failing_email_domain_rules() );
		Metadata::update_fields( [ 'Content Access' ] );

		$contact    = Metadata::get_contact_with_metadata( self::$user_id );
		$normalized = Metadata::normalize_contact_data( $contact );

		$this->assertArrayHasKey( 'NP_Content Access', $normalized['metadata'] );
		$this->assertSame(
			'No',
			$normalized['metadata']['NP_Content Access'],
			'When no custom-access gates grant access, Content_Access must be "No".'
		);
	}

	public function test_legacy_normalize_keeps_already_prefixed_content_access_key() {
		Metadata::update_fields( [ 'Content Access' ] );

		$contact = [
			'email'    => 'reader@example.com',
			'metadata' => [
				'NP_Content Access' => 'Yes',
			],
		];

		$normalized = Metadata::normalize_contact_data( $contact );

		$this->assertArrayHasKey( 'NP_Content Access', $normalized['metadata'] );
		$this->assertSame( 'Yes', $normalized['metadata']['NP_Content Access'] );
	}

	public function test_multiple_gates_aggregate_source_labels() {
		$this->create_custom_access_gate( $this->passing_email_domain_rules(), 'Gate A' );
		$this->create_custom_access_gate(
			[
				[
					[
						'slug'  => 'email_domain',
						'value' => 'example.com,otherdomain.com',
					],
				],
			],
			'Gate B'
		);
		Metadata::update_fields( [ 'Content Access', 'Content Access Source' ] );

		$contact    = Metadata::get_contact_with_metadata( self::$user_id );
		$normalized = Metadata::normalize_contact_data( $contact );

		$this->assertSame( 'Yes', $normalized['metadata']['NP_Content Access'] );
		$this->assertSame(
			'domain',
			$normalized['metadata']['NP_Content Access Source'],
			'Duplicate domain labels from multiple gates must collapse to a single source value.'
		);
	}

	public function test_institution_rule_path_yields_institution_source() {
		$institution_id = Institution::create( 'Test University', '', [ 'email_domain' => 'example.com' ] );
		$this->assertNotInstanceOf( WP_Error::class, $institution_id );

		$this->create_custom_access_gate(
			[
				[
					[
						'slug'  => 'institution',
						'value' => [ $institution_id ],
					],
				],
			]
		);
		Metadata::update_fields( [ 'Content Access', 'Content Access Source' ] );

		$contact    = Metadata::get_contact_with_metadata( self::$user_id );
		$normalized = Metadata::normalize_contact_data( $contact );

		$this->assertSame( 'Yes', $normalized['metadata']['NP_Content Access'] );
		$this->assertSame(
			'institution',
			$normalized['metadata']['NP_Content Access Source'],
			'Institution-rule passes must yield the "institution" source label.'
		);
	}
}

<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests for resolving a Mailchimp merge field's tag by field name.
 *
 * Mailchimp assigns merge-field tags itself, per audience, so the tag for a
 * synced field has to be read back from the audience rather than derived from
 * the field name.
 *
 * @package Newspack_Newsletters
 */

/**
 * Test Newspack_Newsletters_Mailchimp::get_field_merge_tag_name().
 */
class MailchimpFieldTagNameTest extends WP_UnitTestCase {

	/**
	 * Seed the cached-data option for an audience's merge fields, keeping the
	 * test off the network. The `_date_` option stays unseeded so no refresh is
	 * dispatched, and each test uses its own list ID because the cached-data
	 * class memoizes per list ID in a static that outlives a test.
	 *
	 * @param string $list_id      Audience ID, unique per test.
	 * @param array  $merge_fields Merge fields as the Mailchimp API reports them.
	 */
	private function seed_merge_fields( $list_id, $merge_fields ) {
		update_option(
			'newspack_nl_mailchimp_cache_' . $list_id,
			[
				'merge_fields' => $merge_fields,
				'interests'    => [],
				'tags'         => [],
			],
			false
		);
	}

	/**
	 * The common case: the audience reports a tag for the synced field.
	 */
	public function test_resolves_tag_for_field_name() {
		$this->seed_merge_fields(
			'list-resolves',
			[
				[
					'name' => 'NP_Account',
					'tag'  => 'NP_ACCOUNT',
				],
			]
		);
		$this->assertSame(
			'NP_ACCOUNT',
			Newspack_Newsletters_Mailchimp::instance()->get_field_merge_tag_name( 'NP_Account', 'list-resolves' )
		);
	}

	/**
	 * Mailchimp often assigns an opaque tag. It is still the only value
	 * Mailchimp substitutes, so it must be used verbatim.
	 */
	public function test_resolves_opaque_generated_tag() {
		$this->seed_merge_fields(
			'list-opaque',
			[
				[
					'name' => 'NP_Account',
					'tag'  => 'MMERGE7',
				],
			]
		);
		$this->assertSame(
			'MMERGE7',
			Newspack_Newsletters_Mailchimp::instance()->get_field_merge_tag_name( 'NP_Account', 'list-opaque' )
		);
	}

	/**
	 * A field the audience doesn't have yields no tag.
	 */
	public function test_returns_empty_for_unknown_field() {
		$this->seed_merge_fields(
			'list-unknown',
			[
				[
					'name' => 'Something Else',
					'tag'  => 'SOMETHING',
				],
			]
		);
		$this->assertSame(
			'',
			Newspack_Newsletters_Mailchimp::instance()->get_field_merge_tag_name( 'NP_Account', 'list-unknown' )
		);
	}

	/**
	 * Tags are per-audience, so without an audience there is nothing to resolve.
	 */
	public function test_returns_empty_without_a_list_id() {
		$this->seed_merge_fields(
			'list-nolist',
			[
				[
					'name' => 'NP_Account',
					'tag'  => 'NP_ACCOUNT',
				],
			]
		);
		$this->assertSame(
			'',
			Newspack_Newsletters_Mailchimp::instance()->get_field_merge_tag_name( 'NP_Account', null )
		);
	}
}

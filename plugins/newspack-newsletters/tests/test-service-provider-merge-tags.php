<?php
/**
 * Tests for the `get_merge_tags()` provider method introduced in NEWS-2242.
 *
 * @package Newspack_Newsletters
 */

/**
 * Test_Service_Provider_Merge_Tags class.
 */
class Test_Service_Provider_Merge_Tags extends WP_UnitTestCase {

	/**
	 * Base class default should return an empty label and tags array.
	 */
	public function test_base_class_default_is_empty() {
		$result = Newspack_Newsletters_Service_Provider::get_merge_tags();
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'label', $result );
		$this->assertArrayHasKey( 'trigger_prefix', $result );
		$this->assertArrayHasKey( 'tags', $result );
		$this->assertSame( '', $result['label'] );
		$this->assertSame( '', $result['trigger_prefix'] );
		$this->assertSame( [], $result['tags'] );
	}

	/**
	 * Editor data should contain a merge_tags key with label and tags sub-keys.
	 */
	public function test_email_editor_data_includes_merge_tags_key() {
		\Newspack_Newsletters::set_service_provider( 'mailchimp' );
		$data = Newspack_Newsletters_Editor::get_email_editor_data();
		$this->assertArrayHasKey( 'merge_tags', $data );
		$this->assertIsArray( $data['merge_tags'] );
		$this->assertArrayHasKey( 'label', $data['merge_tags'] );
		$this->assertArrayHasKey( 'trigger_prefix', $data['merge_tags'] );
		$this->assertArrayHasKey( 'tags', $data['merge_tags'] );
	}

	/**
	 * When no provider is set, merge_tags should have empty label and tags.
	 */
	public function test_email_editor_data_merge_tags_empty_when_no_provider() {
		\Newspack_Newsletters::set_service_provider( '' );
		$data = Newspack_Newsletters_Editor::get_email_editor_data();
		$this->assertSame( '', $data['merge_tags']['label'] );
		$this->assertSame( '', $data['merge_tags']['trigger_prefix'] );
		$this->assertSame( [], $data['merge_tags']['tags'] );
	}

	/**
	 * Mailchimp get_merge_tags() should return the 'merge tag' label.
	 */
	public function test_mailchimp_merge_tags_has_label() {
		$result = Newspack_Newsletters_Mailchimp::get_merge_tags();
		$this->assertSame( 'merge tag', $result['label'] );
	}

	/**
	 * Mailchimp trigger prefix should be '*|'.
	 */
	public function test_mailchimp_merge_tags_trigger_prefix() {
		$result = Newspack_Newsletters_Mailchimp::get_merge_tags();
		$this->assertSame( '*|', $result['trigger_prefix'] );
	}

	/**
	 * Mailchimp merge tags should include the FNAME tag.
	 */
	public function test_mailchimp_merge_tags_includes_fname() {
		$result = Newspack_Newsletters_Mailchimp::get_merge_tags();
		$tags   = array_column( $result['tags'], 'tag' );
		$this->assertContains( '*|FNAME|*', $tags );
	}

	/**
	 * Mailchimp merge tags should include the ARCHIVE tag.
	 */
	public function test_mailchimp_merge_tags_includes_archive() {
		$result = Newspack_Newsletters_Mailchimp::get_merge_tags();
		$tags   = array_column( $result['tags'], 'tag' );
		$this->assertContains( '*|ARCHIVE|*', $tags );
	}

	/**
	 * Every entry in the Mailchimp merge tags dictionary must have tag and label keys.
	 */
	public function test_mailchimp_merge_tags_entries_have_required_keys() {
		$result = Newspack_Newsletters_Mailchimp::get_merge_tags();
		$this->assertNotEmpty( $result['tags'] );
		foreach ( $result['tags'] as $entry ) {
			$this->assertArrayHasKey( 'tag', $entry );
			$this->assertArrayHasKey( 'label', $entry );
			$this->assertIsString( $entry['label'] );
			$this->assertNotEmpty( $entry['label'] );
			$this->assertIsString( $entry['tag'] );
			$this->assertNotEmpty( $entry['tag'] );
			$this->assertStringStartsWith( '*|', $entry['tag'] );
			$this->assertStringEndsWith( '|*', $entry['tag'] );
		}
	}

	/**
	 * ActiveCampaign get_merge_tags() should return the 'personalization tag' label.
	 */
	public function test_active_campaign_merge_tags_has_label() {
		$result = Newspack_Newsletters_Active_Campaign::get_merge_tags();
		$this->assertSame( 'personalization tag', $result['label'] );
	}

	/**
	 * ActiveCampaign exposes no legacy trigger_prefix; the universal '{}' picker trigger is the canonical entry point.
	 */
	public function test_active_campaign_merge_tags_trigger_prefix() {
		$result = Newspack_Newsletters_Active_Campaign::get_merge_tags();
		$this->assertSame( '', $result['trigger_prefix'] );
	}

	/**
	 * ActiveCampaign merge tags should include all canonical AC personalization tags.
	 */
	public function test_active_campaign_merge_tags_includes_canonical_tags() {
		$result = Newspack_Newsletters_Active_Campaign::get_merge_tags();
		$tags   = array_column( $result['tags'], 'tag' );
		// Sample of canonical AC personalization tags. If any of these go missing the
		// dictionary has drifted from https://help.activecampaign.com/hc/en-us/articles/220709307.
		$canonical = [
			'%EMAIL%',
			'%FIRSTNAME%',
			'%LASTNAME%',
			'%FULLNAME%',
			'%PHONE%',
			'%ORGANIZATION%',
			'%UNSUBSCRIBELINK%',
			'%WEBCOPY%',
			'%UPDATELINK%',
			'%FORWARD2FRIEND%',
			'%ACCT_NAME%',
			'%ACCT_URL%',
			'%LISTNAME%',
			'%SUBSCRIBERID%',
			'%CAMPAIGNID%',
			'%MESSAGEID%',
			'%TODAY%',
		];
		foreach ( $canonical as $tag ) {
			$this->assertContains( $tag, $tags, "Missing canonical AC tag: $tag" );
		}
	}

	/**
	 * ActiveCampaign merge tags should not include invented (non-canonical) tags.
	 */
	public function test_active_campaign_merge_tags_excludes_invented_tags() {
		$result = Newspack_Newsletters_Active_Campaign::get_merge_tags();
		$tags   = array_column( $result['tags'], 'tag' );
		// Tags that LOOK plausible but AC does not actually document. Regression guard
		// against the v1 dictionary drift caught in PR #2133 review.
		$invented = [
			'%ORGNAME%',
			'%PREFERENCESLINK%',
			'%FORWARDLINK%',
			'%LIST_NAME%',
			'%ACCOUNT_NAME%',
			'%ACCOUNT_ADDRESS%',
			'%ACCOUNT_CITY%',
			'%ACCOUNT_STATE%',
			'%ACCOUNT_ZIP%',
			'%ACCOUNT_COUNTRY%',
			'%ACCOUNT_PHONE%',
			'%ACCOUNT_URL%',
			'%CURRENT_YEAR%',
			'%CURRENT_MONTH%',
			'%CURRENT_DAY%',
			'%CAMPAIGN_SUBJECT%',
			'%CAMPAIGN_FROM_NAME%',
			'%CAMPAIGN_FROM_EMAIL%',
			'%CAMPAIGN_LINK_URL%',
		];
		foreach ( $invented as $tag ) {
			$this->assertNotContains( $tag, $tags, "Invented (non-canonical) tag present: $tag" );
		}
	}

	/**
	 * ActiveCampaign merge tags dictionary should contain at least 50 entries.
	 */
	public function test_active_campaign_merge_tags_has_minimum_size() {
		$result = Newspack_Newsletters_Active_Campaign::get_merge_tags();
		// AC documents ~64 standard tags. Allow a buffer below to catch accidental large removals
		// without making the test brittle to small future trims.
		$this->assertGreaterThanOrEqual( 50, count( $result['tags'] ) );
	}

	/**
	 * Every entry in the ActiveCampaign merge tags dictionary must have tag and label keys.
	 */
	public function test_active_campaign_merge_tags_entries_have_required_keys() {
		$result = Newspack_Newsletters_Active_Campaign::get_merge_tags();
		$this->assertNotEmpty( $result['tags'] );
		foreach ( $result['tags'] as $entry ) {
			$this->assertArrayHasKey( 'tag', $entry );
			$this->assertArrayHasKey( 'label', $entry );
			$this->assertIsString( $entry['label'] );
			$this->assertNotEmpty( $entry['label'] );
			$this->assertIsString( $entry['tag'] );
			$this->assertNotEmpty( $entry['tag'] );
			$this->assertStringStartsWith( '%', $entry['tag'] );
			$this->assertStringEndsWith( '%', $entry['tag'] );
		}
	}
}

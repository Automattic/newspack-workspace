<?php
/**
 * Tests for the SEO section's settings payload and its handling of Yoast's
 * `other_social_urls` list.
 *
 * @package Newspack\Tests
 */

use Newspack\Wizards\Newspack\SEO_Section;

if ( ! class_exists( 'Yoast_Configuration_Manager_Mock' ) ) {
	require_once dirname( __DIR__, 2 ) . '/mocks/class-yoast-configuration-manager-mock.php';
}

/**
 * SEO section social profile test case.
 *
 * @group seo-section
 * @covers \Newspack\Wizards\Newspack\SEO_Section
 */
class SEO_Section_Social_Urls_Test extends WP_UnitTestCase {

	/**
	 * The section under test.
	 *
	 * @var SEO_Section
	 */
	private $seo_section;

	/**
	 * Original `blog_public` value, restored in tear_down.
	 *
	 * @var mixed
	 */
	private $original_blog_public;

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();
		$this->seo_section          = new SEO_Section();
		$this->original_blog_public = get_option( 'blog_public', 1 );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		update_option( 'blog_public', $this->original_blog_public );
		parent::tear_down();
	}

	/**
	 * A save rewrites the submitted profiles and leaves every other entry alone.
	 *
	 * @param string[] $stored    URLs already in Yoast's list.
	 * @param array    $submitted Submitted URLs, keyed by profile.
	 * @param string[] $expected  The list the next read should report.
	 * @return void
	 * @dataProvider social_url_merge_data
	 */
	public function test_a_save_rewrites_only_the_submitted_profiles( $stored, $submitted, $expected ) {
		$this->assertSame( $expected, $this->call_section_helper( 'merge_other_social_urls', [ $stored, $submitted ] ) );
	}

	/**
	 * Data provider for test_a_save_rewrites_only_the_submitted_profiles.
	 *
	 * @return array
	 */
	public function social_url_merge_data() {
		return [
			'a second entry on the same host survives'   => [
				[ 'https://bsky.app/profile/example', 'https://bsky.app/profile/example-two' ],
				[ 'bluesky' => 'https://bsky.app/profile/updated' ],
				[ 'https://bsky.app/profile/updated', 'https://bsky.app/profile/example-two' ],
			],
			'an entry on an unrecognized host survives'  => [
				[ 'https://soundcloud.com/example', 'https://tiktok.com/@example' ],
				[ 'tiktok' => 'https://tiktok.com/@updated' ],
				[ 'https://soundcloud.com/example', 'https://tiktok.com/@updated' ],
			],
			'a profile absent from the payload survives' => [
				[ 'https://bsky.app/profile/example', 'https://threads.net/@example' ],
				[ 'bluesky' => 'https://bsky.app/profile/updated' ],
				[ 'https://bsky.app/profile/updated', 'https://threads.net/@example' ],
			],
			'a cleared profile drops only its own entry' => [
				[ 'https://bsky.app/profile/example', 'https://threads.net/@example' ],
				[ 'bluesky' => '' ],
				[ 'https://threads.net/@example' ],
			],
			'a profile with no stored entry is added'    => [
				[ 'https://bsky.app/profile/example' ],
				[ 'tiktok' => 'https://tiktok.com/@example' ],
				[ 'https://bsky.app/profile/example', 'https://tiktok.com/@example' ],
			],
		];
	}

	/**
	 * A stored profile is read back by host, whatever prefix or casing it carries.
	 *
	 * @param string[] $stored   URLs already in Yoast's list.
	 * @param string   $expected The URL the Bluesky field should show.
	 * @return void
	 * @dataProvider social_url_lookup_data
	 */
	public function test_a_stored_profile_is_read_back_by_host( $stored, $expected ) {
		$this->assertSame( $expected, $this->call_section_helper( 'find_social_url', [ $stored, 'bluesky' ] ) );
	}

	/**
	 * Data provider for test_a_stored_profile_is_read_back_by_host.
	 *
	 * @return array
	 */
	public function social_url_lookup_data() {
		return [
			'a www. prefix matches'            => [ [ 'https://www.bsky.app/profile/example' ], 'https://www.bsky.app/profile/example' ],
			'an uppercase host matches'        => [ [ 'https://BSKY.APP/profile/example' ], 'https://BSKY.APP/profile/example' ],
			'no entry on the host reads empty' => [ [ 'https://threads.net/@example' ], '' ],
		];
	}

	/**
	 * Yoast's list reads as a list of strings, whatever Yoast hands back.
	 *
	 * @param mixed    $option   Value Yoast reports for `other_social_urls`.
	 * @param string[] $expected The list the section should work from.
	 * @return void
	 * @dataProvider stored_social_url_list_data
	 */
	public function test_yoasts_list_is_read_as_a_list_of_strings( $option, $expected ) {
		$cm = new Yoast_Configuration_Manager_Mock( [ 'other_social_urls' => $option ] );
		$this->assertSame( $expected, $this->call_section_helper( 'get_other_social_urls', [ $cm ] ) );
	}

	/**
	 * Data provider for test_yoasts_list_is_read_as_a_list_of_strings.
	 *
	 * @return array
	 */
	public function stored_social_url_list_data() {
		return [
			'an uninstalled Yoast reads empty' => [ new WP_Error( 'newspack_missing_required_plugin', 'Yoast plugin is not installed and activated.' ), [] ],
			'a non-list value reads empty'     => [ 'https://bsky.app/profile/example', [] ],
			'non-string entries are dropped'   => [
				[ 'https://bsky.app/profile/example', null, 'https://tiktok.com/@example' ],
				[ 'https://bsky.app/profile/example', 'https://tiktok.com/@example' ],
			],
		];
	}

	/**
	 * Only an intended change that left the stored list untouched counts as reverted.
	 *
	 * @param string[] $before   List as stored before the write.
	 * @param string[] $merged   List handed to Yoast.
	 * @param string[] $after    List as stored after the write.
	 * @param bool     $expected Whether Yoast kept the previously stored list.
	 * @return void
	 * @dataProvider social_url_revert_data
	 */
	public function test_only_an_intended_change_that_left_the_list_untouched_counts_as_reverted( $before, $merged, $after, $expected ) {
		$this->assertSame( $expected, $this->call_section_helper( 'social_urls_reverted', [ $before, $merged, $after ] ) );
	}

	/**
	 * Data provider for test_only_an_intended_change_that_left_the_list_untouched_counts_as_reverted.
	 *
	 * @return array
	 */
	public function social_url_revert_data() {
		return [
			'an intended change Yoast kept out' => [
				[ 'https://threads.net/@example' ],
				[ 'https://threads.net/@example', 'https://bsky.app/profile/example' ],
				[ 'https://threads.net/@example' ],
				true,
			],
			'an entry Yoast normalized'         => [
				[ 'https://threads.net/@example' ],
				[ 'https://threads.net/@example', 'https://bsky.app/profile/example ' ],
				[ 'https://threads.net/@example', 'https://bsky.app/profile/example' ],
				false,
			],
			'a payload that changed nothing'    => [
				[ 'https://threads.net/@example' ],
				[ 'https://threads.net/@example' ],
				[ 'https://threads.net/@example' ],
				false,
			],
		];
	}

	/**
	 * A profile URL on another host, or on another scheme, is refused.
	 *
	 * @param array $urls Submitted URLs, keyed by profile.
	 * @return void
	 * @dataProvider refused_social_url_data
	 */
	public function test_a_profile_url_on_a_foreign_host_or_scheme_is_refused( $urls ) {
		$error = $this->call_section_helper( 'validate_social_hosts', [ $urls ] );

		$this->assertWPError( $error );
		$this->assertSame( 'newspack_seo_invalid_social_url', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
	}

	/**
	 * Data provider for test_a_profile_url_on_a_foreign_host_or_scheme_is_refused.
	 *
	 * @return array
	 */
	public function refused_social_url_data() {
		return [
			'a host we cannot read back' => [ [ 'bluesky' => 'https://example.test/profile/example' ] ],
			'a scheme that is not http'  => [ [ 'bluesky' => 'ftp://bsky.app/profile/example' ] ],
		];
	}

	/**
	 * A cleared field, or a URL on one of the profile's own hosts, is accepted.
	 *
	 * @param array $urls Submitted URLs, keyed by profile.
	 * @return void
	 * @dataProvider accepted_social_url_data
	 */
	public function test_a_cleared_or_recognized_profile_url_is_accepted( $urls ) {
		$this->assertTrue( $this->call_section_helper( 'validate_social_hosts', [ $urls ] ) );
	}

	/**
	 * Data provider for test_a_cleared_or_recognized_profile_url_is_accepted.
	 *
	 * @return array
	 */
	public function accepted_social_url_data() {
		return [
			'a cleared field'              => [ [ 'bluesky' => '' ] ],
			'a www. prefix and mixed case' => [ [ 'bluesky' => 'https://WWW.Bsky.App/profile/example' ] ],
			'a Threads profile on .com'    => [ [ 'threads' => 'https://threads.com/@example' ] ],
			'a Threads profile on .net'    => [ [ 'threads' => 'https://threads.net/@example' ] ],
		];
	}

	/**
	 * Every SEO setting reads as a string when Yoast is not installed.
	 *
	 * @return void
	 */
	public function test_settings_read_as_strings_without_yoast() {
		$settings = $this->seo_section->get_seo_settings();
		$values   = array_merge( $settings['verification'], $settings['urls'] );

		$this->assertNotEmpty( $values );
		foreach ( $values as $key => $value ) {
			$this->assertIsString( $value, sprintf( 'The %s setting should read as a string.', $key ) );
		}
	}

	/**
	 * Search engines count as discouraged whenever `blog_public` drops below one.
	 *
	 * @param int  $blog_public Value of the `blog_public` option.
	 * @param bool $expected    Whether search engines should count as discouraged.
	 * @return void
	 * @dataProvider blog_public_data
	 */
	public function test_search_engines_count_as_discouraged_below_one( $blog_public, $expected ) {
		update_option( 'blog_public', $blog_public );

		$settings = $this->seo_section->get_seo_settings();

		$this->assertSame( $expected, $settings['search_engines_discouraged'] );
	}

	/**
	 * Data provider for test_search_engines_count_as_discouraged_below_one.
	 *
	 * @return array
	 */
	public function blog_public_data() {
		return [
			'indexing blocked outright'           => [ -1, true ],
			'discouraged in the reading settings' => [ 0, true ],
			'indexed'                             => [ 1, false ],
		];
	}

	/**
	 * Call one of the section's private helpers.
	 *
	 * @param string $name Name of the method to call.
	 * @param array  $args Arguments to pass to it.
	 * @return mixed The method's return value.
	 */
	private function call_section_helper( $name, $args ) {
		$section_method = new ReflectionMethod( SEO_Section::class, $name );
		$section_method->setAccessible( true );
		return $section_method->invokeArgs( $this->seo_section, $args );
	}
}

<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class Insertion Test CPT
 *
 * @package Newspack_Popups
 */

/**
 * Insertion test case.
 */
class InsertionTestCPT extends WP_UnitTestCase_PageWithPopups {
	private static $podcast_cpt_name       = 'podcast'; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	private static $press_release_cpt_name = 'press-release'; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	public static function filter_use_only_podcast_cpt_name() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		return [ self::$podcast_cpt_name ];
	}
	public static function filter_use_podcast_cpt_name( $post_types ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$post_types[] = self::$podcast_cpt_name;
		return $post_types;
	}
	public static function filter_use_press_release_cpt_name( $post_types ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$post_types[] = self::$press_release_cpt_name;
		return $post_types;
	}

	/**
	 * Test popup insertion into a CPT, with global supported-CPT setting.
	 */
	public function test_insertion_in_cpt_global() {
		\register_post_type( self::$podcast_cpt_name, [ 'public' => true ] );

		self::renderPost( '', null, [], [], self::$podcast_cpt_name );
		self::assertEquals(
			0,
			self::getRenderedPopupsAmount(),
			'No popups displayed until the CPT is registered as popup-compatible.'
		);

		// Add the podcast CPT to the globally supported post types.
		add_filter( 'newspack_campaigns_post_types_for_campaigns', [ __CLASS__, 'filter_use_podcast_cpt_name' ] );
		self::renderPost( '', null, [], [], self::$podcast_cpt_name );
		self::assertEquals(
			1,
			self::getRenderedPopupsAmount(),
			'Popup is rendered, since the CPT was added to supported post types list.'
		);
		remove_filter( 'newspack_campaigns_post_types_for_campaigns', [ __CLASS__, 'filter_use_podcast_cpt_name' ] );

		// Set the podcast CPT as the *only* globally supported post type.
		add_filter( 'newspack_campaigns_post_types_for_campaigns', [ __CLASS__, 'filter_use_only_podcast_cpt_name' ] );
		self::renderPost( '', null, [], [] );
		self::assertEquals(
			0,
			self::getRenderedPopupsAmount(),
			'No popups displayed - the only popup is set to display on the podcast CPT only.'
		);
		self::renderPost( '', null, [], [], self::$podcast_cpt_name );
		self::assertEquals(
			1,
			self::getRenderedPopupsAmount(),
			'Popup is rendered, since the CPT was added to supported post types list.'
		);
		remove_filter( 'newspack_campaigns_post_types_for_campaigns', [ __CLASS__, 'filter_use_only_podcast_cpt_name' ] );
	}

	/**
	 * Test popup insertion into a CPT, with popup-specific supported-CPT setting.
	 */
	public function test_insertion_in_cpt_popup_specific() {
		$episode_cpt_name = 'episode';
		\register_post_type( $episode_cpt_name, [ 'public' => true ] );

		self::renderPost( '', null, [], [], $episode_cpt_name );
		self::assertEquals(
			0,
			self::getRenderedPopupsAmount(),
			'There are no popups before the CPT is assigned to the popup.'
		);
		self::renderPost( '', null, [], [] );
		self::assertEquals(
			1,
			self::getRenderedPopupsAmount(),
			'Popup is rendered on a regular post.'
		);

		Newspack_Popups_Model::set_popup_options(
			self::$popup_id,
			[
				'post_types' => [ 'post', 'page', $episode_cpt_name ],
			]
		);
		self::renderPost( '', null, [], [], $episode_cpt_name );
		self::assertEquals(
			1,
			self::getRenderedPopupsAmount(),
			'Popup is rendered when the CPT was added to targeted post types.'
		);

		Newspack_Popups_Model::set_popup_options(
			self::$popup_id,
			[
				'post_types' => [ $episode_cpt_name ],
			]
		);
		self::renderPost( '', null, [], [], $episode_cpt_name );
		self::assertEquals(
			1,
			self::getRenderedPopupsAmount(),
			'Popup is rendered when the CPT is the only targeted post type.'
		);

		self::renderPost( '', null, [], [] );
		self::assertEquals(
			0,
			self::getRenderedPopupsAmount(),
			'No popups displayed - the only popup is set to display on the episode CPT only.'
		);
	}

	/**
	 * Test popup insertion into a CPT, with popup-specific supported-CPT setting.
	 */
	public function test_insertion_in_cpt_popup_specific_vs_global() {
		\register_post_type( self::$podcast_cpt_name, [ 'public' => true ] );
		\register_post_type( self::$press_release_cpt_name, [ 'public' => true ] );

		Newspack_Popups_Model::set_popup_options(
			self::$popup_id,
			[
				'post_types' => [ self::$podcast_cpt_name ],
			]
		);

		add_filter( 'newspack_campaigns_post_types_for_campaigns', [ __CLASS__, 'filter_use_press_release_cpt_name' ] );
		self::renderPost( '', null, [], [], self::$press_release_cpt_name );
		self::assertEquals(
			0,
			self::getRenderedPopupsAmount(),
			'No popups displayed - the popup is set to display only on podcast post types.'
		);

		self::renderPost( '', null, [], [], self::$podcast_cpt_name );
		self::assertEquals(
			1,
			self::getRenderedPopupsAmount(),
			'Popup is rendered, since the CPT was added to popup options.'
		);
		remove_filter( 'newspack_campaigns_post_types_for_campaigns', [ __CLASS__, 'filter_use_press_release_cpt_name' ] );
	}

	/**
	 * Test popup insertion into a CPT, with popup-specific supported-CPT setting,
	 * when there's overlap between the popup-specific post types and globally
	 * supported post types.
	 */
	public function test_insertion_in_cpt_popup_specific_vs_global_with_overlap() {
		\register_post_type( self::$podcast_cpt_name, [ 'public' => true ] );

		self::remove_all_popups();
		$post_only_popup    = self::createPopup( null, [ 'post_types' => [ 'post' ] ] );
		$podcast_only_popup = self::createPopup( null, [ 'post_types' => [ self::$podcast_cpt_name ] ] );

		add_filter( 'newspack_campaigns_post_types_for_campaigns', [ __CLASS__, 'filter_use_podcast_cpt_name' ] );

		self::renderPost();
		self::assertEquals(
			1,
			self::getRenderedPopupsAmount(),
			'One popup is rendered on a post.'
		);

		self::renderPost( '', null, [], [], self::$podcast_cpt_name );
		self::assertEquals(
			1,
			self::getRenderedPopupsAmount(),
			'One popup is rendered on podcast CPT.'
		);

		remove_filter( 'newspack_campaigns_post_types_for_campaigns', [ __CLASS__, 'filter_use_podcast_cpt_name' ] );
	}
}

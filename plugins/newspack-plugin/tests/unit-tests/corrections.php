<?php
/**
 * Test_Corrections class.
 *
 * @package Newspack
 */

use Newspack\Corrections;

/**
 * Class Test_Corrections
 *
 * @group corrections
 */
class Test_Corrections extends WP_UnitTestCase {

	/**
	 * Holds a post ID to work with.
	 *
	 * @var int
	 */
	protected static $post_id;

	/**
	 * Holds an editor user ID.
	 *
	 * @var int
	 */
	protected static $editor_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		Corrections::init();

		self::$editor_id = $this->factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( self::$editor_id );

		self::$post_id = $this->factory()->post->create( [ 'post_type' => 'post' ] );
	}

	/**
	 * Test that the corrections feature is disabled.
	 *
	 * @covers \Newspack\Corrections::register_rest_routes
	 */
	public function test_register_rest_routes() {
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes( NEWSPACK_API_NAMESPACE );

		$expected_route = '/' . NEWSPACK_API_NAMESPACE . '/corrections/(?P<id>\\d+)';
		$this->assertArrayHasKey( $expected_route, $routes, 'The corrections REST route should be registered.' );

		$endpoint = $routes[ $expected_route ][0];

		$this->assertArrayHasKey(
			WP_REST_Server::CREATABLE,
			$endpoint['methods'],
			'The corrections REST route should be registered with the POST method (CREATABLE).'
		);
		$this->assertTrue( $endpoint['methods'][ WP_REST_Server::CREATABLE ] );

		$this->assertTrue(
			is_callable( $endpoint['callback'] ),
			'The corrections REST route callback should be callable.'
		);

		$this->assertTrue(
			is_callable( $endpoint['permission_callback'] ),
			'The corrections REST route permission callback should be callable.'
		);
	}

	/**
	 * Test that an invalid post ID returns a WP_Error.
	 *
	 * @covers \Newspack\Corrections::rest_save_corrections
	 */
	public function test_invalid_post_id_returns_error() {
		$request = new WP_REST_Request( WP_REST_Server::CREATABLE, '/' . NEWSPACK_API_NAMESPACE . '/corrections/9999999' );
		$request->set_param( 'corrections', array() );

		$response = Corrections::rest_save_corrections( $request );

		$this->assertInstanceOf( 'WP_Error', $response, 'Expected a WP_Error for an invalid post ID.' );
		$this->assertEquals( 'invalid_post_id', $response->get_error_code() );
	}

	/**
	 * Test that a new correction is created.
	 *
	 * @covers \Newspack\Corrections::rest_save_corrections
	 */
	public function test_new_correction_is_created() {
		$corrections = array(
			array(
				'id'       => null, // New correction.
				'content'  => 'Test correction content',
				'type'     => 'correction',
				'date'     => current_time( 'mysql' ),
				'priority' => 'low',
			),
		);

		$request = new WP_REST_Request( WP_REST_Server::CREATABLE, '/' . NEWSPACK_API_NAMESPACE . '/corrections/' );
		$request->set_param( 'post_id', self::$post_id );
		$request->set_param( 'corrections', $corrections );

		$response = Corrections::rest_save_corrections( $request );
		$this->assertInstanceOf( 'WP_REST_Response', $response, 'Expected a WP_REST_Response for a new correction.' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'success', $data );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'corrections_saved', $data );
		$this->assertCount( 1, $data['corrections_saved'] );

		$response_message = $data['message'];
		$this->assertEquals( 'Corrections saved successfully.', $response_message );
	}

	/**
	 * Test that an existing correction is updated.
	 *
	 * @covers \Newspack\Corrections::rest_save_corrections
	 */
	public function test_existing_correction_is_updated() {
		$initial_data = array(
			'content'  => 'Original correction content',
			'type'     => 'correction',
			'date'     => current_time( 'mysql' ),
			'priority' => 'low',
		);
		$correction_id = Corrections::add_correction( self::$post_id, $initial_data );
		$this->assertNotWPError( $correction_id );
		$this->assertNotEquals( 0, $correction_id );

		$updated_data = array(
			array(
				'id'       => $correction_id,
				'content'  => 'Updated correction content',
				'type'     => 'clarification',
				'date'     => current_time( 'mysql' ),
				'priority' => 'high',
			),
		);

		$request = new WP_REST_Request( WP_REST_Server::CREATABLE, '/' . NEWSPACK_API_NAMESPACE . '/corrections/' );
		$request->set_param( 'post_id', self::$post_id );
		$request->set_param( 'corrections', $updated_data );
		$response = Corrections::rest_save_corrections( $request );
		$data = $response->get_data();

		$this->assertTrue( $data['success'], 'Expected success response on update.' );

		$updated_correction = get_post( $correction_id );
		$this->assertEquals( 'Updated correction content', $updated_correction->post_content, 'The correction content should be updated.' );

		$updated_correction_type = get_post_meta( $correction_id, Corrections::CORRECTIONS_TYPE_META, true );
		$this->assertEquals( 'clarification', $updated_correction_type, 'The correction type should be updated.' );
	}

	/**
	 * Test that multiple corrections are saved.
	 *
	 * @covers \Newspack\Corrections::rest_save_corrections
	 */
	public function test_multiple_corrections_are_saved() {
		$corrections = array(
			array(
				'id'       => null, // New correction.
				'content'  => 'Test correction content 1',
				'type'     => 'correction',
				'date'     => current_time( 'mysql' ),
				'priority' => 'low',
			),
			array(
				'id'       => null, // New correction.
				'content'  => 'Test correction content 2',
				'type'     => 'clarification',
				'date'     => current_time( 'mysql' ),
				'priority' => 'high',
			),
		);

		$request = new WP_REST_Request( WP_REST_Server::CREATABLE, '/' . NEWSPACK_API_NAMESPACE . '/corrections/' );
		$request->set_param( 'post_id', self::$post_id );
		$request->set_param( 'corrections', $corrections );

		$response = Corrections::rest_save_corrections( $request );
		$this->assertInstanceOf( 'WP_REST_Response', $response, 'Expected a WP_REST_Response for multiple corrections.' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'success', $data );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'corrections_saved', $data );
		$this->assertCount( 2, $data['corrections_saved'] );

		$response_message = $data['message'];
		$this->assertEquals( 'Corrections saved successfully.', $response_message );
	}

	/**
	 * Test that a correction is deleted.
	 *
	 * @covers \Newspack\Corrections::rest_save_corrections
	 */
	public function test_correction_is_deleted() {
		$correction_1 = array(
			'id'       => null, // New correction.
			'content'  => 'Test correction content',
			'type'     => 'correction',
			'date'     => current_time( 'mysql' ),
			'priority' => 'low',
		);

		$correction_id_1 = Corrections::add_correction( self::$post_id, $correction_1 );
		$this->assertNotWPError( $correction_id_1 );
		$this->assertNotEquals( 0, $correction_id_1 );

		$correction_2 = array(
			'id'       => null, // New correction.
			'content'  => 'Test correction content 2',
			'type'     => 'clarification',
			'date'     => current_time( 'mysql' ),
			'priority' => 'high',
		);

		$correction_id_2 = Corrections::add_correction( self::$post_id, $correction_2 );
		$this->assertNotWPError( $correction_id_2 );
		$this->assertNotEquals( 0, $correction_id_2 );

		$updated_corrections_array = array(
			array(
				'id'       => $correction_id_1,
				'content'  => 'Updated correction content',
				'type'     => 'clarification',
				'date'     => current_time( 'mysql' ),
				'priority' => 'high',
			),
		);

		$request = new WP_REST_Request( WP_REST_Server::CREATABLE, '/' . NEWSPACK_API_NAMESPACE . '/corrections/' );
		$request->set_param( 'post_id', self::$post_id );
		$request->set_param( 'corrections', $updated_corrections_array );

		$response = Corrections::rest_save_corrections( $request );
		$data = $response->get_data();

		$this->assertTrue( $data['success'], 'Expected success response on update.' );
		$this->assertCount( 1, $data['corrections_saved'] );
		$this->assertContains( $correction_id_1, $data['corrections_saved'] );
		$this->assertNotContains( $correction_id_2, $data['corrections_saved'] );

		$deleted_correction = get_post( $correction_id_2 );
		$this->assertNull( $deleted_correction, 'The deleted correction should not be found.' );

		$updated_correction = get_post( $correction_id_1 );
		$this->assertEquals( 'Updated correction content', $updated_correction->post_content, 'The correction content should be updated.' );

		$updated_correction_type = get_post_meta( $correction_id_1, Corrections::CORRECTIONS_TYPE_META, true );
		$this->assertEquals( 'clarification', $updated_correction_type, 'The correction type should be updated.' );
	}

	/**
	 * Test that a correction created and added to a post and is returned.
	 *
	 * @covers \Newspack\Corrections::add_correction
	 */
	public function test_add_correction() {
		$correction = array(
			'content'  => 'Test correction content',
			'type'     => 'correction',
			'date'     => current_time( 'mysql' ),
			'priority' => 'high',
		);

		$correction_id = Corrections::add_correction( self::$post_id, $correction );
		$this->assertNotWPError( $correction_id );
		$this->assertNotEquals( 0, $correction_id );

		$correction_post = get_post( $correction_id );
		$this->assertInstanceOf( 'WP_Post', $correction_post, 'Expected a WP_Post object for the correction.' );
		$this->assertEquals( Corrections::POST_TYPE, $correction_post->post_type, 'The correction post type should be "correction".' );
		$this->assertEquals( 'Test correction content', $correction_post->post_content, 'The correction content should be set.' );

		$correction_type = get_post_meta( $correction_id, Corrections::CORRECTIONS_TYPE_META, true );
		$this->assertEquals( 'correction', $correction_type, 'The correction type should be set.' );

		$correction_relate_post_id = get_post_meta( $correction_id, Corrections::CORRECTION_POST_ID_META, true );
		$this->assertEquals( self::$post_id, $correction_relate_post_id, 'The correction post ID should be set.' );

		$correction_title = sprintf( 'Correction for %s', get_the_title( self::$post_id ) );
		$this->assertEquals( $correction_title, $correction_post->post_title, 'The correction title should be set.' );

		$correction_priority = get_post_meta( $correction_id, Corrections::CORRECTIONS_PRIORITY_META, true );
		$this->assertEquals( 'high', $correction_priority, 'The correction priority should be set.' );
	}

	/**
	 * Test that a correction created and added to a post and is returned.
	 *
	 * @covers \Newspack\Corrections::get_corrections
	 */
	public function test_get_corrections() {
		$time = time() - 60 * 60;
		$correction_1 = array(
			'content'  => 'Test correction content 1',
			'type'     => 'correction',
			'date'     => gmdate( 'Y-m-d H:i:s', $time ), // 1 hour ago.
			'priority' => 'low',
		);

		$correction_id_1 = Corrections::add_correction( self::$post_id, $correction_1 );
		$this->assertNotWPError( $correction_id_1 );
		$this->assertNotEquals( 0, $correction_id_1 );

		$correction_2 = array(
			'content'  => 'Test correction content 2',
			'type'     => 'clarification',
			'date'     => gmdate( 'Y-m-d H:i:s', $time + 20 * 60 ), // 40 minutes ago.
			'priority' => 'high',
		);

		$correction_id_2 = Corrections::add_correction( self::$post_id, $correction_2 );
		$this->assertNotWPError( $correction_id_2 );
		$this->assertNotEquals( 0, $correction_id_2 );

		$corrections = Corrections::get_corrections( self::$post_id );
		$this->assertCount( 2, $corrections );

		// Reverse the order of corrections as they are returned in descending order.
		$correction_1 = $corrections[1];
		$correction_2 = $corrections[0];

		$this->assertInstanceof( 'WP_Post', $correction_1, 'Expected a WP_Post object for the first correction.' );
		$this->assertInstanceof( 'WP_Post', $correction_2, 'Expected a WP_Post object for the second correction.' );

		$this->assertEquals( Corrections::POST_TYPE, $correction_1->post_type, 'The correction post type should be "correction".' );
		$this->assertEquals( Corrections::POST_TYPE, $correction_2->post_type, 'The correction post type should be "correction".' );

		$this->assertEquals( $correction_id_1, $correction_1->ID );
		$this->assertEquals( $correction_id_2, $correction_2->ID );

		$this->assertEquals( 'Test correction content 1', $correction_1->post_content, 'The correction content is correct.' );
		$this->assertEquals( 'Test correction content 2', $correction_2->post_content, 'The correction content is correct.' );

		$this->assertEquals( 'correction', $correction_1->correction_type, 'The correction type is correct.' );
		$this->assertEquals( 'clarification', $correction_2->correction_type, 'The correction type is correct.' );

		$this->assertEquals( gmdate( 'Y-m-d H:i:s', $time ), $correction_1->correction_date, 'The correction date is correct.' );
		$this->assertEquals( gmdate( 'Y-m-d H:i:s', $time + 20 * 60 ), $correction_2->correction_date, 'The correction date is correct.' );

		$this->assertEquals( 'low', $correction_1->correction_priority, 'The correction priority is correct.' );
		$this->assertEquals( 'high', $correction_2->correction_priority, 'The correction priority is correct.' );
	}

	/**
	 * Test that a correction created and added to a post and is returned.
	 *
	 * @covers \Newspack\Corrections::get_corrections
	 */
	public function test_get_corrections_with_no_corrections() {
		$corrections = Corrections::get_corrections( self::$post_id );
		$this->assertCount( 0, $corrections );
	}

	/**
	 * Test that a created correction is updated.
	 *
	 * @covers \Newspack\Corrections::update_correction
	 */
	public function test_update_correction() {
		$correction = array(
			'content'  => 'Test correction content',
			'type'     => 'correction',
			'date'     => current_time( 'mysql' ),
			'priority' => 'low',
		);

		$correction_id = Corrections::add_correction( self::$post_id, $correction );
		$this->assertNotWPError( $correction_id );
		$this->assertNotEquals( 0, $correction_id );

		$updated_data = array(
			'content'  => 'Updated correction content',
			'type'     => 'clarification',
			'date'     => current_time( 'mysql' ),
			'priority' => 'high',
		);

		Corrections::update_correction( self::$post_id, $correction_id, $updated_data );

		$updated_correction = get_post( $correction_id );
		$this->assertInstanceOf( 'WP_Post', $updated_correction, 'Expected a WP_Post object for the updated correction.' );
		$this->assertEquals( 'Updated correction content', $updated_correction->post_content, 'The correction content should be updated.' );

		$updated_correction_type = get_post_meta( $correction_id, Corrections::CORRECTIONS_TYPE_META, true );
		$this->assertEquals( 'clarification', $updated_correction_type, 'The correction type should be updated.' );

		$updated_correction_priority = get_post_meta( $correction_id, Corrections::CORRECTIONS_PRIORITY_META, true );
		$this->assertEquals( 'high', $updated_correction_priority, 'The correction priority should be updated.' );
	}

	/**
	 * Test that created corrections are deleted.
	 *
	 * @covers \Newspack\Corrections::delete_corrections
	 */
	public function test_delete_corrections() {
		$correction_1 = array(
			'content'  => 'Test correction content',
			'type'     => 'correction',
			'date'     => current_time( 'mysql' ),
			'priority' => 'low',
		);

		$correction_1_id = Corrections::add_correction( self::$post_id, $correction_1 );
		$this->assertNotWPError( $correction_1_id );
		$this->assertNotEquals( 0, $correction_1_id );

		$correction_2 = array(
			'content'  => 'Test correction content 2',
			'type'     => 'clarification',
			'date'     => current_time( 'mysql' ),
			'priority' => 'high',
		);

		$correction_2_id = Corrections::add_correction( self::$post_id, $correction_2 );
		$this->assertNotWPError( $correction_2_id );
		$this->assertNotEquals( 0, $correction_2_id );

		Corrections::delete_corrections( self::$post_id, [ $correction_1_id, $correction_2_id ] );

		$deleted_correction = get_post( $correction_1_id );
		$this->assertNull( $deleted_correction, 'The deleted correction should not be found.' );

		$deleted_correction = get_post( $correction_2_id );
		$this->assertNull( $deleted_correction, 'The deleted correction should not be found.' );
	}

	/**
	 * Test that correction type is returned as "Correction".
	 *
	 * @covers \Newspack\Corrections::get_correction_type
	 */
	public function test_get_correction_type_is_correction() {
		$correction = array(
			'content'  => 'Test correction content',
			'type'     => 'correction',
			'date'     => current_time( 'mysql' ),
			'priority' => 'low',
		);

		$correction_id = Corrections::add_correction( self::$post_id, $correction );
		$this->assertNotWPError( $correction_id );
		$this->assertNotEquals( 0, $correction_id );

		$correction_type = Corrections::get_correction_type( $correction_id );
		$this->assertEquals( 'Correction', $correction_type, 'The correction type should be "Correction".' );
	}

	/**
	 * Test that correction type is returned as "Clarification".
	 *
	 * @covers \Newspack\Corrections::get_correction_type
	 */
	public function test_get_correction_type_is_clarification() {
		$clarification = array(
			'content'  => 'Test clarification content',
			'type'     => 'clarification',
			'date'     => current_time( 'mysql' ),
			'priority' => 'low',
		);

		$clarification_id = Corrections::add_correction( self::$post_id, $clarification );
		$this->assertNotWPError( $clarification_id );
		$this->assertNotEquals( 0, $clarification_id );

		$clarification_type = Corrections::get_correction_type( $clarification_id );
		$this->assertEquals( 'Clarification', $clarification_type, 'The correction type should be "Clarification".' );
	}

	/**
	 * Test that the corrections are output on the post.
	 *
	 * @covers \Newspack\Corrections::output_corrections_on_post
	 */
	public function test_output_corrections_on_post_appends_markup() {
		$correction_1 = array(
			'content'  => 'Test correction content',
			'type'     => 'correction',
			'date'     => current_time( 'mysql' ),
			'priority' => 'low',
		);

		$correction_1_id = Corrections::add_correction( self::$post_id, $correction_1 );
		$this->assertNotWPError( $correction_1_id );
		$this->assertNotEquals( 0, $correction_1_id );

		$correction_2 = array(
			'content'  => 'Test correction content 2',
			'type'     => 'clarification',
			'date'     => current_time( 'mysql' ),
			'priority' => 'high',
		);

		$correction_2_id = Corrections::add_correction( self::$post_id, $correction_2 );
		$this->assertNotWPError( $correction_2_id );
		$this->assertNotEquals( 0, $correction_2_id );

		// Visit the post.
		$this->go_to( get_permalink( self::$post_id ) );
		$this->assertQueryTrue( 'is_single', 'is_singular' );
		$post_content = get_the_content();

		$corrections_markup = Corrections::output_corrections_on_post( $post_content );

		$correction_1_heading = sprintf(
			'%s, %s %s',
			Corrections::get_correction_type( $correction_1_id ),
			get_the_date( get_option( 'date_format' ), $correction_1_id ),
			get_the_time( get_option( 'time_format' ), $correction_1_id )
		);
		$this->assertStringContainsString( $correction_1_heading, $corrections_markup, 'The correction date should be included in the output.' );
		$this->assertStringContainsString( 'Test correction content', $corrections_markup, 'The correction content should be included in the output.' );
		$this->assertStringContainsString( 'corrections-low-module', $corrections_markup, 'The correction priority should be included in the output.' );

		$correction_2_heading = sprintf(
			'%s, %s %s',
			Corrections::get_correction_type( $correction_2_id ),
			get_the_date( get_option( 'date_format' ), $correction_2_id ),
			get_the_time( get_option( 'time_format' ), $correction_2_id )
		);
		$this->assertStringContainsString( $correction_2_heading, $corrections_markup, 'The correction date should be included in the output.' );
		$this->assertStringContainsString( 'Test correction content 2', $corrections_markup, 'The correction content should be included in the output.' );
		$this->assertStringContainsString( 'corrections-high-module', $corrections_markup, 'The correction priority should be included in the output.' );
	}

	/**
	 * Test scripts and styles are enqueued correctly.
	 *
	 * @covers \Newspack\Corrections::wp_enqueue_scripts
	 */
	public function test_enqueue_scripts_and_styles() {
		Corrections::wp_enqueue_scripts();
		$this->assertTrue(
			wp_style_is( 'newspack-corrections-single', 'registered' ),
			'Corrections style should be registered.'
		);
	}

	/**
	 * Test corrections post type registration.
	 *
	 * @covers \Newspack\Corrections::register_post_type
	 */
	public function test_register_post_type() {
		$post_type_exists = post_type_exists( Corrections::POST_TYPE );
		$this->assertTrue( $post_type_exists );

		$post_type_object = get_post_type_object( Corrections::POST_TYPE );
		$this->assertEquals( 'newspack_correction', $post_type_object->name );
		$this->assertTrue( $post_type_object->has_archive );
		$this->assertFalse( $post_type_object->public );
		$this->assertTrue( $post_type_object->show_in_rest );

		$this->assertEquals( 1, get_option( 'newspack_corrections_rewrite_rules_updated' ) );
	}

	/**
	 * Test correction type labels.
	 *
	 * @covers \Newspack\Corrections::get_correction_type_label
	 */
	public function test_correction_type_labels() {
		$correction    = [
			'content'  => 'Test content',
			'type'     => 'correction',
			'date'     => current_time( 'mysql' ),
			'priority' => 'low',
		];
		$clarification = [
			'content'  => 'Test content',
			'type'     => 'clarification', 
			'date'     => current_time( 'mysql' ),
			'priority' => 'low',
		];

		$correction_id    = Corrections::add_correction( self::$post_id, $correction );
		$clarification_id = Corrections::add_correction( self::$post_id, $clarification );

		$this->assertEquals(
			'Correction',
			Corrections::get_correction_type( $correction_id ),
			'Should return "Correction" for correction type'
		);
		
		$this->assertEquals(
			'Clarification',
			Corrections::get_correction_type( $clarification_id ),
			'Should return "Clarification" for clarification type'
		);
	}

	/**
	 * Test that corrections status is updated when post status changes.
	 *
	 * @covers \Newspack\Corrections::update_corrections_status
	 */
	public function test_update_corrections_status() {
		$correction = array(
			'content'  => 'Test correction content',
			'type'     => 'correction',
			'date'     => current_time( 'mysql' ),
			'priority' => 'low',
		);

		$correction_id = Corrections::add_correction( self::$post_id, $correction );
		$this->assertNotWPError( $correction_id );
		$this->assertNotEquals( 0, $correction_id );

		// Change post status to draft.
		wp_update_post(
			array(
				'ID'          => self::$post_id,
				'post_status' => 'draft',
			)
		);

		// Check that correction status was updated.
		$updated_correction = get_post( $correction_id );
		$this->assertEquals( 'draft', $updated_correction->post_status, 'Correction status should match post status.' );

		// Change post status back to publish.
		wp_update_post(
			array(
				'ID'          => self::$post_id,
				'post_status' => 'publish',
			)
		);

		// Check that correction status was updated again.
		$updated_correction = get_post( $correction_id );
		$this->assertEquals( 'publish', $updated_correction->post_status, 'Correction status should match post status.' );
	}

	/**
	 * Test that corrections are set to private when the post is scheduled.
	 *
	 * @covers \Newspack\Corrections::update_corrections_status
	 */
	public function test_corrections_set_to_private_when_post_scheduled() {
		$correction = array(
			'content'  => 'Test correction content',
			'type'     => 'correction',
			'date'     => current_time( 'mysql' ),
			'priority' => 'low',
		);

		$correction_id = Corrections::add_correction( self::$post_id, $correction );
		$this->assertNotWPError( $correction_id );
		$this->assertNotEquals( 0, $correction_id );

		// Schedule the post.
		$future_date = gmdate( 'Y-m-d H:i:s', strtotime( '+1 day' ) );
		wp_update_post(
			array(
				'ID'            => self::$post_id,
				'post_status'   => 'future',
				'post_date'     => $future_date,
				'post_date_gmt' => $future_date,
			)
		);

		// Check that correction status was set to private.
		$updated_correction = get_post( $correction_id );
		$this->assertEquals( 'private', $updated_correction->post_status, 'Correction status should be set to private when post is scheduled.' );
		$this->assertEquals( $correction['date'], $updated_correction->post_date, 'Correction date should be preserved.' );
	}

	/**
	 * Test that corrections can be filtered by supported post types.
	 *
	 * @covers \Newspack\Corrections::get_supported_post_types
	 * @covers \Newspack\Corrections::is_supported_post_type
	 */
	public function test_get_supported_post_types() {
		$supported_types = Corrections::get_supported_post_types();
		$this->assertIsArray( $supported_types );
		$this->assertContains( 'post', $supported_types );

		// Test the filter.
		add_filter(
			'newspack_corrections_supported_post_types',
			function( $types ) {
				$types[] = 'test_post_type';
				return $types;
			}
		);

		$filtered_types = Corrections::get_supported_post_types();
		$this->assertContains( 'test_post_type', $filtered_types );
	}

	/**
	 * Test that an author cannot save corrections for another user's post.
	 *
	 * @covers \Newspack\Corrections::rest_save_corrections
	 */
	public function test_cannot_save_corrections_for_other_users_post() {
		$author_id = $this->factory()->user->create( [ 'role' => 'author' ] );
		wp_set_current_user( $author_id );

		$corrections = [
			[
				'id'       => null,
				'content'  => 'IDOR injected correction',
				'type'     => 'correction',
				'date'     => current_time( 'mysql' ),
				'priority' => 'high',
			],
		];

		$request = new WP_REST_Request( WP_REST_Server::CREATABLE, '/' . NEWSPACK_API_NAMESPACE . '/corrections/' );
		$request->set_param( 'post_id', self::$post_id );
		$request->set_param( 'corrections', $corrections );

		$response = Corrections::rest_save_corrections( $request );

		$this->assertInstanceOf( 'WP_Error', $response, 'Authors should not be able to save corrections for posts they do not own.' );
		$this->assertEquals( 'unauthorized', $response->get_error_code() );
		$this->assertEquals( 403, $response->get_error_data()['status'] );
	}

	/**
	 * Test that a correction ID belonging to a different post cannot be updated via another post.
	 *
	 * @covers \Newspack\Corrections::rest_save_corrections
	 */
	public function test_cannot_update_correction_belonging_to_different_post() {
		wp_set_current_user( self::$editor_id );

		// Create a correction on the default post.
		$correction_id = Corrections::add_correction(
			self::$post_id,
			[
				'content'  => 'Original correction',
				'type'     => 'correction',
				'date'     => current_time( 'mysql' ),
				'priority' => 'low',
			]
		);

		// Create a second post that the editor also owns.
		$other_post_id = $this->factory()->post->create( [ 'post_author' => self::$editor_id ] );

		// Attempt to update the first post's correction via the second post's endpoint.
		$corrections = [
			[
				'id'       => $correction_id,
				'content'  => 'Hijacked correction',
				'type'     => 'correction',
				'date'     => current_time( 'mysql' ),
				'priority' => 'high',
			],
		];

		$request = new WP_REST_Request( WP_REST_Server::CREATABLE, '/' . NEWSPACK_API_NAMESPACE . '/corrections/' );
		$request->set_param( 'post_id', $other_post_id );
		$request->set_param( 'corrections', $corrections );

		$response = Corrections::rest_save_corrections( $request );

		$this->assertInstanceOf( 'WP_Error', $response, 'Should not allow updating a correction that belongs to a different post.' );
		$this->assertEquals( 'invalid_correction', $response->get_error_code() );
		$this->assertEquals( 400, $response->get_error_data()['status'] );

		// Verify the original correction was not modified.
		$original = get_post( $correction_id );
		$this->assertEquals( 'Original correction', $original->post_content );
	}
}

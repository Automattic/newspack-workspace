<?php
/**
 * Helpers for tests that create and save stories through the REST routes the app calls.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget;

/**
 * Sends JSON requests the way the app does, so fixtures go through the app's own write paths.
 */
trait REST_Story_Helpers {
	/**
	 * Budget the test stories belong to.
	 *
	 * @var int
	 */
	protected $budget_id;

	/**
	 * Dispatch a request with a JSON body, as the app sends it.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route path after the namespace.
	 * @param array  $body   Request body.
	 * @return \WP_REST_Response
	 */
	protected function rest( $method, $route, $body = [] ) {
		// These routes declare a `mixed` schema type, which core reports as incorrect usage on every request.
		if ( preg_match( '#^/stories/(update|\d+/[a-z_]+)$#', $route ) ) {
			$this->setExpectedIncorrectUsage( 'rest_validate_value_from_schema' );
			$this->setExpectedIncorrectUsage( 'rest_sanitize_value_from_schema' );
		}

		$request = new \WP_REST_Request( $method, '/' . API::NAMESPACE . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Create a story in the test budget through `POST /stories`, as the create-new-story screen does.
	 *
	 * @param string $title  Story title, also used as its name.
	 * @param array  $fields Other field values to send.
	 * @return int Post ID.
	 */
	protected function create_story_via_app( $title, $fields = [] ) {
		$response = $this->rest(
			'POST',
			'/stories',
			array_merge(
				[
					'title'   => $title,
					'name'    => $title,
					'budgets' => [ $this->budget_id ],
				],
				$fields
			)
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return (int) $response->get_data()['id'];
	}

	/**
	 * Meta key of the Description field.
	 *
	 * @return string
	 */
	protected function description_key() {
		return Fields::get_field( 'description' )->get_post_meta_name();
	}
}

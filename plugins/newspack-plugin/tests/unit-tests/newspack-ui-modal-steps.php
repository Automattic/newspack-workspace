<?php
/**
 * Tests for Newspack UI modals with steps.
 *
 * @package Newspack\Tests
 */

use Newspack\Newspack_UI;

/**
 * Test how a modal's arguments reach its steps.
 */
class Newspack_Test_UI_Modal_Steps extends WP_UnitTestCase {

	/**
	 * Render a modal with steps.
	 *
	 * @param array $args Modal arguments.
	 * @return string Modal markup.
	 */
	private function render_modal( $args ) {
		ob_start();
		Newspack_UI::generate_modal( $args );
		return ob_get_clean();
	}

	/**
	 * A step without content of its own stays empty, and does not print the modal's
	 * content unescaped because the modal's content was marked safe.
	 */
	public function test_step_does_not_inherit_modal_content() {
		$modal = $this->render_modal(
			[
				'id'              => 'steps',
				'content'         => '<script>modal()</script>',
				'content_is_safe' => true,
				'steps'           => [
					'first'  => [ 'content' => '<p>First step</p>' ],
					'second' => [ 'title' => 'Second step' ],
				],
			]
		);

		$this->assertStringContainsString( 'First step', $modal );
		$this->assertStringNotContainsString( 'modal()', $modal );
	}

	/**
	 * Each step with a form gets its own form ID, so the IDs on the page stay unique.
	 */
	public function test_each_step_form_gets_its_own_id() {
		$modal = $this->render_modal(
			[
				'id'      => 'steps',
				'form_id' => 'steps-form',
				'steps'   => [
					'first'  => [ 'form' => 'post' ],
					'second' => [ 'form' => 'post' ],
				],
			]
		);

		$this->assertStringContainsString( 'id="steps-form-first"', $modal );
		$this->assertStringContainsString( 'id="steps-form-second"', $modal );
		$this->assertStringNotContainsString( 'id="steps-form"', $modal );
	}

	/**
	 * A medium or larger modal or step lays its actions out in a row unless it asks for a
	 * column, and a small one always stacks them. Steps inherit the layout and size.
	 */
	public function test_actions_layout_follows_size() {
		$actions = [
			'cancel'  => [
				'label' => 'Cancel',
				'type'  => 'outline',
			],
			'confirm' => [
				'label' => 'Delete',
				'type'  => [ 'destructive', 'outline' ],
			],
		];
		$stacked = 'newspack-ui__button newspack-ui__button--wide newspack-ui__button--destructive newspack-ui__button--outline"';
		$in_row  = 'newspack-ui__button newspack-ui__button--wide-on-mobile newspack-ui__button--destructive newspack-ui__button--outline"';

		$medium = $this->render_modal(
			[
				'size'    => 'medium',
				'content' => '',
				'actions' => $actions,
			]
		);
		$this->assertStringContainsString( 'newspack-ui__stack--horizontal newspack-ui__stack--justify-end', $medium );
		$this->assertStringContainsString( $in_row, $medium );

		$large = $this->render_modal(
			[
				'size'    => 'large',
				'content' => '',
				'actions' => $actions,
			]
		);
		$this->assertStringContainsString( $in_row, $large );

		$medium_column = $this->render_modal(
			[
				'size'           => 'medium',
				'content'        => '',
				'actions_layout' => 'column',
				'actions'        => $actions,
			]
		);
		$this->assertStringContainsString( $stacked, $medium_column );
		$this->assertStringNotContainsString( 'newspack-ui__stack--justify-end', $medium_column );

		foreach ( [ 'small', 'x-small' ] as $size ) {
			$small = $this->render_modal(
				[
					'size'           => $size,
					'content'        => '',
					'actions_layout' => 'row',
					'actions'        => $actions,
				]
			);
			$this->assertStringContainsString( $stacked, $small );
			$this->assertStringNotContainsString( 'newspack-ui__stack--justify-end', $small );
		}

		$default_size = $this->render_modal(
			[
				'content' => '',
				'actions' => $actions,
			]
		);
		$this->assertStringContainsString( $stacked, $default_size );

		$steps = $this->render_modal(
			[
				'steps' => [
					'first'  => [
						'size'    => 'medium',
						'actions' => $actions,
					],
					'second' => [ 'actions' => $actions ],
				],
			]
		);
		$second = substr( $steps, strpos( $steps, 'data-step="second"' ) );
		$first  = substr( $steps, 0, strpos( $steps, 'data-step="second"' ) );
		$this->assertStringContainsString( $in_row, $first );
		$this->assertStringContainsString( 'newspack-ui__stack--justify-end', $first );
		$this->assertStringContainsString( $stacked, $second );
		$this->assertStringNotContainsString( 'newspack-ui__stack--justify-end', $second );

		$medium_steps = $this->render_modal(
			[
				'size'  => 'medium',
				'steps' => [ 'only' => [ 'actions' => $actions ] ],
			]
		);
		$this->assertStringContainsString( $in_row, $medium_steps );

		$column_steps = $this->render_modal(
			[
				'size'           => 'medium',
				'actions_layout' => 'column',
				'steps'          => [ 'only' => [ 'actions' => $actions ] ],
			]
		);
		$this->assertStringContainsString( $stacked, $column_steps );
	}
}

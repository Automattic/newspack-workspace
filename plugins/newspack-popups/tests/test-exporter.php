<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class Exporter Test
 *
 * @package Newspack_Popups
 */

/**
 * Exporter test case.
 */
class ExporterTest extends WP_UnitTestCase_PageWithPopups {

	/**
	 * Call protected/private method of a class.
	 *
	 * @param object $object    Instantiated object that we will run method on.
	 * @param string $method_name Method name to call.
	 * @param array  $parameters Array of parameters to pass into method.
	 *
	 * @return mixed Method return.
	 */
	public function invoke_method( &$object, $method_name, array $parameters = array() ) {
		$reflection = new \ReflectionClass( get_class( $object ) );
		$method     = $reflection->getMethod( $method_name );
		$method->setAccessible( true );

		return $method->invokeArgs( $object, $parameters );
	}

	/**
	 * Data provider for test_prepare_prompt_for_export
	 *
	 * @return array
	 */
	public function prepare_prompt_for_export_data() {
		return [
			'id should be unset'             => [
				[
					'id'      => 123,
					'title'   => 'Test Campaign',
					'content' => 'Test content',
					'status'  => 'publish',
					'options' => [
						'background_color' => '#FFFFFF',
						'hide_border'      => false,
						'large_border'     => false,
						'no_padding'       => false,
						'frequency'        => 'once',
						'placement'        => 'inline',
					],
				],
				[
					'title'   => 'Test Campaign',
					'content' => 'Test content',
					'status'  => 'publish',
					'options' => [
						'background_color' => '#FFFFFF',
						'hide_border'      => false,
						'large_border'     => false,
						'no_padding'       => false,
						'frequency'        => 'once',
						'placement'        => 'inline',
					],
				],

			],
			'fix overlay size'               => [
				[
					'id'      => 123,
					'title'   => 'Test Campaign',
					'content' => 'Test content',
					'status'  => 'publish',
					'options' => [
						'overlay_size' => 'full',
						'hide_border'  => false,
						'large_border' => false,
						'no_padding'   => false,
						'frequency'    => 'once',
						'placement'    => 'inline',
					],
				],
				[
					'title'   => 'Test Campaign',
					'content' => 'Test content',
					'status'  => 'publish',
					'options' => [
						'overlay_size' => 'full-width',
						'hide_border'  => false,
						'large_border' => false,
						'no_padding'   => false,
						'frequency'    => 'once',
						'placement'    => 'inline',
					],
				],

			],
			'unset utm_suppression if empty' => [
				[
					'id'      => 123,
					'title'   => 'Test Campaign',
					'content' => 'Test content',
					'status'  => 'publish',
					'options' => [
						'utm_suppression' => false,
						'hide_border'     => false,
						'large_border'    => false,
						'no_padding'      => false,
						'frequency'       => 'once',
						'placement'       => 'inline',
					],
				],
				[
					'title'   => 'Test Campaign',
					'content' => 'Test content',
					'status'  => 'publish',
					'options' => [
						'hide_border'  => false,
						'large_border' => false,
						'no_padding'   => false,
						'frequency'    => 'once',
						'placement'    => 'inline',
					],
				],

			],
		];
	}

	/**
	 * Test prepare_prompt_for_export.
	 *
	 * @param array $input The input for the prepare_prompt_for_export method.
	 * @param array $expected The expected output.
	 * @return void
	 * @dataProvider prepare_prompt_for_export_data
	 */
	public function test_prepare_prompt_for_export( $input, $expected ) {
		$exporter = new Newspack_Popups_Exporter();
		$result   = $this->invoke_method( $exporter, 'prepare_prompt_for_export', [ $input ] );
		$this->assertSame( $expected, $result );
	}

	/**
	 * Test sanitize terms
	 *
	 * @return void
	 */
	public function test_sanitize_terms() {
		$exporter        = new Newspack_Popups_Exporter();
		$existing_term   = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Test cat',
			]
		);
		$existing_term_2 = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Test cat 2',
			]
		);
		$terms           = [
			$existing_term, // Id of an existing term.
			get_term( $existing_term_2, 'category' ), // a WP_Term object.
			9999, // Id of a non-existing term.
		];
		$result          = $this->invoke_method( $exporter, 'sanitize_terms', [ $terms, 'category' ] );
		$expected        = [
			[
				'id'   => $existing_term,
				'name' => 'Test cat',
			],
			[
				'id'   => $existing_term_2,
				'name' => 'Test cat 2',
			],
		];
		$this->assertSame( $expected, $result );
	}
}

<?php
/**
 * Button hover guard tests.
 *
 * @package Newspack_Block_Theme
 */

use Newspack_Block_Theme\Button_Hover_Guard;
use Newspack_Block_Theme\Contrast;
use Newspack_Block_Theme\Status_Colors;

/**
 * Tests the readability guard on the button hover and pressed fills.
 */
class Test_Button_Hover_Guard extends WP_UnitTestCase {
	/**
	 * The shares the theme mixes with.
	 *
	 * @var float[]
	 */
	const SHARES = [
		'hover'   => 0.85,
		'pressed' => 0.75,
	];

	/**
	 * The palette of a theme.json or style variation file, keyed by slug and lowercased.
	 *
	 * @param string $file Path relative to the theme root.
	 * @return array
	 */
	private function palette( string $file ): array {
		$palette = [];
		foreach ( array_unique( [ 'theme.json', $file ] ) as $name ) {
			$data = wp_json_file_decode( dirname( __DIR__ ) . '/' . $name, [ 'associative' => true ] );
			foreach ( $data['settings']['color']['palette'] ?? [] as $entry ) {
				$palette[ $entry['slug'] ] = strtolower( $entry['color'] );
			}
		}
		return $palette;
	}

	/**
	 * Run the guard on a file's default button: the accent fill with the derived accent-contrast text.
	 *
	 * @param string $file Path relative to the theme root.
	 * @return array
	 */
	private function evaluate_file( string $file ): array {
		$palette = $this->palette( $file );
		$text    = Contrast::get_text_color( $palette['accent'] );

		return Button_Hover_Guard::evaluate( $palette['accent'], $text, $palette['base'], self::SHARES );
	}

	/**
	 * Drop the cached user global styles a test saved.
	 */
	public function tear_down() {
		wp_dequeue_style( 'newspack-block-theme-button-hover-guard' );
		wp_deregister_style( 'newspack-block-theme-button-hover-guard' );
		wp_clean_theme_json_cache();
		Contrast::reset_button_styles();
		parent::tear_down();
	}

	/**
	 * The APCA Lc of the button text at rest, on hover and pressed, and the mix target, per file.
	 *
	 * @return array
	 */
	public function guard_vectors(): array {
		return [
			'theme.json'                  => [ 'theme.json', 95.4, 86.3, 78.9, null ],
			'styles/01-theme-alt.json'    => [ 'styles/01-theme-alt.json', 95.4, 86.3, 78.9, null ],
			'styles/02-arc.json'          => [ 'styles/02-arc.json', 82.2, 72.9, 65.7, null ],
			'styles/03-arc-alt.json'      => [ 'styles/03-arc-alt.json', 82.2, 72.9, 65.7, null ],
			'styles/04-bulletin.json'     => [ 'styles/04-bulletin.json', 76.8, 68.3, 61.7, null ],
			'styles/05-bulletin-alt.json' => [ 'styles/05-bulletin-alt.json', 76.8, 68.3, 61.7, null ],
			'styles/06-foundry.json'      => [ 'styles/06-foundry.json', 92.2, 82.9, 75.6, null ],
			'styles/07-foundry-alt.json'  => [ 'styles/07-foundry-alt.json', 92.2, 82.9, 75.6, null ],
			'styles/08-ledger.json'       => [ 'styles/08-ledger.json', 79.5, 71.6, 65.8, null ],
			'styles/09-ledger-alt.json'   => [ 'styles/09-ledger-alt.json', 79.5, 71.6, 65.8, null ],
			'styles/10-nocturne.json'     => [ 'styles/10-nocturne.json', 79.1, 62.8, 52.4, null ],
			'styles/11-nocturne-alt.json' => [ 'styles/11-nocturne-alt.json', 79.1, 62.8, 52.4, null ],
		];
	}

	/**
	 * Every file's default button reads at rest, and the guard trips exactly where hover or pressed drop below it.
	 *
	 * @dataProvider guard_vectors
	 *
	 * @param string      $file    Path relative to the theme root.
	 * @param float       $rest    Expected Lc at rest.
	 * @param float       $hover   Expected Lc on hover.
	 * @param float       $pressed Expected Lc when pressed.
	 * @param string|null $target  Expected mix target.
	 */
	public function test_guard_vectors( $file, $rest, $hover, $pressed, $target ) {
		$palette = $this->palette( $file );
		$text    = Contrast::get_text_color( $palette['accent'] );
		$result  = $this->evaluate_file( $file );

		$this->assertEqualsWithDelta( $rest, Status_Colors::lc( $text, $palette['accent'] ), 0.05, $file );
		$this->assertEqualsWithDelta( $hover, $result['hover'], 0.05, $file );
		$this->assertEqualsWithDelta( $pressed, $result['pressed'], 0.05, $file );
		$this->assertSame( $target, $result['target'], $file );
		$this->assertSame( $pressed < Button_Hover_Guard::MIN_LC || $hover < Button_Hover_Guard::MIN_LC, null !== $target, $file );
	}

	/**
	 * Nocturne's amber accent stays above Lc 45 in every state, so it mixes toward the base.
	 */
	public function test_nocturne_does_not_trip() {
		$result = Button_Hover_Guard::evaluate( '#ffc857', '#000000', '#1e1e1e', self::SHARES );

		$this->assertNull( $result['target'] );
		$this->assertGreaterThan( Button_Hover_Guard::MIN_LC, $result['pressed'] );
	}

	/**
	 * Light text mixes toward black and dark text toward white.
	 */
	public function test_target_follows_the_text_side() {
		$light = Button_Hover_Guard::evaluate( '#9fb6dd', '#ffffff', '#ffffff', self::SHARES );
		$dark  = Button_Hover_Guard::evaluate( '#c08a1a', '#000000', '#1e1e1e', self::SHARES );

		$this->assertSame( '#000000', $light['target'] );
		$this->assertSame( '#ffffff', $dark['target'] );
	}

	/**
	 * A skipped state does not trip the guard.
	 */
	public function test_skipped_states_are_ignored() {
		$this->assertNull( Button_Hover_Guard::evaluate( '#c08a1a', '#000000', '#1e1e1e', self::SHARES, [ 'hover', 'pressed' ] )['target'] );
		$this->assertSame( '#ffffff', Button_Hover_Guard::evaluate( '#c08a1a', '#000000', '#1e1e1e', self::SHARES, [ 'hover' ] )['target'] );
	}

	/**
	 * Save user global styles for the current theme.
	 *
	 * @param array $styles The styles to save.
	 */
	private function set_user_styles( array $styles ) {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$content = wp_json_encode(
			[
				'version'                     => WP_Theme_JSON::LATEST_SCHEMA,
				'isGlobalStylesUserThemeJSON' => true,
				'styles'                      => $styles,
			]
		);
		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => wp_slash( $content ),
			]
		);
		WP_Theme_JSON_Resolver::clean_cached_data();
		Contrast::reset_button_styles();
	}

	/**
	 * The default theme's accent does not trip the guard.
	 */
	public function test_default_styles_do_not_trip() {
		$this->assertNull( Button_Hover_Guard::get_target() );
	}

	/**
	 * A fill that is a palette reference is resolved to the palette color.
	 */
	public function test_preset_reference_fill_is_resolved() {
		$this->set_user_styles(
			[
				'elements' => [
					'button' => [
						'color' => [
							'background' => 'var:preset|color|accent-3',
							'text'       => '#ffffff',
						],
					],
				],
			]
		);

		$this->assertSame( '#000000', Button_Hover_Guard::get_target() );
	}

	/**
	 * A gradient fill skips the guard.
	 */
	public function test_gradient_fill_is_skipped() {
		$this->set_user_styles(
			[
				'elements' => [
					'button' => [
						'color' => [
							'background' => 'var:preset|color|accent-3',
							'gradient'   => 'linear-gradient(135deg,#9fb6dd 0%,#ffffff 100%)',
							'text'       => '#ffffff',
						],
					],
				],
			]
		);

		$this->assertNull( Button_Hover_Guard::get_target() );
	}

	/**
	 * A hover and pressed fill the publisher set win over the guard.
	 */
	public function test_publisher_states_win() {
		$button = [
			'color' => [
				'background' => 'var:preset|color|accent-3',
				'text'       => '#ffffff',
			],
		];
		$state  = [ 'color' => [ 'background' => '#123456' ] ];

		$this->set_user_styles( [ 'elements' => [ 'button' => $button + [ ':hover' => $state ] ] ] );
		$this->assertSame( '#000000', Button_Hover_Guard::get_target() );

		$this->set_user_styles(
			[
				'elements' => [
					'button' => $button + [
						':hover'  => $state,
						':active' => $state,
					],
				],
			]
		);
		$this->assertNull( Button_Hover_Guard::get_target() );
	}

	/**
	 * The mix target outranks core's global styles declared on the root.
	 */
	public function test_selector_outranks_global_styles() {
		$this->set_user_styles(
			[
				'elements' => [
					'button' => [
						'color' => [
							'background' => 'var:preset|color|accent-3',
							'text'       => '#ffffff',
						],
					],
				],
			]
		);
		Button_Hover_Guard::enqueue_block_assets();

		$css = implode( '', (array) wp_styles()->get_data( 'newspack-block-theme-button-hover-guard', 'after' ) );
		$this->assertSame( ':root:root, :root .editor-styles-wrapper { --wp--custom--button--mix: #000000; }', $css );
	}

	/**
	 * Nothing is emitted unless the guard trips.
	 */
	public function test_nothing_is_enqueued_when_readable() {
		Button_Hover_Guard::enqueue_block_assets();

		$this->assertFalse( wp_style_is( 'newspack-block-theme-button-hover-guard', 'enqueued' ) );
	}
}

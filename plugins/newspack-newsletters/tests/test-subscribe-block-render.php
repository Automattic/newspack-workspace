<?php
/**
 * Tests for the subscribe block's rendered markup.
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Subscription_List;
use function Newspack_Newsletters\Blocks\Subscribe\get_block_lists;
use function Newspack_Newsletters\Blocks\Subscribe\render_form;

/**
 * Tests which lists the subscribe block renders, and how.
 *
 * Calls render_form() rather than render_block(): render_block() also enqueues
 * built assets, which don't exist when CI runs PHPUnit.
 *
 * @group subscribe-block
 */
class Subscribe_Block_Render_Test extends WP_UnitTestCase {

	use Lists_Setup;

	/**
	 * Post IDs of lists hidden from the current reader for this test.
	 *
	 * @var int[]
	 */
	private $hidden_list_ids = [];

	/**
	 * Use the Mailchimp lists from Lists_Setup, and let each test hide some.
	 */
	public function set_up() {
		parent::set_up();
		Newspack_Newsletters::set_service_provider( 'mailchimp' );
		add_filter( 'newspack_newsletters_subscription_lists', [ $this, 'hide_lists' ] );
	}

	/**
	 * Remove the filter and forget the hidden lists.
	 */
	public function tear_down() {
		remove_filter( 'newspack_newsletters_subscription_lists', [ $this, 'hide_lists' ] );
		$this->hidden_list_ids = [];
		parent::tear_down();
	}

	/**
	 * Stand in for Access Control: drop the hidden lists, as its filter does for
	 * a reader without access.
	 *
	 * @param Subscription_List[] $lists Lists for the current provider.
	 *
	 * @return Subscription_List[]
	 */
	public function hide_lists( $lists ) {
		$hidden = $this->hidden_list_ids;
		return array_values(
			array_filter(
				$lists,
				function ( $list ) use ( $hidden ) {
					return ! in_array( $list->get_id(), $hidden, true );
				}
			)
		);
	}

	/**
	 * Get a fixture list by its Lists_Setup key.
	 *
	 * @param string $key Key in Lists_Setup::$posts.
	 *
	 * @return Subscription_List
	 */
	private function get_list( $key ) {
		return new Subscription_List( self::$posts[ $key ] );
	}

	/**
	 * Build block attributes with the block's own defaults applied.
	 *
	 * @param array $overrides Attributes to set.
	 *
	 * @return array
	 */
	private function get_attrs( $overrides = [] ) {
		return WP_Block_Type_Registry::get_instance()
			->get_registered( 'newspack-newsletters/subscribe' )
			->prepare_attributes_for_render( $overrides );
	}

	/**
	 * Render the block's markup for the given attributes.
	 *
	 * @param array $attrs Block attributes.
	 *
	 * @return string|null Null when the block renders nothing.
	 */
	private function render( $attrs ) {
		$lists = get_block_lists( $attrs );
		return null === $lists ? null : render_form( $attrs, $lists );
	}

	/**
	 * Replace the IDs generated per render so two renders can be compared.
	 *
	 * @param string $html Rendered markup.
	 *
	 * @return string
	 */
	private function normalize( $html ) {
		$html = preg_replace( '/newspack-newsletters-\d+-list-checkbox/', 'newspack-newsletters-N-list-checkbox', $html );
		$html = preg_replace( '/block-input-\d+/', 'block-input-N', $html );
		return preg_replace( '/newspack-subscribe-\d+/', 'newspack-subscribe-N', $html );
	}

	/**
	 * One list renders as a hidden input, with no list of checkboxes.
	 */
	public function test_single_list_renders_a_hidden_input() {
		$list = $this->get_list( 'only_mailchimp' );
		$html = $this->render( $this->get_attrs( [ 'lists' => [ $list->get_public_id() ] ] ) );

		$this->assertStringContainsString( '<input type="hidden" name="lists[]" value="' . $list->get_public_id() . '"', $html );
		$this->assertStringNotContainsString( 'newspack-newsletters-lists', $html );
	}

	/**
	 * Several lists render one checkbox each.
	 */
	public function test_multiple_lists_render_checkboxes() {
		$first  = $this->get_list( 'only_mailchimp' );
		$second = $this->get_list( 'two_settings' );
		$html   = $this->render( $this->get_attrs( [ 'lists' => [ $first->get_public_id(), $second->get_public_id() ] ] ) );

		$this->assertSame( 2, substr_count( $html, 'type="checkbox"' ) );
		$this->assertStringContainsString( 'value="' . $first->get_public_id() . '"', $html );
		$this->assertStringContainsString( 'value="' . $second->get_public_id() . '"', $html );
	}

	/**
	 * A list hidden from the reader is left out, and the block falls back to the
	 * single-list form.
	 */
	public function test_hidden_list_is_skipped_by_default() {
		$free                  = $this->get_list( 'only_mailchimp' );
		$hidden                = $this->get_list( 'two_settings' );
		$this->hidden_list_ids = [ $hidden->get_id() ];
		$html                  = $this->render( $this->get_attrs( [ 'lists' => [ $free->get_public_id(), $hidden->get_public_id() ] ] ) );

		$this->assertStringContainsString( '<input type="hidden" name="lists[]" value="' . $free->get_public_id() . '"', $html );
		$this->assertStringNotContainsString( 'value="' . $hidden->get_public_id() . '"', $html );
		$this->assertStringNotContainsString( 'type="checkbox"', $html );
	}

	/**
	 * With no list the reader can join, the block renders nothing.
	 */
	public function test_nothing_renders_without_an_available_list() {
		$this->assertNull( $this->render( $this->get_attrs( [ 'lists' => [ 'does-not-exist' ] ] ) ) );
	}

	/**
	 * Checkboxes follow the order of the lists attribute.
	 */
	public function test_multiple_lists_keep_the_attribute_order() {
		$first  = $this->get_list( 'two_settings' );
		$second = $this->get_list( 'only_mailchimp' );
		$html   = $this->render( $this->get_attrs( [ 'lists' => [ $first->get_public_id(), $second->get_public_id() ] ] ) );

		$this->assertLessThan(
			strpos( $html, 'value="' . $second->get_public_id() . '"' ),
			strpos( $html, 'value="' . $first->get_public_id() . '"' )
		);
	}

	/**
	 * With showPremium on, a list hidden from the reader renders as a row with a
	 * badge and nothing to submit.
	 */
	public function test_show_premium_renders_a_gated_row_without_an_input() {
		$free                  = $this->get_list( 'only_mailchimp' );
		$gated                 = $this->get_list( 'two_settings' );
		$this->hidden_list_ids = [ $gated->get_id() ];
		$html                  = $this->render(
			$this->get_attrs(
				[
					'lists'       => [ $free->get_public_id(), $gated->get_public_id() ],
					'showPremium' => true,
				]
			)
		);

		$this->assertSame( 1, substr_count( $html, 'class="is-gated"' ) );
		$this->assertSame( 1, substr_count( $html, 'type="checkbox"' ), 'Only the free list gets a checkbox.' );
		$this->assertStringContainsString( esc_html( $gated->get_title() ), $html );
		$this->assertStringContainsString( '<span class="newspack-ui__badge newspack-ui__badge--secondary">Premium</span>', $html );
		$this->assertStringNotContainsString( 'value="' . $gated->get_public_id() . '"', $html );
		$this->assertStringNotContainsString( 'checkbox-' . $gated->get_public_id() . '"', $html );
		$this->assertStringNotContainsString( 'type="hidden" name="lists[]"', $html, 'One free list plus a gated one shows the list, not a hidden input.' );
	}

	/**
	 * Rows follow the order of the lists attribute, gated or not.
	 */
	public function test_show_premium_keeps_the_attribute_order() {
		$free                  = $this->get_list( 'only_mailchimp' );
		$gated                 = $this->get_list( 'two_settings' );
		$this->hidden_list_ids = [ $gated->get_id() ];
		$html                  = $this->render(
			$this->get_attrs(
				[
					'lists'       => [ $gated->get_public_id(), $free->get_public_id() ],
					'showPremium' => true,
				]
			)
		);

		$this->assertLessThan( strpos( $html, 'type="checkbox"' ), strpos( $html, 'class="is-gated"' ) );
	}

	/**
	 * When the reader can join none of the block's lists, the gated rows render
	 * with no form at all.
	 */
	public function test_all_gated_renders_no_form() {
		$first                 = $this->get_list( 'only_mailchimp' );
		$second                = $this->get_list( 'two_settings' );
		$this->hidden_list_ids = [ $first->get_id(), $second->get_id() ];
		$html                  = $this->render(
			$this->get_attrs(
				[
					'lists'            => [ $first->get_public_id(), $second->get_public_id() ],
					'showPremium'      => true,
					'displayNameField' => true,
				]
			)
		);

		$this->assertSame( 2, substr_count( $html, 'class="is-gated"' ) );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( '<input', $html );
		$this->assertStringNotContainsString( '<button', $html );
	}

	/**
	 * A single gated list still shows, where showPremium off would render nothing.
	 */
	public function test_single_gated_list_renders_with_show_premium() {
		$gated                 = $this->get_list( 'only_mailchimp' );
		$this->hidden_list_ids = [ $gated->get_id() ];
		$attrs                 = [ 'lists' => [ $gated->get_public_id() ] ];

		$this->assertNull( $this->render( $this->get_attrs( $attrs ) ) );
		$this->assertStringContainsString( 'class="is-gated"', $this->render( $this->get_attrs( $attrs + [ 'showPremium' => true ] ) ) );
	}

	/**
	 * The label is publisher-entered text and is escaped.
	 */
	public function test_premium_label_is_escaped() {
		$gated                 = $this->get_list( 'only_mailchimp' );
		$this->hidden_list_ids = [ $gated->get_id() ];
		$html                  = $this->render(
			$this->get_attrs(
				[
					'lists'            => [ $gated->get_public_id() ],
					'showPremium'      => true,
					'showPremiumLabel' => '<script>alert(1)</script>',
				]
			)
		);

		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	/**
	 * A label that is empty or only whitespace renders no badge.
	 */
	public function test_blank_label_renders_no_badge() {
		$gated                 = $this->get_list( 'only_mailchimp' );
		$this->hidden_list_ids = [ $gated->get_id() ];
		foreach ( [ '', '   ' ] as $label ) {
			$html = $this->render(
				$this->get_attrs(
					[
						'lists'            => [ $gated->get_public_id() ],
						'showPremium'      => true,
						'showPremiumLabel' => $label,
					]
				)
			);
			$this->assertStringContainsString( 'class="is-gated"', $html );
			$this->assertStringNotContainsString( 'newspack-ui__badge', $html );
		}
	}

	/**
	 * A gated row honors the "Display list description" setting.
	 */
	public function test_gated_row_honors_the_description_setting() {
		$gated                 = $this->get_list( 'only_mailchimp' );
		$this->hidden_list_ids = [ $gated->get_id() ];
		$attrs                 = [
			'lists'       => [ $gated->get_public_id() ],
			'showPremium' => true,
		];

		$this->assertStringContainsString( 'class="list-description"', $this->render( $this->get_attrs( $attrs ) ) );
		$this->assertStringNotContainsString( 'class="list-description"', $this->render( $this->get_attrs( $attrs + [ 'displayDescription' => false ] ) ) );
	}

	/**
	 * With no list hidden from the reader, turning showPremium on changes
	 * nothing: existing blocks and readers with full access see today's markup.
	 */
	public function test_show_premium_without_gated_lists_matches_default() {
		$lists = [ $this->get_list( 'only_mailchimp' )->get_public_id(), $this->get_list( 'two_settings' )->get_public_id() ];

		foreach ( [ $lists, [ $lists[0] ] ] as $selection ) {
			$off = $this->render( $this->get_attrs( [ 'lists' => $selection ] ) );
			$on  = $this->render(
				$this->get_attrs(
					[
						'lists'       => $selection,
						'showPremium' => true,
					]
				)
			);
			$this->assertSame( $this->normalize( $off ), $this->normalize( $on ) );
		}
	}

	/**
	 * A list ID left in the attribute after its list was removed is skipped.
	 */
	public function test_stale_list_id_is_skipped() {
		$free = $this->get_list( 'only_mailchimp' );
		$html = $this->render(
			$this->get_attrs(
				[
					'lists'       => [ 'no-longer-a-list', $free->get_public_id() ],
					'showPremium' => true,
				]
			)
		);

		$this->assertStringContainsString( '<input type="hidden" name="lists[]" value="' . $free->get_public_id() . '"', $html );
		$this->assertStringNotContainsString( 'no-longer-a-list', $html );
		$this->assertStringNotContainsString( 'class="is-gated"', $html );
	}
}

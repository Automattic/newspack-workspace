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
	 * Public IDs of lists the gate map reports as premium for this test.
	 *
	 * @var string[]
	 */
	private $premium_public_ids = [];

	/**
	 * Use the Mailchimp lists from Lists_Setup, and let each test hide some.
	 */
	public function set_up() {
		parent::set_up();
		Newspack_Newsletters::set_service_provider( 'mailchimp' );
		add_filter( 'newspack_newsletters_subscription_lists', [ $this, 'hide_lists' ] );
		add_filter( 'newspack_newsletters_subscription_list_gates', [ $this, 'report_premium_lists' ] );
	}

	/**
	 * Remove the filter and forget the hidden lists.
	 */
	public function tear_down() {
		remove_filter( 'newspack_newsletters_subscription_lists', [ $this, 'hide_lists' ] );
		remove_filter( 'newspack_newsletters_subscription_list_gates', [ $this, 'report_premium_lists' ] );
		$this->hidden_list_ids    = [];
		$this->premium_public_ids = [];
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
	 * Stand in for Access Control's gate map: report each marked list as gated,
	 * and each hidden one too, since a gate is the only reason it hides a list.
	 *
	 * @param array[] $list_gates Gates keyed by public list ID.
	 *
	 * @return array[]
	 */
	public function report_premium_lists( $list_gates ) {
		$hidden_public_ids = array_map(
			function ( $list_id ) {
				return ( new Subscription_List( $list_id ) )->get_public_id();
			},
			$this->hidden_list_ids
		);
		foreach ( array_merge( $this->premium_public_ids, $hidden_public_ids ) as $public_id ) {
			$list_gates[ $public_id ] = [
				[
					'id'    => 1,
					'title' => 'Premium Gate',
				],
			];
		}
		return $list_gates;
	}

	/**
	 * Mark fixture lists as premium, as an access gate would, without hiding them.
	 *
	 * That is a premium list for a reader who has access to it.
	 *
	 * @param string[] $keys Keys in Lists_Setup::$posts.
	 */
	private function mark_premium( $keys ) {
		$this->premium_public_ids = array_map(
			function ( $key ) {
				return $this->get_list( $key )->get_public_id();
			},
			$keys
		);
	}

	/**
	 * Get the markup of the list row that holds a list's checkbox.
	 *
	 * @param string $public_id The list's public ID.
	 * @param string $html      Rendered markup.
	 *
	 * @return string Empty when no row has that checkbox.
	 */
	private function get_checkbox_row( $public_id, $html ) {
		preg_match_all( '#<li\b.*?</li>#s', $html, $rows );
		foreach ( $rows[0] as $row ) {
			if ( false !== strpos( $row, 'value="' . $public_id . '"' ) ) {
				return $row;
			}
		}
		return '';
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
	 * Assert a list's public ID appears nowhere in the markup.
	 *
	 * The lookahead keeps a shorter ID from matching inside a longer one, such as
	 * `newspack-12` inside `newspack-123`.
	 *
	 * @param string $public_id The list's public ID.
	 * @param string $html      Rendered markup.
	 */
	private function assert_public_id_absent( $public_id, $html ) {
		$this->assertDoesNotMatchRegularExpression( '/' . preg_quote( $public_id, '/' ) . '(?![0-9A-Za-z_-])/', $html );
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

		$first_position  = strpos( $html, 'value="' . $first->get_public_id() . '"' );
		$second_position = strpos( $html, 'value="' . $second->get_public_id() . '"' );
		$this->assertNotFalse( $first_position, 'The first list renders.' );
		$this->assertNotFalse( $second_position, 'The second list renders.' );
		$this->assertLessThan( $second_position, $first_position );
	}

	/**
	 * With showPremium on, a list hidden from the reader renders as a row with a
	 * badge and a disabled checkbox that submits nothing.
	 */
	public function test_show_premium_renders_a_gated_row_with_a_disabled_checkbox() {
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
		$this->assertSame( 2, substr_count( $html, 'type="checkbox"' ), 'Both lists get a checkbox.' );
		$this->assertSame( 1, preg_match_all( '/<input[^>]*\sdisabled[\s\/>]/', $html ), 'Only the gated list\'s checkbox is disabled.' );
		$this->assertMatchesRegularExpression( '/<input type="checkbox" id="([^"]+)" disabled.*<label class="list-label" for="\1"/s', $html, 'The gated row\'s label points at its checkbox.' );
		$this->assertSame( 1, substr_count( $html, 'name="lists[]"' ), 'Only the free list\'s checkbox can be submitted.' );
		$this->assertStringContainsString( esc_html( $gated->get_title() ), $html );
		$this->assertStringContainsString( '<span class="newspack-ui__badge newspack-ui__badge--secondary">Premium</span>', $html );
		$this->assertStringNotContainsString( 'value="' . $gated->get_public_id() . '"', $html );
		$this->assertStringNotContainsString( 'checkbox-' . $gated->get_public_id() . '"', $html );
		$this->assertStringNotContainsString( 'type="hidden" name="lists[]"', $html, 'One free list plus a gated one shows the list, not a hidden input.' );
		$this->assert_public_id_absent( $gated->get_public_id(), $html );
	}

	/**
	 * Legacy marks a premium list with its label as text after the title, not a badge.
	 */
	public function test_legacy_style_marks_premium_lists_with_text() {
		$free                  = $this->get_list( 'only_mailchimp' );
		$gated                 = $this->get_list( 'two_settings' );
		$this->hidden_list_ids = [ $gated->get_id() ];
		$html                  = $this->render(
			$this->get_attrs(
				[
					'lists'       => [ $free->get_public_id(), $gated->get_public_id() ],
					'showPremium' => true,
					'className'   => 'my-class is-style-legacy',
				]
			)
		);

		$this->assertSame( 1, preg_match_all( '/' . preg_quote( $gated->get_title(), '/' ) . '\s+<span class="list-premium-label">\(Premium\)<\/span>/', $html ) );
		$this->assertStringNotContainsString( 'newspack-ui__badge', $html );
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

		$gated_position    = strpos( $html, 'class="is-gated"' );
		$checkbox_position = strpos( $html, 'value="' . $free->get_public_id() . '"' );
		$this->assertNotFalse( $gated_position, 'The gated row renders.' );
		$this->assertNotFalse( $checkbox_position, 'The free list renders a checkbox.' );
		$this->assertLessThan( $checkbox_position, $gated_position, 'The gated row comes first when its list is first.' );

		$html = $this->render(
			$this->get_attrs(
				[
					'lists'       => [ $free->get_public_id(), $gated->get_public_id() ],
					'showPremium' => true,
				]
			)
		);

		$gated_position    = strpos( $html, 'class="is-gated"' );
		$checkbox_position = strpos( $html, 'value="' . $free->get_public_id() . '"' );
		$this->assertNotFalse( $gated_position, 'The gated row renders.' );
		$this->assertNotFalse( $checkbox_position, 'The free list renders a checkbox.' );
		$this->assertLessThan( $gated_position, $checkbox_position, 'The checkbox comes first when its list is first.' );
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
		$this->assertSame( 2, preg_match_all( '/<input[^>]*\sdisabled[\s\/>]/', $html ), 'Each gated row has a disabled checkbox.' );
		$this->assertStringNotContainsString( 'name="', $html, 'Nothing can be submitted.' );
		$this->assertStringNotContainsString( '<button', $html );
		$this->assert_public_id_absent( $first->get_public_id(), $html );
		$this->assert_public_id_absent( $second->get_public_id(), $html );
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
	 * nothing: the markup is the same as with it off.
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

	/**
	 * A list removed by the block's own filter is hidden outright. It is not a
	 * gated list, so showPremium does not bring it back as a gated row.
	 */
	public function test_list_removed_by_the_block_filter_is_hidden_not_gated() {
		$kept    = $this->get_list( 'only_mailchimp' );
		$removed = $this->get_list( 'two_settings' );
		$filter  = function ( $list_ids ) use ( $removed ) {
			return array_values( array_diff( $list_ids, [ $removed->get_public_id() ] ) );
		};
		add_filter( 'newspack_newsletters_subscription_block_available_lists', $filter );

		foreach ( [ false, true ] as $show_premium ) {
			$html = $this->render(
				$this->get_attrs(
					[
						'lists'       => [ $kept->get_public_id(), $removed->get_public_id() ],
						'showPremium' => $show_premium,
					]
				)
			);
			$this->assertStringContainsString( '<input type="hidden" name="lists[]" value="' . $kept->get_public_id() . '"', $html );
			$this->assertStringNotContainsString( 'class="is-gated"', $html );
			$this->assert_public_id_absent( $removed->get_public_id(), $html );
		}

		remove_filter( 'newspack_newsletters_subscription_block_available_lists', $filter );
	}

	/**
	 * A list withheld for a reason other than an access gate stays hidden. Only a
	 * list the gate map marks as gated comes back as a gated row, so showPremium
	 * can't reveal the title of a list something else took away.
	 */
	public function test_list_hidden_without_a_gate_stays_hidden_with_show_premium() {
		$kept     = $this->get_list( 'only_mailchimp' );
		$withheld = $this->get_list( 'two_settings' );
		$withhold = function ( $lists ) use ( $withheld ) {
			return array_values(
				array_filter(
					$lists,
					function ( $list ) use ( $withheld ) {
						return $list->get_id() !== $withheld->get_id();
					}
				)
			);
		};
		add_filter( 'newspack_newsletters_subscription_lists', $withhold );

		$html = $this->render(
			$this->get_attrs(
				[
					'lists'       => [ $kept->get_public_id(), $withheld->get_public_id() ],
					'showPremium' => true,
				]
			)
		);

		remove_filter( 'newspack_newsletters_subscription_lists', $withhold );

		$this->assertStringContainsString( '<input type="hidden" name="lists[]" value="' . $kept->get_public_id() . '"', $html );
		$this->assertStringNotContainsString( 'class="is-gated"', $html );
		$this->assertStringNotContainsString( $withheld->get_title(), $html );
		$this->assert_public_id_absent( $withheld->get_public_id(), $html );
	}

	/**
	 * A list that the block's own filter adds renders with showPremium on as it
	 * does with it off, after the rows for the lists the attribute names. The gated
	 * rows follow the attribute, so without this the added list would vanish.
	 */
	public function test_list_added_by_the_block_filter_renders_with_show_premium() {
		$free                  = $this->get_list( 'only_mailchimp' );
		$gated                 = $this->get_list( 'two_settings' );
		$added                 = $this->get_list( 'remote_mailchimp' );
		$this->hidden_list_ids = [ $gated->get_id() ];
		$filter                = function ( $list_ids ) use ( $added ) {
			return array_merge( $list_ids, [ $added->get_public_id() ] );
		};
		add_filter( 'newspack_newsletters_subscription_block_available_lists', $filter );

		$attrs = [ 'lists' => [ $free->get_public_id(), $gated->get_public_id() ] ];

		$html = $this->render( $this->get_attrs( $attrs ) );
		$this->assertSame( 2, substr_count( $html, 'type="checkbox"' ), 'With showPremium off, the free list and the added list get a checkbox.' );
		$this->assertStringContainsString( 'value="' . $free->get_public_id() . '"', $html );
		$this->assertStringContainsString( 'value="' . $added->get_public_id() . '"', $html );
		$this->assertStringNotContainsString( 'class="is-gated"', $html );

		$html = $this->render( $this->get_attrs( $attrs + [ 'showPremium' => true ] ) );
		$this->assertSame( 2, substr_count( $html, 'name="lists[]"' ), 'With showPremium on, the free list and the added list get a checkbox that can be submitted.' );
		$this->assertStringContainsString( 'value="' . $free->get_public_id() . '"', $html );
		$this->assertStringContainsString( 'value="' . $added->get_public_id() . '"', $html );
		$this->assertSame( 1, substr_count( $html, 'class="is-gated"' ), 'The hidden list still shows as a gated row.' );
		$this->assertLessThan(
			strpos( $html, 'value="' . $added->get_public_id() . '"' ),
			strpos( $html, 'class="is-gated"' ),
			'The added list comes after the lists the attribute names.'
		);
		$this->assert_public_id_absent( $gated->get_public_id(), $html );

		remove_filter( 'newspack_newsletters_subscription_block_available_lists', $filter );
	}

	/**
	 * The two settings of showPremium.
	 *
	 * @return array
	 */
	public static function show_premium_settings() {
		return [
			'showPremium on'  => [ true ],
			'showPremium off' => [ false ],
		];
	}

	/**
	 * A reader who can join a premium list sees its badge; a free list gets none.
	 * The badge marks every premium list whatever showPremium says. The setting
	 * only adds rows for the lists the reader can't join.
	 *
	 * @dataProvider show_premium_settings
	 *
	 * @param bool $show_premium The block's showPremium attribute.
	 */
	public function test_reader_with_access_sees_the_badge_on_a_premium_list( $show_premium ) {
		$free    = $this->get_list( 'only_mailchimp' );
		$premium = $this->get_list( 'two_settings' );
		$this->mark_premium( [ 'two_settings' ] );
		$html = $this->render(
			$this->get_attrs(
				[
					'lists'       => [ $free->get_public_id(), $premium->get_public_id() ],
					'showPremium' => $show_premium,
				]
			)
		);

		$premium_row = $this->get_checkbox_row( $premium->get_public_id(), $html );
		$this->assertStringContainsString( 'type="checkbox"', $premium_row, 'The reader can still join the premium list.' );
		$this->assertStringContainsString( '<span class="newspack-ui__badge newspack-ui__badge--secondary">Premium</span>', $premium_row );
		$free_row = $this->get_checkbox_row( $free->get_public_id(), $html );
		$this->assertStringContainsString( 'type="checkbox"', $free_row );
		$this->assertStringNotContainsString( 'newspack-ui__badge', $free_row );
		$this->assertSame( 2, substr_count( $html, '<span class="newspack-ui__badge ' ), 'Only the premium list is badged, by its title and by its checkbox.' );
		$this->assertStringNotContainsString( 'class="is-gated"', $html, 'Nothing is hidden from this reader.' );
	}

	/**
	 * A blank label hides the badge for a reader with access too.
	 */
	public function test_blank_label_hides_the_badge_for_a_reader_with_access() {
		$free    = $this->get_list( 'only_mailchimp' );
		$premium = $this->get_list( 'two_settings' );
		$this->mark_premium( [ 'two_settings' ] );
		$html = $this->render(
			$this->get_attrs(
				[
					'lists'            => [ $free->get_public_id(), $premium->get_public_id() ],
					'showPremium'      => true,
					'showPremiumLabel' => '  ',
				]
			)
		);

		$this->assertStringContainsString( 'value="' . $premium->get_public_id() . '"', $html );
		$this->assertStringNotContainsString( 'newspack-ui__badge', $html );
	}

	/**
	 * A single premium list the reader can join stays a hidden input, with no badge.
	 */
	public function test_single_premium_list_with_access_stays_a_hidden_input() {
		$premium = $this->get_list( 'only_mailchimp' );
		$this->mark_premium( [ 'only_mailchimp' ] );
		$html = $this->render( $this->get_attrs( [ 'lists' => [ $premium->get_public_id() ] ] ) );

		$this->assertStringContainsString( '<input type="hidden" name="lists[]" value="' . $premium->get_public_id() . '"', $html );
		$this->assertStringNotContainsString( 'newspack-newsletters-lists', $html );
		$this->assertStringNotContainsString( 'newspack-ui__badge', $html );
	}

	/**
	 * Only listsPosition "after" adds the has-lists-after class.
	 */
	public function test_lists_position_adds_a_class_only_for_after() {
		$lists = [ $this->get_list( 'only_mailchimp' )->get_public_id(), $this->get_list( 'two_settings' )->get_public_id() ];

		$wrapper_classes = function ( $overrides ) use ( $lists ) {
			$html = $this->render( $this->get_attrs( [ 'lists' => $lists ] + $overrides ) );
			preg_match( '/class="([^"]*)"/', $html, $matches );
			return preg_split( '/\s+/', trim( $matches[1] ) );
		};

		$this->assertNotContains( 'has-lists-after', $wrapper_classes( [] ), 'The default puts the lists first.' );
		$this->assertContains( 'has-lists-after', $wrapper_classes( [ 'listsPosition' => 'after' ] ) );
		$this->assertNotContains( 'has-lists-after', $wrapper_classes( [ 'listsPosition' => 'before' ] ) );
	}

	/**
	 * Modern is the default style, Legacy is registered, and lists default to before.
	 */
	public function test_block_registers_modern_as_the_default_style() {
		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( 'newspack-newsletters/subscribe' );

		$styles = array_column( $block_type->styles, null, 'name' );
		$this->assertSame( [ 'modern', 'modern--grid', 'legacy' ], array_keys( $styles ) );
		$this->assertTrue( $styles['modern']['isDefault'] );
		$this->assertArrayNotHasKey( 'isDefault', $styles['modern--grid'] );
		$this->assertArrayNotHasKey( 'isDefault', $styles['legacy'] );
		$this->assertSame( 'before', $block_type->attributes['listsPosition']['default'] );
	}

	/**
	 * Premium lists are the ones the gate map reports with at least one gate. A free
	 * list is not premium, and neither is an entry with no gates in it.
	 */
	public function test_get_block_lists_reports_premium_lists() {
		$free     = $this->get_list( 'only_mailchimp' );
		$mapped   = $this->get_list( 'two_settings' );
		$no_gates = $this->get_list( 'remote_mailchimp' );
		$attrs    = $this->get_attrs( [ 'lists' => [ $free->get_public_id(), $mapped->get_public_id(), $no_gates->get_public_id() ] ] );
		$this->mark_premium( [ 'two_settings' ] );
		$report_no_gates = function ( $list_gates ) use ( $no_gates ) {
			$list_gates[ $no_gates->get_public_id() ] = [];
			return $list_gates;
		};
		add_filter( 'newspack_newsletters_subscription_list_gates', $report_no_gates );

		$lists = get_block_lists( $attrs );

		remove_filter( 'newspack_newsletters_subscription_list_gates', $report_no_gates );

		$this->assertArrayHasKey( $mapped->get_public_id(), $lists['premium'] );
		$this->assertArrayNotHasKey( $free->get_public_id(), $lists['premium'] );
		$this->assertArrayNotHasKey( $no_gates->get_public_id(), $lists['premium'], 'An entry with no gates is not premium.' );
	}

	/**
	 * A provider's list ID can be all digits, which PHP stores as an integer array key.
	 */
	public function test_numeric_list_id_gets_the_badge() {
		$html = render_form(
			$this->get_attrs( [ 'lists' => [ '7', '8' ] ] ),
			[
				'config'     => [
					'7' => [
						'title'       => 'Seven',
						'description' => '',
					],
					'8' => [
						'title'       => 'Eight',
						'description' => '',
					],
				],
				'accessible' => [ '7', '8' ],
				'gated'      => [],
				'order'      => [ '7', '8' ],
				'premium'    => [ '7' => true ],
			]
		);

		$this->assertStringContainsString( 'newspack-ui__badge', $this->get_checkbox_row( '7', $html ) );
		$this->assertNotSame( '', $this->get_checkbox_row( '8', $html ) );
		$this->assertStringNotContainsString( 'newspack-ui__badge', $this->get_checkbox_row( '8', $html ) );
	}
}

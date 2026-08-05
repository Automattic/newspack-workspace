<?php // phpcs:disable Squiz.Commenting, Universal.Files, Generic.Files, WordPress.DB

class WC_Memberships_User_Membership {
	private $id;
	private $user_id;

	// $user_id is optional so this can be constructed the way the plugin does it
	// internally ( `new WC_Memberships_User_Membership( $id )` ); when omitted it is
	// derived from the membership post's author, matching WC Memberships behavior.
	public function __construct( $id, $user_id = null ) {
		$this->id      = $id;
		$this->user_id = null !== $user_id ? $user_id : (int) get_post( $id )->post_author;
	}
	public function get_user() {
		return get_user_by( 'id', $this->user_id );
	}
	public function get_user_id() {
		return (int) $this->user_id;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_status() {
		return str_replace( 'wcm-', '', get_post( $this->id )->post_status );
	}
	// Resolve the plan for this membership from the test's registered plans
	// ( global $test_wc_memberships ), matched on the `_membership_plan_id` meta.
	public function get_plan() {
		global $test_wc_memberships;
		$plan_id = (int) get_post_meta( $this->id, '_membership_plan_id', true );
		foreach ( (array) $test_wc_memberships as $plan ) {
			if ( is_object( $plan ) && method_exists( $plan, 'get_id' ) && (int) $plan->get_id() === $plan_id ) {
				return $plan;
			}
		}
		return null;
	}
}

class WC_Memberships_Membership_Plan {
	private $id;
	private $name;
	// Default to an empty array so get_content_restriction_rules() always returns
	// an array (matching the real WC_Memberships_Membership_Plan), even before
	// set_content_restriction_rules() is called.
	private $rules = [];

	public function __construct( $id ) {
		$this->id   = $id;
		$this->name = 'Test Membership';
	}
	public function get_content_restriction_rules() {
		return $this->rules;
	}
	public function get_memberships() {
		$args = [
			'post_type'   => 'wc_user_membership',
			'post_status' => 'any',
			'meta_query'  => [
				[
					'key'   => '_membership_plan_id',
					'value' => $this->id,
				],
			],
		];
		$query = new WP_Query( $args );
		$memberships = [];
		foreach ( $query->posts as $post ) {
			$memberships[] = new WC_Memberships_User_Membership( $post->ID, $post->post_author );
		}
		return $memberships;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_name() {
		return $this->name;
	}
	public function set_content_restriction_rules( $rules ) {
		$this->rules = $rules;
	}
}

class WC_Memberships_Membership_Plan_Rule {
	private $id;
	private $content_type_name;
	private $object_id_rules;

	public function __construct( $data ) {
		foreach ( $data as $key => $value ) {
			$this->$key = $value;
		}
	}

	public function get_content_type_name() {
		return $this->content_type_name;
	}

	public function get_object_ids() {
		return $this->object_id_rules;
	}
}

function wc_memberships_get_membership_plans() {
	global $test_wc_memberships;
	if ( empty( $test_wc_memberships ) ) {
		return [];
	}
	return $test_wc_memberships;
}

// Returns the user's active memberships across all registered test plans.
function wc_memberships_get_user_active_memberships( $user_id ) {
	global $test_wc_memberships;
	$active_statuses = wc_memberships()->get_user_memberships_instance()->get_active_access_membership_statuses();
	$result          = [];
	foreach ( (array) $test_wc_memberships as $plan ) {
		if ( ! is_object( $plan ) || ! method_exists( $plan, 'get_memberships' ) ) {
			continue;
		}
		foreach ( $plan->get_memberships() as $membership ) {
			if ( (int) $membership->get_user_id() !== (int) $user_id ) {
				continue;
			}
			if ( ! in_array( $membership->get_status(), $active_statuses, true ) ) {
				continue;
			}
			$result[] = $membership;
		}
	}
	return $result;
}

function wc_memberships() {
	return new class() {
		public function get_user_memberships_instance() {
			return new class() {
				public function get_active_access_membership_statuses() {
					return [ 'active', 'complimentary', 'free_trial', 'pending' ];
				}
			};
		}
	};
}

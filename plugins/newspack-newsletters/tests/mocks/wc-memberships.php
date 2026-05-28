<?php // phpcs:disable Squiz.Commenting, Universal.Files, Generic.Files, WordPress.DB

class WC_Memberships_User_Membership {
	private $id;
	private $user_id;

	public function __construct( $id, $user_id ) {
		$this->id   = $id;
		$this->user_id   = $user_id;
	}
	public function get_user() {
		return get_user_by( 'id', $this->user_id );
	}
	public function get_id() {
		return $this->id;
	}
	public function get_status() {
		return str_replace( 'wcm-', '', get_post( $this->id )->post_status );
	}
}

class WC_Memberships_Membership_Plan {
	private $id;
	private $name;
	private $rules;

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

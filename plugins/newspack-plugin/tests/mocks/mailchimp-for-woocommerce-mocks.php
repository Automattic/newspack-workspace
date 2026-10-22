<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, Universal.Files.SeparateFunctionsFromOO.Mixed

/**
 * Stand-ins for the Mailchimp for WooCommerce classes the integration touches. They carry the
 * real class and method names and singletons, so the integration unhooks the same callbacks
 * and calls the same methods as against the plugin.
 *
 * @package Newspack\Tests
 */

if ( ! class_exists( 'MailChimp_WooCommerce_Public' ) ) {
	/**
	 * Mailchimp for WooCommerce's public-facing class.
	 */
	class MailChimp_WooCommerce_Public {
		/**
		 * Singleton, as in the plugin.
		 *
		 * @var MailChimp_WooCommerce_Public|null
		 */
		private static $instance = null;

		/**
		 * Get the singleton.
		 */
		public static function instance() {
			if ( ! self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Renders the opt-in radio in the plugin.
		 */
		public function user_my_account_opt_in() {}
	}
}

if ( ! class_exists( 'MailChimp_Service' ) ) {
	/**
	 * Mailchimp for WooCommerce's service class, which hooks the user and order handlers.
	 */
	class MailChimp_Service {
		/**
		 * Singleton, as in the plugin.
		 *
		 * @var MailChimp_Service|null
		 */
		private static $instance = null;

		/**
		 * Users the profile update handler ran for.
		 *
		 * @var int[]
		 */
		public $updated_users = [];

		/**
		 * Get the singleton.
		 */
		public static function instance() {
			if ( ! self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * In the plugin, queues a job that sends the user's stored opt-in to Mailchimp.
		 *
		 * @param int $user_id User ID.
		 */
		public function handleUserUpdated( $user_id ) {
			$this->updated_users[] = $user_id;
		}
	}
}

if ( ! class_exists( 'MailChimp_WooCommerce_Customer' ) ) {
	/**
	 * The customer on an order, as Mailchimp for WooCommerce builds it.
	 */
	class MailChimp_WooCommerce_Customer {
		/**
		 * Whether the order marks the customer as subscribed.
		 *
		 * @var bool
		 */
		private $opt_in_status = false;

		/**
		 * The customer's Mailchimp status, when the plugin has looked it up.
		 *
		 * @var string|null
		 */
		private $status_in_mailchimp = null;

		/**
		 * The customer's WordPress user.
		 *
		 * @var WP_User|null
		 */
		private $wordpress_user;

		/**
		 * The customer's email address.
		 *
		 * @var string
		 */
		private $email_address;

		/**
		 * The customer's billing address.
		 *
		 * @var MailChimp_WooCommerce_Address|null
		 */
		private $address = null;

		/**
		 * Constructor.
		 *
		 * @param WP_User|null $wordpress_user WordPress user, or null for a guest.
		 * @param string       $email_address  Email address.
		 */
		public function __construct( $wordpress_user, $email_address ) {
			$this->wordpress_user = $wordpress_user;
			$this->email_address  = $email_address;
		}

		/**
		 * Get the email address.
		 */
		public function getEmailAddress() {
			return $this->email_address;
		}

		/**
		 * Get the opt-in status.
		 */
		public function getOptInStatus() {
			return $this->opt_in_status;
		}

		/**
		 * Set the opt-in status.
		 *
		 * @param bool $opt_in_status Opt-in status.
		 */
		public function setOptInStatus( $opt_in_status ) {
			$this->opt_in_status = (bool) $opt_in_status;
			return $this;
		}

		/**
		 * Get the Mailchimp status the plugin looked up, if any.
		 */
		public function getMailchimpStatus() {
			return $this->status_in_mailchimp;
		}

		/**
		 * Set the Mailchimp status, as the plugin does when it reads it during a sync.
		 *
		 * @param string $status_in_mailchimp Status in Mailchimp.
		 */
		public function setMailchimpStatus( $status_in_mailchimp ) {
			$this->status_in_mailchimp = $status_in_mailchimp;
			return $this;
		}

		/**
		 * Get the WordPress user.
		 */
		public function getWordpressUser() {
			return $this->wordpress_user;
		}

		/**
		 * Get the billing address, created empty on first use as in the plugin.
		 */
		public function getAddress() {
			if ( ! $this->address ) {
				$this->address = new MailChimp_WooCommerce_Address();
			}
			return $this->address;
		}
	}
}

if ( ! class_exists( 'MailChimp_WooCommerce_Address' ) ) {
	/**
	 * A customer's billing address, as Mailchimp for WooCommerce sends it.
	 */
	class MailChimp_WooCommerce_Address {
		/**
		 * Address fields, keyed by the plugin's property names.
		 *
		 * @var array<string, string>
		 */
		public $fields = [
			'address1'    => '',
			'address2'    => '',
			'city'        => '',
			'province'    => '',
			'postal_code' => '',
			'country'     => '',
		];

		// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Accessors with the plugin's names.
		public function getAddress1() {
			return $this->fields['address1'];
		}
		public function setAddress1( $value ) {
			$this->fields['address1'] = $value;
			return $this;
		}
		public function getAddress2() {
			return $this->fields['address2'];
		}
		public function setAddress2( $value ) {
			$this->fields['address2'] = $value;
			return $this;
		}
		public function getCity() {
			return $this->fields['city'];
		}
		public function setCity( $value ) {
			$this->fields['city'] = $value;
			return $this;
		}
		public function getProvince() {
			return $this->fields['province'];
		}
		public function setProvince( $value ) {
			$this->fields['province'] = $value;
			return $this;
		}
		public function getPostalCode() {
			return $this->fields['postal_code'];
		}
		public function setPostalCode( $value ) {
			$this->fields['postal_code'] = $value;
			return $this;
		}
		public function getCountry() {
			return $this->fields['country'];
		}
		public function setCountry( $value ) {
			$this->fields['country'] = $value;
			return $this;
		}
		// phpcs:enable Squiz.Commenting.FunctionComment.Missing
	}
}

if ( ! class_exists( 'MailChimp_WooCommerce_Order' ) ) {
	/**
	 * An order, as Mailchimp for WooCommerce sends it.
	 */
	class MailChimp_WooCommerce_Order {
		/**
		 * The order's customer.
		 *
		 * @var MailChimp_WooCommerce_Customer|null
		 */
		private $customer;

		/**
		 * Constructor.
		 *
		 * @param MailChimp_WooCommerce_Customer|null $customer The order's customer.
		 */
		public function __construct( $customer ) {
			$this->customer = $customer;
		}

		/**
		 * Get the customer.
		 */
		public function getCustomer() {
			return $this->customer;
		}
	}
}

if ( ! function_exists( 'mailchimp_get_subscriber_status' ) ) {
	/**
	 * In the plugin, reads a contact's status from the Mailchimp audience.
	 *
	 * @param string $email Email address.
	 *
	 * @return string|null Status, or null if the contact is not in the audience.
	 */
	function mailchimp_get_subscriber_status( $email ) {
		$GLOBALS['newspack_test_mailchimp_status_lookups'][] = $email;
		return $GLOBALS['newspack_test_mailchimp_statuses'][ $email ] ?? null;
	}
}

<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed

if ( ! class_exists( 'Newspack_Newsletters_Contacts' ) ) {
	class Newspack_Newsletters_Contacts {
		/**
		 * All calls made to add_and_remove_lists() during this test run.
		 * Reset this in each test's set_up() via reset_calls().
		 *
		 * @var array[] Each entry: [ 'email', 'lists_to_add', 'lists_to_remove', 'context' ]
		 */
		public static $add_and_remove_lists_calls = [];

		/**
		 * Fixture returned by get_fields(). Set in tests that exercise code paths
		 * calling Newspack_Newsletters_Contacts::get_fields(). An array returns as-is;
		 * a WP_Error is returned to simulate provider failure.
		 *
		 * @var array|\WP_Error
		 */
		public static $fields_fixture = [];

		/**
		 * If set, add_and_remove_lists() returns this value instead of true.
		 * Use a WP_Error to simulate provider failure.
		 *
		 * @var mixed
		 */
		public static $next_return = null;

		/**
		 * If set, the next add_and_remove_lists() call will throw this Throwable.
		 * Single-shot: the property is reset to null after firing.
		 *
		 * @var \Throwable|null
		 */
		public static $next_throw = null;

		public static function reset_calls() {
			self::$add_and_remove_lists_calls = [];
			self::$fields_fixture             = [];
			self::$next_return                = null;
			self::$next_throw                 = null;
		}

		public static function add_and_remove_lists( $email, $lists_to_add, $lists_to_remove, $context = '' ) {
			self::$add_and_remove_lists_calls[] = [
				'email'           => $email,
				'lists_to_add'    => $lists_to_add,
				'lists_to_remove' => $lists_to_remove,
				'context'         => $context,
			];
			if ( null !== self::$next_throw ) {
				$exception        = self::$next_throw;
				self::$next_throw = null;
				throw $exception;
			}
			return null === self::$next_return ? true : self::$next_return;
		}

		public static function get_fields( $list_id = null ) {
			return self::$fields_fixture;
		}
	}
}

if ( ! class_exists( 'Newspack_Newsletters' ) ) {
	class Newspack_Newsletters {
		const EMAIL_HTML_META = 'newspack_email_html';

		public static function service_provider() {
			return get_option( 'newspack_newsletters_service_provider', false );
		}

		public static function get_service_provider() {
			return new Newspack_Newsletters_Service_Provider();
		}

		public static function is_service_provider_configured() {
			return true;
		}
	}
}

if ( ! class_exists( 'Newspack_Newsletters_Settings' ) ) {
	class Newspack_Newsletters_Settings {}
}

if ( ! class_exists( 'Newspack_Newsletters_Subscription' ) ) {
	class Newspack_Newsletters_Subscription {
		/**
		 * Configurable per-email contact list state. Keys are email addresses.
		 * Set this in tests to simulate a contact already subscribed to certain lists.
		 * Example: self::$contact_lists['user@example.com'] = ['list-123'];
		 *
		 * @var array[]
		 */
		public static $contact_lists = [];

		public static function reset_calls() {
			self::$contact_lists = [];
		}

		public static function get_contact_lists( $email ) {
			return self::$contact_lists[ $email ] ?? [];
		}

		public static function get_lists() {
			return [
				[
					'active' => true,
					'name'   => 'test',
					'id'     => '123',
				],
			];
		}
	}
}

if ( ! class_exists( 'Newspack_Newsletters_Service_Provider' ) ) {
	class Newspack_Newsletters_Service_Provider {
		public $service = 'mailchimp';

		public static function get_lists() {
			return [
				[
					'active' => true,
					'name'   => 'test',
					'id'     => '123',
				],
			];
		}
	}
}

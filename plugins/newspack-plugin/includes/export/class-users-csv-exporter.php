<?php
/**
 * Newspack batched CSV exporter for WP users.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-csv-batch-exporter.php';

/**
 * Exports WP users to CSV in pages, honoring the users admin list filters
 * (role, search), with WooCommerce billing/shipping meta columns.
 *
 * Extensibility contract:
 * - `newspack_users_export_headers` filters the column id => label map.
 * - `newspack_users_export_row` filters each row (keyed by column id).
 * - `newspack_users_export_query_args` filters the WP_User_Query args built
 *   from the captured list params.
 *
 * This class must only be loaded after WooCommerce's WC_CSV_Batch_Exporter
 * abstract (see CSV_Exports::load_exporter_dependencies()).
 */
class Users_CSV_Exporter extends CSV_Batch_Exporter {

	/**
	 * Type of export, used in WC filter names.
	 *
	 * @var string
	 */
	protected $export_type = 'newspack_users';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->filename = 'newspack-users-export.csv';
		parent::__construct();
	}

	/**
	 * The WooCommerce address user-meta keys exported as columns.
	 *
	 * @return string[]
	 */
	public static function get_address_meta_keys() {
		return [
			'billing_first_name',
			'billing_last_name',
			'billing_company',
			'billing_address_1',
			'billing_address_2',
			'billing_city',
			'billing_state',
			'billing_postcode',
			'billing_country',
			'billing_email',
			'billing_phone',
			'shipping_first_name',
			'shipping_last_name',
			'shipping_company',
			'shipping_address_1',
			'shipping_address_2',
			'shipping_city',
			'shipping_state',
			'shipping_postcode',
			'shipping_country',
		];
	}

	/**
	 * Default column id => label map.
	 *
	 * @return array
	 */
	public function get_default_column_names() {
		$columns = [
			'ID'              => __( 'User ID', 'newspack-plugin' ),
			'user_login'      => __( 'Username', 'newspack-plugin' ),
			'user_email'      => __( 'Email', 'newspack-plugin' ),
			'display_name'    => __( 'Display Name', 'newspack-plugin' ),
			'first_name'      => __( 'First Name', 'newspack-plugin' ),
			'last_name'       => __( 'Last Name', 'newspack-plugin' ),
			'roles'           => __( 'Roles', 'newspack-plugin' ),
			'user_registered' => __( 'Registered Date', 'newspack-plugin' ),
		];
		foreach ( self::get_address_meta_keys() as $meta_key ) {
			$columns[ $meta_key ] = ucwords( str_replace( '_', ' ', $meta_key ) );
		}

		/**
		 * Filters the users export columns.
		 *
		 * Pair with `newspack_users_export_row` to add custom columns.
		 *
		 * @param array $columns Column id => label map.
		 */
		return apply_filters( 'newspack_users_export_headers', $columns );
	}

	/**
	 * Translate captured users-list query params into WP_User_Query args.
	 *
	 * Third-party list filters are honored by replaying the core
	 * `users_list_table_query_args` filter with the captured params exposed
	 * as $_GET (its callbacks conventionally read the superglobal).
	 *
	 * @param array $params Parsed query-string params from the users list.
	 * @return array WP_User_Query args.
	 */
	public static function build_query_args( $params ) {
		$params = map_deep( (array) $params, 'sanitize_text_field' );
		$args   = [];

		if ( ! empty( $params['role'] ) ) {
			$args['role'] = $params['role'];
		}
		if ( ! empty( $params['s'] ) ) {
			// Core WP_Users_List_Table wraps the term in wildcards.
			$args['search'] = '*' . $params['s'] . '*';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$original_get = $_GET;
		$_GET         = $params; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___GET
		try {
			/** This filter is documented in wp-admin/includes/class-wp-users-list-table.php */
			$args = apply_filters( 'users_list_table_query_args', $args );
		} finally {
			$_GET = $original_get; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___GET
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		/**
		 * Filters the users export query args.
		 *
		 * @param array $args   WP_User_Query args.
		 * @param array $params The captured users-list params.
		 */
		return apply_filters( 'newspack_users_export_query_args', $args, $params );
	}

	/**
	 * Prepare one page of user rows.
	 */
	public function prepare_data_to_export() {
		$args                = self::build_query_args( $this->list_params );
		$args['number']      = $this->get_limit();
		$args['paged']       = $this->get_page();
		$args['count_total'] = true;
		$args['fields']      = 'all';
		// Deterministic ordering keeps pagination stable across steps.
		$args['orderby'] = 'ID';
		$args['order']   = 'ASC';

		$query = new \WP_User_Query( $args );

		$this->total_rows = (int) $query->get_total();
		$this->row_data   = [];
		foreach ( $query->get_results() as $user ) {
			$this->row_data[] = $this->get_row_data( $user );
		}
	}

	/**
	 * Build one CSV row (raw values; escaping happens at write time via
	 * WC_CSV_Exporter::format_data()).
	 *
	 * @param \WP_User $user The user.
	 * @return array Row keyed by column id.
	 */
	public function get_row_data( $user ) {
		$row = [
			'ID'              => (int) $user->ID,
			'user_login'      => $user->user_login,
			'user_email'      => $user->user_email,
			'display_name'    => $user->display_name,
			'first_name'      => $user->first_name,
			'last_name'       => $user->last_name,
			'roles'           => implode( ', ', $user->roles ),
			'user_registered' => $user->user_registered,
		];
		foreach ( self::get_address_meta_keys() as $meta_key ) {
			$row[ $meta_key ] = (string) get_user_meta( $user->ID, $meta_key, true );
		}

		/**
		 * Filters a users export row.
		 *
		 * Pair with `newspack_users_export_headers` to add custom columns.
		 *
		 * @param array    $row  Row values keyed by column id.
		 * @param \WP_User $user The user being exported.
		 */
		return apply_filters( 'newspack_users_export_row', $row, $user );
	}
}

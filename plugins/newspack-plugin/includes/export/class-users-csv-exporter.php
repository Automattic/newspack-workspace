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
	 * The WooCommerce address user-meta keys exported as columns (the shared
	 * address column ids double as the meta keys).
	 *
	 * @return string[]
	 */
	public static function get_address_meta_keys() {
		return array_keys( self::get_address_column_labels() );
	}

	/**
	 * Default column id => label map.
	 *
	 * @return array
	 */
	public function get_default_column_names() {
		$columns = array_merge(
			[
				'ID'              => __( 'User ID', 'newspack-plugin' ),
				'user_login'      => __( 'Username', 'newspack-plugin' ),
				'user_email'      => __( 'Email', 'newspack-plugin' ),
				'display_name'    => __( 'Display Name', 'newspack-plugin' ),
				'first_name'      => __( 'First Name', 'newspack-plugin' ),
				'last_name'       => __( 'Last Name', 'newspack-plugin' ),
				'roles'           => __( 'Roles', 'newspack-plugin' ),
				'user_registered' => __( 'Registered Date', 'newspack-plugin' ),
			],
			self::get_address_column_labels()
		);

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
	 * as $_GET (its callbacks conventionally read the superglobal, so the
	 * values are handed over slashed, superglobal-shaped). Core fires this
	 * filter exclusively on the users list-table screen, so callbacks may
	 * assume admin-only context: under WP-CLI get_current_screen() is not
	 * even defined (the replay is skipped entirely outside admin), and in
	 * the admin-ajax steps it exists but returns null, so a callback
	 * dereferencing the screen would throw — such failures are caught and
	 * degrade to "third-party filters not honored" rather than a fatal.
	 *
	 * @param array $params Parsed query-string params from the users list.
	 * @return array WP_User_Query args.
	 */
	public static function build_query_args( array $params ): array {
		// Array-shaped params (a mangled ?s[]=... URL) would TypeError in the
		// string handling below; degrade to "filter ignored" instead.
		$params = array_filter( map_deep( $params, 'sanitize_text_field' ), 'is_scalar' );
		$args   = [];

		if ( ! empty( $params['role'] ) ) {
			$args['role'] = $params['role'];
		}
		if ( ! empty( $params['s'] ) ) {
			// Core WP_Users_List_Table wraps the term in wildcards.
			$args['search'] = '*' . $params['s'] . '*';
		}

		if ( \is_admin() ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$original_get = $_GET;
			$_GET         = \wp_slash( $params ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___GET
			try {
				/** This filter is documented in wp-admin/includes/class-wp-users-list-table.php */
				$args = apply_filters( 'users_list_table_query_args', $args );
			} catch ( \Throwable $e ) {
				// A callback assumed list-table context that admin-ajax can't
				// provide (e.g. a null get_current_screen()); skip the filter.
				unset( $e );
			} finally {
				$_GET = $original_get; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___GET
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		}

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
	public function prepare_data_to_export(): void {
		$args                = self::build_query_args( $this->list_params );
		$args['number']      = $this->get_limit();
		$args['paged']       = $this->get_page();
		$args['count_total'] = true;
		$args['fields']      = 'all';
		// Deterministic ordering keeps pagination stable across steps.
		$args['orderby'] = 'ID';
		$args['order']   = 'ASC';

		$query = new \WP_User_Query( $args );

		// Pinned to page 1's count so a set that shrinks mid-run can't end the
		// export early with a truncated CSV (see pin_total_rows()).
		$this->pin_total_rows( (int) $query->get_total() );
		$this->row_data = [];
		$users          = $query->get_results();
		// WP_User_Query doesn't prime user meta; batch it to one query
		// instead of one meta-cache load per exported row.
		if ( ! empty( $users ) ) {
			\update_meta_cache( 'user', \wp_list_pluck( $users, 'ID' ) );
		}
		foreach ( $users as $user ) {
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

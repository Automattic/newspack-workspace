<?php
/**
 * Newspack batched CSV exporter for WP users.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-csv-batch-exporter.php';
require_once __DIR__ . '/class-user-meta-columns.php';

/**
 * Exports WP users to CSV in pages, honoring the users admin list filters
 * (role, search), with WooCommerce billing/shipping meta columns.
 *
 * Extensibility contract:
 * - `newspack_users_export_headers` filters the column id => label map.
 * - `newspack_users_export_row` filters each row (keyed by column id).
 * - `newspack_users_export_query_args` filters the WP_User_Query args built
 *   from the captured list params and the chosen export options.
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

		$columns = array_merge( $columns, User_Meta_Columns::get_column_names( $this->get_meta_keys() ) );

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
	 * @param array $config Export options chosen in the export dialog.
	 * @return array WP_User_Query args.
	 */
	public static function build_query_args( array $params, array $config = [] ): array {
		// Array-shaped params (a mangled ?s[]=... URL) would TypeError in the
		// string handling below; degrade to "filter ignored" instead.
		$params = array_filter( map_deep( $params, 'sanitize_text_field' ), 'is_scalar' );
		$args   = [];

		// The dialog's role selection supersedes the list's single-role filter,
		// which is the only reason more than one role can be exported at once.
		// Once a selection has been submitted it is authoritative even when
		// empty — clearing every checkbox means every role, not "keep whatever
		// the list was filtered to". The exception is a list view the dialog
		// cannot represent, core's role-less `?role=none` among them: no box
		// was ever shown for it, so there was nothing for the admin to clear
		// and dropping it would silently widen the export to every user.
		if ( ! empty( $config['roles'] ) ) {
			$args['role__in'] = $config['roles'];
		} elseif ( ! empty( $params['role'] ) && ( empty( $config['selection_submitted'] ) || ! self::is_offered_role( $params['role'] ) ) ) {
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

		$date_query = self::build_date_query( $config );
		if ( ! empty( $date_query ) ) {
			$args['date_query'] = [ $date_query ];
		}

		/**
		 * Filters the users export query args.
		 *
		 * @param array $args   WP_User_Query args.
		 * @param array $params The captured users-list params.
		 * @param array $config The export options chosen in the export dialog.
		 */
		return apply_filters( 'newspack_users_export_query_args', $args, $params, $config );
	}

	/**
	 * Whether the export dialog offers a checkbox for a role.
	 *
	 * @param string $role Role slug from the list's filter.
	 * @return bool
	 */
	private static function is_offered_role( string $role ): bool {
		return array_key_exists( $role, \wp_roles()->get_names() );
	}

	/**
	 * Build the registration-date clause for the export's date range.
	 *
	 * Both bounds are inclusive whole days: `after` is the start of date_from
	 * and `before` the end of date_to, so a single-day range returns that day.
	 * The bounds are the publisher's days, converted to UTC because
	 * `user_registered` is stored in UTC and WP_Date_Query compares a full
	 * datetime string against the column as given. The subscriptions export
	 * reads the same two dates as site-local days, so this keeps one dialog
	 * control meaning one thing on both screens.
	 *
	 * @param array $config Export config.
	 * @return array WP_Date_Query clause, or [] when no range was chosen.
	 */
	private static function build_date_query( array $config ): array {
		$clause = [
			'column'    => 'user_registered',
			'inclusive' => true,
		];
		if ( ! empty( $config['date_from'] ) ) {
			$clause['after'] = \get_gmt_from_date( $config['date_from'] . ' 00:00:00' );
		}
		if ( ! empty( $config['date_to'] ) ) {
			$clause['before'] = \get_gmt_from_date( $config['date_to'] . ' 23:59:59' );
		}
		return isset( $clause['after'] ) || isset( $clause['before'] ) ? $clause : [];
	}

	/**
	 * The user meta keys this export carries as extra columns.
	 *
	 * @return string[]
	 */
	private function get_meta_keys(): array {
		return $this->export_config['meta_keys'] ?? [];
	}

	/**
	 * Prepare one page of user rows.
	 */
	public function prepare_data_to_export(): void {
		$args                = self::build_query_args( $this->list_params, $this->export_config );
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
			// Rendered in the site's timezone: the date range that selects a row
			// is read as the publisher's days, and a boundary row printing a
			// UTC timestamp would contradict the filter that returned it.
			'user_registered' => $this->format_export_date( \get_date_from_gmt( (string) $user->user_registered ) ),
		];
		foreach ( self::get_address_meta_keys() as $meta_key ) {
			$row[ $meta_key ] = (string) get_user_meta( $user->ID, $meta_key, true );
		}
		$row = array_merge( $row, User_Meta_Columns::get_row_values( (int) $user->ID, $this->get_meta_keys() ) );

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

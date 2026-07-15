<?php
/**
 * SEO management for profiles.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles;

use NewspackProfiles\Registrars\Rewrite_Rule_Registrar;
use NewspackProfiles\Traits\Singleton;

/**
 * SEO_Manager class.
 */
class SEO_Manager {

	use Singleton;

	/**
	 * Initialize SEO_Manager.
	 */
	protected function __construct() {
		// Single page.
		add_action( 'wp_head', array( $this, 'add_profile_meta_tags_for_single_page' ), 10 );
		add_filter( 'document_title', array( $this, 'filter_document_title_for_single_page' ), 10, 1 );
		add_filter( 'get_canonical_url', array( $this, 'filter_canonical_url_for_single_page' ), 10, 1 );
		add_filter( 'the_title', array( $this, 'filter_the_title_for_single_page' ), 10, 2 );

		// List page.
		add_filter( 'document_title', array( $this, 'filter_document_title_for_list_page' ), 10, 1 );
		add_filter( 'get_canonical_url', array( $this, 'filter_canonical_url_for_list_page' ), 10, 1 );
	}

	/**
	 * Check if current page is a profile page and extract profile info.
	 *
	 * @return array Array with 'base' and 'slug' keys.
	 */
	private function get_profile_info(): array {
		global $wp;

		return array(
			'base' => sanitize_title( $wp->query_vars['np_base'] ?? '' ),
			'slug' => sanitize_title( $wp->query_vars['np_slug'] ?? '' ),
		);
	}

	/**
	 * Get SEO data for the current profile.
	 * Note: This method internally uses RDB plugin APIs which support caching,
	 * so multiple calls per request are efficient.
	 *
	 * @return array|null
	 */
	private function get_seo_data_for_single_page(): ?array {
		$profile_info = $this->get_profile_info();

		if (
			get_post_type() !== 'np_profile_template'
			|| empty( $profile_info['base'] )
			|| empty( $profile_info['slug'] )
		) {
			return null;
		}

		$config = Profile_Collections::get_instance()->get( $profile_info['base'] );

		if ( ! $config ) {
			return null;
		}

		$query_builder = Query_Manager::get_query_builder( $config );

		if ( null === $query_builder || ! $query_builder->has_valid_data_source() ) {
			return null;
		}

		$item_query = $query_builder->get_item_query( true );

		$result = $item_query->execute(
			array(
				'slug' => $profile_info['slug'],
			)
		);

		if ( is_wp_error( $result ) || empty( $result['results'][0]['result'] ) ) {
			return array();
		}

		$data = array();

		foreach ( $result['results'][0]['result'] as $key => $value ) {
			$data[ $key ] = empty( $value['value'] ) ? '' : $value['value'];
		}

		$base_path = Rewrite_Rule_Registrar::get_instance()->get_base_path();

		return array(
			'heading'     => $this->get_heading_for_single_page( $data, $config['titleFields'] ?? array() ),
			'title'       => $this->get_seo_metadata_value( $config['seoFields']['title'] ?? array(), $data ),
			'description' => $this->get_seo_metadata_value( $config['seoFields']['description'] ?? array(), $data ),
			'image'       => $this->get_seo_metadata_value( $config['seoFields']['image'] ? array( $config['seoFields']['image'] ) : array(), $data ),
			'url'         => sprintf( '/%s/%s/%s/', $base_path, $profile_info['base'], $data['slug'] ),
		);
	}

	/**
	 * Get heading for single profile page based on configured fields and actual data.
	 *
	 * @param array $data   Associative array of actual data with field keys and their values.
	 * @param array $fields Array of field keys from profile configuration.
	 *
	 * @return string Heading value for the single profile page.
	 */
	private function get_heading_for_single_page( array $data, array $fields ): string {
		$values = array();

		foreach ( $fields as $field_key ) {
			if ( isset( $data[ $field_key ] ) && is_string( $data[ $field_key ] ) ) {
				$values[] = (string) $data[ $field_key ];
			}
		}

		return implode( ' ', $values );
	}

	/**
	 * Helper method to compose SEO metadata values based on configured fields and actual data.
	 *
	 * @param array $fields Array of field keys to use for composing the value.
	 * @param array $data   Associative array of actual data with field keys and their values.
	 *
	 * @return string Composed SEO metadata value.
	 */
	private function get_seo_metadata_value( array $fields, array $data ): string {
		$values = array();

		foreach ( $fields as $field ) {
			if ( ! $field ) {
				continue;
			}

			if ( ! isset( $data[ $field ] ) ) {
				$values[] = (string) $field;
			} elseif ( ! empty( $data[ $field ] ) ) {
				$values[] = (string) $data[ $field ];
			}
		}

		return implode( ' ', $values );
	}

	/**
	 * Add profile meta tags to wp_head for single profile pages.
	 */
	public function add_profile_meta_tags_for_single_page(): void {
		$seo_data = $this->get_seo_data_for_single_page();

		if ( ! $seo_data ) {
			return;
		}

		if ( $seo_data['title'] ) {
			echo '<meta property="og:title" content="' . esc_attr( $seo_data['title'] ) . '">' . "\n";
			echo '<meta name="twitter:title" content="' . esc_attr( $seo_data['title'] ) . '">' . "\n";
		}

		if ( $seo_data['description'] ) {
			echo '<meta name="description" content="' . esc_attr( $seo_data['description'] ) . '">' . "\n";
			echo '<meta property="og:description" content="' . esc_attr( $seo_data['description'] ) . '">' . "\n";
			echo '<meta name="twitter:description" content="' . esc_attr( $seo_data['description'] ) . '">' . "\n";
		}

		if ( $seo_data['image'] ) {
			echo '<meta property="og:image" content="' . esc_url( $seo_data['image'] ) . '">' . "\n";
			echo '<meta name="twitter:image" content="' . esc_url( $seo_data['image'] ) . '">' . "\n";
			echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
		}

		if ( $seo_data['url'] ) {
			echo '<meta property="og:url" content="' . esc_url( home_url( $seo_data['url'] ) ) . '">' . "\n";
		}

		echo '<meta property="og:type" content="profile">' . "\n";
	}

	/**
	 * Filter document title for single profile pages.
	 *
	 * @param string $title Title.
	 *
	 * @return string
	 */
	public function filter_document_title_for_single_page( string $title ): string {
		$seo_data = $this->get_seo_data_for_single_page();

		if ( ! empty( $seo_data['title'] ) ) {
			return wp_strip_all_tags( $seo_data['title'] );
		}

		return $title;
	}

	/**
	 * Filter canonical URL for single profile pages.
	 *
	 * @param string|null $canonical_url The canonical URL.
	 *
	 * @return string|null
	 */
	public function filter_canonical_url_for_single_page( ?string $canonical_url ): ?string {
		$seo_data = $this->get_seo_data_for_single_page();

		if ( empty( $seo_data['url'] ) ) {
			return $canonical_url;
		}

		return home_url( $seo_data['url'] );
	}

	/**
	 * Filter the_title for single profile pages.
	 *
	 * @param string $title The title.
	 * @param int    $post_id The post ID.
	 *
	 * @return string
	 */
	public function filter_the_title_for_single_page( string $title, int $post_id ): string {
		if (
			! is_singular()
			|| get_post_type( $post_id ) !== Page_Template_Manager::POST_TYPE
			|| get_the_ID() !== $post_id
		) {
			return $title;
		}

		$data = $this->get_seo_data_for_single_page();

		if ( ! empty( $data['heading'] ) ) {
			return wp_strip_all_tags( $data['heading'] );
		}

		return $title;
	}

	/**
	 * Get SEO data for the profile list page.
	 * Note: This method internally uses caching, so multiple calls per request are efficient.
	 *
	 * @return array|null
	 */
	private function get_seo_data_for_list_page(): ?array {
		$profile_info = $this->get_profile_info();

		if (
			get_post_type() !== Page_Template_Manager::POST_TYPE
			|| empty( $profile_info['base'] )
			|| ! empty( $profile_info['slug'] )
		) {
			return null;
		}

		$config = Profile_Collections::get_instance()->get( $profile_info['base'] );

		if ( ! $config ) {
			return null;
		}

		$base_path = Rewrite_Rule_Registrar::get_instance()->get_base_path();

		return array(
			'title' => $config['name'],
			'url'   => sprintf( '/%s/%s/', $base_path, $config['slug'] ),
		);
	}

	/**
	 * Filter document title for profile list pages.
	 *
	 * @param string $title Title.
	 *
	 * @return string
	 */
	public function filter_document_title_for_list_page( string $title ): string {
		$seo_data = $this->get_seo_data_for_list_page();

		if ( ! empty( $seo_data['title'] ) ) {
			return wp_strip_all_tags( $seo_data['title'] );
		}

		return $title;
	}

	/**
	 * Filter canonical URL for profile list pages.
	 *
	 * @param string|null $canonical_url The canonical URL.
	 *
	 * @return string|null
	 */
	public function filter_canonical_url_for_list_page( ?string $canonical_url ): ?string {
		$seo_data = $this->get_seo_data_for_list_page();

		if ( empty( $seo_data['url'] ) ) {
			return $canonical_url;
		}

		return home_url( $seo_data['url'] );
	}
}

<?php
/**
 * REST surface for the standalone-mode Settings screen.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin;

use Newspack_Newsletters;
use Newspack_Newsletters_Settings;
use WP_Error;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Settings REST controller.
 */
class Settings_REST {
	const API_NAMESPACE = 'newspack-newsletters/v1';
	const ROUTE         = 'admin-shell/settings';

	/**
	 * Credential fields exposed to the React shell, keyed by provider
	 * slug. Excludes long-lived OAuth secrets (Constant Contact's
	 * `access_token` / `refresh_token`) — those never leave the server.
	 */
	const PROVIDER_CREDENTIAL_ALLOWLIST = [
		'mailchimp'        => [ 'api_key' ],
		'constant_contact' => [ 'api_key', 'api_secret' ],
		'active_campaign'  => [ 'url', 'key' ],
	];

	/**
	 * Settings-list option keys managed by the credentials section.
	 * Skipped by the options schema so provider-scoped *non-credential*
	 * entries (e.g. `newspack_mailchimp_auto_append_footer`) still surface.
	 */
	const PROVIDER_CREDENTIAL_OPTION_KEYS = [
		'newspack_mailchimp_api_key',
		'newspack_newsletters_constant_contact_api_key',
		'newspack_newsletters_constant_contact_api_secret',
		'newspack_newsletters_active_campaign_url',
		'newspack_newsletters_active_campaign_key',
	];

	const OAUTH_STATE_CACHE_TTL = 60;

	/**
	 * Boot hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'newspack_newsletters_provider_credentials_changed', [ __CLASS__, 'bust_oauth_cache' ] );
	}

	/**
	 * Register the GET/POST pair.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/' . self::ROUTE,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'get_settings' ],
					'permission_callback' => [ __CLASS__, 'permission_check' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ __CLASS__, 'update_settings' ],
					'permission_callback' => [ __CLASS__, 'permission_check' ],
					'args'                => [
						'provider' => [
							'type' => 'object',
						],
						'options'  => [
							'type' => 'object',
						],
					],
				],
			]
		);
	}

	/**
	 * Capability gate.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return bool|\WP_Error
	 */
	public static function permission_check( $request ) {
		return Newspack_Newsletters::api_administration_permissions_check( $request );
	}

	/**
	 * Return the aggregated payload for a fresh page mount.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public static function get_settings( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return rest_ensure_response( self::build_payload() );
	}

	/**
	 * Persist provider/credentials/options from the payload and return the refreshed view.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_settings( $request ) {
		$errors = new WP_Error();

		$provider_payload = $request->get_param( 'provider' );
		if ( is_array( $provider_payload ) && array_key_exists( 'slug', $provider_payload ) ) {
			$slug          = is_string( $provider_payload['slug'] ) ? $provider_payload['slug'] : '';
			$previous_slug = Newspack_Newsletters::service_provider();
			$valid_slugs   = Newspack_Newsletters::get_supported_providers();
			if ( '' === $slug ) {
				$errors->add(
					'newspack_newsletters_no_service_provider',
					__( 'Please select a newsletter service provider.', 'newspack-newsletters' ),
					[ 'status' => 400 ]
				);
			} elseif ( ! in_array( $slug, $valid_slugs, true ) ) {
				$errors->add(
					'newspack_newsletters_invalid_provider',
					__( 'Unknown service provider.', 'newspack-newsletters' ),
					[ 'status' => 400 ]
				);
			} elseif ( 'manual' === $slug ) {
				Newspack_Newsletters::set_service_provider( $slug );
				self::bust_oauth_cache( $previous_slug );
				self::bust_oauth_cache( $slug );
			} else {
				// Resolve the provider without committing the option — only flip on credentials success
				// so a rejection can't leave the site pointing at an unconfigured provider.
				$provider = Newspack_Newsletters::get_service_provider_instance( $slug );
				if ( ! $provider || ! method_exists( $provider, 'set_api_credentials' ) ) {
					$errors->add(
						'newspack_newsletters_provider_unavailable',
						__( 'The selected service provider is not available on this site.', 'newspack-newsletters' ),
						[ 'status' => 400 ]
					);
				} else {
					$credentials = isset( $provider_payload['credentials'] ) && is_array( $provider_payload['credentials'] )
						? $provider_payload['credentials']
						: [];
					// Validate the merged result, not the raw payload — a no-op save (no fields touched)
					// is legal on an already-configured provider.
					$merged = self::merge_credentials( $slug, $credentials, $provider );
					if ( empty( $merged ) ) {
						$errors->add(
							'newspack_newsletters_invalid_keys',
							__( 'Please input credentials.', 'newspack-newsletters' ),
							[ 'status' => 400 ]
						);
					} else {
						$result = $provider->set_api_credentials( $merged );
						if ( is_wp_error( $result ) ) {
							foreach ( $result->errors as $code => $messages ) {
								$errors->add( $code, implode( ' ', $messages ), [ 'status' => 400 ] );
							}
						} else {
							Newspack_Newsletters::set_service_provider( $slug );
							self::bust_oauth_cache( $previous_slug );
							self::bust_oauth_cache( $slug );
						}
					}
				}
			}
		}

		// If the provider half of the payload errored, don't proceed to
		// options — partial-write semantics (provider rejected, options
		// committed) are confusing for the client.
		if ( $errors->has_errors() ) {
			return $errors;
		}

		$options_payload = $request->get_param( 'options' );
		if ( is_array( $options_payload ) ) {
			$schema   = self::get_options_schema();
			$to_write = [];
			foreach ( $options_payload as $key => $value ) {
				if ( ! isset( $schema[ $key ] ) ) {
					continue;
				}
				$to_write[ $key ] = self::sanitize_option_value( $value, $schema[ $key ] );
			}
			if ( ! empty( $to_write ) ) {
				Newspack_Newsletters_Settings::update_settings( $to_write );
			}
		}

		return rest_ensure_response( self::build_payload() );
	}

	/**
	 * Aggregate provider state, supported providers, OAuth state, and options.
	 *
	 * @return array
	 */
	private static function build_payload(): array {
		$provider_slug = Newspack_Newsletters::service_provider();
		$provider      = Newspack_Newsletters::get_service_provider();

		$credentials_set = [];
		$has_creds       = false;
		if ( $provider && method_exists( $provider, 'api_credentials' ) ) {
			$credentials_set = self::credentials_set_flags( $provider_slug, $provider->api_credentials() );
			if ( method_exists( $provider, 'has_api_credentials' ) ) {
				$has_creds = (bool) $provider->has_api_credentials();
			}
		}

		$is_manual = 'manual' === $provider_slug;
		$status    = $is_manual || ( $provider && $has_creds );

		$oauth = self::resolve_oauth_state( $provider, $provider_slug );

		$schema  = self::get_options_schema();
		$options = [];
		foreach ( $schema as $key => $field ) {
			$options[ $key ] = get_option( $key, $field['default'] );
			if ( 'checkbox' === $field['type'] ) {
				$options[ $key ] = (bool) $options[ $key ];
			}
		}

		// Strip the raw `sanitize` callable before shipping the schema —
		// the client doesn't use it, and a filterable settings list can
		// return a non-JSON-encodable callable (e.g. a Closure) that
		// would break the REST response.
		$client_schema = array_values(
			array_map(
				function ( $field ) {
					unset( $field['sanitize'] );
					return $field;
				},
				$schema
			)
		);

		$lists_can_add_local = (
			class_exists( '\Newspack\Newsletters\Subscription_Lists' )
			&& 'manual' !== $provider_slug
			&& Newspack_Newsletters::is_service_provider_configured()
			&& $provider
			&& ! empty( $provider::$support_local_lists )
		);

		return [
			'provider'            => [
				'selected'        => $provider_slug ? $provider_slug : '',
				'credentials_set' => $credentials_set,
				'status'          => (bool) $status,
				'oauth'           => $oauth,
			],
			'providers'           => self::get_provider_choices(),
			'options'             => $options,
			'schema'              => $client_schema,
			'lists_can_add_local' => (bool) $lists_can_add_local,
		];
	}

	/**
	 * Resolve the provider's OAuth state.
	 *
	 * `valid` is short-cached; `auth_url` is rebuilt every request
	 * because its `wp_create_nonce` is session-token-scoped — caching
	 * it would leak nonces across users.
	 *
	 * @param object|null $provider      Active provider instance.
	 * @param string      $provider_slug Provider slug.
	 * @return array|null
	 */
	private static function resolve_oauth_state( $provider, $provider_slug ): ?array {
		if ( ! $provider || ! method_exists( $provider, 'verify_token' ) ) {
			return null;
		}

		$valid    = self::resolve_oauth_validity( $provider, $provider_slug );
		$auth_url = method_exists( $provider, 'get_oauth_auth_url' ) ? (string) $provider->get_oauth_auth_url() : '';

		return [
			'valid'    => (bool) $valid,
			'auth_url' => $auth_url ? esc_url_raw( $auth_url ) : '',
		];
	}

	/**
	 * Read or compute the validity flag for the active provider, caching
	 * the result so back-to-back GETs share one verify call.
	 *
	 * @param object $provider      Provider instance.
	 * @param string $provider_slug Provider slug.
	 * @return bool
	 */
	private static function resolve_oauth_validity( $provider, $provider_slug ): bool {
		$cache_key = self::oauth_cache_key( $provider_slug );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && array_key_exists( 'valid', $cached ) ) {
			return (bool) $cached['valid'];
		}
		$token = $provider->verify_token( true );
		$valid = is_array( $token ) && ! empty( $token['valid'] );
		set_transient( $cache_key, [ 'valid' => $valid ], self::OAUTH_STATE_CACHE_TTL );
		return $valid;
	}

	/**
	 * Drop the cached OAuth validity. Called after a successful provider
	 * switch or credentials update so the next GET reflects the change.
	 *
	 * @param string $provider_slug Provider slug.
	 */
	public static function bust_oauth_cache( $provider_slug ): void {
		if ( $provider_slug ) {
			delete_transient( self::oauth_cache_key( $provider_slug ) );
		}
	}

	/**
	 * Transient key for the cached OAuth validity (site-global).
	 *
	 * @param string $provider_slug Provider slug.
	 * @return string Transient key.
	 */
	private static function oauth_cache_key( $provider_slug ): string {
		return 'newspack_newsletters_oauth_valid_' . sanitize_key( $provider_slug );
	}

	/**
	 * Build the provider selector choices.
	 *
	 * @return array
	 */
	private static function get_provider_choices(): array {
		$choices = [
			[
				'slug' => '',
				'name' => __( 'Select service provider', 'newspack-newsletters' ),
			],
		];

		$supported = Newspack_Newsletters::get_supported_providers();
		foreach ( Newspack_Newsletters::get_registered_providers() as $slug => $config ) {
			if ( ! in_array( $slug, $supported, true ) ) {
				continue;
			}
			$choices[] = [
				'slug' => $slug,
				'name' => $config['name'],
			];
		}

		$choices[] = [
			'slug' => 'manual',
			'name' => __( 'Manual / Other', 'newspack-newsletters' ),
		];

		return $choices;
	}

	/**
	 * Build the options whitelist plus tracking keys (which live outside `get_settings_list()`).
	 *
	 * @return array
	 */
	private static function get_options_schema(): array {
		// Render order: cross-cutting options first, then provider-scoped
		// extras (e.g. Mailchimp footer toggle), then tracking — keeps the
		// always-relevant settings together at the top of the section.
		$cross_cutting = [];
		$provider_scoped = [];

		foreach ( Newspack_Newsletters_Settings::get_settings_list() as $entry ) {
			$key = isset( $entry['key'] ) ? $entry['key'] : null;
			if ( ! $key || 'newspack_newsletters_service_provider' === $key ) {
				continue;
			}
			if ( in_array( $key, self::PROVIDER_CREDENTIAL_OPTION_KEYS, true ) ) {
				continue;
			}
			$type = isset( $entry['type'] ) ? $entry['type'] : 'text';
			if ( in_array( $type, [ 'boolean', 'bool' ], true ) ) {
				$type = 'checkbox';
			}
			$entry_provider = isset( $entry['provider'] ) ? $entry['provider'] : '';
			$schema_entry   = [
				'key'         => $key,
				'label'       => isset( $entry['description'] ) ? $entry['description'] : $key,
				'type'        => $type,
				'default'     => array_key_exists( 'default', $entry ) ? $entry['default'] : '',
				'help'        => isset( $entry['help'] ) ? $entry['help'] : '',
				'help_url'    => isset( $entry['helpURL'] ) && is_string( $entry['helpURL'] ) ? esc_url_raw( $entry['helpURL'] ) : '',
				'placeholder' => isset( $entry['placeholder'] ) ? $entry['placeholder'] : '',
				'provider'    => $entry_provider,
				'sanitize'    => isset( $entry['sanitize_callback'] ) && is_callable( $entry['sanitize_callback'] )
					? $entry['sanitize_callback']
					: null,
			];
			if ( '' === $entry_provider ) {
				$cross_cutting[ $key ] = $schema_entry;
			} else {
				$provider_scoped[ $key ] = $schema_entry;
			}
		}

		$schema = $cross_cutting + $provider_scoped;

		$schema['newspack_newsletters_use_tracking_pixel'] = [
			'key'         => 'newspack_newsletters_use_tracking_pixel',
			'label'       => __( 'Track the impressions of ads in your newsletter', 'newspack-newsletters' ),
			'type'        => 'checkbox',
			'default'     => true,
			'help'        => '',
			'help_url'    => '',
			'placeholder' => '',
			'provider'    => '',
			'sanitize'    => 'boolval',
		];
		$schema['newspack_newsletters_use_click_tracking'] = [
			'key'         => 'newspack_newsletters_use_click_tracking',
			'label'       => __( 'Track the clicks on ads in your newsletter', 'newspack-newsletters' ),
			'type'        => 'checkbox',
			'default'     => true,
			'help'        => '',
			'help_url'    => '',
			'placeholder' => '',
			'provider'    => '',
			'sanitize'    => 'boolval',
		];

		return $schema;
	}

	/**
	 * Map of credential-field → bool indicating which fields have a stored
	 * value. Credentials themselves never leave the server — the React
	 * shell uses these flags to render a "(set; leave blank to keep)"
	 * affordance and only POSTs new values when the user types them.
	 *
	 * @param string $slug        Provider slug.
	 * @param mixed  $credentials Raw `api_credentials()` payload.
	 * @return array
	 */
	private static function credentials_set_flags( $slug, $credentials ): array {
		$allowlist = self::PROVIDER_CREDENTIAL_ALLOWLIST[ $slug ] ?? [];
		$flags     = [];
		foreach ( $allowlist as $field ) {
			$value         = is_array( $credentials ) && isset( $credentials[ $field ] ) ? $credentials[ $field ] : '';
			$flags[ $field ] = '' !== (string) $value;
		}
		return $flags;
	}

	/**
	 * Merge submitted credential fields with the provider's stored values
	 * so a partial update (only the field the user actually typed into)
	 * doesn't blank out the rest. Empty / missing fields fall back to the
	 * existing stored value.
	 *
	 * @param string $slug        Provider slug.
	 * @param array  $submitted   Credential fields posted by the client.
	 * @param object $provider    The active service-provider instance.
	 * @return array
	 */
	private static function merge_credentials( $slug, $submitted, $provider ): array {
		$allowlist = self::PROVIDER_CREDENTIAL_ALLOWLIST[ $slug ] ?? [];
		if ( empty( $allowlist ) ) {
			return is_array( $submitted ) ? $submitted : [];
		}
		$existing = method_exists( $provider, 'api_credentials' ) ? $provider->api_credentials() : [];
		$existing = is_array( $existing ) ? $existing : [];
		$merged   = [];
		foreach ( $allowlist as $field ) {
			// Match the classic settings flow: `register_setting` runs each
			// value through `sanitize_text_field`. Apply the same here so
			// providers that `update_option()` directly (Constant Contact /
			// ActiveCampaign) don't store whitespace-only or otherwise
			// unsanitised input via the REST path.
			$incoming = is_array( $submitted ) && isset( $submitted[ $field ] )
				? sanitize_text_field( (string) $submitted[ $field ] )
				: '';
			if ( '' !== $incoming ) {
				$merged[ $field ] = $incoming;
				continue;
			}
			if ( isset( $existing[ $field ] ) ) {
				$merged[ $field ] = $existing[ $field ];
			}
		}
		return $merged;
	}

	/**
	 * Sanitise an option value against its schema entry.
	 *
	 * @param mixed $value Incoming value.
	 * @param array $field Schema entry.
	 * @return mixed
	 */
	private static function sanitize_option_value( $value, $field ) {
		// Coerce checkboxes to int 0/1 regardless of any custom sanitize
		// callable. `update_option( …, false )` short-circuits on a fresh
		// site (no row, default also `false`), which would leave a
		// default-true checkbox unable to be switched off — `get_option`
		// would read the default back as `true`. Storing `0` writes a
		// real row that survives subsequent reads.
		if ( 'checkbox' === $field['type'] ) {
			return (int) boolval( $value );
		}
		// Normalise to a scalar string before any callable runs — REST
		// input can arrive as an array/object, and callables like
		// `sanitize_title` warn or misbehave on non-scalar input.
		$scalar = is_scalar( $value ) ? (string) $value : '';
		if ( ! empty( $field['sanitize'] ) && is_callable( $field['sanitize'] ) ) {
			return call_user_func( $field['sanitize'], $scalar );
		}
		return sanitize_text_field( $scalar );
	}
}

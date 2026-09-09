<?php
/**
 * Newspack Content Gate email verification prompt.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Prompts a denied reader to verify their email when that is all that stands
 * between them and the article.
 *
 * The email-domain access rule grants access only to a reader who has verified
 * their address, so a reader on a whitelisted domain who registered without
 * verifying sees the ordinary paywall and no indication of why. This class spots
 * that state at gate-render time and prepends a verification prompt to the gate,
 * above the layout — which keeps the layout's own subscribe CTA as the reader's
 * alternative.
 *
 * The rule itself is untouched: verifying is what grants access, and a reader who
 * does not verify is denied exactly as they would be without this class.
 */
class Email_Verification_Prompt {

	/**
	 * Per-request memo of prompt contexts, keyed by post ID, gate ID and reader ID.
	 * `false` records a reader already found ineligible there, so the rule walk and the
	 * hypothetical evaluation run at most once per combination per request.
	 *
	 * The reader is part of the key because the context carries their address, and the
	 * post because the verdict answers whether *that* post opens: a process resolving
	 * the same gate for several readers or several posts — a CLI worker, a REST
	 * callback iterating a list — would otherwise read back another one's verdict.
	 *
	 * @var array<string,array|false>
	 */
	private static array $context_cache = [];

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		\add_filter( 'newspack_gate_layout_content', [ __CLASS__, 'prepend_prompt' ], 10, 2 );
	}

	/**
	 * Prepend the verification prompt to the gate layout content.
	 *
	 * Hooked on the raw layout content rather than the rendered gate HTML so the
	 * prompt lands inside the gate wrapper for both the inline and the overlay
	 * layout, above content the reader was already going to see.
	 *
	 * @param string $content        The gate layout content.
	 * @param int    $gate_layout_id The gate layout ID.
	 *
	 * @return string
	 */
	public static function prepend_prompt( $content, $gate_layout_id ): string {
		$content = (string) $content;
		if ( Content_Gate\Gate_Preview::is_preview_request() ) {
			return $content;
		}
		// Another layout on the page (a preview, a second gate rendered by a third
		// party) is not the gate that denied this reader.
		if ( (int) $gate_layout_id !== (int) Content_Gate::get_gate_layout_id() ) {
			return $content;
		}
		$prompt_context = self::get_prompt_context();
		if ( ! $prompt_context ) {
			return $content;
		}
		return self::render( $prompt_context ) . $content;
	}

	/**
	 * Resolve the prompt context for the gate denying the current reader.
	 *
	 * Cheap before expensive. The first stage walks the post's gates for a domain the
	 * reader's address matches, which rules out every visitor but the handful the
	 * prompt is for. Only those reach the second, which asks what the reader would see
	 * if they verified — the question the prompt is about to make a promise about, and
	 * one a single rule cannot answer: rules are ANDed within a group, and a post can
	 * be covered by several gates in priority order, so verification can satisfy the
	 * email-domain rule and leave the reader on a paywall either way.
	 *
	 * @return array|false {
	 *     The prompt context, or false when no prompt should render.
	 *
	 *     @type string[] $institutions Names of the institutions the reader's verified
	 *                                  address would grant access through. Empty when
	 *                                  the route in is a gate's own email-domain rule
	 *                                  rather than an institution.
	 *     @type string   $email        The reader's email address.
	 * }
	 */
	public static function get_prompt_context() {
		if ( ! Content_Gate::is_gating_active() || Memberships::is_active() ) {
			return false;
		}
		// The prompt hands the reader to the auth modal to enter their code. Where that
		// modal is filtered off the site, sending a code strands them with nowhere to
		// type it.
		if ( ! Reader_Activation::should_render_auth_modal() ) {
			return false;
		}
		if ( ! \is_user_logged_in() ) {
			return false;
		}
		$user = \wp_get_current_user();
		if ( ! Reader_Activation::is_user_reader( $user ) || Reader_Activation::is_reader_verified( $user ) ) {
			return false;
		}
		$gate_id = (int) Content_Gate::get_gate_post_id();
		if ( ! $gate_id ) {
			return false;
		}
		$post_id = (int) \get_queried_object_id();
		if ( ! $post_id ) {
			return false;
		}
		$cache_key = $post_id . '_' . $gate_id . '_' . $user->ID;
		if ( isset( self::$context_cache[ $cache_key ] ) ) {
			return self::$context_cache[ $cache_key ];
		}
		// Seeded before the build, not after: the build runs the whole
		// `newspack_is_post_restricted` chain, and a callback on it that reaches back
		// here would find no memo and recurse.
		self::$context_cache[ $cache_key ] = false;
		self::$context_cache[ $cache_key ] = self::build_prompt_context( $user, $post_id );
		return self::$context_cache[ $cache_key ];
	}

	/**
	 * Build the prompt context for a reader and post, uncached.
	 *
	 * Every gate covering the post is walked, not only the one that denied. The gate a
	 * reader is stopped at need not be the one their address would let them past: a
	 * registration gate walling verification can deny at priority 1 while an
	 * institution gate at priority 2 is the route in, and the same act of verifying
	 * clears both.
	 *
	 * @param \WP_User $user    The reader.
	 * @param int      $post_id The post they were denied.
	 *
	 * @return array|false
	 */
	private static function build_prompt_context( \WP_User $user, int $post_id ) {
		$matching_groups = [];
		foreach ( Content_Restriction_Control::get_post_gates( $post_id ) as $gate ) {
			$custom_access = $gate['custom_access'] ?? [];
			if ( empty( $custom_access['active'] ) || empty( $custom_access['access_rules'] ) ) {
				continue;
			}
			$matching_groups = array_merge(
				$matching_groups,
				self::get_domain_matching_groups(
					$user->user_email,
					$custom_access['access_rules'],
					$custom_access['payment_recovery_grace'] ?? true
				)
			);
		}
		if ( empty( $matching_groups ) ) {
			return false;
		}

		$institutions = Access_Rules::with_assumed_verification(
			$user->ID,
			function () use ( $user, $post_id, $matching_groups ) {
				return self::get_unlocking_institution_names( $user, $post_id, $matching_groups );
			}
		);
		if ( null === $institutions ) {
			return false;
		}

		return [
			'institutions' => $institutions,
			'email'        => $user->user_email,
		];
	}

	/**
	 * Find the rule groups on a gate whose domain lists the reader's address matches.
	 *
	 * Deliberately ignores verification — that is the whole point of the prompt — and
	 * reads the domain lists straight off the rules rather than through the rule
	 * callbacks, which apply the verification requirement this stage has to look past.
	 * `Institution`'s own matching helpers are wrong here for the same reason, and
	 * because they memoise a result that GA4 labels and ESP contact metadata read back
	 * as fact.
	 *
	 * @param string $email                   The reader's email address.
	 * @param array  $access_rules            The gate's access rules, in grouped format.
	 * @param bool   $payment_recovery_grace  The gate's payment recovery grace setting, carried
	 *                                        on each group so the hypothetical evaluates it
	 *                                        under the settings of the gate it came from.
	 *
	 * @return array<int,array{rules: array, institutions: string[], payment_recovery_grace: bool}>
	 *         One entry per group holding a rule the address matches, carrying that group's
	 *         rules and the names of the matched institutions (empty for a bare email-domain
	 *         rule).
	 */
	private static function get_domain_matching_groups( string $email, array $access_rules, bool $payment_recovery_grace ): array {
		// Read lazily: a gate whose rules are all non-institution never touches the
		// transient, which keeps the cheap stage cheap for the readers it rules out.
		$institutions    = null;
		$matching_groups = [];

		foreach ( Access_Rules::normalize_rules( $access_rules ) as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$matched = false;
			$names   = [];
			foreach ( $group as $rule ) {
				$slug = $rule['slug'] ?? '';
				if ( 'email_domain' === $slug ) {
					if ( ! empty( $rule['value'] ) && Access_Rules::email_matches_domains( $email, $rule['value'] ) ) {
						$matched = true;
					}
					continue;
				}
				if ( 'institution' !== $slug || empty( $rule['value'] ) || ! is_array( $rule['value'] ) ) {
					continue;
				}
				if ( null === $institutions ) {
					$institutions = Institution::get_cached_institutions();
				}
				foreach ( $rule['value'] as $institution_id ) {
					$institution_id = absint( $institution_id );
					if ( empty( $institutions[ $institution_id ]['email_domain'] ) ) {
						continue;
					}
					if ( ! Access_Rules::email_matches_domains( $email, $institutions[ $institution_id ]['email_domain'] ) ) {
						continue;
					}
					$matched = true;
					$name    = Institution::get_decoded_name( $institution_id );
					if ( '' !== $name ) {
						$names[] = $name;
					}
				}
			}
			if ( $matched ) {
				$matching_groups[] = [
					'rules'                  => $group,
					'institutions'           => $names,
					'payment_recovery_grace' => $payment_recovery_grace,
				];
			}
		}

		return $matching_groups;
	}

	/**
	 * The institutions a verified address would actually let the reader in through.
	 *
	 * Must run inside {@see Access_Rules::with_assumed_verification()}. A group is a
	 * route in only if it passes as a whole, so a group that ANDs the matched rule with
	 * one the reader still fails contributes no names — and if no group passes, or
	 * another gate on the post denies regardless, there is no route in at all.
	 *
	 * @param \WP_User $user            The reader.
	 * @param int      $post_id         The post they were denied.
	 * @param array    $matching_groups Groups from {@see self::get_domain_matching_groups()}.
	 *
	 * @return string[]|null Institution names (possibly empty, for a bare email-domain
	 *                       rule), or null when verifying would not unlock the article.
	 */
	private static function get_unlocking_institution_names( \WP_User $user, int $post_id, array $matching_groups ): ?array {
		$names   = [];
		$unlocks = false;

		foreach ( $matching_groups as $group ) {
			$rule_context = [ 'payment_recovery_grace' => $group['payment_recovery_grace'] ];
			if ( ! Access_Rules::evaluate_rules( [ $group['rules'] ], $user->ID, $rule_context ) ) {
				continue;
			}
			$unlocks = true;
			$names   = array_merge( $names, $group['institutions'] );
		}

		if ( ! $unlocks || self::is_post_restricted_hypothetically( $post_id ) ) {
			return null;
		}

		$names = array_values( array_unique( $names ) );
		sort( $names, SORT_NATURAL | SORT_FLAG_CASE );
		return $names;
	}

	/**
	 * Whether the post would still be restricted with the assumption in place.
	 *
	 * Re-runs the whole restriction decision, not just this gate's: a post can be
	 * covered by several gates, and the check stops at the first that denies, so a
	 * lower-priority gate the reader also fails stays invisible until the gate above it
	 * starts granting. Isolated from the request's own gate resolution so the
	 * hypothetical neither reads it nor replaces it.
	 *
	 * @param int $post_id The post to re-evaluate.
	 *
	 * @return bool
	 */
	private static function is_post_restricted_hypothetically( int $post_id ): bool {
		// No post is an unanswerable hypothetical, and the prompt's promise is that this
		// article opens. Answer "still restricted" so the promise is never made on a
		// check that did not run.
		if ( ! $post_id ) {
			return true;
		}
		return (bool) Content_Restriction_Control::with_gate_resolution_isolated(
			function () use ( $post_id ) {
				return Content_Gate::is_post_restricted( $post_id );
			}
		);
	}

	/**
	 * Render the prompt markup.
	 *
	 * The nonce rides on the element rather than the gate's localized script data
	 * because the prompt is decided while the gate renders, after the script payload
	 * has been assembled. It authorizes the same OTP request the registration block's
	 * inline verification box makes, which is not gated on the site's post-registration
	 * verification setting — so the prompt works whether or not a publisher has that
	 * setting on.
	 *
	 * Returned on one line: the gate content pipeline leaves `wpautop` in place for a
	 * layout carrying no block markup, and the icon SVG spans several lines.
	 *
	 * @param array $prompt_context Prompt context from get_prompt_context().
	 *
	 * @return string
	 */
	private static function render( array $prompt_context ): string {
		ob_start();
		?>
		<div
			class="newspack-content-gate__verification-prompt newspack-ui"
			data-verification-url="<?php echo \esc_url( \admin_url( 'admin-ajax.php' ) ); ?>"
			data-verification-nonce="<?php echo \esc_attr( \wp_create_nonce( 'newspack_reader_registration_verification' ) ); ?>"
			data-error-message="<?php \esc_attr_e( 'Something went wrong. Please try again.', 'newspack-plugin' ); ?>"
		>
			<div class="newspack-ui__box newspack-ui__box--x-large newspack-ui__box--text-center">
				<span class="newspack-ui__icon newspack-ui__icon--neutral">
					<?php Newspack_UI_Icons::print_svg( 'login' ); ?>
				</span>
				<h2><?php echo \esc_html( self::get_headline( $prompt_context['institutions'] ) ); ?></h2>
				<p>
					<?php
					echo \wp_kses_post(
						sprintf(
							// translators: %s is the reader's email address.
							__( 'We\'ll send a verification code to %s.', 'newspack-plugin' ),
							'<strong class="email-address">' . \esc_html( $prompt_context['email'] ) . '</strong>'
						)
					);
					?>
				</p>
				<?php // Errors land in their own paragraph, so the line naming the reader's address survives a failed send and still orients them on the retry. ?>
				<p data-error-target role="status" hidden></p>
				<p>
					<button type="button" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide" data-send-otp>
						<?php \esc_html_e( 'Send code', 'newspack-plugin' ); ?>
					</button>
				</p>
			</div>
		</div>
		<?php
		return trim( preg_replace( '/\s*\R\s*/', ' ', (string) ob_get_clean() ) );
	}

	/**
	 * The prompt's headline, naming the institutions that grant access.
	 *
	 * Several institutions are alternatives — any one of them lets the reader in — so
	 * they are joined with "or" rather than `wp_sprintf( '%l' )`'s "and".
	 *
	 * @param string[] $institutions Institution names.
	 *
	 * @return string
	 */
	private static function get_headline( array $institutions ): string {
		if ( empty( $institutions ) ) {
			return __( 'Your email address gives you access to this article. Verify it to keep reading.', 'newspack-plugin' );
		}
		return sprintf(
			// translators: %s is the name of one institution, or a list of institution names.
			__( 'Your %s email address gives you access to this article. Verify it to keep reading.', 'newspack-plugin' ),
			self::join_alternatives( $institutions )
		);
	}

	/**
	 * Join names into an "A, B or C" list.
	 *
	 * @param string[] $names Names to join.
	 *
	 * @return string
	 */
	private static function join_alternatives( array $names ): string {
		if ( count( $names ) < 2 ) {
			return (string) reset( $names );
		}
		$last = array_pop( $names );
		return sprintf(
			// translators: 1: a comma-separated list of institution names. 2: the last institution name.
			__( '%1$s or %2$s', 'newspack-plugin' ),
			implode( ', ', $names ),
			$last
		);
	}

	/**
	 * Reset the per-request context cache. For tests and long-running workers.
	 */
	public static function reset_cache(): void {
		self::$context_cache = [];
	}
}
Email_Verification_Prompt::init();

<?php
/**
 * Service Provider: MailPoet campaign sync.
 *
 * MailPoet has no campaign API, so this class drives its Doctrine entities
 * directly through the plugin's DI container. All of that coupling lives here
 * rather than in the provider, because none of it carries a compatibility
 * promise and it is the first thing to check when MailPoet updates.
 *
 * Two ways to give MailPoet the newsletter body, selectable via the
 * `newspack_newsletters_mailpoet_sync_strategy` filter:
 *
 * - `html` (default) — push the HTML our renderer already produced into a single
 *   MailPoet text block. Works whichever renderer the site uses.
 * - `wp_post` — attach our Newsletter post to the MailPoet newsletter, so
 *   MailPoet renders our blocks itself through the shared WooCommerce email
 *   editor engine. Needs the WC renderer enabled; see Email_Renderers\Feature_Flag.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and updates MailPoet newsletters from Newspack newsletter posts.
 */
class Newspack_Newsletters_Mailpoet_Campaigns {

	/**
	 * Post meta holding the MailPoet newsletter ID for a Newspack newsletter.
	 */
	const CAMPAIGN_ID_META = 'mailpoet_newsletter_id';

	/**
	 * Post meta holding the mirrored `mailpoet_email` post ID, for the
	 * `mailpoet_post` strategy.
	 */
	const MIRROR_POST_META = 'mailpoet_email_post_id';

	/**
	 * Option holding the chosen sync strategy.
	 */
	const STRATEGY_OPTION = 'newspack_newsletters_mailpoet_sync_strategy';

	/**
	 * MailPoet's post type for its block-based email editor.
	 */
	const MAILPOET_EMAIL_CPT = 'mailpoet_email';

	/**
	 * The default strategy.
	 */
	const DEFAULT_STRATEGY = 'mailpoet_post';

	/**
	 * Available strategies, with the labels shown in settings.
	 *
	 * @return array
	 */
	public static function get_strategies() {
		return [
			'mailpoet_post' => __( 'Edit in MailPoet — copies the newsletter into a MailPoet email', 'newspack-newsletters' ),
			'wp_post'       => __( 'Edit in Newspack — MailPoet sends this newsletter directly', 'newspack-newsletters' ),
		];
	}

	/**
	 * Whether MailPoet's entity layer is reachable.
	 *
	 * @return boolean
	 */
	public static function is_available() {
		return class_exists( '\MailPoet\DI\ContainerWrapper' )
			&& class_exists( '\MailPoet\Entities\NewsletterEntity' );
	}

	/**
	 * Get the body strategy in use.
	 *
	 * Set in Newsletters settings when MailPoet is the provider. `html` is not
	 * offered there — it leaves MailPoet with no attached post, which sends the
	 * publisher into MailPoet's legacy page builder — but remains reachable
	 * through the filter for a site without the WC renderer.
	 *
	 * @return string One of 'mailpoet_post', 'wp_post' or 'html'.
	 */
	public static function get_strategy() {
		$strategy = get_option( self::STRATEGY_OPTION, self::DEFAULT_STRATEGY );

		/**
		 * Filters how the newsletter body reaches MailPoet.
		 *
		 * @param string $strategy One of 'mailpoet_post' (mirror into a MailPoet
		 *                         email post), 'wp_post' (attach our own post) or
		 *                         'html' (push rendered HTML into a text block).
		 */
		$strategy = apply_filters( 'newspack_newsletters_mailpoet_sync_strategy', $strategy );

		return in_array( $strategy, [ 'mailpoet_post', 'wp_post', 'html' ], true )
			? $strategy
			: self::DEFAULT_STRATEGY;
	}

	/**
	 * Get MailPoet's newsletters repository.
	 *
	 * @return \MailPoet\Newsletter\NewslettersRepository|WP_Error
	 */
	private static function get_repository() {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'newspack_newsletters_mailpoet_unavailable',
				__( 'MailPoet is not available.', 'newspack-newsletters' )
			);
		}
		try {
			return \MailPoet\DI\ContainerWrapper::getInstance()->get( \MailPoet\Newsletter\NewslettersRepository::class );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'newspack_newsletters_mailpoet_container_error', $e->getMessage() );
		}
	}

	/**
	 * Find a MailPoet newsletter by ID.
	 *
	 * @param int $newsletter_id MailPoet newsletter ID.
	 *
	 * @return \MailPoet\Entities\NewsletterEntity|null
	 */
	public static function find( $newsletter_id ) {
		$repo = self::get_repository();
		if ( is_wp_error( $repo ) || ! $newsletter_id ) {
			return null;
		}
		try {
			return $repo->findOneById( (int) $newsletter_id );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Create or update the MailPoet newsletter for a Newspack newsletter post.
	 *
	 * @param \WP_Post $post The Newspack newsletter post.
	 * @param array    $args {
	 *     Campaign data.
	 *
	 *     @type string $subject      Email subject line.
	 *     @type string $campaign_name Internal name shown in MailPoet.
	 *     @type string $sender_name  Sender name.
	 *     @type string $sender_email Sender email address.
	 *     @type string $html         Rendered email HTML (used by the 'html' strategy).
	 * }
	 *
	 * @return int|WP_Error MailPoet newsletter ID, or error.
	 */
	public static function upsert( $post, $args ) {
		$repo = self::get_repository();
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}

		$newsletter_id = (int) get_post_meta( $post->ID, self::CAMPAIGN_ID_META, true );
		$newsletter    = self::find( $newsletter_id );
		$is_new        = ! $newsletter instanceof \MailPoet\Entities\NewsletterEntity;

		try {
			if ( $is_new ) {
				$newsletter = new \MailPoet\Entities\NewsletterEntity();
				$newsletter->setType( \MailPoet\Entities\NewsletterEntity::TYPE_STANDARD );
				$newsletter->setStatus( \MailPoet\Entities\NewsletterEntity::STATUS_DRAFT );
				$newsletter->setHash( \MailPoet\Util\Security::generateHash( 12 ) );
			}

			// A newsletter MailPoet has already sent must not be rewritten.
			if ( ! $is_new && \MailPoet\Entities\NewsletterEntity::STATUS_SENT === $newsletter->getStatus() ) {
				return new WP_Error(
					'newspack_newsletters_mailpoet_already_sent',
					__( 'This newsletter has already been sent from MailPoet and can no longer be updated.', 'newspack-newsletters' )
				);
			}

			$newsletter->setSubject( $args['subject'] );
			if ( ! empty( $args['sender_name'] ) ) {
				$newsletter->setSenderName( $args['sender_name'] );
			}
			if ( ! empty( $args['sender_email'] ) ) {
				$newsletter->setSenderAddress( $args['sender_email'] );
			}

			$applied = self::apply_body( $newsletter, $post, $args );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}

			$repo->persist( $newsletter );
			$repo->flush();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'newspack_newsletters_mailpoet_sync_failed', $e->getMessage() );
		}

		$newsletter_id = (int) $newsletter->getId();
		update_post_meta( $post->ID, self::CAMPAIGN_ID_META, $newsletter_id );
		return $newsletter_id;
	}

	/**
	 * Put the newsletter body onto the entity, per the active strategy.
	 *
	 * @param \MailPoet\Entities\NewsletterEntity $newsletter The MailPoet newsletter.
	 * @param \WP_Post                            $post       The Newspack newsletter post.
	 * @param array                               $args       Campaign data.
	 *
	 * @return true|WP_Error
	 */
	private static function apply_body( $newsletter, $post, $args ) {
		switch ( self::get_strategy() ) {
			case 'mailpoet_post':
				$mirror_id = self::sync_mirror_post( $post );
				if ( is_wp_error( $mirror_id ) ) {
					return $mirror_id;
				}
				return self::attach_post_id( $newsletter, $mirror_id );
			case 'wp_post':
				return self::attach_post_id( $newsletter, $post->ID );
			default:
				$newsletter->setBody( self::build_html_body( $args['html'] ?? '' ) );
				return true;
		}
	}

	/**
	 * Create or update the `mailpoet_email` post mirroring a Newspack newsletter.
	 *
	 * MailPoet routes the edit link to whatever post a newsletter has attached, so
	 * attaching one of its own post type is what makes its editor open on its own
	 * screen. Our newsletter stays the source of truth and the mirror is rewritten
	 * on every sync.
	 *
	 * The content written here is passed through
	 * `newspack_newsletters_newsletter_content` first, which is what inserts
	 * automatically placed ads. MailPoet never calls our renderers, so without
	 * this the mirror would carry only manually inserted ad blocks.
	 *
	 * @param \WP_Post $post The Newspack newsletter post.
	 *
	 * @return int|WP_Error The mirror post ID.
	 */
	private static function sync_mirror_post( $post ) {
		if ( ! post_type_exists( self::MAILPOET_EMAIL_CPT ) ) {
			return new WP_Error(
				'newspack_newsletters_mailpoet_no_email_cpt',
				__( "MailPoet's email post type is unavailable. Its new email editor may need enabling.", 'newspack-newsletters' )
			);
		}

		// Ads::$inserted_ads is process-global and the renderer will already have
		// run in this request, marking each ad inserted. Without a reset the filter
		// below drops every ad silently, exactly as a repeated render would.
		if ( class_exists( '\Newspack_Newsletters\Ads' ) && method_exists( '\Newspack_Newsletters\Ads', 'reset_inserted_ads' ) ) {
			\Newspack_Newsletters\Ads::reset_inserted_ads( $post->ID );
		}

		$content = (string) apply_filters( 'newspack_newsletters_newsletter_content', $post->post_content, $post );

		$mirror_id = (int) get_post_meta( $post->ID, self::MIRROR_POST_META, true );
		$mirror    = $mirror_id ? get_post( $mirror_id ) : null;

		$postarr = [
			'post_type'    => self::MAILPOET_EMAIL_CPT,
			'post_status'  => 'draft',
			'post_title'   => $post->post_title,
			'post_content' => $content,
		];

		if ( $mirror instanceof \WP_Post && self::MAILPOET_EMAIL_CPT === $mirror->post_type ) {
			$postarr['ID'] = $mirror->ID;
			$result        = wp_update_post( $postarr, true );
		} else {
			// Either never mirrored, or the mirror was deleted in MailPoet.
			$result = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_post_meta( $post->ID, self::MIRROR_POST_META, (int) $result );
		return (int) $result;
	}

	/**
	 * Point a MailPoet newsletter at a WordPress post.
	 *
	 * MailPoet's renderer branches on the presence of a post and, when there is
	 * one, renders it with the WooCommerce email editor engine — the same engine
	 * our WC renderer path uses, so our blocks render through their own email
	 * renderers.
	 *
	 * @param \MailPoet\Entities\NewsletterEntity $newsletter The MailPoet newsletter.
	 * @param int                                 $post_id    Post to attach.
	 *
	 * @return true|WP_Error
	 */
	private static function attach_post_id( $newsletter, $post_id ) {
		if ( ! class_exists( '\MailPoet\Entities\WpPostEntity' ) ) {
			return new WP_Error(
				'newspack_newsletters_mailpoet_no_wp_post_entity',
				__( 'This version of MailPoet does not support post-backed emails.', 'newspack-newsletters' )
			);
		}
		try {
			$entity_manager = \MailPoet\DI\ContainerWrapper::getInstance()->get( \MailPoetVendor\Doctrine\ORM\EntityManager::class );
			$newsletter->setWpPost( $entity_manager->getReference( \MailPoet\Entities\WpPostEntity::class, $post_id ) );
			// The body still has to be valid JSON for MailPoet's own screens,
			// even though the renderer takes the post path.
			$newsletter->setBody( [] );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'newspack_newsletters_mailpoet_attach_failed', $e->getMessage() );
		}
		return true;
	}

	/**
	 * Wrap rendered HTML in the minimal MailPoet body structure.
	 *
	 * MailPoet stores a block tree rather than HTML, and has no raw-HTML block.
	 * A single text block is the closest fit: its content passes through the
	 * renderer with markup, attributes and inline styles intact.
	 *
	 * @param string $html Rendered email HTML.
	 *
	 * @return array MailPoet body structure.
	 */
	private static function build_html_body( $html ) {
		return [
			'content'      => [
				'type'         => 'container',
				'columnLayout' => false,
				'orientation'  => 'vertical',
				'image'        => false,
				'styles'       => [ 'block' => [ 'backgroundColor' => 'transparent' ] ],
				'blocks'       => [
					[
						'type'        => 'container',
						'orientation' => 'horizontal',
						'image'       => false,
						'styles'      => [ 'block' => [ 'backgroundColor' => 'transparent' ] ],
						'blocks'      => [
							[
								'type'        => 'container',
								'orientation' => 'vertical',
								'image'       => false,
								'styles'      => [ 'block' => [ 'backgroundColor' => 'transparent' ] ],
								'blocks'      => [
									[
										'type' => 'text',
										'text' => $html,
									],
								],
							],
						],
					],
				],
			],
			'globalStyles' => [
				'text'    => [
					'fontColor'  => '#000000',
					'fontFamily' => 'Arial',
					'fontSize'   => '16px',
				],
				'body'    => [ 'backgroundColor' => '#ffffff' ],
				'link'    => [
					'fontColor'      => '#21759b',
					'textDecoration' => 'underline',
				],
				'wrapper' => [ 'backgroundColor' => '#ffffff' ],
			],
		];
	}

	/**
	 * Delete the MailPoet newsletter for a Newspack newsletter post.
	 *
	 * @param int $post_id The Newspack newsletter post ID.
	 *
	 * @return true|WP_Error
	 */
	public static function delete_for_post( $post_id ) {
		$repo = self::get_repository();
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}
		$newsletter = self::find( get_post_meta( $post_id, self::CAMPAIGN_ID_META, true ) );
		if ( ! $newsletter instanceof \MailPoet\Entities\NewsletterEntity ) {
			return true;
		}
		try {
			$repo->remove( $newsletter );
			$repo->flush();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'newspack_newsletters_mailpoet_delete_failed', $e->getMessage() );
		}
		delete_post_meta( $post_id, self::CAMPAIGN_ID_META );

		// The mirror exists only to back the campaign, so it goes with it.
		$mirror_id = (int) get_post_meta( $post_id, self::MIRROR_POST_META, true );
		if ( $mirror_id && self::MAILPOET_EMAIL_CPT === get_post_type( $mirror_id ) ) {
			wp_delete_post( $mirror_id, true );
		}
		delete_post_meta( $post_id, self::MIRROR_POST_META );

		return true;
	}

	/**
	 * Get the URL for editing a newsletter, matching where MailPoet itself sends
	 * the publisher.
	 *
	 * MailPoet routes to the block editor for the attached post when a newsletter
	 * has one, and to its legacy page builder otherwise.
	 *
	 * @param int $newsletter_id MailPoet newsletter ID.
	 *
	 * @return string
	 */
	public static function get_edit_url( $newsletter_id ) {
		$newsletter = self::find( $newsletter_id );
		$post_id    = $newsletter ? $newsletter->getWpPostId() : null;
		if ( $post_id ) {
			return admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' );
		}
		return admin_url( 'admin.php?page=mailpoet-newsletter-editor&id=' . (int) $newsletter_id );
	}

	/**
	 * Summarise a MailPoet newsletter for the Newspack editor.
	 *
	 * @param \MailPoet\Entities\NewsletterEntity $newsletter The MailPoet newsletter.
	 *
	 * @return array
	 */
	public static function to_array( $newsletter ) {
		return [
			'id'           => (int) $newsletter->getId(),
			'subject'      => $newsletter->getSubject(),
			'status'       => $newsletter->getStatus(),
			'type'         => $newsletter->getType(),
			'sender_name'  => $newsletter->getSenderName(),
			'sender_email' => $newsletter->getSenderAddress(),
			'wp_post_id'   => $newsletter->getWpPostId(),
		];
	}
}

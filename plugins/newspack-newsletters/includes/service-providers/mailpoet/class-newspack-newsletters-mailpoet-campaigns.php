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
	 * @return string Either 'html' or 'wp_post'.
	 */
	public static function get_strategy() {
		/**
		 * Filters how the newsletter body reaches MailPoet.
		 *
		 * @param string $strategy Either 'html' (push rendered HTML) or 'wp_post'
		 *                         (attach our post and let MailPoet render it).
		 */
		$strategy = apply_filters( 'newspack_newsletters_mailpoet_sync_strategy', 'html' );
		return in_array( $strategy, [ 'html', 'wp_post' ], true ) ? $strategy : 'html';
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
		if ( 'wp_post' === self::get_strategy() ) {
			return self::attach_wp_post( $newsletter, $post );
		}
		$newsletter->setBody( self::build_html_body( $args['html'] ?? '' ) );
		return true;
	}

	/**
	 * Attach our Newsletter post to the MailPoet newsletter.
	 *
	 * MailPoet's renderer branches on the presence of a WP post and, when there
	 * is one, renders it with the WooCommerce email editor engine — the same
	 * engine our WC renderer path uses, so our blocks render through their own
	 * email renderers.
	 *
	 * @param \MailPoet\Entities\NewsletterEntity $newsletter The MailPoet newsletter.
	 * @param \WP_Post                            $post       The Newspack newsletter post.
	 *
	 * @return true|WP_Error
	 */
	private static function attach_wp_post( $newsletter, $post ) {
		if ( ! class_exists( '\MailPoet\Entities\WpPostEntity' ) ) {
			return new WP_Error(
				'newspack_newsletters_mailpoet_no_wp_post_entity',
				__( 'This version of MailPoet does not support post-backed emails.', 'newspack-newsletters' )
			);
		}
		try {
			$entity_manager = \MailPoet\DI\ContainerWrapper::getInstance()->get( \MailPoetVendor\Doctrine\ORM\EntityManager::class );
			$newsletter->setWpPost( $entity_manager->getReference( \MailPoet\Entities\WpPostEntity::class, $post->ID ) );
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
		return true;
	}

	/**
	 * Get the MailPoet admin URL for editing a newsletter.
	 *
	 * @param int $newsletter_id MailPoet newsletter ID.
	 *
	 * @return string
	 */
	public static function get_edit_url( $newsletter_id ) {
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

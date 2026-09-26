<?php
/**
 * Gravity Forms integration.
 *
 * Registers readers from Gravity Forms submissions. The way in is a
 * "Register readers" toggle on the Gravity Forms block: the block attribute
 * is carried to the page as the newspack-form-capture class on the form
 * tag, which the capture script matches. A form placed without the block
 * opts in by carrying the class through its CSS Class Name setting, a route
 * the help docs cover; that setting applies to every placement of the form,
 * whatever each block's toggle says. There are no settings.
 *
 * Capture works as in Form Capture, which this extends, through the
 * same script: Gravity Forms forms register under this integration and every
 * other form under that one, so each integration's switch covers its own
 * forms. Gravity Forms submits every form through programmatic
 * HTMLFormElement.submit(), which dispatches no submit event, so the script
 * captures it through Gravity Forms' own submission filter bus instead.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Integrations;

use Newspack\Newspack;
use Newspack\Plugin_Manager;
use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Gravity Forms integration class.
 */
class Gravity_Forms extends Form_Capture {
	/**
	 * The integration ID.
	 */
	const ID = 'gravity-forms';

	/**
	 * Key of this integration's entry in the capture script's config: the
	 * forms it covers.
	 */
	const SCRIPT_CONFIG_KEY = 'gravity_forms';

	/**
	 * Context of the contact sync a capture of an existing reader schedules.
	 */
	const SYNC_CONTEXT = 'Gravity Forms registration (existing reader)';

	/**
	 * Context of the contact sync a capture of a new reader triggers.
	 */
	const NEW_READER_SYNC_CONTEXT = 'Gravity Forms registration';

	/**
	 * Handle for the block editor extension that adds the toggle to the
	 * Gravity Forms block.
	 */
	const EDITOR_SCRIPT_HANDLE = 'newspack-form-capture-editor';

	/**
	 * Gravity Forms block attribute that opts a placement into capture. Declared
	 * in the editor extension and registered with GF's block schema here.
	 */
	const BLOCK_ATTRIBUTE = 'newspackFormCapture';

	/**
	 * Option set once maybe_enable_on_upgrade() has run on a site.
	 */
	const UPGRADE_OPTION = 'newspack_reader_activation_gravity_forms_upgraded';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			__( 'Gravity Forms', 'newspack-plugin' ),
			__( 'Register readers from Gravity Forms submissions.', 'newspack-plugin' )
		);
	}

	/**
	 * Enable this integration on a site upgrading with Form Capture
	 * enabled and Gravity Forms active. Form Capture used to capture
	 * Gravity Forms forms, which belong to this integration now and would stop
	 * registering readers without it. Without Gravity Forms active there were
	 * no such forms, and a card whose required plugin is uninstalled offers no
	 * Disable. Runs once per site, whatever it finds, so a later Disable
	 * sticks and enabling Form Capture afterwards does not bring this
	 * one along.
	 */
	public function maybe_enable_on_upgrade() {
		if ( \get_option( self::UPGRADE_OPTION ) ) {
			return;
		}
		\update_option( self::UPGRADE_OPTION, true );
		if ( Integrations::is_enabled( Form_Capture::ID ) && $this->is_gravity_forms_active() ) {
			Integrations::enable( self::ID );
		}
	}

	/**
	 * Register hooks: the capture hooks, plus the block toggle.
	 */
	public function register_handlers() {
		parent::register_handlers();
		// GF applies this filter while constructing its block on `init` at
		// priority 10; integrations register at priority 5, so it is in place.
		\add_filter( 'gform_form_block_attributes', [ $this, 'register_block_attribute' ] );
		// After GF's own render filter at 10, which relocates custom-CSS classes.
		\add_filter( 'render_block_gravityforms/form', [ $this, 'mark_captured_block_form' ], 20, 2 );
		\add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
	}

	/**
	 * Register settings fields.
	 *
	 * None: forms opt in through the block toggle or the marker class, so the
	 * Integrations card offers no settings page.
	 *
	 * @return array Array of settings field declarations.
	 */
	public function register_settings_fields() {
		return [];
	}

	/**
	 * The how-to the Integrations card opens from its How it works menu item.
	 * The toggle lives in the block editor, so the guide has to say where to
	 * look and what opting a form in commits the publisher to. The last step
	 * links the help page, which covers forms placed without the block.
	 *
	 * @return array List of associative arrays with keys `title`, `description`, and an optional `link` (`label`, `url`).
	 */
	public function get_guide(): array {
		return [
			[
				'title'       => __( 'Add the form with the Gravity Forms block', 'newspack-plugin' ),
				'description' => __( 'Place the form on a page, post, or prompt with the Gravity Forms block.', 'newspack-plugin' ),
			],
			[
				'title'       => __( 'Turn on Register readers in the block settings', 'newspack-plugin' ),
				'description' => __( 'Select the block, open its settings sidebar, and switch on Register readers under Newspack. The switch belongs to the block, so the same form can register readers in one place and not in another.', 'newspack-plugin' ),
			],
			[
				'title'       => __( 'Submissions register readers', 'newspack-plugin' ),
				'description' => __( 'Each submission registers a reader account with the submitted email address and name, or updates the existing reader without emailing a login link. Only turn this on for forms whose submissions should always create a reader account: registration happens as the form is submitted, so a submission Gravity Forms later rejects has still registered the reader. Never turn it on for a form that collects someone else\'s email address.', 'newspack-plugin' ),
				'link'        => [
					'label' => __( 'Learn how to opt in forms placed without the block', 'newspack-plugin' ),
					'url'   => 'https://help.newspack.com/integrations/gravity-forms/',
				],
			],
		];
	}

	/**
	 * Gravity Forms is the way in: the toggle lives in its block, so without
	 * it the card would offer an Enable that captures nothing. Reported in the
	 * shape the Integrations card reads (see Integration::get_required_plugins()).
	 *
	 * @return array List of associative arrays with keys `slug`, `name`, `is_active`, `is_installed`.
	 */
	public function get_required_plugins() {
		$status = Plugin_Manager::get_managed_plugin_status( 'gravityforms' );
		return [
			[
				'slug'         => 'gravityforms',
				'name'         => __( 'Gravity Forms', 'newspack-plugin' ),
				'is_active'    => 'active' === $status,
				'is_installed' => 'uninstalled' !== $status,
			],
		];
	}

	/**
	 * Whether Gravity Forms is active. The capture switch reads this on every
	 * front-end request, which is why it checks for Gravity Forms' main class
	 * instead of Plugin_Manager's status lookup, a scan of the installed plugins.
	 *
	 * @return bool
	 */
	protected function is_gravity_forms_active() {
		return class_exists( 'GFForms' );
	}

	/**
	 * Frontend registration additionally needs Gravity Forms active. A site
	 * that removes Gravity Forms after enabling would otherwise keep
	 * registering readers behind a card that requires Gravity Forms and, with
	 * it uninstalled, offers no Disable.
	 *
	 * @return bool
	 */
	public function supports_frontend_registration(): bool {
		return $this->is_gravity_forms_active() && parent::supports_frontend_registration();
	}

	/**
	 * The selectors the capture script matches: the marker class alone, which
	 * the block toggle adds and a form's CSS Class Name setting can carry. The
	 * selectors saved for Form Capture cover other tools' forms.
	 *
	 * @return string[] CSS selectors.
	 */
	public function get_selectors() {
		return [ '.' . self::MARKER_CLASS ];
	}

	/**
	 * Load the block editor extension wherever the Gravity Forms block can be
	 * placed. Gated on Gravity Forms alone, without which there is no block to
	 * extend. Neither the integration nor Reader Activation gates it: the
	 * panel is where a placement opts in, so the toggle stays visible and
	 * saves while either is off, and the notice reports through `active` that
	 * a toggled form registers nobody yet.
	 */
	public function enqueue_editor_assets() {
		if ( ! $this->is_gravity_forms_active() ) {
			return;
		}
		$asset_file   = NEWSPACK_ABSPATH . 'dist/form-capture-editor.asset.php';
		$asset        = file_exists( $asset_file ) ? include $asset_file : [];
		$dependencies = $asset['dependencies'] ?? [ 'react-jsx-runtime', 'wp-block-editor', 'wp-components', 'wp-hooks', 'wp-i18n' ];
		\wp_enqueue_script(
			self::EDITOR_SCRIPT_HANDLE,
			Newspack::plugin_url() . '/dist/form-capture-editor.js',
			$dependencies,
			Newspack::asset_version( 'form-capture-editor' ),
			true
		);
		\wp_localize_script(
			self::EDITOR_SCRIPT_HANDLE,
			'newspack_form_capture_editor',
			[
				'active'           => Reader_Activation::is_enabled() && $this->supports_frontend_registration(),
				'integrations_url' => \admin_url( 'admin.php?page=newspack-audience-integrations' ),
			]
		);
	}

	/**
	 * Register the capture toggle with Gravity Forms' block schema.
	 *
	 * As of Gravity Forms 3.1 this filter feeds both halves of its block. The
	 * server-side registration, which keeps only each attribute's type, is
	 * what the REST block renderer validates the editor preview against, so
	 * without it every preview of a toggled block fails. The editor config,
	 * which keeps the default too, is where GF's block script takes its
	 * attributes from, so it is also what keeps the toggle through a save.
	 *
	 * @param array $attributes Block attributes declared by Gravity Forms.
	 *
	 * @return array Attributes with the capture toggle declared.
	 */
	public function register_block_attribute( $attributes ) {
		$attributes[ self::BLOCK_ATTRIBUTE ] = [
			'type'    => 'boolean',
			'default' => false,
		];
		return $attributes;
	}

	/**
	 * Carry the block toggle to the page as the marker class on the form tag,
	 * which is what the capture script matches. Runs whether or not the
	 * integration is enabled: the class is inert on its own and the script is
	 * the switch, so a placement's markup does not change with the setting.
	 *
	 * @param string $content The rendered block HTML.
	 * @param array  $block   The parsed block, including its attributes.
	 *
	 * @return string The block HTML, with the form tag marked when opted in.
	 */
	public function mark_captured_block_form( $content, $block ) {
		if ( empty( $block['attrs'][ self::BLOCK_ATTRIBUTE ] ) ) {
			return $content;
		}
		$tags = new \WP_HTML_Tag_Processor( $content );
		if ( ! $tags->next_tag( 'form' ) ) {
			return $content;
		}
		$tags->add_class( self::MARKER_CLASS );
		return $tags->get_updated_html();
	}
}

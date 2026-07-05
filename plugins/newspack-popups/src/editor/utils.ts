import { __, sprintf, _n } from '@wordpress/i18n';

/**
 * The prompt (popup) custom post type's registered meta fields, as declared by
 * `Newspack_Popups::register_meta()`. Most fields carry a schema `default`
 * (or WordPress' own type zero-value when none is set), so the block editor
 * always populates them once the post's meta has loaded -- consistent with
 * how the sidebar components below read them directly, with no fallback.
 * `post_types`/`archive_page_types`/`excluded_categories`/`excluded_tags`/
 * `additional_classes`/`expiration_date` are marked optional because the
 * components that read them explicitly default them when destructuring.
 */
export interface PromptMeta {
	/** The full meta object returned by `getEditedPostAttribute( 'meta' )` may carry other registered post meta too. */
	[ key: string ]: unknown;
	trigger_type: string;
	trigger_scroll_progress: number;
	trigger_blocks_count: number;
	archive_insertion_posts_count: number;
	archive_insertion_is_repeating: boolean;
	trigger_delay: number;
	frequency: string;
	frequency_max: number;
	frequency_start: number;
	frequency_between: number;
	frequency_reset: string;
	placement: string;
	utm_suppression: string;
	background_color: string;
	close_button_background_color: string;
	enable_close_button_background: boolean;
	overlay_color: string;
	overlay_opacity: number;
	overlay_size: string;
	no_overlay_background: boolean;
	hide_border: boolean;
	large_border: boolean;
	no_padding: boolean;
	duplicate_of: number;
	post_types?: string[];
	archive_page_types?: string[];
	additional_classes?: string;
	excluded_categories?: number[];
	excluded_tags?: number[];
	/** `null` is a deliberate, distinct "cleared" value written by `ExpirationPanel`; not part of the PHP-registered schema type. */
	expiration_date?: string | null;
	activation_date: string;
	deactivation_date: string;
}

/**
 * Fields selected out of the post's meta by `promptEditorPropsSelector`, plus
 * the derived/other-attribute fields it adds. Injected into the composed
 * sidebar components alongside `PromptEditorDispatchProps`.
 */
export type PromptEditorSelectProps = Pick<
	PromptMeta,
	| 'background_color'
	| 'hide_border'
	| 'large_border'
	| 'no_padding'
	| 'frequency'
	| 'frequency_max'
	| 'frequency_start'
	| 'frequency_between'
	| 'frequency_reset'
	| 'close_button_background_color'
	| 'enable_close_button_background'
	| 'overlay_color'
	| 'overlay_opacity'
	| 'no_overlay_background'
	| 'placement'
	| 'trigger_type'
	| 'trigger_delay'
	| 'trigger_scroll_progress'
	| 'trigger_blocks_count'
	| 'archive_insertion_posts_count'
	| 'archive_insertion_is_repeating'
	| 'utm_suppression'
	| 'post_types'
	| 'archive_page_types'
	| 'additional_classes'
	| 'excluded_categories'
	| 'excluded_tags'
	| 'expiration_date'
> & {
	featured_image_id: unknown;
	/** `'full'` is renamed to `'full-width'`; otherwise passed through as-is. */
	overlay_size: string;
	isOverlay: boolean;
	postStatus: unknown;
};

/**
 * A notice-creation dispatcher, as returned by `dispatch( 'core/notices' )`'s
 * `createNotice`. Only the members this unit's components actually call are
 * declared (the real action creator accepts more optional notice options).
 */
export type CreateNotice = (
	status: string,
	content: string,
	options?: { id?: string; isDismissible?: boolean }
) => Promise< { notice: { id: string } } >;

/**
 * Props injected by `mapDispatchToProps` (`src/editor/index.tsx`). A `type`
 * alias (not `interface`) so the `mapDispatchToProps as Parameters<typeof
 * withDispatch>[0]` cast there is checked structurally against a plain
 * object type (TS treats `interface` casts more conservatively).
 */
export type PromptEditorDispatchProps = {
	onMetaFieldChange: ( metaToUpdate: Partial< PromptMeta > ) => void;
	createNotice: CreateNotice;
	removeNotice: ( id: string ) => void;
};

/** Combined props injected into each sidebar component via `compose()`. */
export type PromptEditorProps = PromptEditorSelectProps & PromptEditorDispatchProps;

/**
 * The subset of `@wordpress/data`'s `select( 'core/editor' )` selectors this
 * unit's components call. `@wordpress/data` ships no usable types for the
 * string-keyed store registry, so the shape is re-declared here at the call
 * site.
 */
export interface CoreEditorSelectors {
	getEditedPostAttribute: ( attribute: string ) => unknown;
}

/**
 * Data selector for popup options (stored in post meta)
 *
 * @param {Function} select Select function
 */
export const promptEditorPropsSelector = ( select: ( store: 'core/editor' ) => CoreEditorSelectors ): PromptEditorSelectProps => {
	const { getEditedPostAttribute } = select( 'core/editor' );
	// `getEditedPostAttribute` returns `unknown` (the untyped `@wordpress/data`
	// selector boundary); the prompt CPT's meta shape is `PromptMeta`.
	const meta = ( getEditedPostAttribute( 'meta' ) as PromptMeta | undefined ) || ( {} as PromptMeta );
	const {
		background_color,
		frequency,
		frequency_max,
		frequency_start,
		frequency_between,
		frequency_reset,
		hide_border,
		large_border,
		no_padding,
		close_button_background_color,
		enable_close_button_background,
		overlay_color,
		overlay_opacity,
		overlay_size,
		no_overlay_background,
		placement,
		trigger_type,
		trigger_delay,
		trigger_scroll_progress,
		trigger_blocks_count,
		archive_insertion_posts_count,
		archive_insertion_is_repeating,
		utm_suppression,
		post_types,
		archive_page_types,
		additional_classes,
		excluded_categories,
		excluded_tags,
		expiration_date,
	} = meta;

	const isOverlay = isOverlayPlacement( placement );
	const postStatus = select( 'core/editor' ).getEditedPostAttribute( 'status' );
	const featured_image_id = getEditedPostAttribute( 'featured_media' );

	return {
		background_color,
		hide_border,
		large_border,
		no_padding,
		frequency,
		frequency_max,
		frequency_start,
		frequency_between,
		frequency_reset,
		close_button_background_color,
		enable_close_button_background,
		overlay_color,
		overlay_opacity,
		featured_image_id,
		overlay_size: 'full' === overlay_size ? 'full-width' : overlay_size,
		no_overlay_background,
		placement,
		trigger_type,
		trigger_delay,
		trigger_scroll_progress,
		trigger_blocks_count,
		archive_insertion_posts_count,
		archive_insertion_is_repeating,
		utm_suppression,
		isOverlay,
		post_types,
		archive_page_types,
		additional_classes,
		excluded_categories,
		excluded_tags,
		expiration_date,
		postStatus,
	};
};

/**
 * Convert hex color to RGB.
 * From https://stackoverflow.com/questions/5623838/rgb-to-hex-and-hex-to-rgb
 *
 * @param {string} hex Color in HEX format
 */
const hexToRGB = ( hex: string ): number[] =>
	hex
		.replace( /^#?([a-f\d])([a-f\d])([a-f\d])$/i, ( m, r, g, b ) => '#' + r + r + g + g + b + b )
		.substring( 1 )
		.match( /.{2}/g )!
		.map( x => parseInt( x, 16 ) );

const EDITOR_CANVAS_SELECTOR = 'iframe[name="editor-canvas"]';

/**
 * Get the document context for the block editor. In WordPress 7.0+, the editor
 * canvas is rendered inside an iframe, so querySelector on the parent document
 * will not find editor elements. Falls back to the parent document for older
 * versions, or when the iframe's contentDocument is not yet available (null).
 *
 * TODO: Once WP 6.9 is no longer supported, this can be simplified to always
 * return the iframe's contentDocument.
 *
 * @return {Document} The editor's document context.
 */
export const getEditorDocument = (): Document => {
	const iframe = document.querySelector< HTMLIFrameElement >( EDITOR_CANVAS_SELECTOR );
	return iframe?.contentDocument ?? document; // TODO: Remove `?? document` fallback when WP 6.9 support is dropped.
};

/**
 * Calls `callback` once the editor canvas is available and returns a cleanup
 * function suitable for use as a useEffect return value. Handles three cases:
 *
 * 1. Editor-canvas iframe exists and is fully loaded, or (WP 6.9 fallback) the
 *    non-iframe editor is already rendered in the parent document — calls the
 *    callback immediately.
 * 2. Editor-canvas iframe exists but has not finished loading — waits for the
 *    load event, then calls the callback.
 * 3. Editor-canvas iframe has not been inserted yet — observes the DOM for its
 *    insertion, then waits for its load event.
 *
 * Limitation: only fires once per call. If Gutenberg later unmounts and
 * remounts the canvas iframe (e.g. toggling code-editor or fullscreen), the
 * new iframe won't get the callback applied — EditorAdditions stays mounted
 * as a top-level slot fill, so its effects don't re-run on swap. In practice
 * the dependent effects in EditorAdditions reapply the glue whenever
 * `background_color`, `overlay_size`, or `placement` change.
 *
 * TODO: Once WP 6.9 is no longer supported this can be simplified to cases 2/3
 * only (the iframe is always used for the editor canvas).
 *
 * @param {Function} callback Function to invoke when the editor canvas is ready.
 * @return {Function} Cleanup function that removes any pending listeners/observers.
 */
export const whenEditorReady = ( callback: () => void ): ( () => void ) => {
	const iframe = document.querySelector< HTMLIFrameElement >( EDITOR_CANVAS_SELECTOR );
	if ( iframe ) {
		// Case 1: iframe exists and is fully loaded.
		if ( iframe.contentDocument?.readyState === 'complete' ) {
			callback();
			return () => {};
		}
		// Case 2: iframe exists but hasn't finished loading.
		iframe.addEventListener( 'load', callback, { once: true } );
		return () => iframe.removeEventListener( 'load', callback );
	}

	// WP 6.9 fallback: no iframe, editor renders directly in the parent document.
	if ( document.querySelector( '.editor-styles-wrapper' ) ) {
		callback();
		return () => {};
	}

	// Case 3: iframe hasn't been inserted yet — watch for it.
	let capturedIframe: HTMLIFrameElement | null = null;
	const observer = new MutationObserver( () => {
		const newIframe = document.querySelector< HTMLIFrameElement >( EDITOR_CANVAS_SELECTOR );
		if ( newIframe ) {
			observer.disconnect();
			capturedIframe = newIframe;
			newIframe.addEventListener( 'load', callback, { once: true } );
		}
	} );
	observer.observe( document.body, { childList: true, subtree: true } );
	return () => {
		observer.disconnect();
		if ( capturedIframe ) {
			capturedIframe.removeEventListener( 'load', callback );
		}
	};
};

/**
 * Set the background color meta field.
 * Based on https://github.com/Automattic/newspack-theme/blob/trunk/newspack-theme/inc/template-functions.php#L401-L431
 *
 * @param {string} backgroundColor color string
 */
export const updateEditorColors = ( backgroundColor: string ): void => {
	if ( ! backgroundColor ) {
		return;
	}
	const blackColor = '#000000';
	const whiteColor = '#ffffff';

	const backgroundColorRGB = hexToRGB( backgroundColor );
	const blackRGB = hexToRGB( blackColor );

	const l1 =
		0.2126 * Math.pow( backgroundColorRGB[ 0 ] / 255, 2.2 ) +
		0.7152 * Math.pow( backgroundColorRGB[ 1 ] / 255, 2.2 ) +
		0.0722 * Math.pow( backgroundColorRGB[ 2 ] / 255, 2.2 );
	const l2 =
		0.2126 * Math.pow( blackRGB[ 0 ] / 255, 2.2 ) + 0.7152 * Math.pow( blackRGB[ 1 ] / 255, 2.2 ) + 0.0722 * Math.pow( blackRGB[ 2 ] / 255, 2.2 );

	const contrastRatio = l1 > l2 ? parseInt( String( ( l1 + 0.05 ) / ( l2 + 0.05 ) ) ) : parseInt( String( ( l2 + 0.05 ) / ( l1 + 0.05 ) ) );

	const foregroundColor = contrastRatio > 5 ? blackColor : whiteColor;

	const editorDoc = getEditorDocument();
	const editorStylesEl = editorDoc.querySelector< HTMLElement >( '.editor-styles-wrapper' );
	const editorPostTitleEl = editorDoc.querySelector< HTMLElement >( '.wp-block.editor-post-title__block .editor-post-title__input' );
	if ( editorStylesEl ) {
		editorStylesEl.style.backgroundColor = backgroundColor;
		editorStylesEl.style.color = foregroundColor;
	}
	if ( editorPostTitleEl ) {
		editorPostTitleEl.style.color = foregroundColor;
		editorPostTitleEl.style.setProperty( '--newspack-popups-editor-placeholder-color', `${ foregroundColor }80` );
	}
};

/**
 * Is the given placement value an overlay placement?
 *
 * @param {string} placementValue Placement of the prompt.
 * @return {boolean} Whether or not the prompt has an overlay placement.
 */
export const isOverlayPlacement = ( placementValue: string ): boolean => {
	const overlayPlacements = window.newspack_popups_data?.overlay_placements || [];
	return -1 < overlayPlacements.indexOf( placementValue );
};

/**
 * Is the placement inline?
 * Not including Custom Placement or Manual-Only prompts.
 *
 * @param {string} placementValue Placement value of the prompt.
 * @return {boolean} True if placementValue is an inline placement.
 */
export const isInlinePlacement = ( placementValue: string ): boolean => -1 < [ 'inline', 'above_header', 'archives' ].indexOf( placementValue );

/**
 * Is the given placement value a custom placement?
 *
 * @param {string} placementValue Placement of the prompt.
 * @return {boolean} Whether or not the prompt has a custom placement.
 */
export const isCustomPlacement = ( placementValue: string ): boolean => {
	const customPlacements = window.newspack_popups_data?.custom_placements || {};
	return -1 < Object.keys( customPlacements ).indexOf( placementValue );
};

/**
 * Is the given placement value a manual-only placement?
 *
 * @param {string} placementValue Placement of the prompt.
 * @return {boolean} Whether or not the prompt is manual-only.
 */
export const isManualOnlyPlacement = ( placementValue: string ): boolean => 'manual' === placementValue;

/**
 * Props consumed by `getPlacementHelpMessage()`: the subset of `PromptMeta`
 * needed to build the help copy for the current placement.
 */
export type PlacementHelpMessageProps = Pick<
	PromptMeta,
	| 'placement'
	| 'trigger_type'
	| 'trigger_blocks_count'
	| 'trigger_scroll_progress'
	| 'archive_insertion_is_repeating'
	| 'archive_insertion_posts_count'
>;

/**
 * Given a placement value, construct a context-sensitive help message to display in the editor sidebar.
 *
 * @return {string} An appropriate help message.
 */
export const getPlacementHelpMessage = ( props: PlacementHelpMessageProps ): string => {
	if ( isCustomPlacement( props.placement ) ) {
		const customPlacements = window.newspack_popups_data?.custom_placements || {};
		return sprintf(
			// translators: %s: custom placement name.
			__( 'The prompt will appear where %s is inserted using the Custom Placement block.', 'newspack-popups' ),
			customPlacements[ props.placement ] || __( 'this custom placement', 'newspack-popups' )
		);
	}

	switch ( props.placement ) {
		case 'center':
			return __( 'The prompt will be displayed as an overlay at the center of the viewport.', 'newspack-popups' );
		case 'center_left':
			return __( 'The prompt will be displayed as an overlay at the center left of the viewport.', 'newspack-popups' );
		case 'center_right':
			return __( 'The prompt will be displayed as an overlay at the center right of the viewport.', 'newspack-popups' );
		case 'top':
			return __( 'The prompt will be displayed as an overlay at the top of the viewport.', 'newspack-popups' );
		case 'top_left':
			return __( 'The prompt will be displayed as an overlay at the top left of the viewport.', 'newspack-popups' );
		case 'top_right':
			return __( 'The prompt will be displayed as an overlay at the top right of the viewport.', 'newspack-popups' );
		case 'bottom':
			return __( 'The prompt will be displayed as an overlay at the bottom of the viewport.', 'newspack-popups' );
		case 'bottom_left':
			return __( 'The prompt will be displayed as an overlay at the bottom left of the viewport.', 'newspack-popups' );
		case 'bottom_right':
			return __( 'The prompt will be displayed as an overlay at the bottom right of the viewport.', 'newspack-popups' );
		case 'above_header':
			return __( 'The prompt will be automatically inserted at the very top of the page, above the header.', 'newspack-popups' );
		case 'inline':
			return props.trigger_type === 'blocks_count'
				? sprintf(
						// translators: %s: blocks count until insertion.
						_n(
							'The prompt will be automatically inserted after %s block of content.',
							'The prompt will be automatically inserted after %s blocks of content.',
							props.trigger_blocks_count,
							'newspack-popups'
						),
						String( props.trigger_blocks_count )
				  )
				: sprintf(
						// translators: %s: article percentage count until insertion.
						__( 'The prompt will be automatically inserted about %s into article content.', 'newspack-popups' ),
						props.trigger_scroll_progress + '%'
				  );
		case 'archives':
			return props.archive_insertion_is_repeating
				? sprintf(
						// translators: %d: insertion period.
						__( 'The prompt will be automatically inserted every %d articles in the archive pages.', 'newspack-popups' ),
						props.archive_insertion_posts_count
				  )
				: sprintf(
						// translators: %d: insertion period articles count.
						__( 'The prompt will be automatically inserted after %d articles in the archive pages.', 'newspack-popups' ),
						props.archive_insertion_posts_count
				  );
		case 'manual':
			return __( 'The prompt will appear only where inserted using the Single Prompt block or a shortcode.', 'newspack-popups' );
		default:
			return __( 'The placement where the prompt can appear.', 'newspack-popups' );
	}
};

/**
 * Convert a date object to a string in YYYY-MM-DDTHH:MM:SS format.
 *
 * @param {Date} date
 * @return {string} Date string in Y-m-d H:i:s format or empty string if the given date can't be parsed.
 */
export const convertDateToString = ( date: Date ): string => {
	if ( ! ( date instanceof Date ) ) {
		return '';
	}

	// Format the date string
	const year = date.getFullYear();
	const month = String( date.getMonth() + 1 ).padStart( 2, '0' );
	const day = String( date.getDate() ).padStart( 2, '0' );
	const time = 'T23:59:59'; // Set to the very end of the day.

	return `${ year }-${ month }-${ day }${ time }`;
};

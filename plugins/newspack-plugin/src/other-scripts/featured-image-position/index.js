/**
 * Per-post featured image position, for block themes.
 *
 * The classic theme ships its own control, from
 * `js/src/extend-featured-image-editor.js`, which is not loaded when a block
 * theme is active. Without this a publisher on a block theme cannot reveal an
 * image the site-wide default hides, or hide one post's image.
 *
 * Note on translation: newspack-plugin does not call
 * `wp_set_script_translations()` for any handle and ships no JSON catalogs, so
 * the strings below render in English even on a localized site. The classic
 * theme's equivalent control is translated. Known gap, shared with the rest of
 * the plugin's editor scripts.
 *
 * The options deliberately do not mirror the classic control's. Classic offers
 * six layout positions — large, small, behind, beside, above and hidden — and
 * on a block theme five of them do nothing: where a visible image appears is
 * decided by the post's template, not this setting. Only `hidden` has an
 * effect, so only hiding is offered as a choice.
 */

/**
 * WordPress dependencies.
 */
import { RadioControl } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { Fragment } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { getSelectedOption, getShownValue } from './selected-option';

const META_KEY = 'newspack_featured_image_position';

const PositionControl = () => {
	const { postType, position } = useSelect( select => {
		const editor = select( 'core/editor' );
		const meta = editor.getEditedPostAttribute( 'meta' ) || {};
		return {
			postType: editor.getCurrentPostType(),
			position: meta[ META_KEY ] || '',
		};
	}, [] );

	const { editPost } = useDispatch( 'core/editor' );

	const config = window.newspackFeaturedImagePosition;
	if ( ! config || ! config.postTypes.includes( postType ) ) {
		return null;
	}

	const { siteDefault, fallbackValue, hiddenValue, positions } = config;

	// What "Shown" would write, which is the post's own position when it has one.
	const shownValue = getShownValue( position, siteDefault, hiddenValue, fallbackValue, positions );

	// Derived here, not passed: wp_localize_script() turns a false into "" and a
	// 0 into "0", and "0" is truthy, so a boolean flag could not be trusted.
	const siteHidesImage = siteDefault === hiddenValue;

	const selected = getSelectedOption( position, shownValue, hiddenValue );

	return (
		<RadioControl
			__nextHasNoMarginBottom
			className="newspack-featured-image-position"
			label={ __( 'Featured image', 'newspack-plugin' ) }
			help={ __( 'Where a visible image appears is set by the template. Only hiding it is a per-post choice.', 'newspack-plugin' ) }
			selected={ selected }
			options={ [
				{
					label: siteHidesImage ? __( 'Site default (hidden)', 'newspack-plugin' ) : __( 'Site default (shown)', 'newspack-plugin' ),
					value: '',
				},
				{ label: __( 'Shown', 'newspack-plugin' ), value: shownValue },
				{ label: __( 'Hidden', 'newspack-plugin' ), value: hiddenValue },
			] }
			onChange={ value => editPost( { meta: { [ META_KEY ]: value } } ) }
		/>
	);
};

addFilter( 'editor.PostFeaturedImage', 'newspack/featured-image-position', OriginalComponent =>
	// eslint-disable-next-line react/display-name
	props => (
		<Fragment>
			<OriginalComponent { ...props } />
			<PositionControl />
		</Fragment>
	)
);

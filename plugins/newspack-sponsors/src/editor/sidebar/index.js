/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { SelectControl, TextareaControl, TextControl, ToggleControl } from '@wordpress/components';
import { compose } from '@wordpress/compose';
import { withDispatch, withSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import './style.scss';

const SidebarComponent = props => {
	const { meta, title, updateMetaValue } = props;
	const {
		newspack_sponsor_url,
		newspack_sponsor_flag_override,
		newspack_sponsor_byline_prefix,
		newspack_sponsor_sponsorship_scope,
		newspack_sponsor_native_byline_display,
		newspack_sponsor_native_category_display,
		newspack_sponsor_underwriter_style,
		newspack_sponsor_underwriter_placement,
		newspack_sponsor_only_direct,
		newspack_sponsor_disclaimer_override,
	} = meta;
	const { settings, defaults, post_type: postType, cpt } = window.newspack_sponsors_data;
	const isSponsor = cpt === postType; // True if the post being edited is a sponsor, false if a post type that can be sponsored.

	// If the post is not a sponsor but a post that can be sponsored, add default options to inherit values from the sponsor.
	const scopeDefault = isSponsor ? 'native' : '';
	const scopeOptions = [
		{ value: 'native', label: __( 'Native content', 'newspack-sponsors' ) },
		{ value: 'underwritten', label: __( 'Underwritten content', 'newspack-sponsors' ) },
	];
	const bylineDefault = isSponsor ? 'sponsor' : 'inherit';
	const bylineOptions = [
		{ value: 'sponsor', label: __( 'Sponsor only', 'newspack-sponsors' ) },
		{ value: 'author', label: __( 'Both sponsor and author byline', 'newspack-sponsors' ) },
	];
	const categoryDefault = isSponsor ? 'sponsor' : 'inherit';
	const categoryOptions = [
		{ value: 'sponsor', label: __( 'Sponsor only', 'newspack-sponsors' ) },
		{ value: 'category', label: __( 'Sponsor and categories', 'newspack-sponsors' ) },
	];
	const underwriterStyleDefault = isSponsor ? 'standard' : 'inherit';
	const underwriterStyleOptions = [
		{ value: 'standard', label: __( 'Standard', 'newspack-sponsors' ) },
		{ value: 'simple', label: __( 'Simple', 'newspack-sponsors' ) },
	];
	const underwriterPlacementDefault = isSponsor ? 'top' : 'inherit';
	const underwriterPlacementOptions = [
		{ value: 'top', label: __( 'Top', 'newspack-sponsors' ) },
		{ value: 'bottom', label: __( 'Bottom', 'newspack-sponsors' ) },
	];
	if ( ! isSponsor ) {
		const defaultOption = {
			value: 'inherit',
			label: __( 'Inherit from sponsor', 'newspack-sponsors' ),
		};
		scopeOptions.unshift( defaultOption );
		bylineOptions.unshift( defaultOption );
		categoryOptions.unshift( defaultOption );
		underwriterStyleOptions.unshift( defaultOption );
		underwriterPlacementOptions.unshift( defaultOption );
	}

	return (
		<>
			{ ! isSponsor && (
				<>
					<h2>{ __( 'Sponsor Display Overrides', 'newspack-sponsors' ) }</h2>
					<p>
						<em>{ __( 'The following settings optionally override the settings of assigned sponsors.', 'newspack-sponsors' ) }</em>
					</p>
				</>
			) }
			<SelectControl
				className="newspack-sponsors__select-control"
				label={ __( 'Sponsorship Scope', 'newspack-sponsors' ) }
				value={ newspack_sponsor_sponsorship_scope || scopeDefault }
				options={ scopeOptions }
				onChange={ value => updateMetaValue( 'newspack_sponsor_sponsorship_scope', value ) }
				help={ __(
					'Generally, native content is authored by the sponsor, while underwritten content is authored by editorial staff but supported by the sponsor. This option allows you to select a different visual treatment for native vs. underwitten content.',
					'newspack-sponsors'
				) }
			/>
			{ ( 'native' === newspack_sponsor_sponsorship_scope || ( isSponsor && ! newspack_sponsor_sponsorship_scope ) || ! isSponsor ) && (
				<>
					<SelectControl
						className="newspack-sponsors__select-control"
						label={ __( 'Sponsorship Byline Display', 'newspack-sponsors' ) }
						value={ newspack_sponsor_native_byline_display || bylineDefault }
						options={ bylineOptions }
						onChange={ value => updateMetaValue( 'newspack_sponsor_native_byline_display', value ) }
						help={ __( 'Display the sponsor only in the byline, or both the sponsor and post author.', 'newspack-sponsors' ) }
					/>
					<SelectControl
						className="newspack-sponsors__select-control"
						label={ __( 'Sponsorship Category Display', 'newspack-sponsors' ) }
						value={ newspack_sponsor_native_category_display || categoryDefault }
						options={ categoryOptions }
						onChange={ value => updateMetaValue( 'newspack_sponsor_native_category_display', value ) }
						help={ __( 'Show the sponsor only, or the post’s categories alongside the "sponsored" flag.', 'newspack-sponsors' ) }
					/>
				</>
			) }
			{ ( 'underwritten' === newspack_sponsor_sponsorship_scope || ! isSponsor ) && (
				<>
					<SelectControl
						className="newspack-sponsors__select-control"
						label={ __( 'Underwriter blurb style', 'newspack-sponsors' ) }
						value={ newspack_sponsor_underwriter_style || underwriterStyleDefault }
						options={ underwriterStyleOptions }
						onChange={ value => updateMetaValue( 'newspack_sponsor_underwriter_style', value ) }
						help={ __( 'Show the underwriter blurb in a standard or simplified style.', 'newspack-sponsors' ) }
					/>
					<SelectControl
						className="newspack-sponsors__select-control"
						label={ __( 'Underwriter placement', 'newspack-sponsors' ) }
						value={ newspack_sponsor_underwriter_placement || underwriterPlacementDefault }
						options={ underwriterPlacementOptions }
						onChange={ value => updateMetaValue( 'newspack_sponsor_underwriter_placement', value ) }
						help={ __( 'Show the underwriter blurb at the top or bottom of the post.', 'newspack-sponsors' ) }
					/>
				</>
			) }
			{ isSponsor && (
				<>
					<TextControl
						className="newspack-sponsors__text-control"
						label={ __( 'Sponsor URL', 'newspack-sponsors' ) }
						placeholder={ __( 'URL to link to for this sponsor', 'newspack-sponsors' ) }
						help={ __( 'Required if you want to show a link to an external URL.', 'newspack-sponsors' ) }
						type="url"
						value={ newspack_sponsor_url }
						onChange={ value => updateMetaValue( 'newspack_sponsor_url', value ) }
					/>
					<TextControl
						className="newspack-sponsors__text-control"
						label={ __( 'Sponsor Flag Override (Optional)', 'newspack-sponsors' ) }
						placeholder={ settings.flag || defaults.flag }
						help={ __(
							'The label for the flag that appears in lieu of category flags. If not empty, this field will override the site-wide setting.',
							'newspack-sponsors'
						) }
						type="url"
						value={ newspack_sponsor_flag_override }
						onChange={ value => updateMetaValue( 'newspack_sponsor_flag_override', value ) }
					/>
					<TextareaControl
						className="newspack-sponsors__textarea-control"
						label={ __( 'Sponsor Disclaimer Override (Optional)', 'newspack-sponsors' ) }
						placeholder={ ( settings.disclaimer || defaults.disclaimer ).replace( '[sponsor name]', title ) }
						help={ __(
							'Text shown to explain sponsorship by this sponsor. If not empty, this field will override the site-wide setting.',
							'newspack-sponsors'
						) }
						value={ newspack_sponsor_disclaimer_override }
						onChange={ value => updateMetaValue( 'newspack_sponsor_disclaimer_override', value ) }
					/>
					<TextControl
						className="newspack-sponsors__text-control"
						label={ __( 'Sponsor Byline Prefix (Optional)', 'newspack-sponsors' ) }
						help={ __(
							'The prefix for the sponsor attribution that appears in lieu of author byline. If not empty, this field will override the site-wide setting.',
							'newspack-sponsors'
						) }
						placeholder={ settings.byline || defaults.byline }
						type="url"
						value={ newspack_sponsor_byline_prefix }
						onChange={ value => updateMetaValue( 'newspack_sponsor_byline_prefix', value ) }
					/>
					<ToggleControl
						className="newspack-sponsors__toggle-control"
						label={ __( 'Show on posts only if a direct sponsor?', 'newspack-newsletters' ) }
						help={ __(
							'If this option is enabled, this sponsor will only be shown on single posts if assigned as a direct sponsor. It will still appear on category and tag archive pages, if applicable.'
						) }
						checked={ newspack_sponsor_only_direct }
						onChange={ value => updateMetaValue( 'newspack_sponsor_only_direct', value ) }
					/>
				</>
			) }
		</>
	);
};

const mapStateToProps = select => {
	const { getEditedPostAttribute } = select( 'core/editor' );

	return {
		meta: getEditedPostAttribute( 'meta' ),
		title: getEditedPostAttribute( 'title' ),
	};
};

const mapDispatchToProps = dispatch => {
	const { editPost } = dispatch( 'core/editor' );

	return {
		updateMetaValue: ( key, value ) => editPost( { meta: { [ key ]: value } } ),
	};
};

export const Sidebar = compose( [ withSelect( mapStateToProps ), withDispatch( mapDispatchToProps ) ] )( SidebarComponent );

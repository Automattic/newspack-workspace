import { __ } from '@wordpress/i18n';
import { InspectorControls } from '@wordpress/block-editor';
import {
	BaseControl,
	PanelBody,
	RangeControl,
	ToggleControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { useCallback } from '@wordpress/element';

import { AutocompleteTokenField } from '../../../../packages/components/src';
import {
	fetchCategorySuggestions as fetchCategorySuggestionsRaw,
	fetchSavedCategories as fetchSavedCategoriesRaw,
	fetchCollectionSuggestions as fetchCollectionSuggestionsRaw,
	fetchSavedCollections as fetchSavedCollectionsRaw,
} from '../utils/api';

const InspectorPanel = ( { attributes, setAttributes } ) => {
	const {
		queryType,
		numberOfItems,
		offset,
		selectedCollections,
		includeCategories,
		excludeCategories,
		layout,
		columns,
		imageAlignment,
		imageSize,
		showFeaturedImage,
		showCategory,
		showTitle,
		showExcerpt,
		showVolume,
		showNumber,
		showPeriod,
		showSubscriptionUrl,
		showOrderUrl,
		showCTAs,
		numberOfCTAs,
		specificCTAs,
	} = attributes;

	// Category suggestions.
	const fetchCategorySuggestions = useCallback( search => fetchCategorySuggestionsRaw( search ), [] );

	// Saved categories.
	const fetchSavedCategories = useCallback( categoryIDs => fetchSavedCategoriesRaw( categoryIDs ), [] );

	// Collection suggestions.
	const fetchCollectionSuggestions = useCallback( search => fetchCollectionSuggestionsRaw( search ), [] );

	// Saved collections.
	const fetchSavedCollections = useCallback( collectionIDs => fetchSavedCollectionsRaw( collectionIDs ), [] );

	return (
		<InspectorControls>
			<PanelBody title={ __( 'Settings', 'newspack-plugin' ) }>
				<ToggleGroupControl
					label={ __( 'Mode', 'newspack-plugin' ) }
					value={ queryType }
					onChange={ value => setAttributes( { queryType: value } ) }
					isBlock
					help={
						queryType === 'recent'
							? __( 'The block will display the most recent collection(s).', 'newspack-plugin' )
							: __( 'The block will display only the specifically selected collection(s).', 'newspack-plugin' )
					}
					__next40pxDefaultSize
				>
					<ToggleGroupControlOption label={ __( 'Recent', 'newspack-plugin' ) } value="recent" />
					<ToggleGroupControlOption label={ __( 'Static', 'newspack-plugin' ) } value="specific" />
				</ToggleGroupControl>

				{ queryType === 'specific' && (
					<AutocompleteTokenField
						tokens={ selectedCollections || [] }
						onChange={ value => setAttributes( { selectedCollections: value } ) }
						fetchSuggestions={ fetchCollectionSuggestions }
						fetchSavedInfo={ fetchSavedCollections }
						label={ __( 'Collections', 'newspack-plugin' ) }
						help={ __( 'Begin typing any word in a collection title. Click on an autocomplete result to select it.', 'newspack-plugin' ) }
						__next40pxDefaultSize
					/>
				) }

				{ queryType === 'recent' && (
					<>
						<RangeControl
							label={ __( 'Number of collections', 'newspack-plugin' ) }
							value={ numberOfItems }
							onChange={ value => setAttributes( { numberOfItems: value } ) }
							min={ 1 }
							max={ 24 }
							__next40pxDefaultSize
						/>

						<RangeControl
							label={ __( 'Offset', 'newspack-plugin' ) }
							value={ offset }
							onChange={ value => setAttributes( { offset: value } ) }
							min={ 0 }
							max={ 50 }
							help={ __( 'Number of collections to skip from the beginning', 'newspack-plugin' ) }
							__next40pxDefaultSize
						/>

						<AutocompleteTokenField
							tokens={ includeCategories }
							onChange={ value => setAttributes( { includeCategories: value } ) }
							fetchSuggestions={ fetchCategorySuggestions }
							fetchSavedInfo={ fetchSavedCategories }
							label={ __( 'Included categories', 'newspack-plugin' ) }
							style={ { marginBottom: '16px' } }
							__next40pxDefaultSize
						/>

						<AutocompleteTokenField
							tokens={ excludeCategories }
							onChange={ value => setAttributes( { excludeCategories: value } ) }
							fetchSuggestions={ fetchCategorySuggestions }
							fetchSavedInfo={ fetchSavedCategories }
							label={ __( 'Excluded categories', 'newspack-plugin' ) }
							style={ { marginBottom: '16px' } }
							__next40pxDefaultSize
						/>
					</>
				) }
			</PanelBody>

			{ layout === 'grid' && (
				<PanelBody title={ __( 'Grid', 'newspack-plugin' ) }>
					<RangeControl
						label={ __( 'Columns', 'newspack-plugin' ) }
						value={ columns }
						onChange={ value => setAttributes( { columns: value } ) }
						min={ 1 }
						max={ 6 }
						__next40pxDefaultSize
					/>
				</PanelBody>
			) }

			<PanelBody title={ __( 'Featured Image', 'newspack-plugin' ) }>
				<ToggleControl
					label={ __( 'Show featured image', 'newspack-plugin' ) }
					checked={ showFeaturedImage }
					onChange={ value => setAttributes( { showFeaturedImage: value } ) }
				/>
				{ layout === 'list' && showFeaturedImage && (
					<>
						<ToggleGroupControl
							label={ __( 'Alignment', 'newspack-plugin' ) }
							value={ imageAlignment }
							onChange={ value => setAttributes( { imageAlignment: value } ) }
							isBlock
							__next40pxDefaultSize
						>
							<ToggleGroupControlOption
								label={ __( 'Top', 'newspack-plugin' ) }
								value="top"
								title={ __( 'Show image on top', 'newspack-plugin' ) }
							/>
							<ToggleGroupControlOption
								label={ __( 'Left', 'newspack-plugin' ) }
								value="left"
								title={ __( 'Show image on left', 'newspack-plugin' ) }
							/>
							<ToggleGroupControlOption
								label={ __( 'Right', 'newspack-plugin' ) }
								value="right"
								title={ __( 'Show image on right', 'newspack-plugin' ) }
							/>
						</ToggleGroupControl>

						<ToggleGroupControl
							label={ __( 'Size', 'newspack-plugin' ) }
							value={ imageSize }
							onChange={ value => setAttributes( { imageSize: value } ) }
							isBlock
							__next40pxDefaultSize
						>
							<ToggleGroupControlOption
								label={ __( 'S', 'newspack-plugin' ) }
								value="small"
								title={ __( 'Small', 'newspack-plugin' ) }
							/>
							<ToggleGroupControlOption
								label={ __( 'M', 'newspack-plugin' ) }
								value="medium"
								title={ __( 'Medium', 'newspack-plugin' ) }
							/>
							<ToggleGroupControlOption
								label={ __( 'L', 'newspack-plugin' ) }
								value="large"
								title={ __( 'Large', 'newspack-plugin' ) }
							/>
						</ToggleGroupControl>
					</>
				) }
			</PanelBody>

			<PanelBody title={ __( 'Collection Meta', 'newspack-plugin' ) }>
				<ToggleControl
					label={ __( 'Show title', 'newspack-plugin' ) }
					checked={ showTitle }
					onChange={ value => setAttributes( { showTitle: value } ) }
				/>

				<ToggleControl
					label={ __( 'Show category', 'newspack-plugin' ) }
					checked={ showCategory }
					onChange={ value => setAttributes( { showCategory: value } ) }
				/>

				<ToggleControl
					label={ __( 'Show excerpt', 'newspack-plugin' ) }
					checked={ showExcerpt }
					onChange={ value => setAttributes( { showExcerpt: value } ) }
				/>

				<ToggleControl
					label={ __( 'Show period', 'newspack-plugin' ) }
					checked={ showPeriod }
					onChange={ value => setAttributes( { showPeriod: value } ) }
				/>

				<ToggleControl
					label={ __( 'Show volume', 'newspack-plugin' ) }
					checked={ showVolume }
					onChange={ value => setAttributes( { showVolume: value } ) }
				/>

				<ToggleControl
					label={ __( 'Show number', 'newspack-plugin' ) }
					checked={ showNumber }
					onChange={ value => setAttributes( { showNumber: value } ) }
				/>

				<ToggleControl
					label={ __( 'Show CTAs', 'newspack-plugin' ) }
					checked={ showCTAs }
					onChange={ value => setAttributes( { showCTAs: value } ) }
				/>

				{ showCTAs && (
					<>
						<RangeControl
							label={ __( 'Number of CTAs', 'newspack-plugin' ) }
							value={ numberOfCTAs }
							onChange={ value => setAttributes( { numberOfCTAs: value } ) }
							min={ 1 }
							max={ 5 }
							help={ __( 'Maximum number of CTAs to display', 'newspack-plugin' ) }
							__next40pxDefaultSize
						/>

						<ToggleControl
							label={ __( 'Show subscription URL', 'newspack-plugin' ) }
							checked={ showSubscriptionUrl }
							onChange={ value => setAttributes( { showSubscriptionUrl: value } ) }
						/>

						<ToggleControl
							label={ __( 'Show order URL', 'newspack-plugin' ) }
							checked={ showOrderUrl }
							onChange={ value => setAttributes( { showOrderUrl: value } ) }
						/>

						<BaseControl
							id="specific-ctas-control"
							label={ __( 'Specific CTAs (comma-separated)', 'newspack-plugin' ) }
							help={ __(
								'Enter specific CTA to show, e.g. "Digital Edition" (should match existing CTA label). Leave empty to show first available CTAs.',
								'newspack-plugin'
							) }
						>
							<input
								type="text"
								value={ specificCTAs }
								onChange={ e => setAttributes( { specificCTAs: e.target.value } ) }
								placeholder={ __( '', 'newspack-plugin' ) }
								className="components-text-control__input"
								style={ { height: '40px' } }
							/>
						</BaseControl>
					</>
				) }
			</PanelBody>
		</InspectorControls>
	);
};

export default InspectorPanel;

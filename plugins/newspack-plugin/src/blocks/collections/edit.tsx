import { __ } from '@wordpress/i18n';
import { useBlockProps, BlockControls } from '@wordpress/block-editor';
import { ToolbarGroup, ToolbarButton, Placeholder, Spinner } from '@wordpress/components';
import { caution, list, grid, pullLeft, pullRight, postFeaturedImage } from '@wordpress/icons';
import { useMemo } from '@wordpress/element';
import classnames from 'classnames';

import { useCollections } from './hooks/useCollections';
import CollectionItem from './components/CollectionItem';
import InspectorPanel from './components/InspectorPanel';

const Edit = ( { attributes, setAttributes } ) => {
	const { layout, columns, imageAlignment, imageSize, showFeaturedImage } = attributes;

	// Fetch collections data.
	const { collections, isLoading, hasCollections } = useCollections( attributes );

	// Toolbar controls.
	const layoutControls = useMemo(
		() => [
			{
				icon: list,
				title: __( 'List view', 'newspack-plugin' ),
				onClick: () => setAttributes( { layout: 'list' } ),
				isActive: layout === 'list',
			},
			{
				icon: grid,
				title: __( 'Grid view', 'newspack-plugin' ),
				onClick: () => setAttributes( { layout: 'grid' } ),
				isActive: layout === 'grid',
			},
		],
		[ layout, setAttributes ]
	);

	const imageAlignmentControls = useMemo(
		() => [
			{
				icon: postFeaturedImage,
				title: __( 'Show image on top', 'newspack-plugin' ),
				isActive: imageAlignment === 'top',
				onClick: () => setAttributes( { imageAlignment: 'top' } ),
			},
			{
				icon: pullLeft,
				title: __( 'Show image on left', 'newspack-plugin' ),
				isActive: imageAlignment === 'left',
				onClick: () => setAttributes( { imageAlignment: 'left' } ),
			},
			{
				icon: pullRight,
				title: __( 'Show image on right', 'newspack-plugin' ),
				isActive: imageAlignment === 'right',
				onClick: () => setAttributes( { imageAlignment: 'right' } ),
			},
		],
		[ imageAlignment, setAttributes ]
	);

	const wrapperClassName = useMemo(
		() =>
			classnames(
				'wp-block-newspack-collections',
				`layout-${ layout }`,
				layout === 'grid' && `columns-${ columns }`,
				`image-${ imageAlignment }`,
				layout === 'list' && `image-size-${ imageSize }`
			),
		[ layout, columns, imageAlignment, imageSize ]
	);

	return (
		<>
			<BlockControls>
				<ToolbarGroup>
					{ layoutControls.map( control => (
						<ToolbarButton
							key={ control.title }
							icon={ control.icon }
							isPressed={ control.isActive }
							onClick={ control.onClick }
							label={ control.title }
						/>
					) ) }
				</ToolbarGroup>
				{ layout === 'list' && showFeaturedImage && (
					<ToolbarGroup>
						{ imageAlignmentControls.map( control => (
							<ToolbarButton
								key={ control.title }
								icon={ control.icon }
								isPressed={ control.isActive }
								onClick={ control.onClick }
								label={ control.title }
							/>
						) ) }
					</ToolbarGroup>
				) }
			</BlockControls>

			<InspectorPanel attributes={ attributes } setAttributes={ setAttributes } />

			<div
				{ ...useBlockProps( {
					className: wrapperClassName,
				} ) }
			>
				{ /* Loading state */ }
				{ isLoading && (
					<Placeholder
						icon={ <Spinner style={ { height: '24px', padding: '4px', width: '24px' } } /> }
						label={ __( 'Loading collections…', 'newspack-plugin' ) }
						className="collections-loading"
					/>
				) }

				{ /* No results */ }
				{ ! isLoading && ! hasCollections && (
					<Placeholder icon={ caution } label={ __( 'No collections found', 'newspack-plugin' ) } className="no-collections" />
				) }

				{ /* Collections display */ }
				{ ! isLoading && hasCollections && (
					<>
						{ collections.map( collection => (
							<CollectionItem key={ collection.id } collection={ collection } attributes={ attributes } />
						) ) }
					</>
				) }
			</div>
		</>
	);
};

export default Edit;

/**
 * WordPress dependencies
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, Placeholder, SelectControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { ad as icon } from '../utils/icons';

/**
 * Hook: fetch the list of registered placements from the REST endpoint that
 * the ads wizard already consumes. We rely on whatever endpoint is in place;
 * if the endpoint shape changes we'll surface it here, not in PHP.
 *
 * Returns an array of { value, label } options suitable for SelectControl.
 */
function usePlacementOptions() {
	const [ options, setOptions ] = useState( null );

	useEffect( () => {
		let cancelled = false;
		apiFetch( { path: '/newspack-ads/v1/placements' } )
			.then( placements => {
				if ( cancelled ) {
					return;
				}
				// `placements` is keyed by placement key; convert to options.
				const opts = Object.entries( placements || {} )
					.filter( ( [ , config ] ) => config?.show_ui !== false && config?.block_rendered === true )
					.map( ( [ key, config ] ) => ( {
						value: key,
						label: config?.name || key,
					} ) );
				setOptions( opts );
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setOptions( [] );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [] );

	return options;
}

export default function Edit( { attributes, setAttributes } ) {
	const { placement } = attributes;
	const options = usePlacementOptions();
	const blockProps = useBlockProps( { className: 'newspack-ads-ad-block' } );

	const isLoading = options === null;
	const optionsForSelect = [ { value: '', label: __( '— Select a placement —', 'newspack-ads' ) }, ...( options || [] ) ];

	const selectedLabel = options?.find( opt => opt.value === placement )?.label || placement;

	const inspector = (
		<InspectorControls>
			<PanelBody title={ __( 'Placement', 'newspack-ads' ) }>
				<SelectControl
					label={ __( 'Placement', 'newspack-ads' ) }
					value={ placement }
					options={ optionsForSelect }
					onChange={ value => setAttributes( { placement: value } ) }
					disabled={ isLoading }
				/>
			</PanelBody>
		</InspectorControls>
	);

	// Unselected: show the "configure me" placeholder.
	if ( ! placement ) {
		return (
			<div { ...blockProps }>
				{ inspector }
				<Placeholder
					icon={ icon }
					label={ __( 'Ad Slot', 'newspack-ads' ) }
					instructions={ __( 'Pick the placement this block represents. The wizard binds an ad unit to each placement.', 'newspack-ads' ) }
				>
					<SelectControl
						label={ __( 'Placement', 'newspack-ads' ) }
						hideLabelFromVision
						value={ placement }
						options={ optionsForSelect }
						onChange={ value => setAttributes( { placement: value } ) }
						disabled={ isLoading }
					/>
				</Placeholder>
			</div>
		);
	}

	// Selected: reuse the ad-unit block's placeholder visual so styling is identical.
	return (
		<div { ...blockProps }>
			{ inspector }
			<div className="newspack-ads-ad-block-placeholder">
				<svg className="newspack-ads-ad-block-mock" width="100%" height="100%">
					<rect width="100%" height="100%" />
					<line x1="0" y1="0" x2="100%" y2="100%" />
				</svg>
				<div className="newspack-ads-ad-block-ad-label">{ selectedLabel }</div>
			</div>
		</div>
	);
}

/**
 * External dependencies
 */
import classNames from 'classnames';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment, useState, useEffect } from '@wordpress/element';
import { BlockControls, useBlockProps } from '@wordpress/block-editor';
import { SVG, ToolbarGroup, ToolbarButton, Placeholder, Spinner, Notice, Button } from '@wordpress/components';
import { pencil } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import PlacementControl from '../../placements/placement-control';
import type { AdProvider, AdUnit } from '../../placements/types';
import { ad as icon } from '../utils/icons';
import { fetchProviders, fetchBidders, type Bidders } from './store';

export type AdUnitAttributes = {
	provider?: string;
	ad_unit?: string;
	bidders_ids?: Record< string, string >;
	// Legacy attribute.
	activeAd?: string;
};

type EditProps = {
	attributes: AdUnitAttributes;
	setAttributes: ( attrs: Partial< AdUnitAttributes > ) => void;
};

function Edit( { attributes, setAttributes }: EditProps ) {
	const [ inFlight, setInFlight ] = useState( false );
	const [ error, setError ] = useState< unknown >( null );
	const [ isEditing, setIsEditing ] = useState( false );
	const [ biddersError, setBiddersError ] = useState< unknown >( null );
	const [ providers, setProviders ] = useState< AdProvider[] >( [] );
	const [ bidders, setBidders ] = useState< Bidders >( {} );
	const blockProps = useBlockProps( {
		className: 'newspack-ads-ad-block',
	} );

	const provider = providers.find( p => p.id.toString() === attributes.provider );
	const unit: AdUnit | undefined = provider?.units?.find( u => u.value.toString() === attributes.ad_unit );
	const sizes = unit?.sizes || [];
	const containerWidth = sizes.length ? Math.max( ...sizes.map( s => s[ 0 ] ) ) : 300;
	const containerHeight = sizes.length ? Math.max( ...sizes.map( s => s[ 1 ] ) ) : 200;

	useEffect( () => {
		const fetchData = async () => {
			// Legacy attribute.
			if ( attributes.activeAd && ! attributes.ad_unit ) {
				setAttributes( { ad_unit: attributes.activeAd } );
			}

			setInFlight( true );
			// Fetch providers (shared across all ad unit blocks).
			try {
				setProviders( await fetchProviders() );
			} catch ( err ) {
				setError( err );
			}
			// Fetch bidders (shared across all ad unit blocks).
			try {
				setBidders( await fetchBidders() );
			} catch ( err ) {
				setBiddersError( err );
			}
			setInFlight( false );
		};
		fetchData();
	}, [] );

	useEffect( () => {
		if ( providers?.length && ! attributes.provider ) {
			setAttributes( { provider: providers[ 0 ].id } );
		}
	}, [ providers ] );

	return (
		<div { ...blockProps }>
			{ ! isEditing && unit ? (
				<Fragment>
					{ ! inFlight && (
						<BlockControls>
							<ToolbarGroup>
								<ToolbarButton icon={ pencil } label={ __( 'Edit', 'newspack-ads' ) } onClick={ () => setIsEditing( true ) } />
							</ToolbarGroup>
						</BlockControls>
					) }
					{ /*
					 * `isError`/`noticeText` are not `Notice` props (the current API uses
					 * `status` + `children`) -- these notices render with default (info)
					 * styling and no visible message. Pre-existing bug, not fixed here;
					 * flagged for follow-up. `children: null` and the cast below only
					 * satisfy the type checker for this legacy shape, they don't change
					 * what renders.
					 */ }
					{ Boolean( error ) && (
						<Notice
							{ ...( { isError: true, noticeText: error, isDismissible: false, children: null } as Parameters< typeof Notice >[ 0 ] ) }
						/>
					) }
					{ /*
					 * `provider` here is the `Provider` object found above, never the
					 * string `'gam'` -- this condition is always false, so the bidders
					 * warning notice below never renders. Pre-existing bug (likely meant
					 * `provider?.id === 'gam'`), not fixed here; flagged for follow-up.
					 */ }
					{ ( provider as unknown ) === 'gam' && Boolean( biddersError ) && (
						<Notice
							{ ...( { isWarning: true, noticeText: biddersError, isDismissible: false, children: null } as Parameters<
								typeof Notice
							>[ 0 ] ) }
						/>
					) }
					<div className="newspack-ads-ad-block-placeholder" style={ { width: containerWidth, height: containerHeight } }>
						<Fragment>
							<SVG
								className="newspack-ads-ad-block-mock"
								width={ containerWidth }
								viewBox={ '0 0 ' + containerWidth + ' ' + containerHeight }
							>
								<rect width={ containerWidth } height={ containerHeight } strokeDasharray="2" />
								<line x1="0" y1="0" x2="100%" y2="100%" strokeDasharray="2" />
							</SVG>
							<span className="newspack-ads-ad-block-ad-label">
								{ /* `provider` is always set here: `unit` (checked by the ternary above) only
								 * resolves via `provider?.units?.find(...)`, so if `unit` is truthy `provider` is too. */ }
								{ providers.length > 1 && `${ provider!.name } - ` } { unit.name }
								<br />
								{ sizes.length
									? sizes.map( size => `${ size[ 0 ] }x${ size[ 1 ] }` ).join( ', ' )
									: __( 'Unknown size', 'newspack-ads' ) }
							</span>
						</Fragment>
						{ inFlight && <Spinner /> }
					</div>
				</Fragment>
			) : (
				<Placeholder label={ __( 'Ad Unit', 'newspack-ads' ) } icon={ icon }>
					<div className="newspack-ads-ad-block-edit">
						{ inFlight ? (
							<Spinner />
						) : (
							<Fragment>
								<div
									className={ classNames( {
										'newspack-ads-ad-block-edit-fields': true,
									} ) }
								>
									<PlacementControl
										providers={ providers }
										bidders={ bidders }
										value={ attributes }
										onChange={ value => {
											setIsEditing( true );
											setAttributes( value );
										} }
									/>
									<Button disabled={ ! unit } onClick={ () => setIsEditing( false ) } isPrimary>
										{ __( 'Save', 'newspack-ads' ) }
									</Button>
								</div>
							</Fragment>
						) }
					</div>
				</Placeholder>
			) }
		</div>
	);
}

export default Edit;

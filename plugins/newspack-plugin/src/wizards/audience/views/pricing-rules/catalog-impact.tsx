/**
 * Catalog-wide impact below the Pricing Rules list. The product table sits behind
 * a click because pricing the whole sample costs several times the headline count.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useCallback, useRef } from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import {
	Modal,
	Spinner,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import ImpactEmpty, { type ImpactEmptyReason } from './impact-empty';
import ImpactStats from './impact-stats';
import ImpactTable from './impact-table';
import { sampleNote, finiteNumber } from './impact-format';
import { IMPACT_PREVIEW_API_PATH as API_PATH, IMPACT_SAMPLE_LIMIT } from './constants';

interface CatalogImpactProps {
	stats: CatalogImpactResponse;
}

export default function CatalogImpact( { stats }: CatalogImpactProps ) {
	const headingId = useInstanceId( CatalogImpact, 'newspack-pricing-rules-impact-heading' );
	const [ isOpen, setIsOpen ] = useState( false );
	const [ detail, setDetail ] = useState< CatalogImpactResponse | null >( null );
	const [ hasError, setHasError ] = useState( false );

	// Reopening mid-flight would otherwise re-price the whole catalogue a second
	// time, and let a late failure clear a sample that had already arrived.
	const request = useRef( 0 );
	const inFlight = useRef( false );

	const open = useCallback( () => {
		setIsOpen( true );
		setHasError( false );
		// A failure is retried; only a landed sample short-circuits.
		if ( detail || inFlight.current ) {
			return;
		}
		const generation = ++request.current;
		inFlight.current = true;
		apiFetch< CatalogImpactResponse >( { path: addQueryArgs( API_PATH, { limit: IMPACT_SAMPLE_LIMIT } ) } )
			.then( res => {
				if ( generation === request.current ) {
					setDetail( res );
				}
			} )
			.catch( () => {
				if ( generation === request.current ) {
					setHasError( true );
				}
			} )
			.finally( () => {
				if ( generation === request.current ) {
					inFlight.current = false;
				}
			} );
	}, [ detail ] );

	// `supported: false` arrives with the rest of the payload absent.
	let emptyReason: ImpactEmptyReason | null = null;
	if ( detail ) {
		if ( ! detail.supported ) {
			emptyReason = 'unsupported';
		} else if ( ! detail.sample?.length ) {
			emptyReason = 'no-products';
		}
	}
	const note = detail ? sampleNote( detail ) : null;
	// The table loads its own sample, so only a confirmed zero withdraws it.
	const affected = finiteNumber( stats.total_matching );
	const hasAffectedProducts = 0 !== affected;

	return (
		<section className="newspack-pricing-rules__impact" aria-labelledby={ headingId }>
			{ /* The route renders no section title, so this is the first heading below the breadcrumb h1. */ }
			<h2 id={ headingId } className="screen-reader-text">
				{ __( 'Catalog impact', 'newspack-plugin' ) }
			</h2>
			<ImpactStats
				totalMatching={ stats.total_matching }
				countLimited={ stats.count_limited }
				productsDescription={ __( 'Rules currently price these products.', 'newspack-plugin' ) }
				audience={ stats.audience }
				onViewProducts={ hasAffectedProducts ? open : undefined }
			/>
			{ 0 === affected && (
				<p className="newspack-pricing-rules__muted">{ __( 'No active pricing rules are affecting products yet.', 'newspack-plugin' ) }</p>
			) }
			{ isOpen && (
				<Modal title={ __( 'Affected Products', 'newspack-plugin' ) } size="large" onRequestClose={ () => setIsOpen( false ) }>
					{ hasError && (
						<p className="newspack-pricing-rules__muted" role="alert">
							{ __( 'Could not load the affected products. Please try again.', 'newspack-plugin' ) }
						</p>
					) }
					{ ! hasError && ! detail && (
						<VStack className="newspack-pricing-rules__modal-loading" alignment="center" justify="center" role="status">
							<Spinner />
							<span className="screen-reader-text">{ __( 'Loading the affected products…', 'newspack-plugin' ) }</span>
						</VStack>
					) }
					{ ! hasError && emptyReason && <ImpactEmpty reason={ emptyReason } headingLevel={ 2 } /> }
					{ ! hasError && detail && ! emptyReason && (
						<>
							{ note && <p className="newspack-pricing-rules__muted">{ note }</p> }
							<ImpactTable
								baseline={ detail.sample }
								segmentGroups={ detail.segment_groups ?? [] }
								currency={ detail.currency }
								framed={ false }
								collapsible={ false }
							/>
						</>
					) }
				</Modal>
			) }
		</section>
	);
}

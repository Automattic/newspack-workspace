/**
 * Catalog-wide impact below the Pricing Rules list: the headline numbers, and
 * the product-by-product table behind them. Opening the table is a deliberate
 * click because pricing the whole sample costs several times what the headline
 * count alone does.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import {
	Button,
	Modal,
	Spinner,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { Card } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import ImpactEmpty, { type ImpactEmptyReason } from './impact-empty';
import ImpactStats from './impact-stats';
import ImpactTable from './impact-table';
import { sampleNote } from './impact-format';
import { IMPACT_PREVIEW_API_PATH as API_PATH, IMPACT_SAMPLE_LIMIT } from './constants';

interface CatalogImpactProps {
	stats: CatalogImpactResponse;
}

export default function CatalogImpact( { stats }: CatalogImpactProps ) {
	const [ isOpen, setIsOpen ] = useState( false );
	const [ detail, setDetail ] = useState< CatalogImpactResponse | null >( null );
	const [ hasError, setHasError ] = useState( false );

	// Only the newest request may write state. Closing and reopening before a
	// request lands would otherwise issue a second full catalogue re-price, and
	// let a late failure clear a sample that had already arrived.
	const request = useRef( 0 );
	const inFlight = useRef( false );

	const open = useCallback( () => {
		setIsOpen( true );
		setHasError( false );
		// A landed sample does not move under the modal, so it is kept; a failure is retried.
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

	// The engine ships separately and answers `supported: false` with the rest of
	// the payload absent, so the sample is checked before the table walks it.
	let emptyReason: ImpactEmptyReason | null = null;
	if ( detail ) {
		if ( ! detail.supported ) {
			emptyReason = 'unsupported';
		} else if ( ! detail.sample?.length ) {
			emptyReason = 'no-products';
		}
	}

	return (
		<Card.Root className="newspack-pricing-rules__impact">
			<Card.Content>
				{ /* The stat leads visually; the heading keeps the section reachable by heading navigation. */ }
				<h3 className="screen-reader-text">{ __( 'Catalog impact', 'newspack-plugin' ) }</h3>
				<ImpactStats totalMatching={ stats.total_matching } countLimited={ stats.count_limited } audience={ stats.audience } />
				{ stats.total_matching === 0 ? (
					<p className="newspack-pricing-rules__muted">
						{ __( 'No active pricing rules are affecting products yet.', 'newspack-plugin' ) }
					</p>
				) : (
					<Button variant="secondary" onClick={ open }>
						{ __( 'View Affected Products', 'newspack-plugin' ) }
					</Button>
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
						{ ! hasError && emptyReason && <ImpactEmpty reason={ emptyReason } /> }
						{ ! hasError && detail && ! emptyReason && (
							<>
								{ detail.preview_limited && detail.sample_count >= IMPACT_SAMPLE_LIMIT && (
									<p className="newspack-pricing-rules__muted">{ sampleNote( detail.sample_count ) }</p>
								) }
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
			</Card.Content>
		</Card.Root>
	);
}

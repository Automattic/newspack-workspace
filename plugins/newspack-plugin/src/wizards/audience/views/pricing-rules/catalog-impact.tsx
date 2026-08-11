/**
 * Catalog-wide impact above the Pricing Rules list: the headline numbers, and
 * the product-by-product table behind them. Opening the table is a deliberate
 * click because pricing the whole sample costs several times what the headline
 * count alone does.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Modal,
	Spinner,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import ImpactStats from './impact-stats';
import ImpactTable from './impact-table';
import { formatCount } from './impact-format';
import { IMPACT_PREVIEW_API_PATH as API_PATH, IMPACT_SAMPLE_LIMIT } from './constants';

interface CatalogImpactProps {
	stats: CatalogImpactResponse;
}

export default function CatalogImpact( { stats }: CatalogImpactProps ) {
	const [ isOpen, setIsOpen ] = useState( false );
	const [ detail, setDetail ] = useState< CatalogImpactResponse | null >( null );
	const [ hasError, setHasError ] = useState( false );

	const open = useCallback( () => {
		setIsOpen( true );
		// The sample does not move under the modal, so one fetch serves every open.
		if ( detail || hasError ) {
			return;
		}
		apiFetch< CatalogImpactResponse >( { path: `${ API_PATH }?limit=${ IMPACT_SAMPLE_LIMIT }` } )
			.then( setDetail )
			.catch( () => setHasError( true ) );
	}, [ detail, hasError ] );

	return (
		<div className="newspack-pricing-rules__impact">
			<ImpactStats totalMatching={ stats.total_matching } countLimited={ stats.count_limited } audience={ stats.audience } />
			{ stats.total_matching === 0 ? (
				<p className="newspack-pricing-rules__muted">{ __( 'No active pricing rules are affecting products yet.', 'newspack-plugin' ) }</p>
			) : (
				<Button variant="secondary" onClick={ open }>
					{ __( 'View affected products', 'newspack-plugin' ) }
				</Button>
			) }
			{ isOpen && (
				<Modal title={ __( 'Affected products', 'newspack-plugin' ) } size="large" onRequestClose={ () => setIsOpen( false ) }>
					{ hasError && (
						<p className="newspack-pricing-rules__muted">
							{ __( 'Could not load the affected products. Please try again.', 'newspack-plugin' ) }
						</p>
					) }
					{ ! hasError && ! detail && (
						<VStack className="newspack-pricing-rules__modal-loading" alignment="center" justify="center">
							<Spinner />
						</VStack>
					) }
					{ ! hasError && detail && (
						<>
							{ detail.preview_limited && detail.sample_count >= IMPACT_SAMPLE_LIMIT && (
								<p className="newspack-pricing-rules__muted">
									{ sprintf(
										/* translators: %s: how many products the table lists. */
										_n(
											'Showing a sample of %s product.',
											'Showing a sample of %s products.',
											detail.sample_count,
											'newspack-plugin'
										),
										formatCount( detail.sample_count )
									) }
								</p>
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
		</div>
	);
}

/**
 * Per-rule impact preview for the editor — the composed price-by-cycle table
 * (with unsaved-edit highlighting) plus the eligible-audience line. Debounce-POSTs
 * the in-progress rule body to the plugin's preview route; mirrors the native
 * plugin's impact metabox. Renders nothing until a supported payload arrives.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import ImpactTable from './impact-table';

const PREVIEW_PATH = '/wc-dynamic-pricing/v1/rules/preview';
const DEBOUNCE_MS = 500;

function AudienceLine( { audience }: { audience: RuleAudienceData } ) {
	const total = audience.count_limited ? `${ audience.total }+` : `${ audience.total }`;
	const message =
		'locked' === audience.application
			? sprintf(
					/* translators: %s: number of existing subscribers in scope. */
					__( 'Existing subscribers in scope: %s — none repriced (applies to new sign-ups only).', 'newspack-plugin' ),
					total
			  )
			: sprintf(
					/* translators: 1: total subscribers, 2: count eligible at renewal, 3: count protected. */
					__( 'Existing subscribers in scope: %1$s — %2$s eligible at renewal · %3$s protected.', 'newspack-plugin' ),
					total,
					String( audience.caught ),
					String( audience.protected )
			  );
	return <p className="newspack-pricing-rules__audience">{ message }</p>;
}

interface RulePreviewProps {
	body: Record< string, unknown >;
}

export default function RulePreview( { body }: RulePreviewProps ) {
	const [ data, setData ] = useState< RulePreviewResponse | null >( null );
	const [ isLoading, setIsLoading ] = useState( false );
	const timer = useRef< ReturnType< typeof setTimeout > >();
	const bodyKey = JSON.stringify( body );

	useEffect( () => {
		if ( timer.current ) {
			clearTimeout( timer.current );
		}
		let cancelled = false;
		timer.current = setTimeout( () => {
			setIsLoading( true );
			apiFetch< RulePreviewResponse >( { path: PREVIEW_PATH, method: 'POST', data: body } )
				.then( res => {
					if ( ! cancelled ) {
						setData( res );
					}
				} )
				.catch( () => {
					if ( ! cancelled ) {
						setData( null );
					}
				} )
				.finally( () => {
					if ( ! cancelled ) {
						setIsLoading( false );
					}
				} );
		}, DEBOUNCE_MS );
		return () => {
			cancelled = true;
			if ( timer.current ) {
				clearTimeout( timer.current );
			}
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ bodyKey ] );

	if ( ! data?.supported ) {
		return null;
	}

	const { currency } = data;

	return (
		<div className={ `newspack-pricing-rules__preview${ isLoading ? ' is-loading' : '' }` }>
			{ data.total_matching > 0 ? (
				<>
					<p className="newspack-pricing-rules__muted">
						{ data.preview_limited
							? sprintf(
									/* translators: 1: sample size, 2: total affected products. */
									__(
										'Resulting prices for a sample of %1$d of %2$d affected products (best price wins; updates as you edit).',
										'newspack-plugin'
									),
									data.sample_count,
									data.total_matching
							  )
							: __( 'Resulting prices across affected products (best price wins; updates as you edit).', 'newspack-plugin' ) }
					</p>
					<ImpactTable baseline={ data.sample } segmentGroups={ data.segment_groups ?? [] } currency={ currency } />
				</>
			) : (
				<p className="newspack-pricing-rules__muted">{ __( 'This rule does not affect any products yet.', 'newspack-plugin' ) }</p>
			) }
			{ data.audience?.supported && <AudienceLine audience={ data.audience } /> }
		</div>
	);
}

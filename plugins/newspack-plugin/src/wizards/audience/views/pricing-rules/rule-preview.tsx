/**
 * Per-rule impact preview for the editor: the headline stats, then the composed
 * price-by-cycle table (with unsaved-edit highlighting). Debounce-POSTs the
 * in-progress rule body to the plugin's preview route; mirrors the native
 * plugin's impact metabox. Stands down to an empty card when there is no price
 * yet, nothing matches, or no preview can be had, and to nothing at all until
 * the first request settles.
 */

/**
 * WordPress dependencies
 */
import { _n, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import ImpactEmpty, { type ImpactEmptyReason } from './impact-empty';
import ImpactStats from './impact-stats';
import ImpactTable from './impact-table';
import { formatCount } from './impact-format';
import { RULE_PREVIEW_API_PATH as PREVIEW_PATH } from './constants';

const DEBOUNCE_MS = 500;

interface RulePreviewProps {
	body: Record< string, unknown >;
	hasPrice: boolean;
}

export default function RulePreview( { body, hasPrice }: RulePreviewProps ) {
	const [ data, setData ] = useState< RulePreviewResponse | null >( null );
	const [ hasResolved, setHasResolved ] = useState( false );
	const [ isLoading, setIsLoading ] = useState( false );
	const timer = useRef< ReturnType< typeof setTimeout > | undefined >( undefined );
	const bodyKey = JSON.stringify( body );

	useEffect( () => {
		// A blank price is sent as 0, so fetching now would preview a $0 rule.
		if ( ! hasPrice ) {
			return;
		}
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
						setHasResolved( true );
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
		// Typing 0 leaves bodyKey identical, so hasPrice is what re-runs the effect.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ bodyKey, hasPrice ] );

	if ( hasPrice && ! data && ! hasResolved ) {
		return null;
	}

	let reason: ImpactEmptyReason | null = null;
	if ( ! hasPrice ) {
		reason = 'no-price';
	} else if ( ! data?.supported ) {
		reason = 'unsupported';
	} else if ( data.total_matching === 0 || ! data.sample?.length ) {
		reason = 'no-products';
	}

	if ( reason ) {
		return <ImpactEmpty reason={ reason } />;
	}

	const preview = data as RulePreviewResponse;

	return (
		<div className={ `newspack-pricing-rules__preview${ isLoading ? ' is-loading' : '' }` }>
			{ /* impact_preview() documents a capped total as an upper bound, not a floor. */ }
			<ImpactStats
				totalMatching={ preview.total_matching }
				countLimited={ preview.count_limited }
				countBound="upper"
				audience={ preview.audience }
			/>
			<ImpactTable baseline={ preview.sample } segmentGroups={ preview.segment_groups ?? [] } currency={ preview.currency } />
			{ preview.preview_limited && (
				<p className="newspack-pricing-rules__muted">
					{ sprintf(
						/* translators: %s: how many products the table lists. */
						_n( 'Showing a sample of %s product.', 'Showing a sample of %s products.', preview.sample_count, 'newspack-plugin' ),
						formatCount( preview.sample_count )
					) }
				</p>
			) }
		</div>
	);
}

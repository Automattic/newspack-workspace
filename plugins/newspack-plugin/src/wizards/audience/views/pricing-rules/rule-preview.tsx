/**
 * Per-rule impact preview for the editor. Debounce-POSTs the in-progress rule
 * body to the plugin's preview route; spins until the first request settles and
 * stands down to an empty card when nothing matches or no preview can be had.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
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
import { RULE_PREVIEW_API_PATH as PREVIEW_PATH } from './constants';

const DEBOUNCE_MS = 500;

interface RulePreviewProps {
	body: Record< string, unknown >;
	// Off while the form's section header carries the legend; on so the table can
	// explain markers composed in by other active rules.
	showCycleNote: boolean;
}

export default function RulePreview( { body, showCycleNote }: RulePreviewProps ) {
	const [ data, setData ] = useState< RulePreviewResponse | null >( null );
	const [ hasResolved, setHasResolved ] = useState( false );
	const [ isLoading, setIsLoading ] = useState( false );
	const timer = useRef< ReturnType< typeof setTimeout > | undefined >( undefined );
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
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ bodyKey ] );

	if ( ! data && ! hasResolved ) {
		return (
			<VStack className="newspack-pricing-rules__preview-loading" alignment="center" justify="center" role="status">
				<Spinner />
				<span className="screen-reader-text">{ __( 'Loading the impact preview…', 'newspack-plugin' ) }</span>
			</VStack>
		);
	}

	let reason: ImpactEmptyReason | null = null;
	if ( ! data?.supported ) {
		reason = 'unsupported';
	} else if ( 0 === finiteNumber( data.total_matching ) || ! data.sample?.length ) {
		reason = 'no-products';
	}

	if ( reason ) {
		return <ImpactEmpty reason={ reason } />;
	}

	const preview = data as RulePreviewResponse;
	const note = sampleNote( preview );

	return (
		<div className={ `newspack-pricing-rules__preview${ isLoading ? ' is-loading' : '' }` }>
			{ /* impact_preview() documents a capped total as an upper bound, not a floor. */ }
			<ImpactStats
				totalMatching={ preview.total_matching }
				countLimited={ preview.count_limited }
				countBound="upper"
				productsDescription={ __( 'This rule would price these products.', 'newspack-plugin' ) }
				audience={ preview.audience }
			/>
			<ImpactTable
				baseline={ preview.sample }
				segmentGroups={ preview.segment_groups ?? [] }
				currency={ preview.currency }
				showCycleNote={ showCycleNote }
			/>
			{ note && <p className="newspack-pricing-rules__muted">{ note }</p> }
		</div>
	);
}

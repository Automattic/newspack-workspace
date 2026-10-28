/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Card, CardBody, CardFooter, CardHeader, Notice, Spinner } from '@wordpress/components';

/**
 * Internal dependencies
 */
import AllocationBar from './AllocationBar';
import StatsCard from './StatsCard';
import { getStatusLabel, getTestStatus, getVariantLabel } from './utils';

/**
 * One test: its variants, its split, and its stats on request.
 *
 * @param {Object} props      Props.
 * @param {Object} props.test A test from the boot data.
 * @param {Object} props.data Boot data.
 */
const TestCard = ( { test, data } ) => {
	const [ stats, setStats ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( null );
	const status = getTestStatus( test );
	const letters = Object.keys( test.variants );

	const load = refresh => {
		setLoading( true );
		setError( null );
		apiFetch( {
			path: `/${ data.rest_base }/ab-tests/${ test.test_id }/stats${ refresh ? '?refresh=1' : '' }`,
		} )
			.then( setStats )
			.catch( err => setError( err.message || __( 'The stats could not be loaded.', 'newspack-popups' ) ) )
			.finally( () => setLoading( false ) );
	};

	return (
		<Card className="newspack-popups-ab-tests__test">
			<CardHeader>
				<div>
					<code>{ test.test_id }</code>
					{ test.goal_label && <span className="newspack-popups-ab-tests__goal">{ test.goal_label }</span> }
				</div>
				<span className={ `newspack-popups-ab-tests__badge is-${ status }` }>{ getStatusLabel( status ) }</span>
			</CardHeader>
			<CardBody>
				<AllocationBar controlShare={ test.control_share } variants={ test.variants } />
				<ul className="newspack-popups-ab-tests__variants">
					{ letters.map( letter => {
						const variant = test.variants[ letter ];
						return (
							<li key={ letter }>
								<strong>{ getVariantLabel( letter ) }</strong>{ ' ' }
								{ variant.edit_link ? <a href={ variant.edit_link }>{ variant.title }</a> : variant.title }{ ' ' }
								<span className="newspack-popups-ab-tests__status">{ variant.status_label }</span>
							</li>
						);
					} ) }
				</ul>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ loading && <Spinner /> }
				{ stats && ! loading && <StatsCard stats={ stats } data={ data } /> }
			</CardBody>
			<CardFooter>
				<Button
					variant="secondary"
					onClick={ () => load( Boolean( stats ) ) }
					disabled={ loading || ! data.provider.available }
					label={ data.provider.available ? undefined : data.provider.reason }
					showTooltip={ ! data.provider.available }
				>
					{ stats ? __( 'Refresh stats', 'newspack-popups' ) : __( 'Load stats', 'newspack-popups' ) }
				</Button>
			</CardFooter>
		</Card>
	);
};

export default TestCard;

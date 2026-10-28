/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import { __ } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import apiFetch from '@wordpress/api-fetch';
import { decodeEntities } from '@wordpress/html-entities';
import { Button, Card, CardBody, CardFooter, CardHeader, Notice } from '@wordpress/components';
import { Badge } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import AllocationBar from './AllocationBar';
import StatsCard from './StatsCard';
import { getStatusIntent, getStatusLabel, getTestStatus, getVariantLabel } from './utils';

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
	const headingId = useInstanceId( TestCard, 'newspack-popups-ab-tests__title' );
	const status = getTestStatus( test );
	const letters = Object.keys( test.variants );
	const duplicates = test.duplicates || [];

	const load = refresh => {
		setLoading( true );
		setError( null );
		apiFetch( {
			path: `/${ data.rest_base }/ab-tests/${ test.test_id }/stats${ refresh ? '?refresh=1' : '' }`,
		} )
			.then( response => {
				setStats( response );
				speak( __( 'Stats loaded.', 'newspack-popups' ) );
			} )
			.catch( err => setError( err.message || __( 'The stats could not be loaded.', 'newspack-popups' ) ) )
			.finally( () => setLoading( false ) );
	};

	return (
		<Card className="newspack-popups-ab-tests__test" role="region" aria-labelledby={ headingId }>
			<CardHeader>
				<h2 id={ headingId } className="newspack-popups-ab-tests__title">
					<code>{ test.test_id }</code>
					{ test.goal_label && <span className="newspack-popups-ab-tests__goal">{ test.goal_label }</span> }
				</h2>
				<Badge intent={ getStatusIntent( status ) }>{ getStatusLabel( status ) }</Badge>
			</CardHeader>
			<CardBody>
				{ duplicates.length > 0 && (
					<Notice status="warning" isDismissible={ false } spokenMessage="">
						{ __(
							'Prompts share a variant letter. Readers see every published one, and this page reports only the first (published first, then lowest ID). Give each prompt its own letter in the prompt editor. Also carrying a letter:',
							'newspack-popups'
						) }{ ' ' }
						{ duplicates.map( ( duplicate, index ) => {
							const title = decodeEntities( duplicate.title );
							return (
								<span key={ duplicate.id }>
									{ index > 0 && ', ' }
									{ getVariantLabel( duplicate.letter ) }{ ' ' }
									{ duplicate.edit_link ? <a href={ duplicate.edit_link }>{ title }</a> : title } ({ duplicate.status_label })
								</span>
							);
						} ) }
					</Notice>
				) }
				<AllocationBar controlShare={ test.control_share } variants={ test.variants } />
				<ul className="newspack-popups-ab-tests__variants">
					{ letters.map( letter => {
						const variant = test.variants[ letter ];
						const title = decodeEntities( variant.title );
						return (
							<li key={ letter }>
								<strong>{ getVariantLabel( letter ) }</strong>{ ' ' }
								{ variant.edit_link ? <a href={ variant.edit_link }>{ title }</a> : title }{ ' ' }
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
				{ stats && <StatsCard stats={ stats } data={ data } /> }
			</CardBody>
			<CardFooter>
				<Button
					variant="secondary"
					onClick={ () => load( Boolean( stats ) ) }
					disabled={ loading || ! data.provider.available }
					accessibleWhenDisabled
					isBusy={ loading }
				>
					{ stats ? __( 'Refresh stats', 'newspack-popups' ) : __( 'Load stats', 'newspack-popups' ) }
				</Button>
			</CardFooter>
		</Card>
	);
};

export default TestCard;

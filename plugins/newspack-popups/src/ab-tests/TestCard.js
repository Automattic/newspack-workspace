/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import apiFetch from '@wordpress/api-fetch';
import { decodeEntities } from '@wordpress/html-entities';
import { dateI18n, getSettings } from '@wordpress/date';
import { Button, Card, CardBody, CardHeader, Notice, Spinner } from '@wordpress/components';
import { Badge, Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import AllocationBar, { getShares } from './AllocationBar';
import {
	formatConfidence,
	formatLift,
	formatRate,
	formatLetterList,
	getCountLabel,
	getLabelIntent,
	getStatusIntent,
	getStatusLabel,
	getTestStatus,
	getVariantLabel,
} from './utils';

/**
 * What a test without both roles still needs.
 *
 * @param {Object} variants Variants keyed by letter.
 * @return {string} Hint.
 */
const getMissingRoleHint = variants =>
	variants.a
		? __( 'Add version B: give another prompt this test name.', 'newspack-popups' )
		: __( 'Add version A: give another prompt this test name.', 'newspack-popups' );

/**
 * One test: its split, its variants and their results, loaded when the card opens.
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
	const shares = getShares( test.control_share, test.variants );
	const hasStarted = !! test.started_at;
	const hasStats = hasStarted && stats && stats.source.state !== 'unavailable';

	const ineligible = letters.filter( letter => false === test.variants[ letter ].eligible );

	const load = refresh => {
		setLoading( true );
		setError( null );
		apiFetch( {
			path: `/${ data.rest_base }/ab-tests/${ test.test_id }/stats${ refresh ? '?refresh=1' : '' }`,
		} )
			.then( response => {
				setStats( response );
				if ( refresh ) {
					speak( __( 'Results loaded.', 'newspack-popups' ) );
				}
			} )
			.catch( err => setError( err.message || __( 'The results could not be loaded.', 'newspack-popups' ) ) )
			.finally( () => setLoading( false ) );
	};

	useEffect( () => {
		if ( data.provider.available && hasStarted ) {
			load( false );
		}
	}, [] );

	return (
		<Card className="newspack-popups-ab-tests__test" role="region" aria-labelledby={ headingId }>
			<CardHeader>
				<Stack direction="column" gap="xs">
					<h2 id={ headingId } className="newspack-popups-ab-tests__title">
						{ test.test_id }
					</h2>
					<span className="newspack-popups-ab-tests__goal">
						{ test.goal_label
							? sprintf(
									// Translators: %s is the test's conversion goal.
									__( 'Goal: %s', 'newspack-popups' ),
									test.goal_label
							  )
							: __( 'No goal set', 'newspack-popups' ) }
					</span>
				</Stack>
				<Stack direction="row" gap="md" align="center">
					<Badge intent={ getStatusIntent( status ) }>{ getStatusLabel( status ) }</Badge>
					{ data.provider.available && hasStarted && (
						<Button
							variant="secondary"
							size="compact"
							onClick={ () => load( true ) }
							disabled={ loading }
							accessibleWhenDisabled
							isBusy={ loading }
						>
							{ __( 'Refresh', 'newspack-popups' ) }
						</Button>
					) }
				</Stack>
			</CardHeader>
			<CardBody>
				<Stack direction="column" gap="lg">
					{ duplicates.length > 0 && (
						<Notice status="error" isDismissible={ false } spokenMessage="">
							{ __(
								'More than one prompt in this test is the same version, and results only count the first. Clear the test name on the extra prompt, or unpublish it:',
								'newspack-popups'
							) }{ ' ' }
							{ duplicates.map( ( duplicate, index ) => {
								const title = decodeEntities( duplicate.title );
								return (
									<span key={ duplicate.id }>
										{ index > 0 && ', ' }
										{ duplicate.edit_link ? <a href={ duplicate.edit_link }>{ title }</a> : title }{ ' ' }
										{ sprintf(
											// Translators: %s is the version letter.
											__( '(version %s)', 'newspack-popups' ),
											String( duplicate.letter ).toUpperCase()
										) }
									</span>
								);
							} ) }
						</Notice>
					) }
					{ ineligible.length > 0 && (
						<Notice status="warning" isDismissible={ false } spokenMessage="">
							{ sprintf(
								// Translators: %s is a list of version letters, such as "A" or "A and B".
								_n(
									"Version %s uses a manual or custom placement, which A/B tests don't support, so its results aren't reliable. Change its placement in the prompt editor.",
									"Versions %s use a manual or custom placement, which A/B tests don't support, so their results aren't reliable. Change their placement in the prompt editor.",
									ineligible.length,
									'newspack-popups'
								),
								formatLetterList( ineligible.map( letter => letter.toUpperCase() ) )
							) }
						</Notice>
					) }
					{ status === 'incomplete' ? (
						<p className="newspack-popups-ab-tests__hint">{ getMissingRoleHint( test.variants ) }</p>
					) : (
						<AllocationBar controlShare={ test.control_share } variants={ test.variants } />
					) }
					<div
						className="newspack-popups-ab-tests__table-scroll"
						tabIndex={ 0 }
						role="region"
						aria-label={ sprintf(
							// Translators: %s is the test name.
							__( 'Results for %s', 'newspack-popups' ),
							test.test_id
						) }
					>
						<table className="newspack-popups-ab-tests__table">
							<thead>
								<tr>
									<th scope="col">{ __( 'Version', 'newspack-popups' ) }</th>
									<th scope="col">{ __( 'Prompt', 'newspack-popups' ) }</th>
									<th scope="col" className="is-numeric">
										{ __( 'Traffic', 'newspack-popups' ) }
									</th>
									{ hasStats && (
										<>
											<th scope="col" className="is-numeric">
												{ getCountLabel( stats.source.unit ) }
											</th>
											<th scope="col" className="is-numeric">
												{ __( 'Conversions', 'newspack-popups' ) }
											</th>
											<th scope="col" className="is-numeric">
												{ __( 'Conversion rate', 'newspack-popups' ) }
											</th>
											<th scope="col" className="is-numeric">
												{ __( 'Lift vs. A', 'newspack-popups' ) }
											</th>
											<th scope="col">{ __( 'Confidence', 'newspack-popups' ) }</th>
										</>
									) }
								</tr>
							</thead>
							<tbody>
								{ letters.map( letter => {
									const variant = test.variants[ letter ];
									const title = decodeEntities( variant.title );
									const row = hasStats ? stats.variants[ letter ] : null;
									const comparison = hasStats ? stats.comparisons[ letter ] : null;
									return (
										<tr key={ letter }>
											<th scope="row">
												<span className="newspack-popups-ab-tests__variant">
													<span
														className={ `newspack-popups-ab-tests__swatch is-variant-${ letter }` }
														aria-hidden="true"
													/>
													{ getVariantLabel( letter ) }
												</span>
											</th>
											<td>
												<Stack direction="column" gap="xs">
													{ variant.edit_link ? <a href={ variant.edit_link }>{ title }</a> : title }
													<span className="newspack-popups-ab-tests__muted">{ variant.status_label }</span>
												</Stack>
											</td>
											<td className="is-numeric">{ status === 'incomplete' ? '—' : `${ Math.round( shares[ letter ] ) }%` }</td>
											{ hasStats && (
												<>
													<td className="is-numeric">{ row ? row.readers : '—' }</td>
													<td className="is-numeric">{ row ? row.conversions : '—' }</td>
													<td className="is-numeric">{ row ? formatRate( row.rate ) : '—' }</td>
													<td className="is-numeric">{ letter === 'a' || ! row ? '—' : formatLift( row.lift ) }</td>
													<td>
														{ comparison ? (
															<Stack direction="row" gap="sm" align="center">
																<span>{ formatConfidence( comparison.confidence ) }</span>
																<Badge intent={ getLabelIntent( comparison.label ) }>
																	{ data.labels[ comparison.label ] }
																</Badge>
															</Stack>
														) : (
															'—'
														) }
													</td>
												</>
											) }
										</tr>
									);
								} ) }
							</tbody>
						</table>
					</div>
					{ loading && ! stats && (
						<div className="newspack-popups-ab-tests__loading">
							<Spinner />
							<span>{ __( 'Loading results…', 'newspack-popups' ) }</span>
						</div>
					) }
					{ stats && stats.source.reason && (
						<Notice status={ stats.source.state === 'unavailable' ? 'warning' : 'info' } isDismissible={ false } spokenMessage="">
							{ stats.source.reason }
						</Notice>
					) }
					{ hasStats && stats.source.state === 'ok' && stats.source.unit === 'events' && (
						<p className="newspack-popups-ab-tests__muted">
							{ __(
								'Counts are views rather than readers, because the stats source could not count distinct readers for this test.',
								'newspack-popups'
							) }
						</p>
					) }
					{ hasStats && status !== 'incomplete' && stats.flags.young_test && (
						<Notice status="info" isDismissible={ false } spokenMessage="">
							{ data.copy.young_test }
						</Notice>
					) }
					{ hasStats && status !== 'incomplete' && stats.flags.high_anonymous && (
						<Notice status="info" isDismissible={ false } spokenMessage="">
							{ data.copy.high_anonymous }
						</Notice>
					) }
					{ error && (
						<Notice status="error" isDismissible={ false } politeness="polite">
							{ error }
						</Notice>
					) }
					{ stats && stats.source.fetched_at && (
						<p className="newspack-popups-ab-tests__muted">
							{ sprintf(
								// Translators: %s is a date and time.
								__( 'Updated %s. Results refresh every hour.', 'newspack-popups' ),
								dateI18n( getSettings().formats.datetime, stats.source.fetched_at )
							) }
						</p>
					) }
				</Stack>
			</CardBody>
		</Card>
	);
};

export default TestCard;

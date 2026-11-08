/**
 * WordPress dependencies
 */
import { useEffect, useId, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import apiFetch from '@wordpress/api-fetch';
import { decodeEntities } from '@wordpress/html-entities';
import { dateI18n, getSettings } from '@wordpress/date';
import { Button, CardBody, DropdownMenu, MenuGroup, MenuItem, Notice, Spinner } from '@wordpress/components';
import { moreVertical } from '@wordpress/icons';
import { Badge, Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { Card } from '../../../../../../packages/components/src';
import ResultsTable from './results-table';
import RenameModal from './rename-modal';
import StartTestModal from './start-test-modal';
import EndTestModal from './end-test-modal';
import { REST_BASE, canEndTest, formatDay, formatList, getStatusIntent, getStatusLabel, getTestStatus, openVersionB, splitSummary } from './utils';

/**
 * @param {Object} variants Variants keyed by letter.
 * @return {string} What a test without both versions still needs.
 */
const getMissingVersionHint = variants =>
	variants.a
		? __( 'Not started. Create version B, then publish it to start the test.', 'newspack-plugin' )
		: __( 'This test has no version A, so it can’t run.', 'newspack-plugin' );

/**
 * One test: its header, its notices and its results, loaded when the card mounts.
 *
 * @param {Object}   props
 * @param {Object}   props.test      A test from the list route.
 * @param {Object}   props.data      The rest of the list route's response.
 * @param {Function} props.onRenamed Called with the test ID and its new name.
 * @param {Function} props.onEnded   Called with the ended test the end route returns.
 * @param {boolean}  props.isTarget  Whether the page was opened, or the test just ended, to show this test.
 */
const TestCard = ( { test, data, onRenamed, onEnded, isTarget = false } ) => {
	const [ stats, setStats ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ wasRefresh, setWasRefresh ] = useState( false );
	const [ modal, setModal ] = useState( null );
	const [ focusHeading, setFocusHeading ] = useState( false );
	const headingId = useId();
	const headingRef = useRef( null );

	const status = getTestStatus( test );
	const name = test.name || test.test_id;
	const letters = Object.keys( test.variants );
	const duplicates = test.duplicates || [];
	const hasStarted = !! test.started_at;
	const isEnded = status === 'ended';
	// An ended test's results are kept on the server once taken, so they load
	// whether or not the stats source is connected now.
	const canLoadStats = hasStarted && ( isEnded || !! data.provider?.available );
	const hasStats = hasStarted && stats && stats.source.state !== 'unavailable';
	const ineligible = letters.filter( letter => false === test.variants[ letter ].eligible );
	// Scheduled counts as live: readers see it once the date comes.
	const publishedDuplicate = duplicates.some( duplicate => [ 'publish', 'future' ].includes( duplicate.status ) );
	// An Incomplete test can get its B from the card while A is its only prompt:
	// a start refuses a test with any other, and one whose A is gone has nothing to copy.
	const canCreateVersionB = status === 'incomplete' && !! test.variants.a && false !== test.variants.a.eligible && 1 === test.prompt_count;
	const unpublished =
		status === 'draft' ? letters.find( letter => test.variants[ letter ].status !== 'publish' && test.variants[ letter ].edit_link ) : null;

	let footer = null;
	if ( isEnded ) {
		// Only over numbers: an ended test whose source can't answer shows dashes.
		if ( hasStats && stats.source.final ) {
			footer = sprintf(
				// Translators: %1$s is the date the test started, %2$s the date it ended.
				__( 'Final results from %1$s to %2$s.', 'newspack-plugin' ),
				formatDay( test.started_at ),
				formatDay( test.ended_at )
			);
		} else if ( hasStats ) {
			footer = sprintf(
				// Translators: %1$s is the date the test started, %2$s the date it ended.
				__( 'Results from %1$s to %2$s. They become final two days after the test ends.', 'newspack-plugin' ),
				formatDay( test.started_at ),
				formatDay( test.ended_at )
			);
		}
	} else if ( hasStats && stats.source.state === 'collecting' ) {
		// Until a reader is counted, the start date tells more than the fetch time.
		footer = sprintf(
			// Translators: %s is a date.
			__( 'Started %s. Results refresh every hour.', 'newspack-plugin' ),
			dateI18n( getSettings().formats.date, test.started_at )
		);
	} else if ( stats?.source?.fetched_at ) {
		footer = sprintf(
			// Translators: %s is a date and time.
			__( 'Updated %s. Results refresh every hour.', 'newspack-plugin' ),
			dateI18n( getSettings().formats.datetime, stats.source.fetched_at )
		);
	}

	// Waits for the rename to render and the modal to close: focused any
	// earlier, the heading still reads the old name and sits under the modal's
	// aria-hidden, so a screen reader may not announce it.
	useEffect( () => {
		if ( focusHeading ) {
			headingRef.current?.focus();
			setFocusHeading( false );
		}
	}, [ focusHeading ] );

	// Opened from "View A/B Test" in a prompt's menu: bring this card into view.
	// Centered, because the page header is sticky from 782px up and would cover
	// a heading scrolled to the top.
	useEffect( () => {
		if ( ! isTarget ) {
			return;
		}
		headingRef.current?.scrollIntoView?.( { block: 'center' } );
		headingRef.current?.focus( { preventScroll: true } );
	}, [ isTarget ] );

	const load = refresh => {
		setLoading( true );
		setError( null );
		setWasRefresh( refresh );
		apiFetch( { path: `${ REST_BASE }/${ encodeURIComponent( test.test_id ) }/stats${ refresh ? '?refresh=1' : '' }` } )
			.then( response => {
				setStats( response );
				if ( refresh ) {
					speak( __( 'Results loaded.', 'newspack-plugin' ) );
				}
			} )
			.catch( err => setError( err.message || __( 'The results could not be loaded.', 'newspack-plugin' ) ) )
			.finally( () => setLoading( false ) );
	};

	// Again once the test ends: ending takes its final counts, which can be
	// newer than the ones already on the card.
	useEffect( () => {
		if ( canLoadStats ) {
			load( false );
		}
	}, [ isEnded ] );

	let mainAction = null;
	if ( canEndTest( test ) ) {
		mainAction = (
			<Button
				variant="secondary"
				onClick={ () => setModal( 'end' ) }
				aria-label={ sprintf(
					// Translators: %s is the test name.
					__( 'End Test: %s', 'newspack-plugin' ),
					name
				) }
			>
				{ __( 'End Test', 'newspack-plugin' ) }
			</Button>
		);
	} else if ( canCreateVersionB ) {
		mainAction = (
			<Button
				variant="secondary"
				onClick={ () => setModal( 'start' ) }
				aria-label={ sprintf(
					// Translators: %s is the test name.
					__( 'Create Version B: %s', 'newspack-plugin' ),
					name
				) }
			>
				{ __( 'Create Version B', 'newspack-plugin' ) }
			</Button>
		);
	} else if ( unpublished ) {
		mainAction = (
			<Button
				variant="secondary"
				href={ test.variants[ unpublished ].edit_link }
				aria-label={ sprintf(
					// Translators: %1$s is a version letter, %2$s is the test name.
					__( 'Edit Version %1$s: %2$s', 'newspack-plugin' ),
					unpublished.toUpperCase(),
					name
				) }
			>
				{ sprintf(
					// Translators: %s is a version letter.
					__( 'Edit Version %s', 'newspack-plugin' ),
					unpublished.toUpperCase()
				) }
			</Button>
		);
	}

	const editable = letters.filter( letter => test.variants[ letter ].edit_link );
	const menu = (
		<DropdownMenu
			icon={ moreVertical }
			label={ sprintf(
				// Translators: %s is the test name.
				__( 'Test actions: %s', 'newspack-plugin' ),
				name
			) }
			toggleProps={ { size: 'compact' } }
		>
			{ ( { onClose } ) => (
				<MenuGroup>
					{ canLoadStats && ! isEnded && (
						<MenuItem
							disabled={ loading }
							onClick={ () => {
								onClose();
								load( true );
							} }
						>
							{ __( 'Refresh Results', 'newspack-plugin' ) }
						</MenuItem>
					) }
					{ editable.map( letter => (
						<MenuItem key={ letter } href={ test.variants[ letter ].edit_link }>
							{ sprintf(
								// Translators: %s is a version letter.
								__( 'Edit Version %s', 'newspack-plugin' ),
								letter.toUpperCase()
							) }
						</MenuItem>
					) ) }
					<MenuItem
						aria-label={ sprintf(
							// Translators: %s is the test name.
							__( 'Rename: %s', 'newspack-plugin' ),
							name
						) }
						onClick={ () => {
							onClose();
							setModal( 'rename' );
						} }
					>
						{ __( 'Rename', 'newspack-plugin' ) }
					</MenuItem>
				</MenuGroup>
			) }
		</DropdownMenu>
	);

	const header = (
		<div className="newspack-ab-tests__header">
			<div className="newspack-ab-tests__header-top">
				<h2 id={ headingId } ref={ headingRef } tabIndex={ -1 } className="newspack-ab-tests__title">
					{ name }
				</h2>
				<Badge intent={ getStatusIntent( status ) }>{ getStatusLabel( status ) }</Badge>
				<Stack direction="row" gap="sm" align="center" className="newspack-ab-tests__header-actions">
					{ mainAction }
					{ menu }
				</Stack>
			</div>
			<p className="newspack-ab-tests__setup">
				<span>
					<span className="newspack-ab-tests__setup-key">{ __( 'Goal:', 'newspack-plugin' ) }</span>{ ' ' }
					<span className="newspack-ab-tests__setup-value">{ test.goal_label || __( 'Not set', 'newspack-plugin' ) }</span>
				</span>
				{ status !== 'incomplete' && ! isEnded && (
					<span>
						<span className="newspack-ab-tests__setup-key">{ __( 'Split:', 'newspack-plugin' ) }</span>{ ' ' }
						<span className="newspack-ab-tests__setup-value">
							{ splitSummary( test.control_share, Object.keys( test.variants || {} ) ) }
						</span>
					</span>
				) }
			</p>
			{ isEnded && (
				<p className="newspack-ab-tests__verdict">
					{ sprintf(
						// Translators: %1$s is the date the test ended, %2$s the letter of the version kept, such as "B".
						__( 'Ended %1$s. Kept %2$s.', 'newspack-plugin' ),
						formatDay( test.ended_at ),
						String( test.kept ).toUpperCase()
					) }
				</p>
			) }
		</div>
	);

	const notices = [];
	if ( duplicates.length > 0 ) {
		notices.push(
			<Notice
				key="duplicates"
				status="error"
				isDismissible={ false }
				politeness="polite"
				spokenMessage={
					publishedDuplicate
						? sprintf(
								// Translators: %s is the test name.
								__( 'More than one prompt in %s is the same version. Unpublish the extra prompt.', 'newspack-plugin' ),
								name
						  )
						: sprintf(
								// Translators: %s is the test name.
								__( 'More than one prompt in %s is the same version. Move the extra prompt to the trash.', 'newspack-plugin' ),
								name
						  )
				}
			>
				{ publishedDuplicate
					? __(
							'More than one prompt in this test is the same version. Readers still see both, and results only count the first. Unpublish the extra prompt:',
							'newspack-plugin'
					  )
					: __(
							"More than one prompt in this test is the same version. The extra one isn't published, so readers don't see it. Move the extra prompt to the trash:",
							'newspack-plugin'
					  ) }{ ' ' }
				{ duplicates.map( ( duplicate, index ) => {
					const title = decodeEntities( duplicate.title );
					return (
						<span key={ duplicate.id }>
							{ index > 0 && ', ' }
							{ duplicate.edit_link ? <a href={ duplicate.edit_link }>{ title }</a> : title }{ ' ' }
							{ sprintf(
								// Translators: %s is the version letter.
								__( '(version %s)', 'newspack-plugin' ),
								String( duplicate.letter ).toUpperCase()
							) }
						</span>
					);
				} ) }
			</Notice>
		);
	}
	if ( ineligible.length > 0 ) {
		notices.push(
			<Notice key="ineligible" status="warning" isDismissible={ false } spokenMessage="">
				{ sprintf(
					// Translators: %s is a list of version letters, such as "A" or "A and B".
					_n(
						"Version %s uses a manual or custom placement, or is an overlay set to Manual only, which A/B tests don't support, so its results aren't reliable. Change its placement or turn off Manual only in the prompt editor.",
						"Versions %s use a manual or custom placement, or are overlays set to Manual only, which A/B tests don't support, so their results aren't reliable. Change their placement or turn off Manual only in the prompt editor.",
						ineligible.length,
						'newspack-plugin'
					),
					formatList( ineligible.map( letter => letter.toUpperCase() ) )
				) }
			</Notice>
		);
	}
	if ( status === 'incomplete' ) {
		notices.push(
			<Notice key="incomplete" status="warning" isDismissible={ false } spokenMessage="">
				{ getMissingVersionHint( test.variants ) }
			</Notice>
		);
	}
	if ( loading && ! stats ) {
		notices.push(
			<Stack key="loading" direction="row" gap="sm" align="center">
				<Spinner />
				<span>{ __( 'Loading results…', 'newspack-plugin' ) }</span>
			</Stack>
		);
	}
	// The page already says when no stats source is connected; an ended
	// card, which loads its results regardless, needn't say it again.
	if ( stats?.source?.reason && ! ( stats.source.state === 'unavailable' && ! data.provider?.available ) ) {
		notices.push(
			<Notice key="source" status={ stats.source.state === 'unavailable' ? 'warning' : 'info' } isDismissible={ false } spokenMessage="">
				{ stats.source.reason }
			</Notice>
		);
	}
	if ( hasStats && stats.source.state === 'ok' && stats.source.unit === 'events' ) {
		notices.push(
			<p key="events" className="newspack-ab-tests__muted">
				{ __(
					'Counts are views rather than readers, because the stats source could not count distinct readers for this test.',
					'newspack-plugin'
				) }
			</p>
		);
	}
	if ( hasStats && status !== 'incomplete' && ! isEnded && stats.flags?.young_test ) {
		notices.push(
			<Notice key="young" status="info" isDismissible={ false } spokenMessage="">
				{ data.copy?.young_test }
			</Notice>
		);
	}
	if ( hasStats && status !== 'incomplete' && ! isEnded && stats.flags?.high_anonymous ) {
		notices.push(
			<Notice key="anonymous" status="info" isDismissible={ false } spokenMessage="">
				{ data.copy?.high_anonymous }
			</Notice>
		);
	}
	if ( error ) {
		notices.push(
			<Notice key="error" status="error" isDismissible={ false } politeness={ wasRefresh ? 'assertive' : 'polite' }>
				{ error }
			</Notice>
		);
	}

	return (
		<Card
			className="newspack-ab-tests__card"
			role="region"
			aria-labelledby={ headingId }
			isSmall
			__experimentalCoreCard
			__experimentalCoreProps={ { noMargin: true, header } }
		>
			{ notices.length > 0 && (
				<CardBody className="newspack-ab-tests__notices">
					<Stack direction="column" gap="lg">
						{ notices }
					</Stack>
				</CardBody>
			) }
			<CardBody className="newspack-ab-tests__results">
				<ResultsTable
					test={ test }
					status={ status }
					stats={ hasStats ? stats : null }
					labels={ data.labels || {} }
					kept={ isEnded ? test.kept : null }
				/>
			</CardBody>
			{ footer && (
				<CardBody className="newspack-ab-tests__footer">
					<p className="newspack-ab-tests__updated">{ footer }</p>
				</CardBody>
			) }
			{ modal === 'start' && (
				<StartTestModal
					promptId={ test.variants.a.id }
					testName={ name }
					goals={ data.goals || {} }
					initialGoal={ test.goal }
					initialShare={ test.control_share }
					intro={ sprintf(
						// Translators: %s is the title of version A.
						__(
							'Version B starts as a draft copy of “%s” with the same placement. Change what you want to compare, then publish it to start the test.',
							'newspack-plugin'
						),
						decodeEntities( test.variants.a.title )
					) }
					onCreated={ openVersionB }
					onClose={ () => setModal( null ) }
				/>
			) }
			{ modal === 'end' && (
				<EndTestModal
					test={ test }
					stats={ hasStats ? stats : null }
					onEnded={ ended => {
						setModal( null );
						// End Test is gone from an ended card, so the modal has
						// nowhere to return focus to.
						setFocusHeading( true );
						onEnded( ended );
					} }
					onClose={ () => setModal( null ) }
				/>
			) }
			{ modal === 'rename' && (
				<RenameModal
					test={ test }
					onRenamed={ newName => {
						setModal( null );
						onRenamed( test.test_id, newName );
						setFocusHeading( true );
						speak( __( 'Test renamed.', 'newspack-plugin' ) );
					} }
					onClose={ () => setModal( null ) }
				/>
			) }
		</Card>
	);
};

export default TestCard;

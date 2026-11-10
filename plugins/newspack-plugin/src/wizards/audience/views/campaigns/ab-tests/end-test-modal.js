/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, Notice, RadioControl } from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { useEffect, useRef, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import Modal from '../../../../../../packages/components/src/modal';
import { REST_BASE, formatRate, getRecommendation } from './utils';

/**
 * What an option says about its version: the prompt's title, then its rate,
 * once. The recommended option also says why it is recommended.
 *
 * @param {Object}  props
 * @param {string}  props.letter         Version letter.
 * @param {Object}  props.test           The test.
 * @param {?Object} props.stats          Its stats, or null.
 * @param {Object}  props.recommendation From getRecommendation().
 * @return {string} Description.
 */
const describeOption = ( { letter, test, stats, recommendation } ) => {
	const title = decodeEntities( test.variants[ letter ].title ) || __( '(no title)', 'newspack-plugin' );
	const row = stats?.source?.state !== 'unavailable' ? stats?.variants?.[ letter ] : null;
	const rate = row && row.rate !== null && row.rate !== undefined ? formatRate( row.rate ) : null;
	const parts = [];
	if ( recommendation.letter === letter && recommendation.winner ) {
		// The runner-up: A against a winning challenger, or the best challenger
		// against a winning A.
		const others = Object.keys( test.variants ).filter( other => other !== letter && stats.variants?.[ other ] );
		const other =
			letter === 'a' ? others.reduce( ( top, l ) => ( stats.variants[ l ].rate > stats.variants[ top ].rate ? l : top ), others[ 0 ] ) : 'a';
		parts.push(
			sprintf(
				// Translators: %1$s is a conversion rate such as "2.69%", %2$s another, %3$s a version letter such as "A".
				__( 'The reliable winner: %1$s converted, against %2$s for %3$s.', 'newspack-plugin' ),
				rate,
				formatRate( stats.variants[ other ].rate ),
				other.toUpperCase()
			)
		);
	} else {
		if ( rate ) {
			parts.push(
				sprintf(
					// Translators: %s is a conversion rate such as "1.90%".
					__( '%s converted.', 'newspack-plugin' ),
					rate
				)
			);
		}
		// Only over results: with none, there's no winner to say is missing.
		if ( recommendation.letter === letter && rate ) {
			parts.push( __( "There's no reliable winner yet, so keeping the original is the safer choice.", 'newspack-plugin' ) );
		}
	}
	if ( parts.length === 0 ) {
		return title;
	}
	// A title is a name, not a sentence; end it so the sentences after it read on.
	return `${ /[.!?…]$/.test( title ) ? title : `${ title }.` } ${ parts.join( ' ' ) }`;
};

/**
 * End a test: keep one version published and move the others to draft.
 *
 * @param {Object}   props
 * @param {Object}   props.test    The test.
 * @param {?Object}  props.stats   Its stats, or null while they are loading or missing.
 * @param {Function} props.onEnded Called with the ended test the route returns.
 * @param {Function} props.onClose Called on Cancel or close.
 */
const EndTestModal = ( { test, stats, onEnded, onClose } ) => {
	const recommendation = getRecommendation( test, stats );
	// The selection follows the recommendation, which can change as the card's
	// results arrive, until the user picks a version.
	const [ picked, setPicked ] = useState( null );
	const keep = picked ?? recommendation.letter;
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const formRef = useRef( null );
	const letters = Object.keys( test.variants ).sort();
	const name = test.name || test.test_id;

	// Start on the selected option, where the arrow keys move from, rather than
	// on whichever element the modal's own focus-on-mount settles on.
	useEffect( () => {
		formRef.current?.querySelector( 'input[type="radio"]:checked' )?.focus();
	}, [] );

	const end = async event => {
		event.preventDefault();
		if ( isBusy ) {
			return;
		}
		setIsBusy( true );
		setError( '' );
		try {
			const ended = await apiFetch( {
				path: `${ REST_BASE }/${ encodeURIComponent( test.test_id ) }/end`,
				method: 'POST',
				data: { keep },
			} );
			onEnded( ended );
		} catch ( e ) {
			setError( e?.message || __( 'Could not end the test. Try again.', 'newspack-plugin' ) );
			setIsBusy( false );
		}
	};

	return (
		<Modal title={ __( 'End Test', 'newspack-plugin' ) } size="medium" focusOnMount="firstContentElement" onRequestClose={ onClose }>
			<form onSubmit={ end } ref={ formRef }>
				<Stack direction="column" gap="xl">
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					<p className="newspack-ab-tests__intro">
						{ sprintf(
							// Translators: %s is the test name.
							_n(
								'Choose which version of “%s” to keep. It stays published for every reader, and the other version moves to draft.',
								'Choose which version of “%s” to keep. It stays published for every reader, and the other versions move to draft.',
								letters.length - 1,
								'newspack-plugin'
							),
							name
						) }
					</p>
					<RadioControl
						label={ __( 'Version to keep', 'newspack-plugin' ) }
						selected={ keep }
						onChange={ setPicked }
						disabled={ isBusy }
						options={ letters.map( letter => ( {
							value: letter,
							label:
								recommendation.letter === letter
									? sprintf(
											// Translators: %s is a version letter such as "B".
											__( 'Keep %s (Recommended)', 'newspack-plugin' ),
											letter.toUpperCase()
									  )
									: sprintf(
											// Translators: %s is a version letter such as "A".
											__( 'Keep %s', 'newspack-plugin' ),
											letter.toUpperCase()
									  ),
							description: describeOption( { letter, test, stats, recommendation } ),
						} ) ) }
					/>
					<Stack justify="flex-end" align="center" gap="lg">
						<Button variant="secondary" onClick={ onClose } disabled={ isBusy } __next40pxDefaultSize>
							{ __( 'Cancel', 'newspack-plugin' ) }
						</Button>
						<Button variant="primary" type="submit" isBusy={ isBusy } disabled={ isBusy } accessibleWhenDisabled __next40pxDefaultSize>
							{ __( 'End Test', 'newspack-plugin' ) }
						</Button>
					</Stack>
				</Stack>
			</form>
		</Modal>
	);
};

export default EndTestModal;

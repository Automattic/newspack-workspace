/**
 * Shared Contextual Prompt generation UI.
 *
 * Single source of truth for everything the block's Copy panel and the
 * document-settings panel both need: the post-type label, framing labels, the
 * generation request, and the generate-button / candidate-list presentation.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { dispatch } from '@wordpress/data';
import { useRef, useState } from '@wordpress/element';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { Button, Card, CardBody, __experimentalVStack as VStack } from '@wordpress/components';
import { escapeHTML } from '@wordpress/escape-html';

// The block editor and the document-settings panel are separate entries with
// separate localized objects; either may be the one present.
export const POST_TYPE_LABEL =
	window.newspack_popups_blocks_data?.post_type_label || window.newspackPopupsContextualPrompt?.postTypeLabel || __( 'post', 'newspack-popups' );

// The post type's singular label as it declares it, for the framing headings.
// Recasing the lowercased sentence label here would mis-case some locales.
const POST_TYPE_HEADING =
	window.newspack_popups_blocks_data?.post_type_heading ||
	window.newspackPopupsContextualPrompt?.postTypeHeading ||
	__( 'Post', 'newspack-popups' );

export const FRAMING_LABELS = {
	/* translators: %s: the edited content's post type label, e.g. "Post", "Page". */
	top: sprintf( __( 'Top of %s', 'newspack-popups' ), POST_TYPE_HEADING ),
	/* translators: %s: the edited content's post type label, e.g. "Post", "Page". */
	mid: sprintf( __( 'Mid-%s', 'newspack-popups' ), POST_TYPE_HEADING ),
	/* translators: %s: the edited content's post type label, e.g. "Post", "Page". */
	end: sprintf( __( 'End of %s', 'newspack-popups' ), POST_TYPE_HEADING ),
};

/**
 * The framing implied by a block's position among the article's top-level
 * blocks, as a coarse ratio-based bucket: top / mid / end.
 *
 * Mirrors get_placement() in class-newspack-popups-contextual-prompt-render.php,
 * which computes the server-side analytics placement. Keep the two in sync: if
 * they diverge, the generated copy is framed for a different position than the
 * one analytics reports.
 *
 * @param {number} index Block index.
 * @param {number} total Top-level block count.
 * @return {string} One of 'top' | 'mid' | 'end'.
 */
export const framingForPosition = ( index, total ) => {
	if ( total <= 1 ) {
		return 'top';
	}
	const ratio = index / ( total - 1 );
	if ( ratio <= 1 / 3 ) {
		return 'top';
	}
	if ( ratio >= 2 / 3 ) {
		return 'end';
	}
	return 'mid';
};

/**
 * Model output is stored in a RichText attribute, which serializes strings as
 * raw HTML. The manager sanitizes server-side; encoding here too means nothing
 * a model returns can reach the post as markup.
 *
 * @param {string} body The candidate copy.
 * @return {string} The copy, encoded as plain text.
 */
export const toRichTextContent = body => escapeHTML( String( body ?? '' ) );

/**
 * Request donation prompt candidates for a post.
 *
 * @param {Object}  args              Request arguments.
 * @param {number}  args.postId       The post being edited.
 * @param {string}  args.content      The edited post content.
 * @param {string}  [args.framing]    Optional framing; when set, candidates are variants of it.
 * @param {boolean} [args.regenerate] Whether this is an explicit re-run, which bypasses the cached response.
 * @return {Promise<Array>} The candidate list (possibly empty). Rejects on a malformed response.
 */
export const generateCandidates = async ( { postId, content, framing, regenerate } ) => {
	const response = await apiFetch( {
		path: '/wp/v2/newspack-editorial-assistant/generate/donation',
		method: 'POST',
		data: { post_id: postId, content, ...( framing ? { framing } : {} ), ...( regenerate ? { regenerate: true } : {} ) },
	} );
	const payload = response && response.data ? response.data : response;
	// A malformed or version-skewed response must land in the caller's error
	// state, not crash the candidate list; entries the UI can't render are
	// dropped rather than trusted.
	if ( ! Array.isArray( payload?.candidates ) ) {
		throw new Error( __( 'Could not generate suggestions.', 'newspack-popups' ) );
	}
	return payload.candidates.filter(
		candidate =>
			candidate &&
			'object' === typeof candidate &&
			! Array.isArray( candidate ) &&
			'string' === typeof candidate.body &&
			candidate.body.trim() &&
			( undefined === candidate.framing || Boolean( FRAMING_LABELS[ candidate.framing ] ) )
	);
};

export const GenerateButton = ( { busy, disabled, onClick, variant = 'secondary', children } ) => (
	<Button
		variant={ variant }
		onClick={ onClick }
		disabled={ busy || disabled }
		isBusy={ busy }
		__next40pxDefaultSize
		className="newspack-popups__contextual-prompt-generate"
	>
		{ busy ? __( 'Generating…', 'newspack-popups' ) : children }
	</Button>
);

// Arrow keys move the selection within a radiogroup, in either orientation.
const ARROW_STEPS = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 };

// Applying is near-instant, so the busy state is held first, long enough to
// read as an acknowledgement rather than a flicker.
const MIN_APPLY_MS = 900;

export const CandidateList = ( { candidates, onApply, onApplyingChange, confirmation, action } ) => {
	const [ selected, setSelected ] = useState( -1 );
	const [ listed, setListed ] = useState( candidates );
	const [ applying, setApplying ] = useState( false );
	const cardRefs = useRef( [] );

	// Regenerating replaces the list, so a selection made against the previous
	// one points at copy nobody chose.
	if ( listed !== candidates ) {
		setListed( candidates );
		setSelected( -1 );
	}

	// With nothing to choose from, generating is the only thing on offer.
	if ( ! candidates.length ) {
		return action || null;
	}

	const select = index => {
		if ( applying ) {
			return;
		}
		setSelected( index );
		cardRefs.current[ index ]?.focus();
	};

	const applySelected = () => {
		const candidate = candidates[ selected ];
		setApplying( true );
		onApplyingChange?.( true );
		// Both hosts hide the list as soon as the copy lands, so the busy state
		// has to play out before the application, not during it.
		setTimeout( () => {
			Promise.resolve( onApply( candidate ) ).then( () => {
				setApplying( false );
				onApplyingChange?.( false );
				dispatch( 'core/notices' ).createSuccessNotice( confirmation || __( 'Suggestion applied.', 'newspack-popups' ), {
					type: 'snackbar',
				} );
			} );
		}, MIN_APPLY_MS );
	};

	const onKeyDown = ( event, index ) => {
		const step = ARROW_STEPS[ event.key ];
		if ( step ) {
			event.preventDefault();
			const from = 0 > selected ? index : selected;
			select( ( from + step + candidates.length ) % candidates.length );
			return;
		}
		if ( 'Enter' === event.key || ' ' === event.key ) {
			event.preventDefault();
			select( index );
		}
	};

	// Roving tab stop: the group is one stop, entered on the selection or, with
	// nothing selected, on the first card.
	const tabStop = 0 > selected ? 0 : selected;

	const selectedFraming = 0 <= selected ? FRAMING_LABELS[ candidates[ selected ].framing ] || candidates[ selected ].framing : '';

	return (
		<>
			<VStack
				spacing={ 4 }
				className="newspack-popups__contextual-prompt-candidates"
				role="radiogroup"
				aria-label={ __( 'Suggestions', 'newspack-popups' ) }
			>
				{ candidates.map( ( candidate, index ) => {
					const framingLabel = FRAMING_LABELS[ candidate.framing ] || candidate.framing;
					return (
						<Card
							key={ index }
							ref={ node => {
								cardRefs.current[ index ] = node;
							} }
							className="newspack-popups__contextual-prompt-candidate"
							size="small"
							role="radio"
							aria-checked={ index === selected }
							aria-label={
								framingLabel
									? sprintf(
											/* translators: %1$s: the suggestion's framing label, e.g. "Top of Post". %2$s: the suggested copy. */
											__( 'Suggestion (%1$s): %2$s', 'newspack-popups' ),
											framingLabel,
											candidate.body
									  )
									: sprintf(
											/* translators: %s: the suggested copy. */
											__( 'Suggestion: %s', 'newspack-popups' ),
											candidate.body
									  )
							}
							tabIndex={ index === tabStop ? 0 : -1 }
							onClick={ () => select( index ) }
							onKeyDown={ event => onKeyDown( event, index ) }
						>
							<CardBody>
								{ framingLabel && <strong>{ framingLabel }</strong> }
								<p className="newspack-popups__contextual-prompt-candidate-body">{ candidate.body }</p>
							</CardBody>
						</Card>
					);
				} ) }
			</VStack>
			<VStack spacing={ 2 }>
				<Button
					variant="primary"
					__next40pxDefaultSize
					className="newspack-popups__contextual-prompt-apply"
					disabled={ applying || 0 > selected }
					isBusy={ applying }
					onClick={ applySelected }
					aria-label={
						selectedFraming
							? sprintf(
									/* translators: %s: the selected suggestion's framing label, e.g. "Top of Post". */
									__( 'Apply suggestion: %s', 'newspack-popups' ),
									selectedFraming
							  )
							: __( 'Apply the selected suggestion', 'newspack-popups' )
					}
				>
					{ __( 'Apply', 'newspack-popups' ) }
				</Button>
				{ action }
			</VStack>
		</>
	);
};

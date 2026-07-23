/**
 * Contextual Prompt editor panel.
 *
 * Generates story-specific donation prompts for the post being edited (via the
 * Newspack Manager editorial-assistant), lets the editor pick and edit one, and
 * creates or updates a post-scoped Campaigns prompt from it — all without
 * leaving the story.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import {
	Button,
	TextControl,
	TextareaControl,
	ToggleControl,
	Notice,
	Spinner,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import ContextualPromptPreview from './contextual-prompt-preview';
import { STORE_NAME } from './contextual-prompt-store';

const FRAMING_LABELS = {
	top: __( 'Top of story', 'newspack-popups' ),
	mid: __( 'Mid-story', 'newspack-popups' ),
	end: __( 'End of story', 'newspack-popups' ),
};

const ContextualPromptPanel = () => {
	const { postId, postType, postLink, content, paragraphCount } = useSelect( select => {
		const editor = select( 'core/editor' );
		// Count top-level paragraphs from the block tree rather than by matching
		// `<!-- wp:paragraph` in the serialized content: paragraphs nested inside
		// columns, groups or reusable blocks would inflate a raw match and push the
		// suggested mid/end position past the visible body.
		const blocks = select( 'core/block-editor' ).getBlocks() || [];
		return {
			postId: editor.getCurrentPostId(),
			postType: editor.getCurrentPostType(),
			postLink: editor.getPermalink(),
			content: editor.getEditedPostContent(),
			paragraphCount: blocks.filter( block => 'core/paragraph' === block.name ).length,
		};
	}, [] );

	const [ loading, setLoading ] = useState( true );
	const [ promptId, setPromptId ] = useState( null );
	const [ editLink, setEditLink ] = useState( '' );
	const [ candidates, setCandidates ] = useState( [] );
	const [ templateVersion, setTemplateVersion ] = useState( '' );
	const [ body, setBody ] = useState( '' );
	const [ originalBody, setOriginalBody ] = useState( '' );
	const [ buttonLabel, setButtonLabel ] = useState( '' );
	const [ buttonUrl, setButtonUrl ] = useState( '' );
	const [ position, setPosition ] = useState( 3 );
	const [ editing, setEditing ] = useState( false );
	const [ generating, setGenerating ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ saved, setSaved ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ enabled, setEnabled ] = useState( true );
	const [ overrideActive, setOverrideActive ] = useState( false );
	const [ customized, setCustomized ] = useState( false );

	const optedIn = window.newspackPopupsContextualPrompt?.enabled;
	const donationsNative = window.newspackPopupsContextualPrompt?.donationsNative;
	const isPrompt = 'newspack_popups_cpt' === postType;

	// Publish what the prompt looks like and where it lands, so the in-editor
	// placement indicator can draw it in the block list.
	const { setPreview } = useDispatch( STORE_NAME );
	useEffect( () => {
		setPreview( {
			active: Boolean( optedIn && ! isPrompt && editing && enabled ),
			position,
			body,
			buttonLabel,
			donationsNative: Boolean( donationsNative ),
			overrideActive,
		} );
	}, [ optedIn, isPrompt, editing, enabled, position, body, buttonLabel, donationsNative, overrideActive, setPreview ] );

	// Load an existing Contextual Prompt for this post, if any.
	useEffect( () => {
		if ( ! optedIn || isPrompt || ! postId ) {
			setLoading( false );
			return;
		}
		apiFetch( { path: `/newspack-popups/v1/contextual-prompt?post_id=${ postId }` } )
			.then( ( { prompt, override_active: isOverridden } ) => {
				setOverrideActive( Boolean( isOverridden ) );
				if ( prompt ) {
					setPromptId( prompt.id );
					setEditLink( prompt.edit_link );
					setBody( prompt.body );
					setOriginalBody( prompt.body );
					setButtonLabel( prompt.button_label );
					setButtonUrl( prompt.button_url );
					setPosition( prompt.position );
					setEnabled( false !== prompt.enabled );
					setCustomized( Boolean( prompt.customized ) );
					setEditing( true );
				}
			} )
			.catch( () => {} )
			.finally( () => setLoading( false ) );
	}, [ optedIn, isPrompt, postId ] );

	// Hidden until an administrator opts the site into AI use; never on a prompt.
	if ( ! optedIn || isPrompt ) {
		return null;
	}

	const generate = async () => {
		setGenerating( true );
		setError( '' );
		try {
			const response = await apiFetch( {
				path: '/wp/v2/newspack-editorial-assistant/generate/donation',
				method: 'POST',
				data: { post_id: postId, content },
			} );
			const payload = response && response.data ? response.data : response;
			const list = ( payload && payload.candidates ) || [];
			setCandidates( list );
			setTemplateVersion( ( payload && payload.templateVersion ) || '' );
			if ( ! list.length ) {
				setError( __( 'No suggestions were returned. Try generating again.', 'newspack-popups' ) );
			}
		} catch ( e ) {
			setError( e.message || __( 'Could not generate suggestions.', 'newspack-popups' ) );
		} finally {
			setGenerating( false );
		}
	};

	// A framing implies where the prompt should sit; default the position to match.
	const chooseCandidate = candidate => {
		setBody( candidate.body );
		setOriginalBody( candidate.body );
		if ( candidate.buttonLabel ) {
			setButtonLabel( candidate.buttonLabel );
		}
		let framePosition = Math.max( 1, Math.floor( paragraphCount / 2 ) );
		if ( 'top' === candidate.framing ) {
			framePosition = 0;
		} else if ( 'end' === candidate.framing ) {
			framePosition = paragraphCount;
		}
		setPosition( framePosition );
		setEditing( true );
		setSaved( false );
	};

	const persist = async ( overrides = {} ) => {
		const response = await apiFetch( {
			path: '/newspack-popups/v1/contextual-prompt',
			method: 'POST',
			data: {
				post_id: postId,
				prompt_id: promptId || undefined,
				body,
				button_label: buttonLabel,
				button_url: buttonUrl,
				position,
				template_version: templateVersion,
				ai_generated: ! promptId,
				ai_edited: body !== originalBody,
				...overrides,
			},
		} );
		setPromptId( response.id );
		setEditLink( response.edit_link );
		setCustomized( Boolean( response.customized ) );
		// The server is authoritative about visibility — reset and show/hide are
		// applied independently of the copy, so a copy failure must not leave the
		// toggle showing something the database disagrees with.
		if ( undefined !== response.enabled ) {
			setEnabled( Boolean( response.enabled ) );
		}
		if ( response.copy_error ) {
			setError( response.copy_error.message );
		}
		return response;
	};

	// Discard this prompt's custom design and go back to the site default.
	const resetDesign = async () => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( 'Discard this prompt’s custom design and use the site default?', 'newspack-popups' ) ) ) {
			return;
		}
		setSaving( true );
		setError( '' );
		try {
			await persist( { reset_design: true } );
		} catch ( e ) {
			setError( e.message || __( 'Could not reset the design.', 'newspack-popups' ) );
		} finally {
			setSaving( false );
		}
	};

	const save = async () => {
		setSaving( true );
		setError( '' );
		try {
			await persist();
			setCandidates( [] );
			setSaved( true );
		} catch ( e ) {
			setError( e.message || __( 'Could not save the prompt.', 'newspack-popups' ) );
		} finally {
			setSaving( false );
		}
	};

	// Show/hide takes effect immediately — an editor pulling a CTA from a story
	// shouldn't have to also remember to save.
	const toggleEnabled = async next => {
		setEnabled( next );
		setSaving( true );
		setError( '' );
		try {
			// persist() reconciles the toggle with the server's own state, so a
			// partially-applied request can't leave the panel out of step.
			await persist( { enabled: next } );
		} catch ( e ) {
			setEnabled( ! next );
			setError( e.message || __( 'Could not update the prompt.', 'newspack-popups' ) );
		} finally {
			setSaving( false );
		}
	};

	const generateLabel =
		candidates.length || editing ? __( 'Regenerate suggestions', 'newspack-popups' ) : __( 'Generate suggestions', 'newspack-popups' );
	const saveLabel = promptId ? __( 'Update prompt', 'newspack-popups' ) : __( 'Create prompt', 'newspack-popups' );

	return (
		<PluginDocumentSettingPanel name="newspack-contextual-prompt" title={ __( 'Contextual Prompt', 'newspack-popups' ) }>
			{ loading ? (
				<Spinner />
			) : (
				<VStack spacing={ 4 }>
					{ ! editing && ! candidates.length && (
						<p style={ { margin: 0 } }>{ __( 'Generate a story-specific donation prompt for this post.', 'newspack-popups' ) }</p>
					) }

					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }

					{ overrideActive && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'A site-wide override is currently replacing every Contextual Prompt. This story will show the override copy instead of its own until the override is turned off in Campaigns → Settings.',
								'newspack-popups'
							) }
						</Notice>
					) }

					{ saved && (
						<Notice status="success" isDismissible={ false }>
							<p style={ { margin: 0 } }>
								{ __( 'Saved. Readers see it on this story at the position you chose.', 'newspack-popups' ) }
							</p>
						</Notice>
					) }

					<Button variant="secondary" onClick={ generate } disabled={ generating }>
						{ generating && <Spinner /> }
						{ generating ? __( 'Generating…', 'newspack-popups' ) : generateLabel }
					</Button>

					{ candidates.map( ( candidate, index ) => (
						<VStack key={ index } spacing={ 2 } className="newspack-contextual-prompt__candidate">
							<strong>{ FRAMING_LABELS[ candidate.framing ] || candidate.framing }</strong>
							{ candidate.flags?.includes( 'over_word_cap' ) && (
								<Notice status="warning" isDismissible={ false }>
									{ __( 'This suggestion is longer than recommended.', 'newspack-popups' ) }
								</Notice>
							) }
							<p style={ { margin: 0 } }>{ candidate.body }</p>
							<div>
								<Button variant="secondary" onClick={ () => chooseCandidate( candidate ) }>
									{ __( 'Use this', 'newspack-popups' ) }
								</Button>
							</div>
						</VStack>
					) ) }

					{ editing && (
						<VStack spacing={ 3 } className="newspack-contextual-prompt__edit">
							{ customized && (
								<Notice status="info" isDismissible={ false }>
									<p style={ { margin: 0 } }>
										{ __(
											'This prompt uses a custom design you made in Advanced settings, instead of your site’s default. Editing the copy here updates the wording and leaves that design as it is.',
											'newspack-popups'
										) }
									</p>
									<p style={ { margin: '8px 0 0' } }>
										<Button variant="link" onClick={ resetDesign } disabled={ saving }>
											{ __( 'Reset to default design', 'newspack-popups' ) }
										</Button>
									</p>
								</Notice>
							) }

							{ promptId && (
								<ToggleControl
									label={ __( 'Show on this story', 'newspack-popups' ) }
									help={
										enabled
											? __( 'Readers see this prompt on the story.', 'newspack-popups' )
											: __( 'Hidden from readers. The copy is kept so you can show it again.', 'newspack-popups' )
									}
									checked={ enabled }
									disabled={ saving }
									onChange={ toggleEnabled }
								/>
							) }

							{ /* Roughly phone-width, where awkward line breaks show up. */ }
							<div>
								<p
									style={ {
										margin: '0 0 4px',
										fontSize: 11,
										textTransform: 'uppercase',
										letterSpacing: '0.4px',
										color: '#757575',
									} }
								>
									{ __( 'Preview', 'newspack-popups' ) }
								</p>
								<ContextualPromptPreview body={ body } buttonLabel={ buttonLabel } donationsNative={ donationsNative } narrow />
							</div>

							<TextareaControl label={ __( 'Prompt copy', 'newspack-popups' ) } rows={ 4 } value={ body } onChange={ setBody } />
							{ donationsNative ? (
								<p style={ { margin: 0, fontStyle: 'italic' } }>
									{ __( 'Uses your Newspack donation form, so gifts are tracked as donations.', 'newspack-popups' ) }
								</p>
							) : (
								<>
									<TextControl
										label={ __( 'Button label', 'newspack-popups' ) }
										value={ buttonLabel }
										onChange={ setButtonLabel }
									/>
									<TextControl
										label={ __( 'Donate URL', 'newspack-popups' ) }
										type="url"
										value={ buttonUrl }
										onChange={ setButtonUrl }
									/>
								</>
							) }
							<TextControl
								label={ __( 'Position (paragraph)', 'newspack-popups' ) }
								help={ __( 'Set automatically from the suggestion’s framing; adjust as needed.', 'newspack-popups' ) }
								type="number"
								min={ 0 }
								value={ position }
								onChange={ value => setPosition( parseInt( value, 10 ) || 0 ) }
							/>
							<div>
								<Button variant="primary" onClick={ save } disabled={ saving || ! body.trim() }>
									{ saving ? __( 'Saving…', 'newspack-popups' ) : saveLabel }
								</Button>
							</div>

							{ /* Always reachable once a prompt exists — Advanced settings is where
							     design is edited, so it must not be a one-time post-save link. */ }
							{ promptId && ( postLink || editLink ) && (
								<p style={ { margin: 0 } }>
									{ postLink && (
										<a href={ postLink } target="_blank" rel="noreferrer">
											{ __( 'View story', 'newspack-popups' ) }
										</a>
									) }
									{ postLink && editLink && ' · ' }
									{ editLink && (
										<a href={ editLink } target="_blank" rel="noreferrer">
											{ __( 'Edit design in Advanced settings', 'newspack-popups' ) }
										</a>
									) }
								</p>
							) }
						</VStack>
					) }
				</VStack>
			) }
		</PluginDocumentSettingPanel>
	);
};

export default ContextualPromptPanel;

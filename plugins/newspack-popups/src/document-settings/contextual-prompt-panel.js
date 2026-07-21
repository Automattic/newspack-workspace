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
import { useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { Button, TextControl, TextareaControl, Notice, Spinner, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import apiFetch from '@wordpress/api-fetch';

const FRAMING_LABELS = {
	top: __( 'Top of story', 'newspack-popups' ),
	mid: __( 'Mid-story', 'newspack-popups' ),
	end: __( 'End of story', 'newspack-popups' ),
};

const ContextualPromptPanel = () => {
	const { postId, postType, postLink, content } = useSelect( select => {
		const editor = select( 'core/editor' );
		return {
			postId: editor.getCurrentPostId(),
			postType: editor.getCurrentPostType(),
			postLink: editor.getPermalink(),
			content: editor.getEditedPostContent(),
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

	const optedIn = window.newspackPopupsContextualPrompt?.enabled;
	const isPrompt = 'newspack_popups_cpt' === postType;

	// Load an existing Contextual Prompt for this post, if any.
	useEffect( () => {
		if ( ! optedIn || isPrompt || ! postId ) {
			setLoading( false );
			return;
		}
		apiFetch( { path: `/newspack-popups/v1/contextual-prompt?post_id=${ postId }` } )
			.then( ( { prompt } ) => {
				if ( prompt ) {
					setPromptId( prompt.id );
					setEditLink( prompt.edit_link );
					setBody( prompt.body );
					setOriginalBody( prompt.body );
					setButtonLabel( prompt.button_label );
					setButtonUrl( prompt.button_url );
					setPosition( prompt.position );
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
		const count = ( content.match( /<!-- wp:paragraph/g ) || [] ).length;
		let framePosition = Math.max( 1, Math.floor( count / 2 ) );
		if ( 'top' === candidate.framing ) {
			framePosition = 0;
		} else if ( 'end' === candidate.framing ) {
			framePosition = count;
		}
		setPosition( framePosition );
		setEditing( true );
		setSaved( false );
	};

	const save = async () => {
		setSaving( true );
		setError( '' );
		try {
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
				},
			} );
			setPromptId( response.id );
			setEditLink( response.edit_link );
			setCandidates( [] );
			setSaved( true );
		} catch ( e ) {
			setError( e.message || __( 'Could not save the prompt.', 'newspack-popups' ) );
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

					{ saved && (
						<Notice status="success" isDismissible={ false }>
							<p style={ { margin: 0 } }>
								{ __( 'Saved. Readers see it on this story at the position you chose.', 'newspack-popups' ) }
							</p>
							<p style={ { margin: '8px 0 0' } }>
								{ postLink && (
									<a href={ postLink } target="_blank" rel="noreferrer">
										{ __( 'View story', 'newspack-popups' ) }
									</a>
								) }
								{ postLink && editLink && ' · ' }
								{ editLink && (
									<a href={ editLink } target="_blank" rel="noreferrer">
										{ __( 'Advanced settings', 'newspack-popups' ) }
									</a>
								) }
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
							<TextareaControl label={ __( 'Prompt copy', 'newspack-popups' ) } rows={ 4 } value={ body } onChange={ setBody } />
							<TextControl label={ __( 'Button label', 'newspack-popups' ) } value={ buttonLabel } onChange={ setButtonLabel } />
							<TextControl label={ __( 'Donate URL', 'newspack-popups' ) } type="url" value={ buttonUrl } onChange={ setButtonUrl } />
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
						</VStack>
					) }
				</VStack>
			) }
		</PluginDocumentSettingPanel>
	);
};

export default ContextualPromptPanel;

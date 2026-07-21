/**
 * Contextual Prompt editor panel.
 *
 * Generates story-specific donation calls-to-action for the post being edited
 * (via the Newspack Manager editorial-assistant), lets the editor pick and edit
 * one, and creates a post-scoped Campaigns prompt from it.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
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
	const { postId, postType, content } = useSelect( select => {
		const editor = select( 'core/editor' );
		return {
			postId: editor.getCurrentPostId(),
			postType: editor.getCurrentPostType(),
			content: editor.getEditedPostContent(),
		};
	}, [] );

	const [ generating, setGenerating ] = useState( false );
	const [ candidates, setCandidates ] = useState( [] );
	const [ templateVersion, setTemplateVersion ] = useState( '' );
	const [ selected, setSelected ] = useState( null );
	const [ edited, setEdited ] = useState( false );
	const [ buttonLabel, setButtonLabel ] = useState( '' );
	const [ buttonUrl, setButtonUrl ] = useState( '' );
	const [ position, setPosition ] = useState( 3 );
	const [ creating, setCreating ] = useState( false );
	const [ created, setCreated ] = useState( null );
	const [ error, setError ] = useState( '' );

	// Hidden until an administrator opts the site into AI use (see Campaigns >
	// Settings). Also never shown while editing a prompt itself.
	const optedIn = window.newspackPopupsContextualPrompt?.enabled;
	if ( ! optedIn || 'newspack_popups_cpt' === postType ) {
		return null;
	}

	const generate = async () => {
		setGenerating( true );
		setError( '' );
		setCreated( null );
		try {
			const response = await apiFetch( {
				path: '/wp/v2/newspack-editorial-assistant/generate/donation',
				method: 'POST',
				data: { post_id: postId, content },
			} );
			// Fresh responses are wrapped in { data }, cached ones are the raw result.
			const payload = response && response.data ? response.data : response;
			const list = ( payload && payload.candidates ) || [];
			setCandidates( list );
			setTemplateVersion( ( payload && payload.templateVersion ) || '' );
			setSelected( null );
			if ( ! list.length ) {
				setError( __( 'No suggestions were returned. Try generating again.', 'newspack-popups' ) );
			}
		} catch ( e ) {
			setError( e.message || __( 'Could not generate suggestions.', 'newspack-popups' ) );
		} finally {
			setGenerating( false );
		}
	};

	const chooseCandidate = index => {
		setSelected( index );
		setEdited( false );
		if ( ! buttonLabel && candidates[ index ]?.buttonLabel ) {
			setButtonLabel( candidates[ index ].buttonLabel );
		}
	};

	const updateBody = ( index, value ) => {
		const next = [ ...candidates ];
		next[ index ] = { ...next[ index ], body: value };
		setCandidates( next );
		if ( index === selected ) {
			setEdited( true );
		}
	};

	const create = async () => {
		if ( null === selected ) {
			return;
		}
		setCreating( true );
		setError( '' );
		try {
			const response = await apiFetch( {
				path: '/newspack-popups/v1/contextual-prompt',
				method: 'POST',
				data: {
					post_id: postId,
					body: candidates[ selected ].body,
					button_label: buttonLabel,
					button_url: buttonUrl,
					position,
					template_version: templateVersion,
					ai_generated: true,
					ai_edited: edited,
				},
			} );
			setCreated( response );
		} catch ( e ) {
			setError( e.message || __( 'Could not create the prompt.', 'newspack-popups' ) );
		} finally {
			setCreating( false );
		}
	};

	return (
		<PluginDocumentSettingPanel name="newspack-contextual-prompt" title={ __( 'Contextual Prompt', 'newspack-popups' ) }>
			<VStack spacing={ 4 }>
				<p style={ { margin: 0 } }>{ __( 'Generate a story-specific donation call-to-action for this post.', 'newspack-popups' ) }</p>

				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }

				{ created ? (
					<Notice status="success" isDismissible={ false }>
						{ __( 'Prompt created for this post.', 'newspack-popups' ) }{ ' ' }
						{ created.edit_link && <a href={ created.edit_link }>{ __( 'Edit prompt', 'newspack-popups' ) }</a> }
					</Notice>
				) : (
					<>
						<Button variant="secondary" onClick={ generate } disabled={ generating }>
							{ generating && <Spinner /> }
							{ generating ? __( 'Generating…', 'newspack-popups' ) : __( 'Generate suggestions', 'newspack-popups' ) }
						</Button>

						{ candidates.map( ( candidate, index ) => (
							<VStack key={ index } spacing={ 2 } className="newspack-contextual-prompt__candidate">
								<strong>{ FRAMING_LABELS[ candidate.framing ] || candidate.framing }</strong>
								{ candidate.flags?.includes( 'over_word_cap' ) && (
									<Notice status="warning" isDismissible={ false }>
										{ __( 'This suggestion is longer than recommended.', 'newspack-popups' ) }
									</Notice>
								) }
								<TextareaControl rows={ 4 } value={ candidate.body } onChange={ value => updateBody( index, value ) } />
								<div>
									<Button variant={ selected === index ? 'primary' : 'secondary' } onClick={ () => chooseCandidate( index ) }>
										{ selected === index ? __( 'Selected', 'newspack-popups' ) : __( 'Use this', 'newspack-popups' ) }
									</Button>
								</div>
							</VStack>
						) ) }

						{ null !== selected && (
							<VStack spacing={ 3 } className="newspack-contextual-prompt__placement">
								<TextControl label={ __( 'Button label', 'newspack-popups' ) } value={ buttonLabel } onChange={ setButtonLabel } />
								<TextControl
									label={ __( 'Donate URL', 'newspack-popups' ) }
									type="url"
									value={ buttonUrl }
									onChange={ setButtonUrl }
								/>
								<TextControl
									label={ __( 'Position (paragraph)', 'newspack-popups' ) }
									type="number"
									min={ 0 }
									value={ position }
									onChange={ value => setPosition( parseInt( value, 10 ) || 0 ) }
								/>
								<div>
									<Button variant="primary" onClick={ create } disabled={ creating }>
										{ creating ? __( 'Creating…', 'newspack-popups' ) : __( 'Create prompt', 'newspack-popups' ) }
									</Button>
								</div>
							</VStack>
						) }
					</>
				) }
			</VStack>
		</PluginDocumentSettingPanel>
	);
};

export default ContextualPromptPanel;

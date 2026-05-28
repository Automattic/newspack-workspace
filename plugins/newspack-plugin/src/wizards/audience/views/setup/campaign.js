/* global newspackAudience */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import WizardsTab from '../../../wizards-tab';
import { Button, Notice, Waiting, withWizardScreen } from '../../../../../packages/components/src';
import Prompt from '../../components/prompt';
import Router from '../../../../../packages/components/src/proxied-imports/router';
import './style.scss';

const { useHistory } = Router;

const AudienceCampaign = withWizardScreen( ( { error, setError, skipPrerequisite } ) => {
	const { reader_activation_url } = newspackAudience;
	const [ inFlight, setInFlight ] = useState( false );
	const [ prompts, setPrompts ] = useState( null );
	const [ allReady, setAllReady ] = useState( false );
	const history = useHistory();

	const fetchPrompts = () => {
		setError( false );
		setInFlight( true );
		apiFetch( {
			path: '/newspack-popups/v1/audience-management/campaign',
		} )
			.then( fetchedPrompts => {
				setPrompts( fetchedPrompts );
			} )
			.catch( setError )
			.finally( () => setInFlight( false ) );
	};

	useEffect( () => {
		window.scrollTo( 0, 0 );
		fetchPrompts();
	}, [] );

	useEffect( () => {
		if ( Array.isArray( prompts ) && 0 < prompts.length ) {
			setAllReady( prompts.every( prompt => prompt.ready ) );
		}
	}, [ prompts ] );

	return (
		<WizardsTab
			title={ __( 'Set Up Audience Management Campaign', 'newspack-plugin' ) }
			description={ __( 'Preview and customize the prompts, or use our suggested defaults.', 'newspack-plugin' ) }
		>
			{ error && <Notice noticeText={ error?.message || __( 'Something went wrong.', 'newspack-plugin' ) } isError /> }
			{ ! prompts && ! error && (
				<>
					<Waiting isLeft />
					{ __( 'Retrieving prompts…', 'newspack-plugin' ) }
				</>
			) }
			{ prompts &&
				prompts.map( prompt => (
					<Prompt key={ prompt.slug } prompt={ prompt } inFlight={ inFlight } setInFlight={ setInFlight } setPrompts={ setPrompts } />
				) ) }
			<div className="newspack-buttons-card">
				<Button
					variant={ 'secondary' }
					isDestructive
					disabled={ inFlight }
					onClick={ () => {
						skipPrerequisite(
							{
								prerequisite: 'ras_campaign',
								skip: true,
							},
							() => history.push( '/complete' )
						);
					} }
				>
					{ __( 'Skip', 'newspack-plugin' ) }
				</Button>
				<Button isPrimary disabled={ inFlight || ! allReady } href={ `${ reader_activation_url }complete` }>
					{ __( 'Continue', 'newspack-plugin' ) }
				</Button>
				<Button isSecondary disabled={ inFlight } href={ reader_activation_url }>
					{ __( 'Back', 'newspack-plugin' ) }
				</Button>
			</div>
		</WizardsTab>
	);
} );

export default AudienceCampaign;

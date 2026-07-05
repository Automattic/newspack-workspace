/* global newspackAudience */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { ExternalLink } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import WizardsTab from '../../../wizards-tab';
import { Button, withWizardScreen, Card, Notice, ProgressBar, StepsList } from '../../../../../packages/components/src';

const listItems = [
	__( 'Your <strong>current segments and prompts</strong> will be deactivated and archived.', 'newspack-plugin' ),
	__( 'The <strong>Audience Management campaign</strong> will be activated with default segments and settings.', 'newspack-plugin' ),
];

const ACTIVATION_STEPS = [ __( 'Setting up new segments…', 'newspack-plugin' ), __( 'Activating Audience Management Campaign…', 'newspack-plugin' ) ];

/**
 * Get a random number between min and max.
 *
 * @param {number} min Minimum value to return.
 * @param {number} max Maximum value to return.
 * @return {number} Random number between min and max.
 */
const generateRandomNumber = ( min, max ) => {
	return min + Math.random() * ( max - min );
};

export default withWizardScreen( ( { fetchConfig } ) => {
	const [ inFlight, setInFlight ] = useState( false );
	const [ error, setError ] = useState( false );
	const [ progress, setProgress ] = useState( null );
	const [ progressLabel, setProgressLabel ] = useState( false );
	const [ completed, setCompleted ] = useState( false );
	const timer = useRef();
	const { reader_activation_url } = newspackAudience;

	useEffect( () => {
		if ( timer.current ) {
			clearTimeout( timer.current );
		}
		if ( error ) {
			setInFlight( false );
		}
		if ( ! error && inFlight && 0 <= progress && progress < ACTIVATION_STEPS.length ) {
			setProgressLabel( ACTIVATION_STEPS[ progress ] );
			timer.current = setTimeout(
				() => {
					setProgress( _progress => _progress + 1 );
				},
				generateRandomNumber( 1000, 2000 )
			);
		}
		if ( progress >= ACTIVATION_STEPS.length && completed ) {
			setProgress( ACTIVATION_STEPS.length + 1 ); // Plus one to account for the "Done!" step.
			setProgressLabel( __( 'Done!', 'newspack-plugin' ) );
			setTimeout( () => {
				fetchConfig().finally( () => {
					setInFlight( false );
					window.location.replace( reader_activation_url );
				} );
			}, 3000 );
		}
	}, [ completed, progress ] );

	const activate = async () => {
		setError( false );
		setInFlight( true );
		setProgress( 0 );

		try {
			setCompleted(
				await apiFetch( {
					path: '/newspack/v1/wizard/newspack-audience/audience-management/activate',
					method: 'post',
				} )
			);
		} catch ( err ) {
			if ( timer.current ) {
				clearTimeout( timer.current );
			}
			setInFlight( false );
			setError( err );
		}
	};

	return (
		<div className="newspack-ras-campaign__completed">
			<WizardsTab
				title={ __( 'Audience Management Campaign', 'newspack-plugin' ) }
				description={
					<>
						{ __(
							'Publish a set of prompts with default segments and settings, optimized for audience management. ',
							'newspack-plugin'
						) }

						{ /** TODO: Update this URL with the real one once the docs are ready. */ }
						<ExternalLink href={ 'https://help.newspack.com' }>{ __( 'Learn more', 'newspack-plugin' ) }</ExternalLink>
					</>
				}
			>
				{ inFlight && (
					<Card className="newspack-ras-campaign__completed-card">
						<ProgressBar
							completed={ progress }
							displayFraction={ false }
							total={ ACTIVATION_STEPS.length + 1 } // Plus one to account for the "Done!" step.
							label={ progressLabel }
						/>
					</Card>
				) }
				{ ! inFlight && (
					<Card className="newspack-ras-campaign__completed-card">
						<h2>{ __( "You're all set to publish the Audience Management campaign!", 'newspack-plugin' ) }</h2>
						<p>{ __( 'This is what will happen next:', 'newspack-plugin' ) }</p>

						<Card noBorder className="justify-center">
							<StepsList stepsListItems={ listItems } narrowList />
						</Card>

						{ error && <Notice noticeText={ error?.message || __( 'Something went wrong.', 'newspack-plugin' ) } isError /> }

						<Card buttonsCard noBorder className="justify-center">
							<Button isPrimary onClick={ () => activate() }>
								{ __( 'Publish campaign', 'newspack-plugin' ) }
							</Button>
						</Card>
					</Card>
				) }
				<div className="newspack-buttons-card">
					<Button isSecondary disabled={ inFlight } href={ `${ reader_activation_url }campaign` }>
						{ __( 'Back', 'newspack-plugin' ) }
					</Button>
				</div>
			</WizardsTab>
		</div>
	);
} );

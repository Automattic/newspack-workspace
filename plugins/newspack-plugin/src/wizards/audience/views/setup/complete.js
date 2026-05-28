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
	{
		text: __( 'Your <strong>current segments and prompts</strong> will be deactivated and archived.', 'newspack-plugin' ),
		isSkipped: '<span class="is-skipped">[skipped]</span>',
	},
	{
		text: __(
			'<strong>Reader registration</strong> will be activated to enable better targeting for driving engagement and conversations.',
			'newspack-plugin'
		),
	},
	{
		text: __( 'The <strong>Audience Management campaign</strong> will be activated with default segments and settings.', 'newspack-plugin' ),
		isSkipped: '<span class="is-skipped">[skipped]</span>',
	},
];

const DEFAULT_ACTIVATION_STEPS = {
	campaignsSegments: __( 'Setting up new segments…', 'newspack-plugin' ),
	readerRegistration: __( 'Activating reader registration…', 'newspack-plugin' ),
	campaignsPrompts: __( 'Activating Audience Management Campaign…', 'newspack-plugin' ),
};

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
	const [ activationSteps, setActivationSteps ] = useState( Object.values( DEFAULT_ACTIVATION_STEPS ) );
	const { reader_activation_url, is_skipped_campaign_setup = '' } = newspackAudience;
	const isSkippedCampaignSetup = is_skipped_campaign_setup === '1';

	useEffect( () => {
		if ( isSkippedCampaignSetup ) {
			setActivationSteps( [ DEFAULT_ACTIVATION_STEPS.readerRegistration ] );
		}
	}, [ isSkippedCampaignSetup ] );

	/**
	 * Generate step list strings
	 */
	for ( const listItemIndex in listItems ) {
		if ( ! listItems[ listItemIndex ].text ) {
			continue;
		}
		const suffix = isSkippedCampaignSetup ? ` ${ listItems[ listItemIndex ].isSkipped ?? '' }` : '';
		listItems[ listItemIndex ] = `${ listItems[ listItemIndex ].text }${ suffix }`;
		if ( isSkippedCampaignSetup ) {
			listItems[ listItemIndex ] += ` ${ listItems[ listItemIndex ].isSkipped ?? '' }`;
		}
	}

	useEffect( () => {
		if ( timer.current ) {
			clearTimeout( timer.current );
		}
		if ( error ) {
			setInFlight( false );
		}
		if ( ! error && inFlight && 0 <= progress && progress < activationSteps.length ) {
			setProgressLabel( activationSteps[ progress ] );
			timer.current = setTimeout(
				() => {
					setProgress( _progress => _progress + 1 );
				},
				generateRandomNumber( 1000, 2000 )
			);
		}
		if ( progress >= activationSteps.length && completed ) {
			setProgress( activationSteps.length + 1 ); // Plus one to account for the "Done!" step.
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
					data: {
						skip_activation: isSkippedCampaignSetup,
					},
				} )
			);
		} catch ( err ) {
			setError( err );
		}
	};

	return (
		<div className="newspack-ras-campaign__completed">
			<WizardsTab
				title={ __( 'Enable Audience Management', 'newspack-plugin' ) }
				description={
					<>
						{ __(
							'An easy way to let your readers register for your site, sign up for newsletters, or become donors and paid members. ',
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
							total={ activationSteps.length + 1 } // Plus one to account for the "Done!" step.
							label={ progressLabel }
						/>
					</Card>
				) }
				{ ! inFlight && (
					<Card className="newspack-ras-campaign__completed-card">
						<h2>{ __( "You're all set to enable Audience Management!", 'newspack-plugin' ) }</h2>
						<p>{ __( 'This is what will happen next:', 'newspack-plugin' ) }</p>

						<Card noBorder className="justify-center">
							<StepsList stepsListItems={ listItems } narrowList />
						</Card>

						{ error && <Notice noticeText={ error?.message || __( 'Something went wrong.', 'newspack-plugin' ) } isError /> }

						<Card buttonsCard noBorder className="justify-center">
							<Button isPrimary onClick={ () => activate() }>
								{ __( 'Enable Audience Management', 'newspack-plugin' ) }
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

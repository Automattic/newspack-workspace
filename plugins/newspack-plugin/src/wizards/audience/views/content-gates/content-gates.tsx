/**
 * Content Gate component.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { useDispatch } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Divider, Grid } from '../../../../../packages/components/src';
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import { useWizardApiFetch } from '../../../hooks/use-wizard-api-fetch';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import ContentGatesOnboarding from './content-gates-onboarding';
import ContentGatesPriority from './content-gates-priority';
import ContentGateSettings from './content-gate-settings';
import AdvancedSettings from './advanced-settings';
import SettingsCard from './settings-card';
import { AUDIENCE_CONTENT_GATES_WIZARD_SLUG } from './consts';
import { isGateMetered } from './utils';
import './style.scss';

const ContentGates = ( { updateGatesData }: { updateGatesData: ( gates: Gate[] ) => void } ) => {
	const wizardData = useWizardData( AUDIENCE_CONTENT_GATES_WIZARD_SLUG ) as WizardData;
	const { wizardApiFetch, isFetching, errorMessage, resetError } = useWizardApiFetch( AUDIENCE_CONTENT_GATES_WIZARD_SLUG );
	const { addNotice, resetNotices, resetHeaderData, setHeaderData, updateWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	const [ showPriorityModal, setShowPriorityModal ] = useState( false );
	const [ showAdvancedSettings, setShowAdvancedSettings ] = useState( false );
	const ref = useRef( null );
	const gates = ( wizardData?.gates || [] ) as Gate[];
	const config = ( wizardData?.config || {} ) as GateSettings;
	const hasMetering = gates.some( isGateMetered );
	const hasInstitutions = !! config.has_institutions;

	useEffect( () => {
		if ( isFetching ) {
			return;
		}
		if ( ! gates?.length ) {
			resetHeaderData();
			return;
		}
		const institutionsLink: SectionMenuItem = {
			label: __( 'Institutions', 'newspack-plugin' ),
			href: '#/institutions',
		};
		const gatePriorityItem: SectionMenuItem = {
			label: __( 'Gate Priority', 'newspack-plugin' ),
			action: () => setShowPriorityModal( true ),
		};
		const advancedSettingsItem: SectionMenuItem = {
			label: __( 'Advanced Settings', 'newspack-plugin' ),
			action: () => setShowAdvancedSettings( true ),
		};
		// Built in display order. Gate Priority only appears once there is more
		// than one gate to order; Institutions is promoted out of the kebab to
		// a visible entry point beside the title while it is in use, so it stays
		// in the menu only when it is not.
		const sectionMenu: SectionMenuItem[] = [
			...( gates.length > 1 ? [ gatePriorityItem ] : [] ),
			...( ! hasInstitutions ? [ institutionsLink ] : [] ),
			advancedSettingsItem,
		];
		setHeaderData( {
			actions: [
				{
					type: 'primary',
					label: __( 'Add Content Gate', 'newspack-plugin' ),
					href: '#/edit/new/all',
				},
			],
			sectionTitle: __( 'Access Control', 'newspack-plugin' ),
			sectionDescription: __(
				'Set up gates to manage what content readers can access across your site. Start by selecting which content to restrict, then configure access through registered and/or paid options (including metered rules).',
				'newspack-plugin'
			),
			sectionMenu,
			sectionSecondaryAction: hasInstitutions ? institutionsLink : undefined,
		} );
	}, [ isFetching, gates, hasInstitutions ] );

	const toggleCountdownBanner = useRef< () => void >();
	const handleToggleCountdownBanner = () => {
		resetError();
		resetNotices();
		wizardApiFetch(
			{
				path: '/newspack/v1/wizard/newspack-audience-access-control/countdown-banner',
				method: 'POST',
				quiet: true,
				data: { enabled: config.countdown_banner?.enabled ? 0 : 1 },
			},
			{
				onSuccess( data: MeteringCountdownConfig ) {
					updateWizardSettings( {
						slug: AUDIENCE_CONTENT_GATES_WIZARD_SLUG,
						path: [ 'config' ],
						value: { ...wizardData?.config, countdown_banner: data },
					} );
					addNotice( {
						message: sprintf(
							// translators: %s is the status of the countdown banner.
							__( 'Metered countdown %s.', 'newspack-plugin' ),
							config.countdown_banner?.enabled ? __( 'disabled', 'newspack-plugin' ) : __( 'enabled', 'newspack-plugin' )
						),
						type: 'success',
						id: 'countdown-banner-config-updated',
						actions: [ { label: __( 'Undo', 'newspack-plugin' ), onClick: () => toggleCountdownBanner.current?.() } ],
					} );
				},
			}
		);
	};
	toggleCountdownBanner.current = handleToggleCountdownBanner;
	const toggleContentGifting = useRef< () => void >();
	const handleToggleContentGifting = () => {
		resetError();
		resetNotices();
		wizardApiFetch(
			{
				path: '/newspack/v1/wizard/newspack-audience-access-control/content-gifting',
				method: 'POST',
				quiet: true,
				data: { enabled: config.content_gifting?.enabled ? 0 : 1 },
			},
			{
				onSuccess( data: ContentGiftingConfig ) {
					updateWizardSettings( {
						slug: AUDIENCE_CONTENT_GATES_WIZARD_SLUG,
						path: [ 'config' ],
						value: { ...wizardData?.config, content_gifting: data },
					} );
					addNotice( {
						message: sprintf(
							// translators: %s is the status of the content gifting.
							__( 'Content gifting %s.', 'newspack-plugin' ),
							config.content_gifting?.enabled ? __( 'disabled', 'newspack-plugin' ) : __( 'enabled', 'newspack-plugin' )
						),
						type: 'success',
						id: 'content-gifting-config-updated',
						actions: [ { label: __( 'Undo', 'newspack-plugin' ), onClick: () => toggleContentGifting.current?.() } ],
					} );
				},
			}
		);
	};
	toggleContentGifting.current = handleToggleContentGifting;

	useEffect( () => {
		if ( errorMessage ) {
			addNotice( {
				message: errorMessage,
				type: 'error',
				id: 'content-gate-error',
			} );
		}
	}, [ errorMessage ] );

	if ( ! gates?.length ) {
		return <ContentGatesOnboarding />;
	}

	return (
		<>
			<ContentGatesPriority
				showModal={ showPriorityModal }
				closeModal={ () => setShowPriorityModal( false ) }
				updateGatesData={ updateGatesData }
			/>
			<AdvancedSettings showModal={ showAdvancedSettings } closeModal={ () => setShowAdvancedSettings( false ) } />
			<VStack className="newspack-content-gates__gates" spacing="16px" ref={ ref }>
				{ gates.map( gate => {
					return <ContentGateSettings key={ gate.id } gate={ gate } updateGatesData={ updateGatesData } />;
				} ) }
			</VStack>
			<Divider alignment="full-width" />
			<Grid className="newspack-content-gates__other-settings" columns={ 2 } gutter={ 32 }>
				<SettingsCard
					title={ __( 'Metered Countdown', 'newspack-plugin' ) }
					description={ __(
						'Show a countdown banner letting readers know how many free views they have left before content is restricted.',
						'newspack-plugin'
					) }
					enabled={ !! config.countdown_banner?.enabled }
					requirements={ ! hasMetering ? __( 'Requires Metering', 'newspack-plugin' ) : undefined }
					toggleEnabled={ toggleCountdownBanner.current }
					href={ '/settings/countdown-banner' }
				/>
				<SettingsCard
					title={ __( 'Content Gifting', 'newspack-plugin' ) }
					description={ __(
						'Let members gift articles to non-subscribers. Recipients can read the full content without needing to subscribe.',
						'newspack-plugin'
					) }
					enabled={ !! config.content_gifting?.enabled }
					toggleEnabled={ toggleContentGifting.current }
					href={ '/settings/content-gifting' }
				/>
			</Grid>
		</>
	);
};
export default ContentGates;

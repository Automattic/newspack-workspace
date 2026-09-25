/**
 * Metering settings page: the shared free-view allowance, and the banner that
 * counts it down.
 */

/**
 * WordPress dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Notice,
	__experimentalNumberControl as NumberControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Button, Divider, Grid, SectionHeader, useConfirmDialog } from '../../../../../../packages/components/src';
import EmptyState from '../../../../../../packages/components/src/empty-state';
import { countdown as countdownIcon } from '../../../../../../packages/icons';
import { useWizardData } from '../../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import { AUDIENCE_CONTENT_GATES_WIZARD_SLUG } from '../consts';
import { getMeteringDescription, hasOwnMeter, hasSharedMeteredPath, sharesTheSiteMeter } from '../utils';
import CountdownBanner from './countdown-banner';

const DEFAULT_SITE_METER: SiteMeterConfig = {
	anonymous_count: 1,
	registered_count: 1,
	period: 'month',
};

const MeteringSettings = () => {
	const wizardData = useWizardData( AUDIENCE_CONTENT_GATES_WIZARD_SLUG ) as ContentGatesWizardData;
	const { addNotice, resetNotices, setHeaderData, updateWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	const { wizardApiFetch, errorMessage, resetError } = useWizardApiFetch( AUDIENCE_CONTENT_GATES_WIZARD_SLUG );

	const savedSiteMeter = wizardData?.config?.site_meter;
	const savedCountdown = wizardData?.config?.countdown_banner;
	const [ siteMeter, setSiteMeter ] = useState< SiteMeterConfig >( savedSiteMeter || DEFAULT_SITE_METER );
	const [ countdown, setCountdown ] = useState< MeteringCountdownConfig >( savedCountdown || ( {} as MeteringCountdownConfig ) );

	const siteMeterDirty = useMemo(
		() => Boolean( savedSiteMeter ) && JSON.stringify( siteMeter ) !== JSON.stringify( savedSiteMeter ),
		[ siteMeter, savedSiteMeter ]
	);
	const countdownDirty = useMemo(
		() => Boolean( savedCountdown ) && JSON.stringify( countdown ) !== JSON.stringify( savedCountdown ),
		[ countdown, savedCountdown ]
	);
	const isDirty = siteMeterDirty || countdownDirty;

	const isSaving = useRef( false );
	const { confirmDialog } = useConfirmDialog( {
		when: !! ( isDirty && ! isSaving.current ),
		message: __( 'You have unsaved changes that will be lost. Discard changes?', 'newspack-plugin' ),
		confirmButtonText: __( 'Discard Changes', 'newspack-plugin' ),
		isDestructive: true,
		hideTitle: true,
	} );

	const gates = wizardData?.gates || [];
	const gatesWithOwnMeter = gates.filter( hasOwnMeter );
	// Whether any gate, active or draft, switches metering on, not whether it counts: a 0/0 allowance
	// must keep the form reachable so it can be raised again, and a draft gate needs the allowance it will use.
	const hasMetering = gates.some( gate =>
		[ gate.registration, gate.custom_access ].some( section => section?.active && section.metering?.enabled )
	);
	// Layout wording is written once at creation and never rewritten from here. Judged
	// per path: a gate with one path pinned and one sharing still quotes the shared number.
	const gatesQuotingTheAllowance = gates.filter( gate => hasSharedMeteredPath( gate, savedSiteMeter ) );
	const gatesSharingTheAllowance = gates.filter( sharesTheSiteMeter );

	const handleSave = async () => {
		isSaving.current = true;
		resetError();
		resetNotices();

		// Only the dirty half is written, so saving one cannot clobber the other.
		const nextConfig: GateSettings = { ...wizardData?.config };
		let anyWritten = false;
		try {
			if ( siteMeterDirty ) {
				nextConfig.site_meter = await wizardApiFetch( {
					path: '/newspack/v1/wizard/newspack-audience-access-control/site-meter',
					method: 'POST',
					quiet: true,
					data: siteMeter,
				} );
				anyWritten = true;
			}
			if ( countdownDirty ) {
				nextConfig.countdown_banner = await wizardApiFetch( {
					path: '/newspack/v1/wizard/newspack-audience-access-control/countdown-banner',
					method: 'POST',
					data: countdown,
					quiet: true,
				} );
				anyWritten = true;
			}
		} catch {
			// Separate requests, so one can land before the other fails. Leaving out what
			// did land would show it as unsaved and let a discard imply it never changed.
			if ( anyWritten ) {
				updateWizardSettings( {
					slug: AUDIENCE_CONTENT_GATES_WIZARD_SLUG,
					path: [ 'config' ],
					value: nextConfig,
				} );
			}
			// The error notice comes from the errorMessage effect.
			isSaving.current = false;
			return;
		}

		updateWizardSettings( {
			slug: AUDIENCE_CONTENT_GATES_WIZARD_SLUG,
			path: [ 'config' ],
			value: nextConfig,
		} );
		addNotice( {
			message: __( 'Metering settings updated.', 'newspack-plugin' ),
			type: 'success',
			id: 'metering-config-updated',
		} );
		isSaving.current = false;
	};

	useEffect( () => {
		if ( ! hasMetering ) {
			setHeaderData( {
				actions: [],
				subTitle: __( 'Give readers a set number of free views before a gate applies.', 'newspack-plugin' ),
			} );
			return;
		}
		setHeaderData( {
			actions: [
				{
					label: __( 'Save', 'newspack-plugin' ),
					action: handleSave,
					disabled: ! isDirty,
					type: 'primary',
				},
			],
			subTitle: getMeteringDescription( savedSiteMeter ),
		} );
	}, [ siteMeter, countdown, isDirty, setHeaderData, hasMetering, savedSiteMeter ] );

	// Compared by value: saving deep-clones the whole config, so the half that did not
	// change still arrives as a fresh object, and reacting to that identity would
	// overwrite the edits a failed save left on screen.
	const appliedSiteMeter = useRef< string | null >( null );
	useEffect( () => {
		if ( ! savedSiteMeter ) {
			return;
		}
		const applied = JSON.stringify( savedSiteMeter );
		if ( appliedSiteMeter.current === applied ) {
			return;
		}
		appliedSiteMeter.current = applied;
		setSiteMeter( savedSiteMeter );
	}, [ savedSiteMeter ] );

	const appliedCountdown = useRef< string | null >( null );
	useEffect( () => {
		if ( ! savedCountdown ) {
			return;
		}
		const applied = JSON.stringify( savedCountdown );
		if ( appliedCountdown.current === applied ) {
			return;
		}
		appliedCountdown.current = applied;
		setCountdown( savedCountdown );
	}, [ savedCountdown ] );

	useEffect( () => {
		if ( errorMessage ) {
			addNotice( {
				message: errorMessage,
				type: 'error',
				id: 'metering-settings-error',
			} );
		}
	}, [ errorMessage ] );

	const setCount = ( key: 'anonymous_count' | 'registered_count' ) => ( value: string | number | undefined ) =>
		setSiteMeter( { ...siteMeter, [ key ]: Math.max( 0, Math.round( Number( value ) || 0 ) ) } );

	const noneSignedOut = siteMeter.anonymous_count === 0;
	const noneSignedIn = siteMeter.registered_count === 0;
	let noFreeViewsWarning = null;
	if ( noneSignedOut && noneSignedIn ) {
		noFreeViewsWarning = __(
			'No reader gets a free view, so every gate sharing this allowance gates everyone on their first view, the same as turning metering off.',
			'newspack-plugin'
		);
	} else if ( noneSignedOut ) {
		noFreeViewsWarning = __(
			'Signed-out readers get 0 free views, so every gate sharing this allowance gates them on their first view, the same as turning metering off.',
			'newspack-plugin'
		);
	} else if ( noneSignedIn ) {
		noFreeViewsWarning = __(
			'Signed-in readers get 0 free views, so every gate sharing this allowance gates them on their first view, the same as turning metering off.',
			'newspack-plugin'
		);
	}

	if ( ! hasMetering ) {
		return (
			<EmptyState.Root>
				<EmptyState.Header
					icon={ countdownIcon }
					title={ __( 'No gate uses metering yet', 'newspack-plugin' ) }
					description={ __( "Metering starts when an active gate turns it on. Set it in a gate's access settings.", 'newspack-plugin' ) }
				/>
				<EmptyState.Actions>
					<Button variant="primary" href="#/edit/new/all">
						{ __( 'Add Content Gate', 'newspack-plugin' ) }
					</Button>
					<Button variant="secondary" href="#/content-gates">
						{ __( 'View Content Gates', 'newspack-plugin' ) }
					</Button>
				</EmptyState.Actions>
			</EmptyState.Root>
		);
	}

	return (
		<div className="newspack-content-gate__edit">
			{ confirmDialog }
			<Grid columns={ 2 } noMargin>
				<VStack spacing={ 6 } justify="flex-start">
					<SectionHeader
						heading={ 2 }
						title={ __( 'Free Views', 'newspack-plugin' ) }
						description={ __(
							'One allowance for the whole site, so moving between gated sections does not reset it.',
							'newspack-plugin'
						) }
					/>
					{ gatesWithOwnMeter.length > 0 && (
						<Notice status="warning" isDismissible={ false }>
							{ sprintf(
								// translators: %1$d is a number of gates, %2$s is a comma-separated list of gate names.
								_n(
									'%1$d gate ignores these settings for at least one audience: %2$s',
									'%1$d gates ignore these settings for at least one audience: %2$s',
									gatesWithOwnMeter.length,
									'newspack-plugin'
								),
								gatesWithOwnMeter.length,
								gatesWithOwnMeter.map( gate => gate.title ).join( ', ' )
							) }
						</Notice>
					) }
					{ gatesSharingTheAllowance.length > 0 && noFreeViewsWarning && (
						<Notice status="warning" isDismissible={ false }>
							{ noFreeViewsWarning }
						</Notice>
					) }
					{ siteMeterDirty && gatesQuotingTheAllowance.length > 0 && (
						<Notice status="info" isDismissible={ false }>
							{ sprintf(
								// translators: %s is a comma-separated list of gate names.
								_n(
									'Gates keep the wording they were created with. Check the layout for %s if it promises readers a number of free articles.',
									'Gates keep the wording they were created with. Check the layouts for %s if they promise readers a number of free articles.',
									gatesQuotingTheAllowance.length,
									'newspack-plugin'
								),
								gatesQuotingTheAllowance.map( gate => gate.title ).join( ', ' )
							) }
						</Notice>
					) }
				</VStack>
				<VStack spacing={ 6 } justify="flex-start">
					<NumberControl
						label={ __( 'Signed-out readers', 'newspack-plugin' ) }
						help={ __( 'Free views before a gate applies.', 'newspack-plugin' ) }
						min={ 0 }
						value={ siteMeter.anonymous_count }
						onChange={ setCount( 'anonymous_count' ) }
						__next40pxDefaultSize
					/>
					<NumberControl
						label={ __( 'Signed-in readers', 'newspack-plugin' ) }
						help={ __( 'Free views before a paywall appears.', 'newspack-plugin' ) }
						min={ 0 }
						value={ siteMeter.registered_count }
						onChange={ setCount( 'registered_count' ) }
						__next40pxDefaultSize
					/>
					<ToggleGroupControl
						label={ __( 'Reset period', 'newspack-plugin' ) }
						help={ __( 'How often free views reset.', 'newspack-plugin' ) }
						value={ siteMeter.period }
						onChange={ value => setSiteMeter( { ...siteMeter, period: value as SiteMeterConfig[ 'period' ] } ) }
						isBlock
						__next40pxDefaultSize
					>
						<ToggleGroupControlOption label={ __( 'Monthly', 'newspack-plugin' ) } value="month" />
						<ToggleGroupControlOption label={ __( 'Weekly', 'newspack-plugin' ) } value="week" />
					</ToggleGroupControl>
				</VStack>
			</Grid>
			<Divider alignment="full-width" variant="tertiary" />
			<CountdownBanner
				countdown={ countdown }
				onChange={ setCountdown }
				meterCount={ siteMeter.registered_count || siteMeter.anonymous_count }
				meterPeriod={ siteMeter.period }
				meterAudience={ siteMeter.registered_count ? 'registered' : 'anonymous' }
				otherAudienceMeters={ Boolean( siteMeter.registered_count && siteMeter.anonymous_count ) }
			/>
		</div>
	);
};

export default MeteringSettings;

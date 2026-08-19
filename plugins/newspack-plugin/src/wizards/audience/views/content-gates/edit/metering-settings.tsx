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
import { Divider, Grid, Router, SectionHeader, useConfirmDialog } from '../../../../../../packages/components/src';
import { useWizardData } from '../../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import { AUDIENCE_CONTENT_GATES_WIZARD_SLUG } from '../consts';
import { getMeteringDescription, hasOwnMeter, isGateMetered } from '../utils';
import CountdownBanner from './countdown-banner';

const { useHistory } = Router;

const DEFAULT_SITE_METER: SiteMeterConfig = {
	anonymous_count: 1,
	registered_count: 1,
	period: 'month',
};

const MeteringSettings = () => {
	const history = useHistory();
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
	// Gates that opted out are not governed by this page. Saying so here is the
	// only place a publisher can find out why a section still counts separately.
	const gatesWithOwnMeter = gates.filter( hasOwnMeter );
	const hasMetering = gates.some( gate => isGateMetered( gate, savedSiteMeter ) );

	const handleSave = async () => {
		isSaving.current = true;
		resetError();
		resetNotices();

		// Only the dirty half is written, so saving a banner tweak cannot clobber a
		// meter that changed underneath this page.
		const nextConfig: GateSettings = { ...wizardData?.config };
		try {
			if ( siteMeterDirty ) {
				nextConfig.site_meter = await wizardApiFetch( {
					path: '/newspack/v1/wizard/newspack-audience-access-control/site-meter',
					method: 'POST',
					quiet: true,
					data: siteMeter,
				} );
			}
			if ( countdownDirty ) {
				nextConfig.countdown_banner = await wizardApiFetch( {
					path: '/newspack/v1/wizard/newspack-audience-access-control/countdown-banner',
					method: 'POST',
					data: countdown,
					quiet: true,
				} );
			}
		} catch {
			// The error notice comes from the errorMessage effect. Stay on the page so
			// the unsaved values are still there to retry with.
			isSaving.current = false;
			return;
		}

		isSaving.current = false;
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
		history.push( '/content-gates' );
	};

	useEffect( () => {
		setHeaderData( {
			backNav: '#/content-gates',
			sectionTitle: __( 'Metering', 'newspack-plugin' ),
			sectionSize: 'hidden',
			sectionDescription: getMeteringDescription( savedSiteMeter ),
			actions: [
				{
					label: __( 'Save', 'newspack-plugin' ),
					action: handleSave,
					disabled: ! isDirty,
					type: 'primary',
				},
			],
		} );
	}, [ siteMeter, countdown, isDirty, savedSiteMeter, setHeaderData ] );

	useEffect( () => {
		if ( savedSiteMeter ) {
			setSiteMeter( savedSiteMeter );
		}
	}, [ savedSiteMeter ] );

	useEffect( () => {
		if ( savedCountdown ) {
			setCountdown( savedCountdown );
		}
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

	return (
		<div className="newspack-content-gate__edit">
			{ confirmDialog }
			<Grid columns={ 2 } gutter={ 32 } noMargin>
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
									'%1$d gate keeps its own allowance and ignores these settings: %2$s',
									'%1$d gates keep their own allowance and ignore these settings: %2$s',
									gatesWithOwnMeter.length,
									'newspack-plugin'
								),
								gatesWithOwnMeter.length,
								gatesWithOwnMeter.map( gate => gate.title ).join( ', ' )
							) }
						</Notice>
					) }
				</VStack>
				<VStack spacing={ 6 } justify="flex-start">
					<NumberControl
						label={ __( 'Signed-out readers', 'newspack-plugin' ) }
						help={ __( 'Free views before a registration wall appears.', 'newspack-plugin' ) }
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
			<CountdownBanner countdown={ countdown } onChange={ setCountdown } hasMetering={ hasMetering } />
		</div>
	);
};

export default MeteringSettings;

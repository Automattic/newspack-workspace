/**
 * The Subscriptions wizard's Advanced Settings tab: site-wide subscription
 * settings.
 */

/**
 * WordPress dependencies.
 */
import { sprintf, __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import {
	ExternalLink,
	RadioControl,
	SelectControl,
	ToggleControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Button, Card, Divider, Grid, Notice, SectionHeader, useUnsavedChangesDialog } from '../../../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../../packages/components/src/wizard/store';
import WizardsTab from '../../../../../wizards-tab';
import WizardSection from '../../../../../wizards-section';
import { registerTab } from '../registry';
import { WIZARD_ENDPOINT } from '../../constants';

import './style.scss';
import { DISCOUNTS_ENDPOINT, DISCOUNT_SETTINGS_ENDPOINT } from '../discounts/constants';
import type { DiscountSettings, DiscountsPayload } from '../discounts/types';

function AdvancedSettings() {
	const [ isLoading, setIsLoading ] = useState( true );
	const [ inFlight, setInFlight ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ productSaved, setProductSaved ] = useState( window.newspackAudienceSubscriptions.primary_product );
	const [ productDraft, setProductDraft ] = useState( window.newspackAudienceSubscriptions.primary_product );
	const [ settingsSaved, setSettingsSaved ] = useState< DiscountSettings | null >( null );
	const [ settingsDraft, setSettingsDraft ] = useState< DiscountSettings | null >( null );
	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	useEffect( () => {
		apiFetch< DiscountsPayload >( { path: DISCOUNTS_ENDPOINT } )
			.then( payload => {
				setSettingsSaved( payload.settings );
				setSettingsDraft( payload.settings );
			} )
			.catch( ( apiError: { message?: string } ) =>
				setError( apiError?.message || __( 'These settings could not be loaded.', 'newspack-plugin' ) )
			)
			.finally( () => setIsLoading( false ) );
	}, [] );

	const isDirty = productDraft !== productSaved || JSON.stringify( settingsDraft ) !== JSON.stringify( settingsSaved );
	const { confirmDialog: navBlockDialog } = useUnsavedChangesDialog( { when: isDirty && ! inFlight } );

	useEffect( () => {
		const save = () => {
			setInFlight( true );
			setError( '' );
			const jobs = [];
			if ( productDraft !== productSaved ) {
				jobs.push(
					apiFetch( {
						path: `${ WIZARD_ENDPOINT }/primary-product`,
						method: 'POST',
						data: { primary_product: productDraft },
					} ).then( () => {
						setProductSaved( productDraft );
						window.newspackAudienceSubscriptions.primary_product = productDraft;
					} )
				);
			}
			if ( settingsDraft && JSON.stringify( settingsDraft ) !== JSON.stringify( settingsSaved ) ) {
				jobs.push(
					apiFetch< DiscountsPayload >( {
						path: DISCOUNT_SETTINGS_ENDPOINT,
						method: 'POST',
						data: settingsDraft,
					} ).then( next => {
						setSettingsSaved( next.settings );
						setSettingsDraft( next.settings );
					} )
				);
			}
			Promise.all( jobs )
				.catch( ( apiError: { message?: string } ) =>
					setError( apiError?.message || __( 'These settings could not be saved.', 'newspack-plugin' ) )
				)
				.finally( () => setInFlight( false ) );
		};
		setHeaderData( {
			actions: [
				{
					type: 'primary',
					label: __( 'Save', 'newspack-plugin' ),
					action: save,
					disabled: isLoading || ! isDirty || inFlight,
				},
			],
		} );
	}, [ setHeaderData, isLoading, productDraft, productSaved, settingsDraft, settingsSaved, isDirty, inFlight ] );

	return (
		<WizardsTab className="newspack-advanced-settings">
			<WizardSection>
				{ error && <Notice isError noticeText={ error } /> }
				<Grid columns={ 2 } gutter={ 32 }>
					<SectionHeader
						heading={ 2 }
						title={ __( 'Subscription Upgrade Link', 'newspack-plugin' ) }
						description={ __(
							'Select a grouped or variable subscription product to allow readers to change their active subscriptions amongst all of its linked products and variations.',
							'newspack-plugin'
						) }
					/>
					<VStack spacing={ 4 } justify="flex-start">
						<SelectControl
							label={ __( 'Primary Subscription Product', 'newspack-plugin' ) }
							hideLabelFromVision
							options={ [
								{
									value: '',
									label: __( 'Select a product…', 'newspack-plugin' ),
								},
								...window.newspackAudienceSubscriptions.eligible_products.map( product => ( {
									value: product.id,
									label: product.title,
								} ) ),
							] }
							value={ productDraft }
							onChange={ setProductDraft }
							disabled={ inFlight }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
						{ productSaved && (
							<Notice isDismissible={ false }>
								{ __( 'Share the following URL to trigger the subscription upgrade:', 'newspack-plugin' ) }{ ' ' }
								<a href={ window.newspackAudienceSubscriptions.upgrade_subscription_url } target="_blank" rel="noreferrer noopener">
									{ window.newspackAudienceSubscriptions.upgrade_subscription_url }
								</a>
							</Notice>
						) }
						{ productDraft ? (
							<HStack>
								<p>
									<Button variant="link" disabled={ inFlight } onClick={ () => setProductDraft( '' ) }>
										{ __( 'Reset primary product', 'newspack-plugin' ) }
									</Button>
								</p>
								<p>
									<ExternalLink href={ `/wp-admin/post.php?post=${ productDraft }&action=edit` }>
										{ sprintf(
											/* translators: %s: product title */
											__( 'Edit %s', 'newspack-plugin' ),
											window.newspackAudienceSubscriptions.eligible_products.find(
												product => parseInt( product.id ) === parseInt( productDraft )
											)?.title || __( 'the product', 'newspack-plugin' )
										) }
									</ExternalLink>
								</p>
							</HStack>
						) : null }
					</VStack>
				</Grid>
				{ settingsDraft && (
					<>
						<Divider alignment="full-width" variant="tertiary" />
						<Grid columns={ 2 } gutter={ 32 }>
							<SectionHeader
								heading={ 2 }
								title={ __( 'Discounts', 'newspack-plugin' ) }
								description={ __( 'How subscriber discounts behave across your store.', 'newspack-plugin' ) }
							/>
							<VStack spacing={ 6 } justify="flex-start">
								<VStack spacing={ 4 }>
									<h3>{ __( 'Combining Discounts', 'newspack-plugin' ) }</h3>
									<RadioControl
										label={ __( 'Overlapping discounts', 'newspack-plugin' ) }
										hideLabelFromVision
										help={ __(
											'What happens when more than one subscriber discount applies to the same product.',
											'newspack-plugin'
										) }
										selected={ settingsDraft.overlap }
										onChange={ ( value: string ) =>
											setSettingsDraft( { ...settingsDraft, overlap: value as DiscountSettings[ 'overlap' ] } )
										}
										options={ [
											{ value: 'best', label: __( 'Apply the best discount only', 'newspack-plugin' ) },
											{ value: 'combine', label: __( 'Combine discounts', 'newspack-plugin' ) },
										] }
									/>
									<ToggleControl
										label={ __( 'Apply on top of sale prices', 'newspack-plugin' ) }
										help={ __( 'Subscribers get their discount even on products that are already on sale.', 'newspack-plugin' ) }
										checked={ settingsDraft.apply_on_sale }
										onChange={ value => setSettingsDraft( { ...settingsDraft, apply_on_sale: value } ) }
										disabled={ inFlight }
										__nextHasNoMarginBottom
									/>
								</VStack>
								<VStack spacing={ 4 }>
									<h3>{ __( 'Timing', 'newspack-plugin' ) }</h3>
									<ToggleControl
										label={ __( 'Apply discounts at checkout', 'newspack-plugin' ) }
										help={ __(
											'Give readers their subscriber prices as soon as a subscription is in their cart, before they have completed the purchase.',
											'newspack-plugin'
										) }
										checked={ settingsDraft.apply_at_checkout }
										onChange={ value => setSettingsDraft( { ...settingsDraft, apply_at_checkout: value } ) }
										disabled={ inFlight }
										__nextHasNoMarginBottom
									/>
								</VStack>
							</VStack>
						</Grid>
					</>
				) }
				{ /* Only meaningful while Memberships is still installed; a migrated site has no such screen. */ }
				{ window.newspackAudienceSubscriptions.memberships_active && (
					<Card>
						<h2>{ __( 'Manage Subscriptions settings in Woo Memberships', 'newspack-plugin' ) }</h2>
						<p>{ __( 'You can manage the details of your subscription offerings in the Woo Memberships plugin.', 'newspack-plugin' ) }</p>
						<Button variant="primary" href={ window.newspackAudienceSubscriptions.memberships_url }>
							{ __( 'Manage Subscriptions', 'newspack-plugin' ) }
						</Button>
					</Card>
				) }
			</WizardSection>
			{ navBlockDialog }
		</WizardsTab>
	);
}

registerTab( 'advanced-settings', { render: () => <AdvancedSettings /> } );

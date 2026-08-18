/**
 * The Subscriptions wizard's Advanced Settings tab: site-wide subscription settings.
 */

/**
 * WordPress dependencies.
 */
import { sprintf, __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { ExternalLink, SelectControl, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Button, Card, Grid, Notice, SectionHeader } from '../../../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../../packages/components/src/wizard/store';
import WizardsTab from '../../../../../wizards-tab';
import WizardSection from '../../../../../wizards-section';
import { registerTab } from '../registry';
import { WIZARD_ENDPOINT } from '../../constants';

function AdvancedSettings() {
	const [ inFlight, setInFlight ] = useState( false );
	const [ saved, setSaved ] = useState( window.newspackAudienceSubscriptions.primary_product );
	const [ draft, setDraft ] = useState( window.newspackAudienceSubscriptions.primary_product );
	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	const isDirty = draft !== saved;

	useEffect( () => {
		const save = () => {
			setInFlight( true );
			apiFetch( {
				path: `${ WIZARD_ENDPOINT }/primary-product`,
				method: 'POST',
				data: { primary_product: draft },
			} )
				.then( () => {
					setSaved( draft );
					window.newspackAudienceSubscriptions.primary_product = draft;
				} )
				.finally( () => {
					setInFlight( false );
				} );
		};
		setHeaderData( {
			actions: [
				{
					type: 'primary',
					label: __( 'Save', 'newspack-plugin' ),
					action: save,
					disabled: ! isDirty || inFlight,
				},
			],
		} );
	}, [ setHeaderData, draft, isDirty, inFlight ] );

	return (
		<WizardsTab>
			<WizardSection>
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
							value={ draft }
							onChange={ setDraft }
							disabled={ inFlight }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
						{ saved && (
							<Notice isDismissible={ false }>
								{ __( 'Share the following URL to trigger the subscription upgrade:', 'newspack-plugin' ) }{ ' ' }
								<a href={ window.newspackAudienceSubscriptions.upgrade_subscription_url } target="_blank" rel="noreferrer noopener">
									{ window.newspackAudienceSubscriptions.upgrade_subscription_url }
								</a>
							</Notice>
						) }
						{ draft ? (
							<HStack>
								<p>
									<Button variant="link" disabled={ inFlight } onClick={ () => setDraft( '' ) }>
										{ __( 'Reset primary product', 'newspack-plugin' ) }
									</Button>
								</p>
								<p>
									<ExternalLink href={ `/wp-admin/post.php?post=${ draft }&action=edit` }>
										{ sprintf(
											/* translators: %s: product title */
											__( 'Edit %s', 'newspack-plugin' ),
											window.newspackAudienceSubscriptions.eligible_products.find(
												product => parseInt( product.id ) === parseInt( draft )
											)?.title || __( 'the product', 'newspack-plugin' )
										) }
									</ExternalLink>
								</p>
							</HStack>
						) : null }
					</VStack>
				</Grid>
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
		</WizardsTab>
	);
}

registerTab( 'advanced-settings', { render: () => <AdvancedSettings /> } );

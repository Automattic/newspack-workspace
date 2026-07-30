/**
 * The saved-card list on the person profile.
 *
 * One card per saved token: brand mark, label, and the two states the design
 * calls out — Default (what renewals fall back to) and Expired. The only
 * action is "Make default", and only on a card that is neither default nor
 * expired: an expired card can't be charged, so offering to make it the
 * fallback would be a trap for every renewal after it. The same rule is
 * enforced server-side; hiding the item here is presentation, not the guard.
 *
 * There is deliberately no add, edit or remove: card entry is a reader-side
 * act (checkout / My Account), where the gateway's own hosted fields keep the
 * card number out of this admin's hands and this screen out of PCI scope.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	Dropdown,
	MenuGroup,
	MenuItem,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { moreVertical } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { Badge, Button, Card } from '../../../../packages/components/src';
import '../screens/style.scss';
import { visa as visaIcon, mastercard as mastercardIcon, amex as amexIcon, discover as discoverIcon, jcb as jcbIcon } from '../assets/cards';

// Keyed by WooCommerce's normalized card types (WC_Payment_Token_CC stores
// lowercase brand slugs; "american express" appears from some gateways).
const BRAND_ICONS = {
	visa: visaIcon,
	mastercard: mastercardIcon,
	amex: amexIcon,
	'american express': amexIcon,
	discover: discoverIcon,
	jcb: jcbIcon,
};

// Resolved per call, not at module scope: __() at import time would freeze
// untranslated strings if this chunk evaluates before translations register.
const brandLabels = () => ( {
	visa: __( 'Visa', 'newspack-plugin' ),
	mastercard: __( 'Mastercard', 'newspack-plugin' ),
	amex: __( 'Amex', 'newspack-plugin' ),
	'american express': __( 'American Express', 'newspack-plugin' ),
	discover: __( 'Discover', 'newspack-plugin' ),
	jcb: __( 'JCB', 'newspack-plugin' ),
} );

const brandKey = pm => ( pm.brand || '' ).toLowerCase();

/**
 * "Visa ending in 4242" — the label every flow and snackbar uses for a card,
 * exported so the profile's modals cannot describe the same card differently.
 *
 * @param {Object} pm A payment method entry from the subscriber payload.
 * @return {string} The label.
 */
export const cardLabel = pm => {
	const brand = brandLabels()[ brandKey( pm ) ] || pm.brand;
	if ( brand && pm.last4 ) {
		// translators: 1: card brand (e.g. "Visa"), 2: the card's last four digits.
		return sprintf( __( '%1$s ending in %2$s', 'newspack-plugin' ), brand, pm.last4 );
	}
	return pm.label || __( 'Saved payment method', 'newspack-plugin' );
};

/**
 * @param {Object}   props                Component props.
 * @param {Array}    props.paymentMethods The subscriber's saved payment methods.
 * @param {Function} props.onMakeDefault  Called with the payment method to promote.
 */
export default function PaymentMethodsList( { paymentMethods, onMakeDefault } ) {
	if ( ! paymentMethods.length ) {
		return (
			<Card __experimentalCoreCard className="newspack-subscribers__card">
				<p>{ __( 'No saved payment methods.', 'newspack-plugin' ) }</p>
			</Card>
		);
	}
	return (
		<VStack spacing={ 4 }>
			{ paymentMethods.map( pm => {
				const icon = BRAND_ICONS[ brandKey( pm ) ];
				const promotable = ! pm.isDefault && ! pm.isExpired;
				return (
					<Card key={ pm.id } __experimentalCoreCard className="newspack-subscribers__card">
						<HStack spacing={ 3 } justify="space-between" alignment="center">
							<HStack spacing={ 3 } justify="flex-start" alignment="center" expanded={ false }>
								{ icon && <img src={ icon } alt="" className="newspack-subscribers__card-icon" /> }
								<VStack spacing={ 1 }>
									<HStack spacing={ 2 } justify="flex-start" expanded={ false }>
										<strong>{ cardLabel( pm ) }</strong>
										{ pm.isDefault && <Badge level="default" text={ __( 'Default', 'newspack-plugin' ) } /> }
										{ pm.isExpired && <Badge level="error" text={ __( 'Expired', 'newspack-plugin' ) } /> }
										{ promotable && (
											// An active, non-default card has no badge to show, so an
											// invisible one reserves the badge height and keeps the
											// rows aligned when a badge appears or goes away.
											<span className="newspack-subscribers__badge-placeholder" aria-hidden="true">
												<Badge level="success" text={ __( 'Active', 'newspack-plugin' ) } />
											</span>
										) }
									</HStack>
									{ pm.expiry && (
										<span className="newspack-subscribers__muted">
											{
												// translators: %s is the card expiry (MM/YY).
												sprintf( __( 'Expiry %s', 'newspack-plugin' ), pm.expiry )
											}
										</span>
									) }
								</VStack>
							</HStack>
							{ promotable && (
								<Dropdown
									className="newspack-subscribers__card-menu"
									placement="bottom-end"
									renderToggle={ ( { isOpen, onToggle } ) => (
										<Button
											icon={ moreVertical }
											size="compact"
											onClick={ onToggle }
											aria-expanded={ isOpen }
											label={
												// translators: %s is a card label (e.g. "Visa ending in 4242").
												sprintf( __( 'Payment method actions: %s', 'newspack-plugin' ), cardLabel( pm ) )
											}
											showTooltip={ false }
										/>
									) }
									renderContent={ ( { onClose } ) => (
										<MenuGroup>
											<MenuItem
												aria-label={
													// translators: %s is a card label (e.g. "Visa ending in 4242").
													sprintf( __( 'Make default: %s', 'newspack-plugin' ), cardLabel( pm ) )
												}
												onClick={ () => {
													onClose();
													onMakeDefault( pm );
												} }
											>
												{ __( 'Make default', 'newspack-plugin' ) }
											</MenuItem>
										</MenuGroup>
									) }
								/>
							) }
						</HStack>
					</Card>
				);
			} ) }
		</VStack>
	);
}

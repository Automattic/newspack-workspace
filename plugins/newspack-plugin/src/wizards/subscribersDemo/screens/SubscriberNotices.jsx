/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * Shared copy and rendering for the full-width notices portaled below the
 * wizard header (the `newspack-subscribers-demo__notices-inner` strip on
 * PersonProfile and GroupDetail).
 *
 * The builders below are the single source of truth for notice wording, so the
 * two screens can't drift. Each returns a normalized notice
 * `{ id, status, plan, body, actionLabel }`; the screen attaches the CTA's
 * onClick (action behavior stays local) and passes the list to NoticesPanel.
 */

/**
 * WordPress dependencies.
 */
import { createInterpolateElement, createPortal } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Notice, __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies.
 */
import { Button } from '../../../../packages/components/src';

// The reader's own subscription is on hold. `lastCharged` is the formatted
// last-successful-charge date, or empty when none is on record.
export const onHoldSelfNotice = ( { plan, lastCharged } ) => ( {
	id: 'status_on_hold',
	status: 'warning',
	plan,
	body: lastCharged
		? sprintf(
				// translators: %s is the last successful charge date.
				__( 'is on hold after a failed payment (last charged %s). Reactivate to restore access.', 'newspack-plugin' ),
				lastCharged
		  )
		: __( 'is on hold after a failed payment. Reactivate to restore access.', 'newspack-plugin' ),
	actionLabel: __( 'Reactivate', 'newspack-plugin' ),
} );

// A group the reader owns is on hold; the owner pays, so it's theirs to fix.
export const onHoldOwnedGroupNotice = ( { plan, lastCharged } ) => ( {
	id: 'status_group_on_hold_owner',
	status: 'warning',
	plan,
	body: lastCharged
		? sprintf(
				// translators: %s is the last successful charge date.
				__( "is on hold after a failed payment (last charged %s). Reactivate to restore your members' access.", 'newspack-plugin' ),
				lastCharged
		  )
		: __( "is on hold after a failed payment. Reactivate to restore your members' access.", 'newspack-plugin' ),
	actionLabel: __( 'Reactivate', 'newspack-plugin' ),
} );

// A group the reader belongs to is on hold; only the owner can resolve payment.
export const onHoldMemberNotice = ( { plan, ownerName } ) => ( {
	id: 'status_group_on_hold_member',
	status: 'warning',
	plan,
	body: ownerName
		? sprintf(
				// translators: %s is the group owner's name.
				__( 'is on hold. %s must reactivate it before access is restored.', 'newspack-plugin' ),
				ownerName
		  )
		: __( 'is on hold. The owner must reactivate it before access is restored.', 'newspack-plugin' ),
	actionLabel: __( 'View owner', 'newspack-plugin' ),
} );

// The group's own page: it's on hold, so management actions are paused.
export const groupOnHoldNotice = ( { plan, lastCharged } ) => ( {
	id: 'group_on_hold',
	status: 'warning',
	plan,
	body: lastCharged
		? sprintf(
				// translators: %s is the last successful charge date.
				__(
					'is on hold after a failed payment (last charged %s). Invites and seat changes are paused until reactivated.',
					'newspack-plugin'
				),
				lastCharged
		  )
		: __( 'is on hold after a failed payment. Invites and seat changes are paused until reactivated.', 'newspack-plugin' ),
	actionLabel: __( 'View owner', 'newspack-plugin' ),
} );

// The group's own page: the owner has requested more seats. `awaiting` switches
// the copy to the payment-pending state (admin has already sent a link).
export const seatRequestNotice = ( { target, status } ) => ( {
	id: 'group_seat_request',
	status: 'warning',
	body:
		status === 'awaiting-payment'
			? sprintf(
					// translators: %d is the requested seat count (wrapped in bold tags).
					__( "Awaiting the owner's payment to increase to <b>%d seats</b>.", 'newspack-plugin' ),
					target
			  )
			: sprintf(
					// translators: %d is the requested seat count (wrapped in bold tags).
					__( 'The owner requested an increase to <b>%d seats</b>.', 'newspack-plugin' ),
					target
			  ),
} );

/**
 * Portal + render for the notices strip. `notices` are normalized notice
 * objects with an optional `action: { label, onClick }`; when `plan` is set it
 * leads the sentence in bold.
 */
export const NoticesPanel = ( { noticesNode, notices } ) => {
	if ( ! noticesNode || ! notices.length ) {
		return null;
	}
	return createPortal(
		<div className="newspack-subscribers-demo__notices-inner">
			{ notices.map( notice => (
				// `spokenMessage` is given an explicit plain-text string: without it, WP
				// Notice defaults it to the JSX children and calls renderToString() on
				// them during render, which walks our Button subtree and corrupts React's
				// hook dispatcher (crashes on the next re-render).
				<Notice
					key={ notice.id }
					status={ notice.status }
					isDismissible={ false }
					spokenMessage={ ( notice.plan ? `${ notice.plan } ` : '' ) + notice.body.replace( /<[^>]+>/g, '' ) }
				>
					<HStack justify="space-between" alignment="center" spacing={ 4 }>
						<span>
							{ notice.plan
								? createInterpolateElement(
										sprintf(
											// translators: 1: subscription/plan name (bold), 2: notice sentence.
											__( '<name>%1$s</name> %2$s', 'newspack-plugin' ),
											notice.plan,
											notice.body
										),
										{ name: <strong />, b: <strong /> }
								  )
								: createInterpolateElement( notice.body, { b: <strong /> } ) }
						</span>
						{ ( notice.actions || ( notice.action ? [ notice.action ] : [] ) ).length > 0 && (
							<HStack spacing={ 2 } justify="flex-end" expanded={ false }>
								{ ( notice.actions || [ notice.action ] ).map( ( action, index ) => (
									<Button key={ index } variant={ action.variant || 'secondary' } size="compact" onClick={ action.onClick }>
										{ action.label }
									</Button>
								) ) }
							</HStack>
						) }
					</HStack>
				</Notice>
			) ) }
		</div>,
		noticesNode
	);
};

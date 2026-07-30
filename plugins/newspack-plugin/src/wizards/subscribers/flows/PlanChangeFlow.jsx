/**
 * Flow — change a subscription's plan.
 *
 * Offers the other individual subscription products (resolved server-side, so
 * group products and the current plan never appear) and swaps the line item on
 * confirm. Deliberately not a WCS "switch": nothing is charged now and no
 * dates move — the new price bills at the already-scheduled next renewal. The
 * copy states that plainly so the admin is never promising the reader a
 * prorated invoice that will not exist.
 */

/**
 * WordPress dependencies.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { SelectControl, Spinner, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies.
 */
import { Button, Modal, Notice } from '../../../../packages/components/src';
import { fmtCurrency, fmtDate } from '../format';

// "Digital Access — $10.00/month", the picker's option label.
const optionLabel = option =>
	`${ option.name } — ${ fmtCurrency( option.amount, option.currency ) }/${
		1 === option.interval ? option.period : `${ option.interval } ${ option.period }s`
	}`;

/**
 * @param {Object}   props              Component props.
 * @param {Object}   props.subscription The profile subscription entry.
 * @param {Object}   props.actions      The payment write calls (usePaymentActions).
 * @param {Function} props.onClose      Close without acting.
 * @param {Function} props.onDone       Called with a success message after the change.
 */
export default function PlanChangeFlow( { subscription, actions, onClose, onDone } ) {
	const [ options, setOptions ] = useState( null );
	const [ selectedId, setSelectedId ] = useState( 0 );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let cancelled = false;
		actions
			.fetchPlanOptions( subscription.id )
			.then( response => {
				if ( ! cancelled ) {
					setOptions( response.options || [] );
					setSelectedId( response.options?.[ 0 ]?.id || 0 );
				}
			} )
			.catch( e => {
				if ( ! cancelled ) {
					setOptions( [] );
					setError( e?.message || __( 'Something went wrong.', 'newspack-plugin' ) );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ subscription.id, actions ] );

	const selected = ( options || [] ).find( option => option.id === Number( selectedId ) );

	const submit = async () => {
		setBusy( true );
		setError( '' );
		try {
			await actions.changePlan( subscription.id, selected.id );
			onDone(
				sprintf(
					// translators: %s is the new plan name.
					__( 'Subscription changed to %s. The new price applies from the next renewal.', 'newspack-plugin' ),
					selected.name
				)
			);
		} catch ( e ) {
			// The server knows why it said no; its message travels verbatim.
			setError( e?.message || __( 'Something went wrong.', 'newspack-plugin' ) );
			setBusy( false );
		}
	};

	return (
		<Modal title={ __( 'Change subscription', 'newspack-plugin' ) } onRequestClose={ busy ? () => {} : onClose } size="small">
			<VStack spacing={ 4 }>
				{ /* role=alert: a refused change must be announced, not appear silently while focus sits on the re-enabled Confirm. */ }
				{ error && (
					<div role="alert">
						<Notice isError noticeText={ error } />
					</div>
				) }
				{ null === options && <Spinner /> }
				{ null !== options && 0 === options.length && ! error && (
					<Notice noticeText={ __( 'There are no other subscriptions to switch to.', 'newspack-plugin' ) } />
				) }
				{ null !== options && options.length > 0 && (
					<>
						<p className="newspack-subscribers__modal-text">
							<strong>
								{
									// translators: %s is the current plan name.
									sprintf( __( 'Currently on %s.', 'newspack-plugin' ), subscription.plan )
								}
							</strong>
						</p>
						<SelectControl
							label={ __( 'New subscription', 'newspack-plugin' ) }
							value={ String( selectedId ) }
							options={ options.map( option => ( { label: optionLabel( option ), value: String( option.id ) } ) ) }
							onChange={ value => setSelectedId( Number( value ) ) }
							__nextHasNoMarginBottom
						/>
						{ selected && (
							<p className="newspack-subscribers__modal-text">
								{ subscription.nextBillingDate
									? sprintf(
											// translators: 1: the next renewal date, 2: the new charge amount.
											__(
												'The change takes effect at the next renewal on %1$s, which will charge %2$s. Nothing is charged or refunded now.',
												'newspack-plugin'
											),
											fmtDate( subscription.nextBillingDate ),
											fmtCurrency( selected.amount, selected.currency )
									  )
									: sprintf(
											// translators: %s is the new charge amount.
											__(
												'The change takes effect at the next renewal, which will charge %s. Nothing is charged or refunded now.',
												'newspack-plugin'
											),
											fmtCurrency( selected.amount, selected.currency )
									  ) }
							</p>
						) }
					</>
				) }
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="tertiary" size="compact" disabled={ busy } onClick={ onClose }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" size="compact" isBusy={ busy } disabled={ busy || ! selected } onClick={ submit }>
						{ __( 'Confirm', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}

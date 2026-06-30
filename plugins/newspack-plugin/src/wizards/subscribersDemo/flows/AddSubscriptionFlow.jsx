/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * Add subscription flow (absorbs the former Resubscribe flow).
 *
 * One screen: pick a plan, then choose how to add it (charge the card now,
 * send a payment link, or grant free access). Team plans route to a seat
 * step. Group restore is a confirm-only branch.
 */

import { createInterpolateElement, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
	CheckboxControl,
	Notice,
	TextControl,
} from '@wordpress/components';
import { Button, Modal, SelectControl } from '../../../../packages/components/src';
import { DIGITAL_PLANS, PRINT_PLANS } from '../data/mock-subscribers';
import { TEAM_PLANS, createGroup } from '../data/mock-groups';
import { fmtCurrency } from '../format';
import { ChargeNote, FreeAccessFields, ModeRadio, PaymentLinkNote, hasUsableCard, useFreeAccess } from './free-access';
import { StepButtons, useTwoStep } from './steps';

// Catalog grouped by family, so the picker can label options and the duplicate
// guard can compare like for like.
const CATALOG = [
	{ family: 'digital', label: __( 'Digital', 'newspack-plugin' ), plans: DIGITAL_PLANS },
	{ family: 'print', label: __( 'Print', 'newspack-plugin' ), plans: PRINT_PLANS },
	{ family: 'team', label: __( 'Team', 'newspack-plugin' ), plans: TEAM_PLANS },
];
const planFamily = name => CATALOG.find( g => g.plans.some( p => p.name === name ) )?.family || null;

const ADD_METHOD_DETAIL = {
	charge: __( "Charge the customer's card on file now. Billing starts today.", 'newspack-plugin' ),
	link: __( 'Email the customer a link to pay; the subscription activates once they do.', 'newspack-plugin' ),
	comp: __( 'Grant access for free, then choose how long it stays free.', 'newspack-plugin' ),
};

function SeatStep( { plan, onBack, onConfirm } ) {
	const [ seats, setSeats ] = useState( String( plan.seats ) );
	return (
		<VStack spacing={ 4 } className="newspack-subscribers-demo__flow">
			<TextControl
				type="number"
				label={ __( 'Seats', 'newspack-plugin' ) }
				value={ seats }
				onChange={ setSeats }
				help={ __( 'The owner occupies one seat.', 'newspack-plugin' ) }
			/>
			<HStack spacing={ 2 } justify="flex-end">
				<Button variant="tertiary" size="compact" onClick={ onBack }>
					{ __( 'Back', 'newspack-plugin' ) }
				</Button>
				<Button variant="primary" size="compact" onClick={ () => onConfirm( Math.max( 1, Number( seats ) || 1 ) ) }>
					{ __( 'Confirm', 'newspack-plugin' ) }
				</Button>
			</HStack>
		</VStack>
	);
}

function PlanPicker( { subscriber, onPaid, onSendLink, onComp, onTeam, onCancel } ) {
	const priorSub =
		( subscriber.subscriptions || [] ).find( s => s.status === 'cancelled' || s.status === 'on-hold' ) || ( subscriber.subscriptions || [] )[ 0 ];
	const allPlans = CATALOG.flatMap( g => g.plans );
	const priorPlan = priorSub ? allPlans.find( p => p.name === priorSub.plan ) : null;
	const [ planName, setPlanName ] = useState( priorPlan?.name || allPlans[ 0 ].name );
	const plan = allPlans.find( p => p.name === planName );
	const [ customAmount, setCustomAmount ] = useState( String( plan.amount ) );
	const canCharge = hasUsableCard( subscriber );
	const [ mode, setMode ] = useState( canCharge ? 'charge' : 'link' );
	const free = useFreeAccess();
	const [ notify, setNotify ] = useState( true );
	const [ loading, setLoading ] = useState( false );
	// Two steps: 'method' picks the plan and how to add it; 'details' collects the
	// inputs for the chosen mode.
	const step = useTwoStep();

	const onChangePlan = name => {
		setPlanName( name );
		setCustomAmount( String( allPlans.find( p => p.name === name ).amount ) );
	};

	const family = planFamily( planName );
	const isTeam = family === 'team';
	const hasActiveSameFamily = ( subscriber.subscriptions || [] ).some( s => s.status === 'active' && planFamily( s.plan ) === family );

	const buildPaidDraft = () => ( {
		id: 'sub_new_' + Date.now(),
		plan: plan.name,
		status: 'active',
		access: plan.access || null,
		cadence: plan.cadence,
		startDate: new Date().toISOString().slice( 0, 10 ),
		nextBillingDate: new Date( Date.now() + 30 * 86400000 ).toISOString().slice( 0, 10 ),
		amount: Number( customAmount ),
	} );

	// Step 1 Continue: team plans skip straight to seat setup; others advance to
	// the mode details.
	const onMethodContinue = () => {
		if ( isTeam ) {
			onTeam( plan );
			return;
		}
		step.toDetails();
	};

	// Step 2 Continue: run the selected mode.
	const onConfirm = () => {
		setLoading( true );
		setTimeout( () => {
			if ( mode === 'comp' ) {
				onComp(
					{
						id: 'sub_new_' + Date.now(),
						plan: plan.name,
						status: 'active',
						access: plan.access || null,
						cadence: plan.cadence,
						startDate: new Date().toISOString().slice( 0, 10 ),
						nextBillingDate: free.freeForever ? null : new Date( Date.now() + 30 * 86400000 ).toISOString().slice( 0, 10 ),
						amount: free.freeForever ? 0 : plan.amount,
						freeCyclesRemaining: free.freeCyclesRemaining,
					},
					notify
				);
			} else if ( mode === 'link' ) {
				onSendLink( {
					...buildPaidDraft(),
					id: 'sub_pending_' + Date.now(),
					status: 'pending',
					linkSentAt: new Date().toISOString().slice( 0, 10 ),
				} );
			} else {
				onPaid( buildPaidDraft(), notify );
			}
		}, 700 );
	};

	if ( step.isMethod ) {
		return (
			<VStack spacing={ 4 } className="newspack-subscribers-demo__flow">
				{ hasActiveSameFamily && (
					<Notice status="warning" isDismissible={ false }>
						{ sprintf(
							__( '%1$s already has an active %2$s subscription. Adding another will not replace it.', 'newspack-plugin' ),
							subscriber.name,
							family
						) }
					</Notice>
				) }
				<SelectControl
					label={ __( 'Choose a subscription', 'newspack-plugin' ) }
					value={ planName }
					options={ CATALOG.flatMap( g =>
						g.plans.map( p => ( {
							label: `${ g.label } — ${ p.name } (${ fmtCurrency( p.amount ) }/${ p.cadence === 'Monthly' ? 'mo' : 'yr' })`,
							value: p.name,
						} ) )
					) }
					onChange={ onChangePlan }
				/>
				{ ! isTeam && (
					<ModeRadio
						title={ __( 'How would you like to add this subscription?', 'newspack-plugin' ) }
						freeOption={ { label: __( 'Grant free access', 'newspack-plugin' ), value: 'comp' } }
						canCharge={ canCharge }
						details={ ADD_METHOD_DETAIL }
						mode={ mode }
						setMode={ setMode }
					/>
				) }
				<StepButtons
					leftLabel={ __( 'Cancel', 'newspack-plugin' ) }
					onLeft={ onCancel }
					rightLabel={ __( 'Continue', 'newspack-plugin' ) }
					onRight={ onMethodContinue }
				/>
			</VStack>
		);
	}

	return (
		<VStack spacing={ 4 } className="newspack-subscribers-demo__flow">
			<p>
				<strong>{ plan.name }</strong>
			</p>
			{ ( mode === 'charge' || mode === 'link' ) && (
				<TextControl
					type="number"
					label={ __( 'Price', 'newspack-plugin' ) }
					value={ customAmount }
					onChange={ setCustomAmount }
					help={
						Number( customAmount ) !== plan.amount
							? sprintf( __( 'Catalog price is %s.', 'newspack-plugin' ), fmtCurrency( plan.amount ) )
							: undefined
					}
				/>
			) }
			{ mode === 'charge' && <ChargeNote amount={ Number( customAmount ) } cadence={ plan.cadence } /> }
			{ mode === 'link' && <PaymentLinkNote email={ subscriber.email } /> }
			{ mode === 'comp' && (
				<FreeAccessFields
					free={ free }
					planName={ plan.name }
					amount={ plan.amount }
					foreverCopy={ createInterpolateElement(
						sprintf(
							// translators: %s is the plan name (bold).
							__( 'Grants <name>%s</name> ongoing free access, with no billing.', 'newspack-plugin' ),
							plan.name
						),
						{ name: <strong /> }
					) }
				/>
			) }
			{ mode !== 'link' && (
				<CheckboxControl
					label={ __( 'Send a confirmation email', 'newspack-plugin' ) }
					help={ __( 'Email the subscriber a confirmation of the new subscription.', 'newspack-plugin' ) }
					checked={ notify }
					onChange={ setNotify }
					__nextHasNoMarginBottom
				/>
			) }
			<StepButtons
				leftLabel={ __( 'Back', 'newspack-plugin' ) }
				onLeft={ step.toMethod }
				rightLabel={ __( 'Confirm', 'newspack-plugin' ) }
				onRight={ onConfirm }
				busy={ loading }
				disabled={ loading }
			/>
		</VStack>
	);
}

function CardPicker( { subscriber, onBack, onConfirm } ) {
	const cards = subscriber.paymentMethods || [];
	const defaultCard = cards.find( c => c.isDefault ) || cards[ 0 ];
	const [ cardId, setCardId ] = useState( defaultCard.id );
	return (
		<VStack spacing={ 4 } className="newspack-subscribers-demo__flow">
			<SelectControl
				label={ __( 'Charge which card', 'newspack-plugin' ) }
				value={ cardId }
				options={ cards.map( c => ( {
					label: `${ c.type } ····${ c.last4 }${ c.isDefault ? __( ' (default)', 'newspack-plugin' ) : '' }`,
					value: c.id,
				} ) ) }
				onChange={ setCardId }
			/>
			<HStack spacing={ 2 } justify="flex-end">
				<Button variant="tertiary" size="compact" onClick={ onBack }>
					{ __( 'Back', 'newspack-plugin' ) }
				</Button>
				<Button variant="primary" size="compact" onClick={ () => onConfirm( cardId ) }>
					{ __( 'Confirm and charge', 'newspack-plugin' ) }
				</Button>
			</HStack>
		</VStack>
	);
}

export default function AddSubscriptionFlow( { subscriber, addMode = false, onClose, onComplete, onOpenPaymentUpdate } ) {
	const [ step, setStep ] = useState( 'plan' );
	const [ paidDraft, setPaidDraft ] = useState( null );
	const [ teamPlan, setTeamPlan ] = useState( null );

	const finishGroup = ( plan, seatLimit ) => {
		const groupRecord = createGroup( { ownerId: subscriber.id, plan: plan.name, cadence: plan.cadence, amount: plan.amount, seatLimit } );
		onComplete( {
			type: 'success',
			message: sprintf( __( 'Added %1$s for %2$s.', 'newspack-plugin' ), plan.name, subscriber.name ),
			groupCreate: groupRecord,
		} );
	};

	const onSendLink = draft => {
		const message = sprintf( __( 'Payment link sent to %s.', 'newspack-plugin' ), subscriber.email );
		onComplete( {
			type: 'success',
			message,
			mutate: s => ( {
				...s,
				subscriptions: [ ...( s.subscriptions || [] ), draft ],
				orders: [
					{
						id: 'ord_link_' + Date.now(),
						date: draft.linkSentAt,
						amount: null,
						type: __( 'Payment link sent', 'newspack-plugin' ),
						subscriptionId: draft.id,
					},
					...( s.orders || [] ),
				],
			} ),
		} );
	};

	const onComp = ( compedSub, notify ) => {
		const base = sprintf( __( 'Granted %1$s free access to %2$s.', 'newspack-plugin' ), subscriber.name, compedSub.plan );
		const order = {
			id: 'ord_' + compedSub.id + '_comp',
			date: new Date().toISOString().slice( 0, 10 ),
			amount: null,
			type: __( 'Granted free access', 'newspack-plugin' ),
			subscriptionId: compedSub.id,
		};
		onComplete( {
			type: 'success',
			message: notify ? sprintf( __( '%s A confirmation email was sent.', 'newspack-plugin' ), base ) : base,
			mutate: s => ( {
				...s,
				status: 'active',
				subscriptions: [ ...( s.subscriptions || [] ), compedSub ],
				orders: [ order, ...( s.orders || [] ) ],
			} ),
		} );
	};

	const finishPaid = ( draft, notify ) => {
		const base = sprintf( __( 'Added %1$s for %2$s.', 'newspack-plugin' ), draft.plan, subscriber.name );
		const order = {
			id: 'ord_' + draft.id + '_charge',
			date: new Date().toISOString().slice( 0, 10 ),
			amount: draft.amount,
			type: __( 'Subscription payment', 'newspack-plugin' ),
			subscriptionId: draft.id,
		};
		onComplete( {
			type: 'success',
			message: notify ? sprintf( __( '%s A confirmation email was sent.', 'newspack-plugin' ), base ) : base,
			mutate: s => ( {
				...s,
				status: 'active',
				subscriptions: [ ...( s.subscriptions || [] ), draft ],
				orders: [ order, ...( s.orders || [] ) ],
			} ),
		} );
	};

	const onPaid = ( draft, notify ) => {
		const cards = subscriber.paymentMethods || [];
		if ( ! cards.length ) {
			onOpenPaymentUpdate( draft, notify );
			return;
		}
		if ( cards.length === 1 ) {
			finishPaid( draft, notify );
			return;
		}
		setPaidDraft( { ...draft, _notify: notify } );
		setStep( 'card' );
	};

	let body;
	if ( step === 'plan' ) {
		body = (
			<PlanPicker
				subscriber={ subscriber }
				onPaid={ onPaid }
				onSendLink={ onSendLink }
				onComp={ onComp }
				onCancel={ onClose }
				onTeam={ plan => {
					setTeamPlan( plan );
					setStep( 'seat' );
				} }
			/>
		);
	} else if ( step === 'seat' ) {
		body = <SeatStep plan={ teamPlan } onBack={ () => setStep( 'plan' ) } onConfirm={ seatLimit => finishGroup( teamPlan, seatLimit ) } />;
	} else if ( step === 'card' ) {
		body = (
			<CardPicker
				subscriber={ subscriber }
				onBack={ () => setStep( 'plan' ) }
				onConfirm={ () => {
					const { _notify, ...cleanDraft } = paidDraft;
					finishPaid( cleanDraft, _notify );
				} }
			/>
		);
	}

	return (
		<Modal
			title={ addMode ? __( 'Add subscription', 'newspack-plugin' ) : __( 'Resubscribe', 'newspack-plugin' ) }
			onRequestClose={ onClose }
			size="small"
		>
			{ body }
		</Modal>
	);
}

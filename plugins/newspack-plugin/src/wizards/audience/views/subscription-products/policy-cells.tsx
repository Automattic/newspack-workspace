/**
 * RSM Layer 2 cell renderers: applied-policy chips and base → effective price.
 *
 * These read only the `policy` field of a product row, which comes from the PHP
 * integration seam (Subscription_Policy_Resolver) — now backed by the live
 * pricing-rule engine. The chips list every applied rule (the per-cycle winner is
 * shown in the effective-price schedule).
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Badge, Popover } from '../../../../../packages/components/src';

/**
 * Format a number as a currency amount using the store currency.
 */
export function formatAmount( amount: number, currency: SubscriptionProductsCurrency ): string {
	return `${ currency.symbol }${ amount.toFixed( currency.decimals ) }`;
}

/**
 * Renders the applied pricing rules as chips. Every rule that applies to the
 * product is shown with the success label — different rules can win at different
 * cycles (see the effective-price schedule), so the column doesn't single one out.
 */
export function PolicyChips( { policy }: { policy: SubscriptionPolicyResolution } ) {
	if ( ! policy?.policies?.length ) {
		return <span className="newspack-subscription-products__muted">{ __( 'No rules', 'newspack-plugin' ) }</span>;
	}

	return (
		<div className="newspack-subscription-products__policy-chips">
			{ policy.policies.map( p => (
				<span key={ p.id } className="newspack-subscription-products__policy-chip" title={ `${ p.label } — ${ p.adjustment_label }` }>
					<Badge level="success" text={ p.label } />
				</span>
			) ) }
		</div>
	);
}

/**
 * Human label for a segment's starting cycle. Cycle 1 is the purchase; later
 * cycles are renewals.
 */
function cycleLabel( fromCycle: number ): string {
	return fromCycle === 1
		? __( 'At purchase', 'newspack-plugin' )
		: sprintf(
				/* translators: %d: renewal number. */
				__( 'Renewal %d', 'newspack-plugin' ),
				fromCycle - 1
		  );
}

/**
 * The per-cycle price trajectory, rendered as a compact list for the schedule popover.
 */
function ScheduleList( { schedule, currency }: { schedule: SubscriptionPolicySegment[]; currency: SubscriptionProductsCurrency } ) {
	return (
		<ul className="newspack-subscription-products__schedule">
			{ schedule.map( seg => (
				<li key={ seg.from_cycle } className="newspack-subscription-products__schedule-row">
					<span className="newspack-subscription-products__schedule-when">{ cycleLabel( seg.from_cycle ) }</span>
					<span className="newspack-subscription-products__schedule-price">
						<strong>{ formatAmount( seg.amount, currency ) }</strong>
					</span>
				</li>
			) ) }
		</ul>
	);
}

/**
 * Renders the base price and, when rules change it, the resulting effective price.
 * A multi-cycle schedule (the price changes across renewals) reveals the full
 * per-cycle trajectory + winning rule in a Popover on hover/focus/click — it can't
 * fit the column.
 */
export function EffectivePrice( { policy, currency }: { policy: SubscriptionPolicyResolution; currency: SubscriptionProductsCurrency } ) {
	const [ showSchedule, setShowSchedule ] = useState( false );

	if ( ! policy || policy.base_price === null || policy.base_price === undefined ) {
		return <span className="newspack-subscription-products__muted">&mdash;</span>;
	}

	const baseLabel = formatAmount( policy.base_price, currency );
	const effectiveLabel = formatAmount( policy.effective_price, currency );
	const schedule = policy.schedule ?? [];
	const hasSchedule = schedule.length > 1;
	const changed = policy.effective_price !== policy.base_price;

	// Flat, unchanged price across all cycles — nothing to expand.
	if ( ! changed && ! hasSchedule ) {
		return <strong>{ baseLabel }</strong>;
	}

	const value = changed ? (
		<span className="newspack-subscription-products__effective-price">
			<span className="newspack-subscription-products__base-price">{ baseLabel }</span>
			<span aria-hidden="true"> → </span>
			<strong className="newspack-subscription-products__effective-price-value">{ effectiveLabel }</strong>
		</span>
	) : (
		<strong>{ effectiveLabel }</strong>
	);

	// A single-segment change has no trajectory to reveal.
	if ( ! hasSchedule ) {
		return value;
	}

	return (
		<button
			type="button"
			className="newspack-subscription-products__effective-price-trigger"
			aria-expanded={ showSchedule }
			onMouseEnter={ () => setShowSchedule( true ) }
			onMouseLeave={ () => setShowSchedule( false ) }
			onFocus={ () => setShowSchedule( true ) }
			onBlur={ () => setShowSchedule( false ) }
			onClick={ () => setShowSchedule( prev => ! prev ) }
		>
			{ value }
			{ showSchedule && (
				<Popover
					className="newspack-subscription-products__schedule-popover"
					position="bottom left"
					focusOnMount={ false }
					onClose={ () => setShowSchedule( false ) }
				>
					<ScheduleList schedule={ schedule } currency={ currency } />
				</Popover>
			) }
		</button>
	);
}

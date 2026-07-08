/**
 * Subscriptions demo — group & team-plan data.
 *
 * Re-exports the Subscribers demo's group dataset verbatim (single source of
 * truth for people and groups), layering on the team-plan commercial extras
 * the subscription views need (status, total sales, total revenue).
 */

/**
 * Internal dependencies.
 */
import { TEAM_PLANS as BASE_TEAM_PLANS } from '../../subscribersDemo/data/mock-groups';

export {
	getPlanOptions,
	GROUP_STATUS_LABELS,
	GROUP_STATUS_BADGE_LEVEL,
	ROLE_LABELS,
	ROLE_RANK,
	GROUPS,
	ALL_GROUP_PLAN_NAMES,
	seatsUsed,
	seatsAvailable,
	isGroupFull,
	reservedSeats,
	isInviteExpired,
	hasActiveInviteLink,
	inviteCapacity,
	isGroupActive,
	isGroupManageable,
	hasSeatRequest,
	canRequestSeats,
	requestSeatIncrease,
	applySeatIncrease,
	sendSeatUpgradeLink,
	paySeatUpgrade,
	clearSeatRequest,
	setMemberRole,
	getStoredGroup,
	setStoredGroup,
	getGroupById,
	getAllGroups,
	createGroup,
	getGroupsForSubscriber,
	getInvitableSubscribers,
	getMemberSubscriber,
	addMembersByEmail,
	getGroupOwnerName,
	getGroupLabel,
} from '../../subscribersDemo/data/mock-groups';

// Per-team-subscription commercial extras keyed by name, merged onto the shared
// team-plan definitions. Defaults keep any unlisted plan active with zero sales.
const TEAM_EXTRAS = {
	'Team Monthly': { status: 'active', totalSales: 22, totalRevenue: 14800 },
	'Team Yearly': { status: 'active', totalSales: 12, totalRevenue: 19000 },
	'Education Annual': { status: 'active', totalSales: 8, totalRevenue: 32000 },
};

const withExtras = plan => ( { status: 'active', totalSales: 0, totalRevenue: 0, ...plan, ...TEAM_EXTRAS[ plan.name ] } );

export const TEAM_PLANS = BASE_TEAM_PLANS.map( withExtras );

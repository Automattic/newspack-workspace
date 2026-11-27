/**
 * Shared types for the Subscribers wizard.
 *
 * The wizard spreads across data hooks, flows, components and screens that all
 * read the same server entities. These shapes are the single source of truth for
 * those entities, so the pieces cannot describe the same reader, group or card
 * differently. Every field is optional unless a consumer treats it as always
 * present: the server omits what does not apply, and reads guard accordingly.
 */

/** A rejected apiFetch error: a serialized `WP_Error`, with its message written for a publisher. */
export interface WpError {
	message?: string;
	code?: string;
	// Some routes attach data the flows read: the HTTP status, and the amount a
	// refund already moved before a later step failed.
	data?: { status?: number; refunded?: number } | null;
}

// Alias for the caught-error shape; the two names are the same thing.
export type ApiError = WpError;

/** A reference to another site of the network that manages a subscription or account. */
export interface NetworkRef {
	name: string;
	url?: string;
}

/** A WooCommerce order reference, by number and optional edit screen. */
export interface OrderRef {
	number: string | number;
	editUrl?: string;
}

/** The buyer of a gift subscription. */
export interface GiftRef {
	name?: string;
	editUrl?: string;
}

/** A group owner reference, as the list, profile and detail endpoints embed it. */
export interface GroupOwnerRef {
	id?: number;
	name?: string;
	email?: string;
	editUrl?: string;
}

/** The group's shareable invite link. */
export interface InviteLink {
	active?: boolean;
	url?: string;
}

/**
 * The billing fields the single-entity endpoints return, read by the shared
 * `format` helpers (`billingText`, `scheduleRow`, `paymentRows`) and the cards.
 */
export interface BillingEntry {
	amount?: number | string;
	currency?: string;
	billingPeriod?: string;
	billingInterval?: number;
	isComplimentary?: boolean;
	nextBillingDate?: string;
	endDate?: string;
	lastPayment?: string;
	startDate?: string;
}

/** A saved payment method (card) on a subscriber. */
export interface PaymentMethod {
	id: number;
	brand?: string;
	last4?: string;
	label?: string;
	expiry?: string;
	isDefault?: boolean;
	isExpired?: boolean;
	/** The gateway the card is saved on; a subscription only switches between cards on its own gateway. */
	gatewayId?: string;
}

/** One subscription on a person's profile: an individual plan, or a group's billing. */
export interface ProfileSubscription {
	id: number;
	plan?: string;
	planName?: string;
	status?: string;
	amount?: number;
	currency?: string;
	billingPeriod?: string;
	billingInterval?: number;
	isComplimentary?: boolean;
	billingEmail?: string;
	editUrl?: string;
	startDate?: string;
	nextBillingDate?: string;
	endDate?: string;
	lastPayment?: string;
	// The subscription's own number, and its latest order, for cross-referencing.
	number?: string | number;
	lastOrder?: OrderRef;
	// Present only on a subscription another network site manages (read-only here).
	managedOn?: NetworkRef;
	// The gift buyer, when the subscription is a gift.
	giftedBy?: GiftRef | null;
	isManual?: boolean;
	isPendingCancel?: boolean;
	canChangePlan?: boolean;
	canCharge?: boolean;
	canReactivate?: boolean;
	/** The gateway the subscription renews through. */
	paymentGatewayId?: string;
	/** The saved card the subscription charges, if any. */
	paymentTokenId?: number;
	refundableAmount?: number;
	refundableCurrency?: string;
}

// The person profile reads a subscription both by this name and as the group's
// billing block; the two are the same shape.
export type SubscriptionEntry = ProfileSubscription;

/**
 * A group as it appears on a reader's profile (one they own or belong to). Unlike
 * the group-detail `GroupSubscription`, the billing fields sit flat on the entry,
 * the way the profile endpoint returns them.
 */
export interface ProfileGroupEntry extends BillingEntry {
	id: number;
	role?: string;
	status?: string;
	plan?: string;
	createdAt?: string;
	joinedAt?: string;
	members?: number;
	seatLimit?: number;
	owner?: GroupOwnerRef | null;
	editUrl?: string;
	number?: string | number;
	lastOrder?: OrderRef;
}

/** A one-time donation listed on a reader's profile. */
export interface Donation {
	id: number;
	status?: string;
	date?: string;
	amount?: number;
	refunded?: number;
	currency?: string;
	number: string | number;
	editUrl?: string;
}

/** A member row within a group. */
export interface GroupMember {
	id: number;
	email?: string;
	name?: string;
	role?: string;
	joinedAt?: string;
}

/** A pending or expired invitation on a group. */
export interface GroupInvite {
	id?: number | string;
	email?: string;
	status?: string;
	sentAt?: string;
}

// The group-detail flows refer to an invitation by this name.
export type Invite = GroupInvite;

/** One group subscription, hydrated for the group-detail screen. */
export interface GroupSubscription {
	id: number;
	status?: string;
	// The group's own name (its subscription's name, or a publisher-set name).
	plan?: string;
	planName?: string;
	role?: string;
	members?: number;
	seatLimit?: number;
	seatsReserved?: number;
	memberList?: GroupMember[];
	invites?: GroupInvite[];
	owner?: GroupOwnerRef | null;
	ownerId?: number;
	ownerStaysAsMember?: boolean;
	ownerChangeable?: boolean;
	canManage?: boolean;
	isComplimentary?: boolean;
	inviteLink?: InviteLink;
	editUrl?: string;
	createdAt?: string;
	joinedAt?: string;
	billing?: ProfileSubscription | null;
}

// The group-detail screen and its flows refer to the group by this name.
export type Group = GroupSubscription;

/** A full subscriber profile, as the single-subscriber endpoint returns it. */
export interface Subscriber {
	id: number;
	name?: string;
	email?: string;
	status?: string;
	editUrl?: string;
	memberSince?: string;
	// The network site where the reader created their account, when not this one.
	signedUpOn?: NetworkRef;
	subscriptions?: ProfileSubscription[];
	groups?: ProfileGroupEntry[];
	paymentMethods?: PaymentMethod[];
	donations?: Donation[];
	donationsMore?: boolean;
}

/** A subscriber-list row's group membership, embedded flat on the item. */
export interface GroupMembershipRef {
	id: number | string;
	plan: string;
	planName?: string;
	status?: string;
	role?: string;
	editUrl?: string;
	isComplimentary?: boolean;
}

/** A subscriber-list row's individual subscription, embedded flat on the item. */
export interface SubscriptionRef {
	plan?: string;
	planName?: string;
	status?: string;
	editUrl?: string;
	isComplimentary?: boolean;
	managedOn?: NetworkRef | null;
}

/** A subscriber-list row (L0), server-paginated. */
export interface SubscriberRow {
	id: number | string;
	// A reader always has these, so the row carries them unconditionally.
	name: string;
	email: string;
	status: string;
	lastPayment?: string;
	memberSince?: string;
	lastSeen?: string;
	tags?: string[];
	newsletters?: Array< { id: string | number; title: string | null } >;
	groups?: GroupMembershipRef[];
	subscriptions?: SubscriptionRef[];
}

/** A group-list row (L0). */
export interface GroupRow {
	id: number | string;
	owner?: GroupOwnerRef;
	plan?: string;
	product?: string;
	editUrl?: string;
	members: number;
	seatLimit: number;
	seatRequest?: { status?: string };
	status?: string;
	createdAt?: string;
}

/** An option in the owner/member combobox: the picked value plus its display name. */
export interface ReaderOption {
	value: string;
	label: string;
	name: string;
}

/** A match from a group's `/search-users` lookup: `email (#id)` text plus the display name. */
export interface ReaderSearchMatch {
	id: number;
	text?: string;
	name?: string;
}

/** Query params the list hooks build from a view for a server-paginated endpoint. */
export interface ListQueryParams {
	page: number;
	per_page: number;
	orderby: string;
	order: 'asc' | 'desc';
	search?: string;
	status?: string | string[];
	plan?: string[];
	access?: string[];
	managed_on?: string[];
	product?: string[];
}

/** The `{ items, total, pages }` envelope a server-paginated list endpoint returns. */
export interface PagedListResponse< T = unknown > {
	items: T[];
	total: number;
	pages: number;
	[ key: string ]: unknown;
}

/** An alternate plan a subscription can switch to, as `/plan-options` returns it. */
export interface PlanOption {
	id: number;
	name: string;
	priceString?: string;
	amount?: number | string;
	currency?: string;
}

/** Input and result shapes for the profile's refund write. */
export interface RefundInput {
	refund?: boolean;
	cancel?: boolean;
	expectedAmount?: number | null;
}
export interface RefundResult {
	refunded?: number;
	cancelled?: boolean;
	gatewayRefund?: boolean;
}

/** The result of the bulk-invite route, in preview and after a send. */
export interface BulkInviteResult {
	summary?: string[];
	to_invite?: unknown[];
	invited?: unknown[];
	not_sent?: unknown[];
	failed?: unknown[];
	over_limit?: string;
}

/**
 * Report back to a screen after a flow completes: a message to show, and whether
 * to keep the modal open (the group screen reads `keepOpen`).
 */
export type OnDone = ( message?: string, options?: { keepOpen?: boolean } ) => void;

/**
 * The group-detail write calls (the return of `useGroupActions`). Every call
 * resolves against `newspack-group-subscription/v1` and rejects with the
 * server's own message.
 */
export interface GroupActions {
	addMembers: ( userIds: Array< number | string > ) => Promise< unknown >;
	removeMembers: ( userIds: Array< number | string > ) => Promise< { members_removed?: Record< string, unknown > } >;
	invite: ( email: string ) => Promise< { email_sent?: boolean } >;
	inviteBulk: ( input: { emails?: string; csv?: string; confirm?: boolean } ) => Promise< BulkInviteResult >;
	cancelInvite: ( email: string ) => Promise< unknown >;
	generateInviteLink: () => Promise< { url?: string } >;
	disableInviteLink: () => Promise< unknown >;
	setManagerRole: ( userId: number | string, role: string ) => Promise< unknown >;
	setSeatLimit: ( limit: number ) => Promise< unknown >;
	changeOwner: ( userId: number | string ) => Promise< unknown >;
	searchReaders: ( search: string ) => Promise< Array< { id: number; text: string; name: string } > >;
	resolveReaderId: ( email: string ) => Promise< number | null >;
}

/** The payment write calls behind a profile's subscription cards (the return of `usePaymentActions`). */
export interface PaymentActions {
	changePaymentMethod: ( subscriptionId: number | string, tokenId: number | string ) => Promise< unknown >;
	refund: ( subscriptionId: number | string, input: RefundInput ) => Promise< RefundResult >;
	changePlan: ( subscriptionId: number | string, productId: number | string ) => Promise< unknown >;
	fetchPlanOptions: ( subscriptionId: number | string ) => Promise< { options?: PlanOption[] } >;
	setDefaultPaymentMethod: ( tokenId: number | string ) => Promise< unknown >;
}

/** The on-hold recovery write calls (the return of `useSubscriptionActions`). */
export interface SubscriptionActions {
	reactivate: (
		subscriptionId: number | string,
		mode: string
	) => Promise< { status?: string; nextBillingDate?: string | null; pendingConfirmation?: boolean } >;
	sendPaymentLink: ( subscriptionId: number | string ) => Promise< { paymentUrl?: string; emailSent?: boolean } >;
}

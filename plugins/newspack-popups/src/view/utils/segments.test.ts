import { registerCriteria } from '../../criteria/utils';
import type { CriteriaConfig } from '../../criteria/utils';
import { getBestPrioritySegment, getOverride, shouldPromptBeDisplayed, periods } from './index';

// Mock the window.location object. See: https://developer.mozilla.org/en-US/docs/Web/API/Location
// `Object.defineProperty` (rather than `delete`-then-reassign) sidesteps `location`'s
// non-optional, not-freely-assignable typing -- `PropertyDescriptor.value` is untyped,
// so this needs no cast, and jsdom happily swaps the whole object out underneath it.
const setWindowLocation = ( domain = 'example.com', search = '' ) => {
	Object.defineProperty( window, 'location', {
		configurable: true,
		value: {
			ancestorOrigins: null,
			hash: null,
			host: domain,
			port: '80',
			protocol: 'http:',
			hostname: domain,
			href: 'https://' + domain + search,
			origin: 'https://' + domain,
			pathname: null,
			search,
			assign: null,
			reload: null,
			replace: null,
			toString: () => 'https://' + domain + search,
		},
	} );
};

// Mock some test criteria.
const criteria: Record< string, CriteriaConfig > = {
	simple: {},
	list__in: {
		matchingFunction: 'list__in',
	},
	range: {
		matchingFunction: 'range',
	},
};

// Mock some test segments.
const segments: Record< string, PopupsSegment > = {
	segment1: {
		criteria: [
			{
				criteria_id: 'simple',
				value: 'simple-non-match',
			},
		],
		priority: 0,
	},
	segment2: {
		criteria: [
			{
				criteria_id: 'simple',
				value: 'simple-match',
			},
		],
		priority: 1,
	},
	segment3: {
		criteria: [
			{
				criteria_id: 'simple',
				value: 'simple-match',
			},
		],
		priority: 1,
	},
	segment4: {
		criteria: [
			{
				criteria_id: 'list__in',
				value: [ 'list-value', 'list-value-2' ],
			},
		],
		priority: 2,
	},
};

// Mock the RAS data library object.
const now = Date.now();
window.newspack_popups_view = {};

// Backing storage for the mock store below. `resetStoreValues()` (called from `beforeEach`)
// replaces the original mock's non-standard `store.clear()` method with a reset of this
// module-scoped object directly -- test-only plumbing, not part of the RAS store contract.
let storeValues: Record< string, unknown > = {};
const resetStoreValues = () => {
	storeValues = {};
};

const mockStore: NewspackReaderActivationStore = {
	get: ( key: string ) => storeValues[ key ],
	getAll: () => storeValues,
	set: ( key: string, value: unknown ) => {
		storeValues[ key ] = value;
	},
	delete: ( key: string ) => {
		delete storeValues[ key ];
	},
	add: ( key: string, value: unknown ) => {
		storeValues[ key ] = [ ...( ( storeValues[ key ] as unknown[] ) || [] ), value ];
	},
	register: () => {},
	rehydrate: () => {},
};

const mockGetActivities = ( action?: string ): NewspackReaderActivity[] => {
	const testActivities: NewspackReaderActivity[] = [
		{
			action: 'article_view',
			data: {},
			timestamp: now - 60 * 60, // 1 hour ago.
		},
		{
			action: 'article_view',
			data: {},
			timestamp: now - periods.week, // 1 week ago.
		},
		{
			action: 'article_view',
			data: {},
			timestamp: now - periods.week * 3, // 3 weeks ago.
		},
		{
			action: 'prompt_seen',
			data: { prompt_id: 1 },
			timestamp: now - 60 * 60,
		},
	];

	if ( ! action ) {
		return testActivities;
	}

	return testActivities.filter( activity => activity.action === action );
};

// This suite only exercises `store` and `getActivities`; the object literal only declares
// `store` (an exact `NewspackReaderActivationStore` match, same as it's used elsewhere in
// this codebase) so the cast to the full interface is comparable, then the other two mocked
// members are assigned individually, each checked against its own declared property type.
window.newspackReaderActivation = { store: mockStore } as NewspackReaderActivation;
window.newspackReaderActivation.getActivities = mockGetActivities;
window.newspackReaderActivation.on = () => {};

const ras = window.newspackReaderActivation!;

const createPrompt = (
	assignedSegments: string[] = [],
	frequency = '0,0,0,month',
	id = '1',
	type = 'inline',
	utmSuppression = ''
): PromptElement => {
	const prompt = document.createElement( 'div' );
	prompt.setAttribute( 'id', 'id_' + id );
	prompt.setAttribute( 'data-segments', assignedSegments.join( ',' ) );
	prompt.setAttribute( 'data-frequency', frequency );

	if ( utmSuppression ) {
		prompt.setAttribute( 'data-suppression', utmSuppression );
	}

	if ( 'inline' === type ) {
		prompt.classList.add( 'newspack-inline-popup' );
	} else if ( 'overlay' === type ) {
		prompt.classList.add( 'newspack-lightbox' );
	}

	return prompt;
};

describe( 'segmentation API', () => {
	beforeEach( () => {
		setWindowLocation();
		window.newspackPopupsCriteria = { criteria: {} };
		for ( const criteriaId in criteria ) {
			registerCriteria( criteriaId, criteria[ criteriaId ] );
		}
		resetStoreValues();
		ras.store.set( 'pageviews', {
			day: {
				count: 1,
				start: now,
			},
			week: {
				count: 1,
				start: now,
			},
			month: {
				count: 1,
				start: now,
			},
		} );
	} );

	it( 'should return null if the reader matches no segment', () => {
		// Set an initial value.
		ras.store.set( 'simple', 'initial-value' );
		expect( getBestPrioritySegment( segments ) ).toEqual( null );
	} );

	it( 'should return the segment ID of the matching segment with the highest priority', () => {
		ras.store.set( 'simple', 'simple-match' );
		expect( getBestPrioritySegment( segments ) ).toEqual( 'segment2' );
	} );

	it( 'should return the segment ID of the segment in the view_as query string', () => {
		const queryString = '?view_as=segment:segment1;all;session_id:1';
		expect( getBestPrioritySegment( segments, queryString ) ).toEqual( 'segment1' );

		const queryString2 = '?view_as=segment:segment2;all;session_id:2';
		expect( getBestPrioritySegment( segments, queryString2 ) ).toEqual( 'segment2' );
	} );

	it( 'should return false if the reader doesn’t match the prompt’s segments', () => {
		const prompt = createPrompt( [ 'segment4' ] );
		expect( shouldPromptBeDisplayed( prompt, getBestPrioritySegment( segments ), ras ) ).toBeFalsy();
	} );

	it( 'should return true if the reader matches the prompt’s segments', () => {
		const prompt = createPrompt( [ 'segment4' ] );
		ras.store.set( 'list__in', 'list-value' );
		expect( shouldPromptBeDisplayed( prompt, getBestPrioritySegment( segments ), ras ) ).toBeTruthy();
	} );

	it( 'should return true if the prompt has no assigned segments', () => {
		const prompt = createPrompt();
		expect( shouldPromptBeDisplayed( prompt, null, ras ) ).toBeTruthy();
	} );

	it( 'should return false if the reader hasn’t amassed enough pageviews', () => {
		const prompt = createPrompt( [], '2,0,0,month' );
		expect( shouldPromptBeDisplayed( prompt, getBestPrioritySegment( segments ), ras ) ).toBeFalsy();
	} );

	it( 'should return false if the reader has already viewed the prompt the max number of times', () => {
		const prompt = createPrompt( [], '0,0,1,month' );
		expect( shouldPromptBeDisplayed( prompt, getBestPrioritySegment( segments ), ras ) ).toBeFalsy();
	} );

	it( 'should only show one overlay prompt per request', () => {
		const prompt1 = createPrompt( [], '0,0,0,month', '1', 'overlay' );
		const prompt2 = createPrompt( [], '0,0,0,month', '2', 'overlay' );

		const shouldPrompt1BeDisplayed = shouldPromptBeDisplayed( prompt1, null, ras );

		// First overlay prompt should be displayed.
		expect( shouldPrompt1BeDisplayed ).toBeTruthy();

		// Force the second overlay prompt to not be displayed even though all other criteria are met.
		const overlayOverride = getOverride( 2, true, shouldPrompt1BeDisplayed );
		expect( shouldPromptBeDisplayed( prompt2, null, ras, overlayOverride ) ).toBeFalsy();
	} );

	it( 'should allow a specific prompt to be always displayed', () => {
		// Force specific prompt to always be displayed.
		const prompt = createPrompt( [], '0,0,0,month', '123' );
		const pidOverride = getOverride( 123, false, false, '?pid=123' );
		expect( shouldPromptBeDisplayed( prompt, null, ras, pidOverride ) ).toBeTruthy();
	} );

	it( 'should return false if the reader has or had the UTM Suppression value in utm_source params', () => {
		const prompt = createPrompt( [], '0,0,0,month', '1', 'inline', 'suppress_this' );

		// If the URL does not contain the prompt's UTM suppression value in utm_source, the prompt should be displayed.
		expect( shouldPromptBeDisplayed( prompt, null, ras ) ).toBeTruthy();

		// If the URL has the prompt's UTM suppression value in utm_source, the prompt should not be displayed.
		setWindowLocation( 'example.com', '?utm_source=suppress_this' );
		expect( shouldPromptBeDisplayed( prompt, null, ras ) ).toBeFalsy();

		// Once the reader has had the UTM suppression value, the prompt should no longer be displayed.
		setWindowLocation( 'example.com', '' );
		expect( shouldPromptBeDisplayed( prompt, null, ras ) ).toBeFalsy();
	} );
} );

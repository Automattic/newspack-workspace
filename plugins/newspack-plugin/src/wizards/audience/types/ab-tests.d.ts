/**
 * Ambient types for the Audience Campaigns A/B Tests tab. The tab reads tests,
 * their variants and their per-reader stats from newspack-popups' ab-tests
 * REST routes.
 *
 * Global script file: no top-level imports — an import would turn this file
 * into a module and strip every type below of its global scope.
 */

/**
 * One version of a test: the prompt that is version A, B, C… keyed by letter
 * in a test's `variants`.
 */
type ABTestVariant = {
	status: string;
	status_label?: string;
	eligible?: boolean;
	edit_link?: string;
	id?: number;
	title: string;
};

/**
 * An extra prompt sharing a test's version letter, which the server flags so
 * the publisher can remove it.
 */
type ABTestDuplicate = {
	id: number;
	title: string;
	status: string;
	letter: string;
	edit_link?: string;
};

/**
 * A test from the list route.
 */
type ABTest = {
	test_id: string;
	name?: string;
	status?: string;
	variants: Record< string, ABTestVariant >;
	cached_stats?: ABTestStats | null;
	kept?: string;
	started_at?: string;
	ended_at?: string;
	duplicates?: ABTestDuplicate[];
	prompt_count?: number;
	goal?: string;
	goal_label?: string;
	control_share: number;
};

/**
 * The reader/conversion counts for one version in a test's stats.
 */
type ABTestStatVariant = {
	readers: number;
	conversions: number;
	rate?: number | null;
	lift?: number | null;
};

/**
 * A version's comparison against the control in a test's stats.
 */
type ABTestComparison = {
	confidence?: number | null;
	label?: string;
};

/**
 * Where a test's stats come from, and whether they can be read right now.
 */
type ABTestStatsSource = {
	state?: string;
	final?: boolean;
	reason?: string;
	fetched_at?: string;
	unit?: string;
};

/**
 * Server flags about a test's stats: its age and whether a result is reliable.
 */
type ABTestStatsFlags = {
	young_test?: boolean;
	days_running?: number;
	high_anonymous?: boolean;
};

/**
 * The stats route's response for one test.
 */
type ABTestStats = {
	source: ABTestStatsSource;
	comparisons?: Record< string, ABTestComparison >;
	variants?: Record< string, ABTestStatVariant >;
	flags?: ABTestStatsFlags;
};

/**
 * A prompt as the list route reports it for the tab's picker and the per-prompt
 * menus in Campaigns.
 */
type ABTestPromptState = {
	id: number;
	title: string;
	status: string;
	test_id?: string | null;
	ineligible_reason?: string;
	suggested_goal?: string;
};

/**
 * Whether the stats provider is connected, and why not when it isn't.
 */
type ABTestProvider = {
	available?: boolean;
	reason?: string;
};

/**
 * The A/B Tests list route's response.
 */
type ABTestsData = {
	tests?: ABTest[];
	prompts?: ABTestPromptState[];
	goals?: Record< string, string >;
	provider?: ABTestProvider;
	labels?: Record< string, string >;
};

/**
 * The start route's result: version B, if it was created.
 */
type ABTestStartResult = {
	version_b?: {
		edit_link?: string;
	};
};

/**
 * One bar of the card's result chart.
 */
type ABTestBar = {
	letter: string;
	width: number;
};

/**
 * The bars of a card's result chart, faint while there is no data yet.
 */
type ABTestBars = {
	placeholder: boolean;
	rows: ABTestBar[];
};

/**
 * What a card's Result section shows: a verdict, an optional detail and bars.
 */
type ABTestVerdict = {
	answer: string;
	detail: string | null;
	bars: ABTestBars | null;
};

/**
 * The version the End Test modal recommends keeping, and whether a reliable
 * winner backs it.
 */
type ABTestRecommendation = {
	letter: string;
	winner: boolean;
};

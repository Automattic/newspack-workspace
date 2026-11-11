/**
 * WordPress dependencies.
 */
import { check, search } from '@wordpress/icons';

/**
 * How each glyph name a story status can ask for draws in a `StatusIndicator`.
 *
 * Search and check have no name in the shared vocabulary, so they pass their
 * own glyph. The names match `Statuses::ICONS` in PHP.
 */
const STORY_STATUS_INDICATORS = {
	draft: { status: 'draft' },
	pending: { status: 'pending' },
	scheduled: { status: 'scheduled' },
	search: { icon: search },
	check: { icon: check },
	active: { status: 'active' },
};

const dot = (
	<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">
		<circle cx="12" cy="12" r="3" fill="currentColor" />
	</svg>
);

const NEUTRAL_STATUS_INDICATOR = { icon: dot };

/**
 * The `StatusIndicator` props for a story status.
 *
 * Statuses are filterable through `newspack_story_budget_statuses`, and each
 * names its glyph with `icon`. A status without one, or with a name outside
 * the list, draws a plain dot that implies no state.
 *
 * @param {string} icon The glyph name from the status.
 * @return {Object} `status` or `icon` props for `StatusIndicator`.
 */
export const getStatusIndicatorProps = icon => STORY_STATUS_INDICATORS[ icon ] || NEUTRAL_STATUS_INDICATOR;

/**
 * How each WordPress post status a story can have draws in a `StatusIndicator`.
 */
const WP_STATUS_INDICATORS = {
	publish: { status: 'active' },
	future: { status: 'scheduled' },
	draft: { status: 'draft' },
	pending: { status: 'pending' },
	private: { status: 'private' },
};

/**
 * The `StatusIndicator` props for a WordPress post status.
 *
 * @param {string} slug The post status slug.
 * @return {Object|undefined} `status` props for `StatusIndicator`, or undefined
 *                            for a status without a glyph, which shows as text.
 */
export const getWpStatusIndicatorProps = slug => WP_STATUS_INDICATORS[ slug ];

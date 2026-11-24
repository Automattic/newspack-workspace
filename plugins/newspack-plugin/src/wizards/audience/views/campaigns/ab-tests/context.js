/**
 * WordPress dependencies
 */
import { createContext } from '@wordpress/element';

/**
 * The A/B Tests list route's data, for the prompt menus in Campaigns: which
 * prompts are in a test, which can't be tested and why, and the goals. Null
 * while A/B testing is off or the data hasn't loaded, and the menus then offer
 * nothing about tests.
 */
const ABTestsContext = createContext( null );

export default ABTestsContext;

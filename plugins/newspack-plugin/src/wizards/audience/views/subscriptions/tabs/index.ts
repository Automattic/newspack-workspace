/**
 * Tab registrations for the Subscriptions wizard.
 *
 * Importing this module runs every feature's registerTab() call. A new feature
 * adds one import line here and owns everything else in its own directory.
 */

// Feature tab registrations.
import './advanced-settings';
import './discounts';
import './subscriber-only';

export { getTab, registerTab } from './registry';

/**
 * Internal dependencies.
 */
import { withWizardScreen } from '../../../../../../packages/components/src';

// The form sets its own header actions, so the wizard screen wraps each view rather than the route.
export default withWizardScreen( ( { children } ) => <>{ children }</> );

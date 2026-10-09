/**
 * Snackbar.
 */

/**
 * WordPress dependencies.
 */
import { Snackbar as BaseComponent } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';

/**
 * Internal dependencies.
 */
import { WIZARD_STORE_NAMESPACE } from '../store';
import type { WizardNotice } from '../store';
import './style.scss';

/**
 * External dependencies.
 */
import classnames from 'classnames';

type WizardSnackbarProps = {
	/** The component children. */
	children?: React.ReactNode;
	/** The notice severity: 'error' announces assertively, anything else politely. */
	type?: string;
	/** The actions to display in the snackbar. */
	actions?: NonNullable< WizardNotice[ 'actions' ] >;
	/** ID of the store notice the snackbar displays; removed from the store on dismissal. */
	id?: string;
	/** Additional CSS class name. */
	className?: string;
	/** Called when the snackbar is dismissed. */
	onRemove?: () => void;
	/** Remaining props are passed through to the Snackbar component. See: https://wordpress.github.io/gutenberg/?path=/docs/components-snackbar--docs */
	[ propName: string ]: unknown;
};

/**
 * WizardSnackbar component.
 *
 * Wraps core's Snackbar with the wizard-store `onRemove` glue. Positioning and
 * styling are neutral (bottom-centered via the snackbar list container). The
 * notice `type` no longer drives any visual styling, but it still maps to the
 * screen-reader announcement politeness: only `error` announces assertively,
 * every other severity announces politely.
 *
 * @param root0
 * @param root0.children
 * @param root0.type
 * @param root0.actions
 */
const WizardSnackbar = ( { children, type, actions = [], ...props }: WizardSnackbarProps ) => {
	const className = classnames( 'newspack-wizard__snackbar', props.className );
	const politeness = 'error' === type ? 'assertive' : 'polite';
	const { removeNotice, resetNotices } = useDispatch( WIZARD_STORE_NAMESPACE );
	const onRemove = () => {
		if ( props.onRemove ) {
			props.onRemove();
		}
		if ( props.id ) {
			removeNotice( props.id );
		} else {
			resetNotices();
		}
	};
	return (
		<BaseComponent
			{ ...( props as Record< string, never > ) }
			className={ className }
			politeness={ politeness }
			onRemove={ onRemove }
			actions={ actions }
		>
			{ children }
		</BaseComponent>
	);
};

export default WizardSnackbar;

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
	/** The snackbar position. */
	position?: string;
	/** The snackbar type: 'info', 'success', 'warning', or 'error'. */
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
 * @param root0
 * @param root0.children
 * @param root0.position
 * @param root0.type
 * @param root0.actions
 */
const WizardSnackbar = ( { children, position = 'bottom-left', type = 'info', actions = [], ...props }: WizardSnackbarProps ) => {
	const className = classnames(
		'newspack-wizard__snackbar',
		props.className,
		`newspack-wizard__snackbar--${ position }`,
		`newspack-wizard__snackbar--${ type }`
	);
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
		<BaseComponent className={ className } { ...( props as Record< string, never > ) } onRemove={ onRemove } actions={ actions }>
			{ children }
		</BaseComponent>
	);
};

export default WizardSnackbar;

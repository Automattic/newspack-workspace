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
import './style.scss';

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * WizardSnackbar component.
 *
 * @param {Object}      props          - The component props.
 * @param {Object[]}    props.actions  - The actions to display in the snackbar.
 * @param {JSX.Element} props.children - The component children.
 * @param {Object}      props.props    - The component props. See: https://wordpress.github.io/gutenberg/?path=/docs/components-snackbar--docs
 * @param {string}      props.position - The snackbar position.
 * @param {string}      props.type     - The snackbar type: 'info', 'success', 'warning', or 'error'.
 * @return {JSX.Element} The component.
 */
const WizardSnackbar = ( { children, position = 'bottom-left', type = 'info', actions = [], ...props } ) => {
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
		<BaseComponent className={ className } { ...props } onRemove={ onRemove } actions={ actions }>
			{ children }
		</BaseComponent>
	);
};

export default WizardSnackbar;

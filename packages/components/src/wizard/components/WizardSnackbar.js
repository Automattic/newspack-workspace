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
 * Wraps core's Snackbar with the wizard-store `onRemove` glue. Positioning and
 * styling are neutral (bottom-centered via the snackbar list container). The
 * notice `type` no longer drives any visual styling, but it still maps to the
 * screen-reader announcement politeness: only `error` announces assertively,
 * every other severity announces politely.
 *
 * Remaining props are spread onto core's Snackbar. See:
 * https://wordpress.github.io/gutenberg/?path=/docs/components-snackbar--docs
 *
 * @param {Object}      props          - The component props.
 * @param {JSX.Element} props.children - The component children.
 * @param {string}      props.type     - The notice severity ('error' announces assertively, anything else politely).
 * @param {Object[]}    props.actions  - The actions to display in the snackbar.
 * @return {JSX.Element} The component.
 */
const WizardSnackbar = ( { children, type, actions = [], ...props } ) => {
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
		<BaseComponent { ...props } className={ className } politeness={ politeness } onRemove={ onRemove } actions={ actions }>
			{ children }
		</BaseComponent>
	);
};

export default WizardSnackbar;

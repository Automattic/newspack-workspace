/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Internal dependencies
 */
import { espProviderIcons } from '../integration-icons';
import './style.scss';

/**
 * The single source for how an ESP provider is shown: brand mark plus badge
 * background. A new provider needs an icon file, an `espProviderIcons` entry,
 * and a background rule in this component's stylesheet.
 */
const IntegrationIcon = ( { provider, className }: { provider: string; className?: string } ) => {
	const node = Object.prototype.hasOwnProperty.call( espProviderIcons, provider ) ? espProviderIcons[ provider ] : null;
	if ( ! node ) {
		return null;
	}
	return (
		<span className={ classnames( 'newspack-integration-icon', `newspack-integration-icon--${ provider.replace( /_/g, '-' ) }`, className ) }>
			{ node }
		</span>
	);
};

export default IntegrationIcon;

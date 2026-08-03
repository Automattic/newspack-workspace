import '../../shared/js/public-path';

/**
 * Newsletters wizard entry.
 */

/**
 * WordPress dependencies.
 */
import { render } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { Wizard } from '../../../packages/components/src';
import NewslettersSettings from './views/settings';

const NewslettersWizard = () => (
	<Wizard
		headerText={ __( 'Newsletters', 'newspack-plugin' ) }
		requiredPlugins={ [ 'newspack-newsletters' ] }
		fixedHeader
		sections={ [
			{
				path: '/',
				render: NewslettersSettings,
			},
		] }
	/>
);

render( <NewslettersWizard />, document.getElementById( 'newspack-newsletters' ) );

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

const ROOT = [ { label: __( 'Newsletters', 'newspack-plugin' ) } ];

const NewslettersWizard = () => (
	<Wizard
		headerText={ __( 'Newsletters', 'newspack-plugin' ) }
		requiredPlugins={ [ 'newspack-newsletters' ] }
		sections={ [
			{
				path: '/',
				render: NewslettersSettings,
				breadcrumbs: [ ...ROOT, { label: __( 'Settings', 'newspack-plugin' ) } ],
			},
		] }
	/>
);

render( <NewslettersWizard />, document.getElementById( 'newspack-newsletters' ) );

import '../../../../shared/js/public-path';

/**
 * External dependencies.
 */
import values from 'lodash/values';

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { forwardRef } from '@wordpress/element';
/**
 * Internal dependencies.
 */
import { Wizard, Notice, withWizard } from '../../../../../packages/components/src';
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import Configuration from './configuration';
import { AUDIENCE_DONATIONS_WIZARD_SLUG, OTHER } from '../../constants';

const AudienceDonations = ( props, ref ) => {
	const { platform_data, donation_data } = useWizardData( AUDIENCE_DONATIONS_WIZARD_SLUG );
	const usedPlatform = platform_data?.platform;
	const sections = [
		{
			label: __( 'Configuration', 'newspack-plugin' ),
			path: '/configuration',
			render: Configuration,
			isHidden: usedPlatform === OTHER,
		},
	];
	return (
		<Wizard
			headerText={ __( 'Audience Management / Donations', 'newspack-plugin' ) }
			sections={ sections }
			apiSlug={ AUDIENCE_DONATIONS_WIZARD_SLUG }
			renderAboveSections={ () => values( donation_data?.errors ).map( ( error, i ) => <Notice key={ i } isError noticeText={ error } /> ) }
			requiredPlugins={ [ 'newspack-blocks' ] }
			ref={ ref }
		/>
	);
};

export default withWizard( forwardRef( AudienceDonations ) );

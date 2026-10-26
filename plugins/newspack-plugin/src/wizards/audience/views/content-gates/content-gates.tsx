/**
 * Content Gate component.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import ContentGatesOnboarding from './content-gates-onboarding';
import ContentGatesPriority from './content-gates-priority';
import ContentGateSettings from './content-gate-settings';
import AdvancedSettings from './advanced-settings';
import { getGatesSubTitle } from './utils';
import { AUDIENCE_CONTENT_GATES_WIZARD_SLUG } from './consts';
import './style.scss';

const ContentGates = ( { updateGatesData }: { updateGatesData: ( gates: Gate[] ) => void } ) => {
	const wizardData = useWizardData( AUDIENCE_CONTENT_GATES_WIZARD_SLUG ) as WizardData;
	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );
	const [ showPriorityModal, setShowPriorityModal ] = useState( false );
	const [ showAdvancedSettings, setShowAdvancedSettings ] = useState( false );
	const gates = ( wizardData?.gates || [] ) as Gate[];
	const gateCount = gates.length;

	useEffect( () => {
		const subTitle = getGatesSubTitle();
		if ( ! gateCount ) {
			setHeaderData( { actions: [], subTitle } );
			return;
		}
		setHeaderData( {
			actions: [
				{
					type: 'primary',
					label: __( 'Add Content Gate', 'newspack-plugin' ),
					href: '#/edit/new/all',
				},
				...( gateCount > 1
					? [
							{
								type: 'more',
								label: __( 'Gate Priority', 'newspack-plugin' ),
								action: () => setShowPriorityModal( true ),
							},
					  ]
					: [] ),
				{
					type: 'more',
					label: __( 'Advanced Settings', 'newspack-plugin' ),
					action: () => setShowAdvancedSettings( true ),
				},
			],
			subTitle,
		} );
	}, [ gateCount ] );

	if ( ! gates?.length ) {
		return <ContentGatesOnboarding />;
	}

	return (
		<>
			<ContentGatesPriority
				showModal={ showPriorityModal }
				closeModal={ () => setShowPriorityModal( false ) }
				updateGatesData={ updateGatesData }
			/>
			<AdvancedSettings showModal={ showAdvancedSettings } closeModal={ () => setShowAdvancedSettings( false ) } />
			<Stack direction="column" gap="lg" className="newspack-content-gates__gates">
				{ gates.map( gate => {
					return <ContentGateSettings key={ gate.id } gate={ gate } updateGatesData={ updateGatesData } />;
				} ) }
			</Stack>
		</>
	);
};
export default ContentGates;

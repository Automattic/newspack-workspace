/**
 * Internal dependencies.
 */
import { withWizardScreen } from '../../../../../../packages/components/src';
import SegmentsList from './segments-list';
import SingleSegment from './single-segment';
import './style.scss';

/**
 * Props of the Segments screen: the wizard-level shared props, the segments
 * setter, and the route match carrying an optional segment id.
 */
type PopupSegmentationProps = CampaignsWizardSharedProps & {
	setSegments: ( segments: CampaignsSegment[] ) => void;
	match: { params: { id?: string } };
};

/**
 * Popups Segmentation screen.
 */
const PopupSegmentation = ( { wizardApiFetch, match, ...props }: PopupSegmentationProps ) => {
	const segmentId = match.params.id;
	return segmentId ? (
		<SingleSegment segmentId={ segmentId } wizardApiFetch={ wizardApiFetch } { ...props } />
	) : (
		<SegmentsList wizardApiFetch={ wizardApiFetch } { ...props } />
	);
};

export default withWizardScreen( PopupSegmentation );

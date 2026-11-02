/**
 * Internal dependencies.
 */
import SegmentsList from './segments-list';
import SingleSegment from './single-segment';
import './style.scss';

/**
 * Popups Segmentation screen.
 */
const PopupSegmentation = ( { wizardApiFetch, match, ...props } ) => {
	const segmentId = match.params.id;
	return segmentId ? (
		<SingleSegment segmentId={ segmentId } wizardApiFetch={ wizardApiFetch } { ...props } />
	) : (
		<SegmentsList wizardApiFetch={ wizardApiFetch } { ...props } />
	);
};

export default PopupSegmentation;

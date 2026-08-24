/**
 * External dependencies
 */
import { parse } from 'qs';

/**
 * WordPress dependencies
 */
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { Notice, Slot, createSlotFill, __experimentalUseSlot as useSlot, __experimentalUseSlotFills as useSlotFills } from '@wordpress/components';
import { useRef } from '@wordpress/element';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import './style.scss';

const SLOT_NAME = 'NewspackGlobalNotice';

const { Fill: GlobalNoticeFill } = createSlotFill( SLOT_NAME );

export { GlobalNoticeFill };

/**
 * Notices passed through the `newspack-notice` query parameter.
 *
 * The text comes off the query string, so it is rendered as plain text rather than
 * markup. Do not reach for `__unstableHTML` here to match the other notices: that
 * would let a crafted admin URL inject markup into wp-admin.
 *
 * These mount together, and `speak()` clears the live regions before it writes, so
 * only the last announcement would survive. The first message is the one that
 * matters, since a redirect puts the error first, so it is the one announced.
 *
 * @return {Array} Notice elements, empty when the parameter is absent.
 */
const queryNotices = () => {
	const notice = parse( window.location.search, { ignoreQueryPrefix: true } )[ 'newspack-notice' ];
	if ( typeof notice !== 'string' || ! notice ) {
		return [];
	}
	return notice.split( ',' ).map( ( text, i ) => {
		const isError = text.indexOf( '_error_' ) === 0;
		const message = isError ? text.replace( '_error_', '' ) : text;
		return (
			<Notice isDismissible={ false } status={ isError ? 'error' : 'success' } key={ i } spokenMessage={ 0 === i ? message : '' }>
				{ message }
			</Notice>
		);
	} );
};

/**
 * The page-level notice region.
 *
 * Holds the query-parameter notices and a slot every other page-level notice
 * fills, so they share one position and one rhythm.
 */
const GlobalNotices = () => {
	const slotNode = useRef( null );
	const activeSlot = useSlot( SLOT_NAME );
	const fills = useSlotFills( SLOT_NAME );
	const notices = queryNotices();

	// A page can mount a second region deeper in the tree, as a wizard nested in
	// another does. The registry holds one slot per name, so the last to register
	// is where every fill lands; a region that no longer owns it would paint an
	// empty duplicate and a second copy of the query notices, so it steps aside.
	const ownsSlot = ! activeSlot?.ref || activeSlot.ref.current === slotNode.current;

	// An empty region would still paint its padding at the top of every screen.
	if ( ! ownsSlot || ( ! notices.length && ! fills?.length ) ) {
		return null;
	}

	return (
		<Stack direction="column" gap="sm" className="newspack-global-notices">
			{ notices }
			<Slot ref={ slotNode } name={ SLOT_NAME } bubblesVirtually />
		</Stack>
	);
};

export default GlobalNotices;

/**
 * External dependencies
 */
import { parse } from 'qs';

/**
 * WordPress dependencies
 */
// The two slot hooks are experimental. A `@wordpress/components` minor that renames
// either one takes this region down at runtime in a consumer's build rather than in
// this repo's CI, since the package declares that dependency as a caret peer.
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { Notice, Slot, createSlotFill, __experimentalUseSlot as useSlot, __experimentalUseSlotFills as useSlotFills } from '@wordpress/components';
import domReady from '@wordpress/dom-ready';
import { useEffect, useRef, useState } from '@wordpress/element';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import './style.scss';

const SLOT_NAME = 'NewspackGlobalNotice';

const { Fill: GlobalNoticeFill } = createSlotFill( SLOT_NAME );

export { GlobalNoticeFill };

/**
 * Messages passed through the `newspack-notice` query parameter.
 *
 * The text comes off the query string, so it is rendered as plain text rather than
 * markup. Do not reach for `__unstableHTML` here to match the other notices: that
 * would let a crafted admin URL inject markup into wp-admin.
 *
 * A comma separates messages, so a producer must not send one inside a message.
 * Percent-encoding does not protect it: `qs` decodes the value before this splits
 * it, so an encoded comma still arrives as a separator. What the encoding on the
 * producer side does buy is a message containing `&` or `#` surviving the redirect.
 *
 * @return {Array} `{ message, isError }` per message, empty when the parameter is absent.
 */
const queryNotices = () => {
	const notice = parse( window.location.search, { ignoreQueryPrefix: true } )[ 'newspack-notice' ];
	if ( typeof notice !== 'string' || ! notice ) {
		return [];
	}
	return notice.split( ',' ).map( text => {
		const isError = text.indexOf( '_error_' ) === 0;
		return { isError, message: isError ? text.replace( '_error_', '' ) : text };
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
	// A slot registering itself re-renders this component, by which point the
	// effect below has stripped the parameter, so re-reading the query string
	// would erase notices this mount already announced.
	const [ notices ] = useState( queryNotices );
	// `speak()` resolves the live regions by id and drops the message when they are
	// absent, and `@wordpress/a11y` only creates them on `domReady`. The wizard
	// bundle mounts before that, so announcing on mount is silently lost. Holding
	// `spokenMessage` empty until then also keeps a nested pair of regions from
	// announcing twice: by the time this flips, slot ownership has settled and only
	// the owning region is still mounted.
	const [ announceable, setAnnounceable ] = useState( false );

	// A wizard nested in another mounts a second region. One slot per name means
	// the last to register takes every fill, so the region that lost it steps
	// aside rather than paint an empty duplicate. Only for an owner it can
	// identify: were every region to stand down, withWizard's error notices,
	// which this region also hosts, would vanish with them.
	const ownsSlot = ! activeSlot?.ref?.current || activeSlot.ref.current === slotNode.current;

	useEffect( () => {
		if ( ! notices.length ) {
			return;
		}
		domReady( () => setAnnounceable( true ) );
	}, [ notices.length ] );

	useEffect( () => {
		if ( ! notices.length || ! window.history?.replaceState ) {
			return;
		}
		// TabbedNavigation remounts the wizard tree on every tab change, and the
		// parameter otherwise survives in the URL to be re-announced each time.
		const params = new URLSearchParams( window.location.search );
		params.delete( 'newspack-notice' );
		const query = params.toString() ? `?${ params.toString() }` : '';
		window.history.replaceState( {}, '', `${ window.location.pathname }${ query }${ window.location.hash }` );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// An empty region would still paint its padding at the top of every screen.
	if ( ! ownsSlot || ( ! notices.length && ! fills?.length ) ) {
		return null;
	}

	return (
		<Stack direction="column" gap="sm" className="newspack-global-notices">
			{ notices.map( ( { message, isError }, i ) => (
				// These mount together, and `speak()` clears the live regions before it
				// writes, so only the last announcement would survive. The first message
				// is the one that matters, since a redirect puts the error first.
				<Notice
					isDismissible={ false }
					status={ isError ? 'error' : 'success' }
					key={ i }
					spokenMessage={ announceable && 0 === i ? message : '' }
				>
					{ message }
				</Notice>
			) ) }
			<Slot ref={ slotNode } name={ SLOT_NAME } bubblesVirtually />
		</Stack>
	);
};

export default GlobalNotices;

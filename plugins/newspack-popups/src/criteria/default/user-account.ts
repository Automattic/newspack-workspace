/* globals newspackPopupsCriteria */
import { setMatchingFunction } from '../utils';
import type { SegmentConfig, MatchingRas } from '../utils';

setMatchingFunction( 'user_account', ( config: SegmentConfig, ras?: MatchingRas ) => {
	// `ras` is only ever called via `criteria.matches()` once the reader-activation
	// client has loaded (see `getValue()`/`setup()` in `../utils`); the non-null
	// assertion mirrors that pre-existing assumption rather than adding a new guard.
	// `store.get()` itself returns `unknown` by design (values are JSON
	// round-tripped); the 'reader' key holds a `NewspackReader`-shaped object.
	const reader = ras!.store.get( 'reader' ) as NewspackReader | undefined;
	switch ( config.value ) {
		case 'with-account':
			return newspackPopupsCriteria.is_non_preview_user || reader?.email;
		case 'without-account':
			return ! newspackPopupsCriteria.is_non_preview_user && ! reader?.email;
	}
} );

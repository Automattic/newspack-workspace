/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { connect, getCurrentSite, getSites } from '../utils/sites';
import type { Site } from './types';

function ConnectButton( { url }: { url: string } ) {
	const [ isBusy, setBusy ] = useState( false );
	return (
		<Button
			variant="secondary"
			onClick={ () => {
				setBusy( true );
				connect( url );
			} }
			disabled={ getCurrentSite() === url || isBusy }
			isBusy={ isBusy }
		>
			{ __( 'Connect', 'newspack-story-budget' ) }
		</Button>
	);
}

export default function Sites() {
	// `getSites()` is a bare `applyFilters()` call in `../utils/sites` (not yet migrated),
	// so it types as `unknown`; the real shape is `Site[]`.
	const sites = getSites() as Site[];

	return (
		<div className="newspack-story-budget__sites-list">
			{ sites.map( site => (
				<div className="newspack-story-budget__site-card" key={ site.url }>
					<div className="newspack-story-budget__site-card-header">
						<div className="newspack-story-budget__site-card-header-title">{ site.name }</div>
						<div className="newspack-story-budget__site-card-header-url">{ site.url }</div>
					</div>
					<div className="newspack-story-budget__site-card-actions">
						<ConnectButton url={ site.url } />
					</div>
				</div>
			) ) }
		</div>
	);
}

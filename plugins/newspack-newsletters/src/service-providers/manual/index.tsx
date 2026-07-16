/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import CopyHTML from '../../components/copy-html';
import type { ProviderSidebarProps, ServiceProvider } from '../types';
import './style.scss';

/**
 * Component to be rendered in the sidebar panel. Has full control over the
 * panel contents rendering, so that it's possible to render e.g. a loader while
 * the data is not yet available.
 *
 * The manual provider's sidebar is always mounted with `renderSubject` and
 * `renderPreviewText` provided; the non-null assertions preserve the original
 * throw-on-misuse behavior while satisfying the shared (looser) props type.
 */
const ProviderSidebar = ( { renderSubject, renderPreviewText }: ProviderSidebarProps ) => {
	return (
		<div className="newspack-newsletters__manual">
			{ renderSubject!() }
			{ renderPreviewText!() }
		</div>
	);
};

const renderPreSendInfo = () => {
	return (
		<Fragment>
			<p>{ __( 'Copy the HTML code below to manually publish your newsletter with your provider.', 'newspack-newsletters' ) }</p>
			<CopyHTML />
		</Fragment>
	);
};

const renderPostUpdateInfo = () => {
	return (
		<Fragment>
			<p>{ __( 'Copy the HTML code below to manually publish your newsletter with your provider.', 'newspack-newsletters' ) }</p>
			<CopyHTML />
		</Fragment>
	);
};

const provider: ServiceProvider = {
	ProviderSidebar,
	renderPreSendInfo,
	renderPostUpdateInfo,
};

export default provider;

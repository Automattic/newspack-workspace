/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import CopyHTML from '../../components/copy-html';
import './style.scss';

/**
 * Component to be rendered in the sidebar panel.
 * Has full control over the panel contents rendering,
 * so that it's possible to render e.g. a loader while
 * the data is not yet available.
 *
 * @param {Object}   props                   Component props.
 * @param {Function} props.renderSubject     Function that renders email subject input.
 * @param {Function} props.renderPreviewText Function that renders preview text input
 */
const ProviderSidebar = ( { renderSubject, renderPreviewText } ) => {
	return (
		<div className="newspack-newsletters__manual">
			{ renderSubject() }
			{ renderPreviewText() }
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

export default {
	ProviderSidebar,
	renderPreSendInfo,
	renderPostUpdateInfo,
};

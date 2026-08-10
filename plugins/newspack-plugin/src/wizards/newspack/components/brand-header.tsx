/**
 * Newspack Dashboard, Brand-Header
 *
 * Displaying stored logo and header bg color in a header
 */

import { BoxContrast } from '../../../../packages/components/src';

const { settings } = window.newspackDashboard;

const BrandHeader = () => {
	return (
		<header className="newspack-dashboard__brand-header" style={ { backgroundColor: settings.headerBgColor } }>
			<BoxContrast className="brand-header__inner" hexColor={ settings.headerBgColor }>
				<p className="newspack-dashboard__brand-header__title">{ settings.siteName }</p>
			</BoxContrast>
		</header>
	);
};

export default BrandHeader;

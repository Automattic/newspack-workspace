/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { Button, Card, withWizardScreen } from '../../../../../../packages/components/src';
import './style.scss';

/**
 * Popups Analytics screen.
 */
const PopupAnalytics = () => (
	<div className="newspack-campaigns-wizard-analytics__wrapper">
		<Card isNarrow>
			<h2>{ __( 'Coming soon', 'newspack-plugin' ) }</h2>
			<p>
				<>
					{ __(
						'We’re currently redesigning this dashboard to accommodate GA4 and give you deeper insights into Campaign performance. In the meantime, you can find Campaign event data within your GA account. Review this ',
						'newspack-plugin'
					) }
					<a target="_blank" rel="noopener noreferrer" href="https://help.newspack.com/analytics/">
						{ __( 'help page', 'newspack-plugin' ) }
					</a>
					{ __( ' to see how Campaign data is being recorded in GA.', 'newspack-plugin' ) },
				</>
			</p>
			<Card buttonsCard noBorder>
				<Button target="_blank" rel="noopener noreferrer" href="https://help.newspack.com/analytics/" isPrimary>
					{ __( 'View the help page', 'newspack-plugin' ) }
				</Button>
			</Card>
		</Card>
	</div>
);

export default withWizardScreen( PopupAnalytics );

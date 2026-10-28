/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';

/**
 * Internal dependencies
 */
import TestCard from './TestCard';

/**
 * The A/B Tests page.
 *
 * @param {Object} props      Props.
 * @param {Object} props.data Boot data.
 */
const App = ( { data } ) => {
	const { tests = [], provider = {}, editor_new_prompt_url: newPromptUrl } = data;
	return (
		<div className="newspack-popups-ab-tests">
			<h1>{ __( 'A/B Tests', 'newspack-popups' ) }</h1>
			<p>
				{ __(
					'Each test compares a control prompt with the challengers that share its Test ID. Stats come from the connected stats source and are kept for an hour between refreshes.',
					'newspack-popups'
				) }
			</p>
			{ ! provider.available && (
				<Notice status="warning" isDismissible={ false }>
					{ provider.reason }
				</Notice>
			) }
			{ tests.length === 0 ? (
				<Notice status="info" isDismissible={ false }>
					{ __( 'No A/B tests yet. Give two prompts the same Test ID in the prompt editor to start one.', 'newspack-popups' ) }{ ' ' }
					<a href={ newPromptUrl }>{ __( 'Create a prompt', 'newspack-popups' ) }</a>
				</Notice>
			) : (
				tests.map( test => <TestCard key={ test.test_id } test={ test } data={ data } /> )
			) }
		</div>
	);
};

export default App;

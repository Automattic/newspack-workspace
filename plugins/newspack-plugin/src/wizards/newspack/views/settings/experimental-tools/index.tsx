/**
 * Newspack > Settings > Experimental Tools.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useCallback } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { CardFeature, Grid, Router } from '../../../../../../packages/components/src';
import WizardsTab from '../../../../wizards-tab';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import EnableModal from './enable-modal';
import ConfigureView from './configure-view';
import type { Tool } from './types';
import './style.scss';

const { useHistory, useRouteMatch, Route, Switch } = Router;

const { 'experimental-tools': experimentalToolsData } = window.newspackSettings;
const initialTools: Tool[] = experimentalToolsData?.sections?.tools ?? [];

export default function ExperimentalTools() {
	const { wizardApiFetch, isFetching } = useWizardApiFetch( 'newspack-settings/experimental-tools' );
	const [ tools, setTools ] = useState< Tool[] >( initialTools );
	const [ enableSlug, setEnableSlug ] = useState< string | null >( null );
	const history = useHistory();
	const match = useRouteMatch();

	const updateTools = useCallback( ( updatedTools: Tool[] ) => {
		setTools( updatedTools );
	}, [] );

	const handleToggle = useCallback(
		( slug: string, enabled: boolean ) => {
			wizardApiFetch< Tool[] >(
				{
					path: `/newspack/v1/experimental-tools/${ slug }/toggle`,
					method: 'POST',
					data: { enabled },
					isCached: false,
				},
				{ onSuccess: updateTools }
			);
		},
		[ wizardApiFetch, updateTools ]
	);

	const handleSaveFields = useCallback(
		( slug: string, fields: Record< string, string | boolean > ) => {
			wizardApiFetch< Tool[] >(
				{
					path: `/newspack/v1/experimental-tools/${ slug }/settings`,
					method: 'POST',
					data: { fields },
					isCached: false,
				},
				{ onSuccess: updateTools }
			);
		},
		[ wizardApiFetch, updateTools ]
	);

	const enableTool = tools.find( t => t.slug === enableSlug );
	const hasConfigurableFields = ( tool: Tool ) => tool.fields.length > 0;

	const ToolList = () => (
		<WizardsTab
			title={ __( 'Experimental tools', 'newspack-plugin' ) }
			description={ __(
				"These tools are early-stage features we're developing based on publisher feedback. They're functional and supported, but still evolving. Your experience using them directly shapes what they become. Enable any tool below to try it in your newsroom. You can turn tools off at any time, and nothing changes in your published content.",
				'newspack-plugin'
			) }
			isFetching={ isFetching }
		>
			<Grid columns={ 2 } gutter={ 32 }>
				{ tools.map( ( tool: Tool ) => (
					<CardFeature
						key={ tool.slug }
						title={ tool.label }
						description={ tool.description }
						enabled={ tool.enabled }
						requirements={ tool.constant_active ? __( 'Managed by site configuration', 'newspack-plugin' ) : undefined }
						onEnable={ () => setEnableSlug( tool.slug ) }
						onConfigure={ hasConfigurableFields( tool ) ? () => history.push( `${ match.url }/${ tool.slug }` ) : undefined }
						moreControls={ [
							{
								title: __( 'Disable', 'newspack-plugin' ),
								onClick: () => handleToggle( tool.slug, false ),
							},
						] }
					/>
				) ) }
			</Grid>

			{ enableTool && (
				<EnableModal
					tool={ enableTool }
					disabled={ isFetching }
					onConfirm={ () => {
						handleToggle( enableTool.slug, true );
						setEnableSlug( null );
					} }
					onClose={ () => setEnableSlug( null ) }
				/>
			) }
		</WizardsTab>
	);

	const ToolConfigure = ( { slug }: { slug: string } ) => {
		const tool = tools.find( t => t.slug === slug );
		if ( ! tool ) {
			history.push( match.url );
			return null;
		}
		return (
			<ConfigureView
				tool={ tool }
				isFetching={ isFetching }
				onSave={ fields => handleSaveFields( tool.slug, fields ) }
				onBack={ () => history.push( match.url ) }
			/>
		);
	};

	return (
		<Switch>
			<Route
				path={ `${ match.path }/:toolSlug` }
				render={ ( { match: toolMatch }: { match: { params: { toolSlug: string } } } ) => (
					<ToolConfigure key={ toolMatch.params.toolSlug } slug={ toolMatch.params.toolSlug } />
				) }
			/>
			<Route path={ match.path } component={ ToolList } />
		</Switch>
	);
}

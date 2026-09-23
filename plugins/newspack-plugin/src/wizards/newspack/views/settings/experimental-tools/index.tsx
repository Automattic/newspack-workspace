/**
 * Newspack > Settings > Experimental Tools.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useCallback, useEffect } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { CardFeature, Grid, Router } from '../../../../../../packages/components/src';
import WizardsTab from '../../../../wizards-tab';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import EnableModal from './enable-modal';
import ConfigureView from './configure-view';
import type { SaveNotice, Tool } from './types';
import './style.scss';

const { useHistory, useRouteMatch, Redirect, Route, Switch } = Router;

const { 'experimental-tools': experimentalToolsData } = window.newspackSettings;
const initialTools: Tool[] = experimentalToolsData?.sections?.tools ?? [];
const tabLabel: string = experimentalToolsData?.label ?? __( 'Experimental Tools', 'newspack-plugin' );

function ListBreadcrumb() {
	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );
	useEffect( () => {
		setHeaderData( { sectionName: tabLabel } );
	}, [ setHeaderData ] );
	return null;
}

export default function ExperimentalTools() {
	const { wizardApiFetch, isFetching } = useWizardApiFetch( 'newspack-settings/experimental-tools' );
	const [ tools, setTools ] = useState< Tool[] >( initialTools );
	const [ enableSlug, setEnableSlug ] = useState< string | null >( null );
	const history = useHistory();
	const match = useRouteMatch();
	const { addNotice, removeNotice } = useDispatch( WIZARD_STORE_NAMESPACE );

	const updateTools = useCallback( ( updatedTools: Tool[] ) => {
		setTools( updatedTools );
	}, [] );

	const handleToggle = useCallback(
		( slug: string, enabled: boolean ) =>
			wizardApiFetch< Tool[] >(
				{
					path: `/newspack/v1/experimental-tools/${ slug }/toggle`,
					method: 'POST',
					data: { enabled },
					isCached: false,
				},
				{
					onSuccess: updatedTools => {
						updateTools( updatedTools );
						if ( ! enabled ) {
							removeNotice( 'experimental-tools-disabled' );
							addNotice( {
								id: 'experimental-tools-disabled',
								type: 'success',
								message: sprintf(
									/* translators: %s: tool name. */
									__( '%s disabled.', 'newspack-plugin' ),
									updatedTools.find( tool => tool.slug === slug )?.label ?? slug
								),
							} );
						}
					},
				}
			),
		[ wizardApiFetch, updateTools, addNotice, removeNotice ]
	);

	const handleSaveFields = useCallback(
		( slug: string, fields: Record< string, string | boolean >, notice?: SaveNotice ) =>
			wizardApiFetch< Tool[] >(
				{
					path: `/newspack/v1/experimental-tools/${ slug }/settings`,
					method: 'POST',
					data: { fields },
					isCached: false,
				},
				{
					onSuccess: updatedTools => {
						updateTools( updatedTools );
						removeNotice( 'experimental-tools-saved' );
						addNotice( {
							id: 'experimental-tools-saved',
							type: 'success',
							message: notice?.message ?? __( 'Settings saved.', 'newspack-plugin' ),
							actions: notice?.actions,
						} );
					},
				}
			),
		[ wizardApiFetch, updateTools, addNotice, removeNotice ]
	);

	const enableTool = tools.find( t => t.slug === enableSlug );
	const hasConfigurableFields = ( tool: Tool ) => tool.fields.length > 0;

	const toolList = (
		<WizardsTab isFetching={ isFetching }>
			<ListBreadcrumb />
			<Notice status="info" isDismissible={ false } spokenMessage="">
				{ __(
					"These tools are early-stage features we're developing based on publisher feedback. They're functional and supported, but still evolving. Your experience using them directly shapes what they become. Enable any tool below to try it in your newsroom. You can turn tools off at any time, and nothing changes in your published content.",
					'newspack-plugin'
				) }
			</Notice>
			<Grid columns={ 2 } gutter={ 32 }>
				{ tools.map( ( tool: Tool ) => (
					<CardFeature
						headingLevel={ 3 }
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

	const renderToolConfigure = ( { match: toolMatch }: { match: { params: { toolSlug: string } } } ) => {
		const tool = tools.find( t => t.slug === toolMatch.params.toolSlug );
		if ( ! tool ) {
			return <Redirect to={ match.url } />;
		}
		return (
			<ConfigureView
				key={ tool.slug }
				tool={ tool }
				isFetching={ isFetching }
				tabLabel={ tabLabel }
				tabUrl={ `#${ match.url }` }
				onSave={ ( fields, notice ) => handleSaveFields( tool.slug, fields, notice ) }
				onDisable={ () => handleToggle( tool.slug, false ).then( () => history.push( match.url ) ) }
			/>
		);
	};

	return (
		<Switch>
			<Route path={ `${ match.path }/:toolSlug` } render={ renderToolConfigure } />
			<Route path={ match.path } render={ () => toolList } />
		</Switch>
	);
}

import '../../shared/js/public-path';

/* eslint-disable jsx-a11y/anchor-is-valid, no-console */

/**
 * Components Demo
 */

/**
 * WordPress dependencies.
 */
import { CardBody, CardDivider, CardMedia, ExternalLink, ToggleControl, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Component, Fragment, render, createInterpolateElement, createRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Icon, category, plus, postList, settings } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import {
	ActionCard,
	AutocompleteWithSuggestions,
	AutocompleteWithLatestPosts,
	BoxContrast,
	Button,
	Card,
	CardFeature,
	CardForm,
	CardSettingsGroup,
	ColorPicker,
	Footer,
	Grid,
	Handoff,
	ImageUpload,
	Modal,
	NewspackIcon,
	Notice,
	PluginInstaller,
	PluginSettings,
	PluginToggle,
	ProgressBar,
	SelectControl,
	TextControl,
	Divider,
	Waiting,
	WebPreview,
} from '../../../packages/components/src';
import * as newspackIcons from '../../../packages/icons';

class ComponentsDemo extends Component {
	/**
	 * constructor. Demo of how the parent interacts with the components, and controls their values.
	 */
	constructor() {
		super( ...arguments );
		this.state = {
			selectedPostForAutocompleteWithSuggestions: [],
			selectedPostsForAutocompleteWithSuggestionsMultiSelect: [],
			selectedPostForAutocompleteWithLatestPosts: [],
			selectedPostsForAutocompleteWithLatestPostsMultiSelect: [],
			image: null,
			selectValue1: '2nd',
			selectValue2: '',
			selectValue3: '',
			selectValues: [],
			modalShown: false,
			color1: '#003da5',
			draggableList: [
				{ id: 1, title: 'Draggable Item 1' },
				{ id: 2, title: 'Draggable Item 2' },
				{ id: 3, title: 'Draggable Item 3' },
				{ id: 4, title: 'Draggable Item 4' },
				{ id: 5, title: 'Draggable Item 5' },
			],
			settingsGroupCardActive: false,
			cardFeatureEnabled: false,
			cardFeatureCustomEnabled: false,
			cardFormEnabled: false,
			cardFormOpen: false,
		};
		this.dragWrapperRef = createRef();
	}

	/**
	 * Render the example stub.
	 */
	render() {
		const {
			selectedPostForAutocompleteWithSuggestions,
			selectedPostsForAutocompleteWithSuggestionsMultiSelect,
			selectedPostForAutocompleteWithLatestPosts,
			selectedPostsForAutocompleteWithLatestPostsMultiSelect,
			selectValue1,
			selectValue2,
			selectValue3,
			modalShown,
			actionCardToggleChecked,
			color1,
		} = this.state;

		return (
			<Fragment>
				{ newspack_aux_data.is_debug_mode && <Notice debugMode /> }
				<div className="newspack-wizard__header">
					<div className="newspack-wizard__header__inner">
						<div className="newspack-wizard__title">
							<Button
								isLink
								href={ newspack_urls.dashboard }
								label={ __( 'Return to Dashboard', 'newspack-plugin' ) }
								showTooltip={ true }
								icon={ category }
								iconSize={ 36 }
							>
								<NewspackIcon size={ 36 } />
							</Button>
							<div>
								<h2>{ __( 'Components Demo', 'newspack-plugin' ) }</h2>
								<span>{ __( 'Simple components used for composing the UI of Newspack', 'newspack-plugin' ) }</span>
							</div>
						</div>
					</div>
				</div>
				<div className="newspack-wizard newspack-wizard__content">
					<Card>
						<h2>{ __( 'Autocomplete with Suggestions (single-select)', 'newspack-plugin' ) }</h2>
						<AutocompleteWithSuggestions
							label={ __( 'Search for a post', 'newspack-plugin' ) }
							help={ __( 'Begin typing post title, click autocomplete result to select.', 'newspack-plugin' ) }
							onChange={ items => this.setState( { selectedPostForAutocompleteWithSuggestions: items } ) }
							selectedItems={ selectedPostForAutocompleteWithSuggestions }
						/>

						<Divider marginTop={ 32 } marginBottom={ 32 } />

						<h2>{ __( 'Autocomplete with Suggestions (multi-select)', 'newspack-plugin' ) }</h2>
						<AutocompleteWithSuggestions
							hideHelp
							multiSelect
							label={ __( 'Search widgets', 'newspack-plugin' ) }
							help={ __( 'Begin typing post title, click autocomplete result to select.', 'newspack-plugin' ) }
							onChange={ items =>
								this.setState( {
									selectedPostsForAutocompleteWithSuggestionsMultiSelect: items,
								} )
							}
							postTypes={ [
								{ slug: 'page', label: 'Pages' },
								{ slug: 'post', label: 'Posts' },
							] }
							postTypeLabel={ 'widget' }
							postTypeLabelPlural={ 'widgets' }
							selectedItems={ selectedPostsForAutocompleteWithSuggestionsMultiSelect }
						/>
					</Card>
					<Card>
						<h2>{ __( 'Autocomplete with Latest Posts (single-select)', 'newspack-plugin' ) }</h2>
						<AutocompleteWithLatestPosts
							hideHelp
							label={ __( 'Posts', 'newspack-plugin' ) }
							onChange={ items => this.setState( { selectedPostForAutocompleteWithLatestPosts: items } ) }
							selectedItems={ selectedPostForAutocompleteWithLatestPosts }
						/>

						<Divider marginTop={ 32 } marginBottom={ 32 } />

						<h2>{ __( 'Autocomplete with Latest Posts (multi-select)', 'newspack-plugin' ) }</h2>
						<AutocompleteWithLatestPosts
							hideHelp
							multiSelect
							label={ __( 'Posts', 'newspack-plugin' ) }
							onChange={ items =>
								this.setState( {
									selectedPostsForAutocompleteWithLatestPostsMultiSelect: items,
								} )
							}
							selectedItems={ selectedPostsForAutocompleteWithLatestPostsMultiSelect }
						/>
					</Card>
					<Card>
						<h2>{ __( 'Plugin toggles', 'newspack-plugin' ) }</h2>
						<PluginToggle
							plugins={ {
								woocommerce: {
									shouldRefreshAfterUpdate: true,
								},
								'fb-instant-articles': {
									actionText: __( 'Configure Instant Articles', 'newspack-plugin' ),
									href: '/wp-admin/admin.php?page=newspack-dashboard',
								},
							} }
						/>
					</Card>
					<Card>
						<h2>{ __( 'Web Previews', 'newspack-plugin' ) }</h2>
						<Card buttonsCard noBorder className="items-center">
							<WebPreview url="//newspack.com/" label={ __( 'Preview Newspack Site', 'newspack-plugin' ) } variant="primary" />
							<WebPreview
								url="//newspack.com/"
								renderButton={ ( { showPreview } ) => (
									<a href="#" onClick={ showPreview }>
										{ __( 'Preview Newspack Site', 'newspack-plugin' ) }
									</a>
								) }
								title={ __( 'Preview Newspack Site', 'newspack-plugin' ) }
							/>
						</Card>
					</Card>
					<Card>
						<h2>{ __( 'Waiting', 'newspack-plugin' ) }</h2>
						<Card buttonsCard noBorder>
							<Grid columns={ 1 } gutter={ 16 } className="w-100">
								<Waiting />
								<div className="flex items-center">
									<Waiting isLeft />
									{ __( 'Spinner on the left', 'newspack-plugin' ) }
								</div>
								<div className="flex items-center">
									<Waiting isRight />
									{ __( 'Spinner on the right', 'newspack-plugin' ) }
								</div>
								<Waiting isCenter />
							</Grid>
						</Card>
					</Card>
					<Card>
						<h2>{ __( 'Color picker', 'newspack-plugin' ) }</h2>
						<ColorPicker
							label={ __( 'Color Picker', 'newspack-plugin' ) }
							color={ color1 }
							onChange={ color => this.setState( { color1: color } ) }
						/>
					</Card>
					<Card>
						<h2>{ __( 'Handoff Buttons', 'newspack-plugin' ) }</h2>
						<Card buttonsCard noBorder>
							<Handoff plugin="jetpack" />
							<Handoff plugin="google-site-kit" />
							<Handoff plugin="woocommerce" />
							<Handoff plugin="wordpress-seo" isPrimary editLink="/wp-admin/admin.php?page=wpseo_dashboard#top#features">
								{ __( 'Specific Yoast Page', 'newspack-plugin' ) }
							</Handoff>
							<Handoff
								url="/wp-admin/admin.php?page=newspack-dashboard"
								bannerText={ __( "Return to Components Demo once you're done.", 'newspack-plugin' ) }
								bannerButtonText={ __( 'Back to Components Demo', 'newspack-plugin' ) }
							>
								{ __( 'Go to Dashboard', 'newspack-plugin' ) }
							</Handoff>
						</Card>
					</Card>
					<Card>
						<h2>{ __( 'Modal', 'newspack-plugin' ) }</h2>
						<Card buttonsCard noBorder>
							<Button isPrimary onClick={ () => this.setState( { modalShown: true } ) }>
								{ __( 'Open modal', 'newspack-plugin' ) }
							</Button>
						</Card>
						{ modalShown && (
							<Modal
								title={ __( 'This is the modal title', 'newspack-plugin' ) }
								onRequestClose={ () => this.setState( { modalShown: false } ) }
							>
								<p>
									{ __(
										'Based on industry research, we advise to test the modal component, and continuing this sentence so we can see how the text wraps is one good way of doing that.',
										'newspack-plugin'
									) }
								</p>
								<Card buttonsCard noBorder className="justify-end">
									<Button isPrimary onClick={ () => this.setState( { modalShown: false } ) }>
										{ __( 'Dismiss', 'newspack-plugin' ) }
									</Button>
									<Button isSecondary onClick={ () => this.setState( { modalShown: false } ) }>
										{ __( 'Also dismiss', 'newspack-plugin' ) }
									</Button>
								</Card>
							</Modal>
						) }
					</Card>
					<Card>
						<h2>{ __( 'Notice', 'newspack-plugin' ) }</h2>
						<Notice noticeText={ __( 'This is an info notice.', 'newspack-plugin' ) } />
						<Notice noticeText={ __( 'This is an error notice.', 'newspack-plugin' ) } isError />
						<Notice noticeText={ __( 'This is a help notice.', 'newspack-plugin' ) } isHelp />
						<Notice noticeText={ __( 'This is a success notice.', 'newspack-plugin' ) } isSuccess />
						<Notice noticeText={ __( 'This is a warning notice.', 'newspack-plugin' ) } isWarning />
					</Card>
					<Card>
						<h2>{ __( 'Plugin installer', 'newspack-plugin' ) }</h2>
						<PluginInstaller
							plugins={ [ 'woocommerce', 'wordpress-seo' ] }
							canUninstall
							onStatus={ ( { complete, pluginInfo } ) => {
								console.log( complete ? 'All plugins installed successfully' : 'Plugin installation incomplete', pluginInfo );
							} }
						/>
					</Card>
					<Card>
						<h2>{ __( 'Plugin installer (small)', 'newspack-plugin' ) }</h2>
						<PluginInstaller
							plugins={ [ 'woocommerce', 'wordpress-seo' ] }
							isSmall
							canUninstall
							onStatus={ ( { complete, pluginInfo } ) => {
								console.log( complete ? 'All plugins installed successfully' : 'Plugin installation incomplete', pluginInfo );
							} }
						/>
					</Card>
					<ActionCard
						title={ __( 'Example One', 'newspack-plugin' ) }
						description={ __( 'Has an action button.', 'newspack-plugin' ) }
						actionText={ __( 'Install', 'newspack-plugin' ) }
						onClick={ () => {
							console.log( 'Install clicked' );
						} }
					/>
					<ActionCard
						title={ __( 'Example Two', 'newspack-plugin' ) }
						description={ __( 'Has action button and secondary button.', 'newspack-plugin' ) }
						actionText={ __( 'Edit', 'newspack-plugin' ) }
						secondaryActionText={ __( 'Delete', 'newspack-plugin' ) }
						secondaryDestructive
						onClick={ () => {
							console.log( 'Edit clicked' );
						} }
						onSecondaryActionClick={ () => {
							console.log( 'Delete clicked' );
						} }
					/>
					<ActionCard
						title={ __( 'Example Three', 'newspack-plugin' ) }
						description={ __( 'Waiting/in-progress state, no action button.', 'newspack-plugin' ) }
						actionText={ __( 'Installing…', 'newspack-plugin' ) }
						isWaiting
					/>
					<ActionCard
						title={ __( 'Example Four', 'newspack-plugin' ) }
						description={ __( 'Error notification', 'newspack-plugin' ) }
						actionText={ __( 'Install', 'newspack-plugin' ) }
						onClick={ () => {
							console.log( 'Install clicked' );
						} }
						notification={
							<Fragment>
								Plugin cannot be installed <a href="#">Retry</a> | <a href="#">Documentation</a>
							</Fragment>
						}
						notificationLevel="error"
					/>
					<ActionCard
						title={ __( 'Example Five', 'newspack-plugin' ) }
						description={ __( 'Warning notification, action button', 'newspack-plugin' ) }
						notification={
							<Fragment>
								There is a new version available. <a href="#">View details</a> or <a href="#">update now</a>
							</Fragment>
						}
						notificationLevel="warning"
					/>
					<ActionCard
						title={ __( 'Example Six', 'newspack-plugin' ) }
						description={ __( 'Static text, no button', 'newspack-plugin' ) }
						actionText={ __( 'Active', 'newspack-plugin' ) }
					/>
					<ActionCard
						title={ __( 'Example Seven', 'newspack-plugin' ) }
						description={ __( 'Static text, secondary action button.', 'newspack-plugin' ) }
						actionText={ __( 'Active', 'newspack-plugin' ) }
						secondaryActionText={ __( 'Delete', 'newspack-plugin' ) }
						secondaryDestructive
						onSecondaryActionClick={ () => {
							console.log( 'Delete clicked' );
						} }
					/>
					<ActionCard
						title={ __( 'Example Eight', 'newspack-plugin' ) }
						description={ __( 'Image with link and action button.', 'newspack-plugin' ) }
						actionText={ __( 'Configure', 'newspack-plugin' ) }
						onClick={ () => {
							console.log( 'Configure clicked' );
						} }
						image="https://i0.wp.com/newspack.com/wp-content/uploads/2020/06/pexels-photo-3183150.jpeg"
						imageLink="https://newspack.com"
					/>
					<ActionCard
						title={ __( 'Example Nine', 'newspack-plugin' ) }
						description={ __( 'Action Card with Toggle Control.', 'newspack-plugin' ) }
						actionText={ actionCardToggleChecked && __( 'Configure', 'newspack-plugin' ) }
						onClick={ () => {
							console.log( 'Configure clicked' );
						} }
						toggleOnChange={ checked => this.setState( { actionCardToggleChecked: checked } ) }
						toggleChecked={ actionCardToggleChecked }
					/>
					<ActionCard
						badge={ __( 'Premium', 'newspack-plugin' ) }
						title={ __( 'Example Ten', 'newspack-plugin' ) }
						description={ __( 'An example of an action card with a badge.', 'newspack-plugin' ) }
						actionText={ __( 'Install', 'newspack-plugin' ) }
						onClick={ () => {
							console.log( 'Install clicked' );
						} }
					/>
					<ActionCard
						isSmall
						title={ __( 'Example Eleven', 'newspack-plugin' ) }
						description={ __( 'An example of a small action card.', 'newspack-plugin' ) }
						actionText={ __( 'Installing', 'newspack-plugin' ) }
						onClick={ () => {
							console.log( 'Install clicked' );
						} }
					/>
					<ActionCard
						title={ __( 'Example Twelve', 'newspack-plugin' ) }
						description={ __( 'Action card with an unchecked checkbox.', 'newspack-plugin' ) }
						actionText={ __( 'Configure', 'newspack-plugin' ) }
						onClick={ () => {
							console.log( 'Configure' );
						} }
						checkbox="unchecked"
					/>
					<ActionCard
						title={ __( 'Example Thirteen', 'newspack-plugin' ) }
						description={ __( 'Action card with a checked checkbox.', 'newspack-plugin' ) }
						secondaryActionText={ __( 'Disconnect', 'newspack-plugin' ) }
						onSecondaryActionClick={ () => {
							console.log( 'Disconnect' );
						} }
						checkbox="checked"
					/>
					<ActionCard
						badge={ [ __( 'Premium', 'newspack-plugin' ), __( 'Archived', 'newspack-plugin' ) ] }
						title={ __( 'Example Fourteen', 'newspack-plugin' ) }
						description={ __( 'An example of an action card with two badges.', 'newspack-plugin' ) }
						actionText={ __( 'Install', 'newspack-plugin' ) }
						onClick={ () => {
							console.log( 'Install clicked' );
						} }
					/>
					<ActionCard
						badge={ __( 'It works', 'newspack-plugin' ) }
						badgeLevel="success"
						title={ __( 'Example Fifteen', 'newspack-plugin' ) }
						description={ __( 'An example of an action card with a success badge.', 'newspack-plugin' ) }
						actionText={ __( 'Install', 'newspack-plugin' ) }
						onClick={ () => {
							console.log( 'Install clicked' );
						} }
					/>
					<ActionCard
						badge={ __( 'Uh oh', 'newspack-plugin' ) }
						badgeLevel="warning"
						title={ __( 'Example Sixteen', 'newspack-plugin' ) }
						description={ __( 'An example of an action card with a warning badge.', 'newspack-plugin' ) }
						actionText={ __( 'Install', 'newspack-plugin' ) }
						onClick={ () => {
							console.log( 'Install clicked' );
						} }
					/>
					<ActionCard
						badge={ __( 'Oh no', 'newspack-plugin' ) }
						badgeLevel="error"
						title={ __( 'Example Seventeen', 'newspack-plugin' ) }
						description={ __( 'An example of an action card with an error badge.', 'newspack-plugin' ) }
						actionText={ __( 'Install', 'newspack-plugin' ) }
						onClick={ () => {
							console.log( 'Install clicked' );
						} }
					/>
					<ActionCard
						badge={ __( 'Brand awareness', 'newspack-plugin' ) }
						badgeLevel="brand"
						title={ __( 'Example Eighteen', 'newspack-plugin' ) }
						description={ __( 'An example of an action card with a brand-colored badge.', 'newspack-plugin' ) }
						actionText={ __( 'Install', 'newspack-plugin' ) }
						onClick={ () => {
							console.log( 'Install clicked' );
						} }
					/>
					<ActionCard
						title={ __( 'Handoff', 'newspack-plugin' ) }
						description={ __( 'An example of an action card with Handoff.', 'newspack-plugin' ) }
						actionText={ __( 'Configure', 'newspack-plugin' ) }
						handoff="jetpack"
					/>
					<ActionCard
						title={ __( 'Handoff', 'newspack-plugin' ) }
						description={ __( ' An example of an action card with Handoff and EditLink.', 'newspack-plugin' ) }
						actionText={ __( 'Configure', 'newspack-plugin' ) }
						handoff="jetpack"
						editLink="admin.php?page=jetpack#/settings"
					/>
					<ActionCard
						title={ __( 'Handoff with URL', 'newspack-plugin' ) }
						description={ __( 'An example of an action card with URL-based Handoff.', 'newspack-plugin' ) }
						actionText={ __( 'Go to Dashboard', 'newspack-plugin' ) }
						handoffUrl="/wp-admin/admin.php?page=newspack-dashboard"
						bannerText={ __( "Return to Components Demo once you're done.", 'newspack-plugin' ) }
						bannerButtonText={ __( 'Back to Components Demo', 'newspack-plugin' ) }
					/>
					<ActionCard
						expandable
						title={ __( 'Expandable', 'newspack-plugin' ) }
						description={ __( ' An example of an action card with expandable inner content.', 'newspack-plugin' ) }
					>
						<p>{ __( 'Some inner content to display when the card is expanded.', 'newspack-plugin' ) }</p>
					</ActionCard>
					<Card>
						<h2>{ __( 'Image Uploader', 'newspack-plugin' ) }</h2>
						<ImageUpload
							image={ this.state.image }
							onChange={ image => {
								this.setState( { image } );
								console.log( 'Image:' );
								console.log( image );
							} }
						/>
					</Card>
					<Card>
						<h2>{ __( 'Progress bar', 'newspack-plugin' ) }</h2>
						<ProgressBar completed="2" total="3" />
						<ProgressBar completed="2" total="5" label={ __( 'Progress made', 'newspack-plugin' ) } />
						<ProgressBar completed="0" total="5" displayFraction />
						<ProgressBar completed="3" total="8" label={ __( 'Progress made', 'newspack-plugin' ) } displayFraction />
					</Card>
					<Card>
						<h2>{ __( 'Select dropdowns', 'newspack-plugin' ) }</h2>
						<Grid columns={ 1 } gutter={ 16 }>
							<SelectControl
								label={ __( 'Label for Select with a preselection', 'newspack-plugin' ) }
								value={ selectValue1 }
								options={ [
									{
										value: null,
										label: __( '- Select -', 'newspack-plugin' ),
										disabled: true,
									},
									{ value: '1st', label: __( 'First', 'newspack-plugin' ) },
									{ value: '2nd', label: __( 'Second', 'newspack-plugin' ) },
									{ value: '3rd', label: __( 'Third', 'newspack-plugin' ) },
								] }
								onChange={ value => this.setState( { selectValue1: value } ) }
							/>
							<SelectControl
								label={ __( 'Label for Select with no preselection', 'newspack-plugin' ) }
								value={ selectValue2 }
								options={ [
									{
										value: null,
										label: __( '- Select -', 'newspack-plugin' ),
										disabled: true,
									},
									{ value: '1st', label: __( 'First', 'newspack-plugin' ) },
									{ value: '2nd', label: __( 'Second', 'newspack-plugin' ) },
									{ value: '3rd', label: __( 'Third', 'newspack-plugin' ) },
								] }
								onChange={ value => this.setState( { selectValue2: value } ) }
							/>
							<SelectControl
								label={ __( 'Label for disabled Select', 'newspack-plugin' ) }
								disabled
								options={ [
									{
										value: null,
										label: __( '- Select -', 'newspack-plugin' ),
										disabled: true,
									},
									{ value: '1st', label: __( 'First', 'newspack-plugin' ) },
									{ value: '2nd', label: __( 'Second', 'newspack-plugin' ) },
									{ value: '3rd', label: __( 'Third', 'newspack-plugin' ) },
								] }
							/>
							<SelectControl
								label={ __( 'Small', 'newspack-plugin' ) }
								value={ selectValue3 }
								isSmall
								options={ [
									{
										value: null,
										label: __( '- Select -', 'newspack-plugin' ),
										disabled: true,
									},
									{ value: '1st', label: __( 'First', 'newspack-plugin' ) },
									{ value: '2nd', label: __( 'Second', 'newspack-plugin' ) },
									{ value: '3rd', label: __( 'Third', 'newspack-plugin' ) },
								] }
								onChange={ value => this.setState( { selectValue3: value } ) }
							/>
							<SelectControl
								multiple
								label={ __( 'Multi-select', 'newspack-plugin' ) }
								value={ this.state.selectValues }
								options={ [
									{ value: '1st', label: __( 'First', 'newspack-plugin' ) },
									{ value: '2nd', label: __( 'Second', 'newspack-plugin' ) },
									{ value: '3rd', label: __( 'Third', 'newspack-plugin' ) },
									{ value: '4th', label: __( 'Fourth', 'newspack-plugin' ) },
									{ value: '5th', label: __( 'Fifth', 'newspack-plugin' ) },
									{ value: '6th', label: __( 'Sixth', 'newspack-plugin' ) },
									{ value: '7th', label: __( 'Seventh', 'newspack-plugin' ) },
								] }
								onChange={ selectValues => this.setState( { selectValues } ) }
							/>
							<Notice
								noticeText={
									<>
										{ __( 'Selected:', 'newspack-plugin' ) }{ ' ' }
										{ this.state.selectValues.length > 0
											? this.state.selectValues.join( ', ' )
											: __( 'none', 'newspack-plugin' ) }
									</>
								}
							/>
						</Grid>
					</Card>
					<Card>
						<h2>{ __( 'Buttons', 'newspack-plugin' ) }</h2>
						<Grid columns={ 1 } gutter={ 16 }>
							<p>
								<strong>{ __( 'Default', 'newspack-plugin' ) }</strong>
							</p>
							<Card buttonsCard noBorder>
								<Button variant="primary">{ __( 'Primary', 'newspack-plugin' ) }</Button>
								<Button variant="secondary">{ __( 'Secondary', 'newspack-plugin' ) }</Button>
								<Button variant="tertiary">{ __( 'Tertiary', 'newspack-plugin' ) }</Button>
								<Button>{ __( 'Default', 'newspack-plugin' ) }</Button>
								<Button isLink>{ __( 'isLink', 'newspack-plugin' ) }</Button>
							</Card>
							<Divider variant="tertiary" />
							<p>
								<strong>{ __( 'Disabled', 'newspack-plugin' ) }</strong>
							</p>
							<Card buttonsCard noBorder>
								<Button variant="primary" disabled>
									{ __( 'Primary', 'newspack-plugin' ) }
								</Button>
								<Button variant="secondary" disabled>
									{ __( 'Secondary', 'newspack-plugin' ) }
								</Button>
								<Button variant="tertiary" disabled>
									{ __( 'Tertiary', 'newspack-plugin' ) }
								</Button>
								<Button disabled>{ __( 'Default', 'newspack-plugin' ) }</Button>
								<Button isLink disabled>
									{ __( 'isLink', 'newspack-plugin' ) }
								</Button>
							</Card>
							<Divider variant="tertiary" />
							<p>
								<strong>{ __( 'Small', 'newspack-plugin' ) }</strong>
							</p>
							<Card buttonsCard noBorder>
								<Button variant="primary" isSmall>
									{ __( 'isPrimary', 'newspack-plugin' ) }
								</Button>
								<Button variant="secondary" isSmall>
									{ __( 'isSecondary', 'newspack-plugin' ) }
								</Button>
								<Button variant="tertiary" isSmall>
									{ __( 'isTertiary', 'newspack-plugin' ) }
								</Button>
								<Button isSmall>{ __( 'Default', 'newspack-plugin' ) }</Button>
								<Button isLink isSmall>
									{ __( 'isLink', 'newspack-plugin' ) }
								</Button>
							</Card>
						</Grid>
					</Card>
					<Card>
						<h2>{ __( 'Card (experimental Core component)', 'newspack-plugin' ) }</h2>
						<p>
							{ __(
								'By passing the __experimentalCoreCard prop, the card will be rendered using WP Core’s Card component. ',
								'newspack-plugin'
							) }
							<ExternalLink href="https://wordpress.github.io/gutenberg/?path=/docs/components-card--docs">
								{ __( 'Component details', 'newspack-plugin' ) }
							</ExternalLink>
						</p>
						<VStack spacing="16px">
							<Card
								__experimentalCoreCard
								__experimentalCoreProps={ {
									actionType: 'chevron',
									as: 'a',
									header: (
										<>
											<h3>{ __( 'Button card w/ icon', 'newspack-plugin' ) }</h3>
											<p>{ __( "Clickable card using Core's Card component.", 'newspack-plugin' ) }</p>
										</>
									),
									href: '#',
									icon: plus,
								} }
							/>
							<Card
								isSmall
								__experimentalCoreCard
								__experimentalCoreProps={ {
									actionType: 'chevron',
									as: 'a',
									header: (
										<>
											<h3>{ __( 'Small button card w/ icon + background color + chevron', 'newspack-plugin' ) }</h3>
											<p>{ __( "Clickable card using Core's Card component.", 'newspack-plugin' ) }</p>
										</>
									),
									href: '#',
									icon: postList,
									iconBackgroundColor: true,
								} }
							/>
							<Card
								__experimentalCoreCard
								__experimentalCoreProps={ {
									header: <h3>{ __( 'Card w/ child components', 'newspack-plugin' ) }</h3>,
									footer: (
										<>
											<p>{ __( 'Card Footer', 'newspack-plugin' ) }</p>
											<Button __next40pxDefaultSize variant="secondary">
												{ __( 'Action Button', 'newspack-plugin' ) }
											</Button>
										</>
									),
								} }
							>
								<>
									<CardBody key="1">
										<p>
											{ __(
												'Recommended top-level child components: CardBody, CardMedia, or CardDivider.',
												'newspack-plugin'
											) }
										</p>
									</CardBody>
									<CardMedia key="2">
										<img
											alt="Card Media"
											src="https://images.unsplash.com/photo-1566125882500-87e10f726cdc?ixlib=rb-1.2.1&ixid=MnwxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8&auto=format&fit=crop&w=1867&q=80"
										/>
									</CardMedia>
									<CardBody key="4">
										<p>{ __( 'CardBody (before CardDivider)', 'newspack-plugin' ) }</p>
									</CardBody>
									<CardDivider key="4" />
									<CardBody key="5">
										<p>{ __( 'CardBody (after CardDivider)', 'newspack-plugin' ) }</p>
									</CardBody>
								</>
							</Card>
							<CardSettingsGroup
								actionType="toggle"
								title={ __( 'Settings Group Card', 'newspack-plugin' ) }
								description={ __( 'Can be used in lieu of the ActionCard component.', 'newspack-plugin' ) }
								icon={ settings }
								isActive={ this.state.settingsGroupCardActive }
								onEnable={ () => this.setState( { settingsGroupCardActive: ! this.state.settingsGroupCardActive } ) }
							>
								<>
									<CardBody>
										<ToggleControl
											label={ __( 'A settings option', 'newspack-plugin' ) }
											help={ __( 'A description of the setting', 'newspack-plugin' ) }
											checked={ false }
										/>
									</CardBody>
									<CardDivider />
									<CardBody>
										<TextControl
											label={ __( 'A text input', 'newspack-plugin' ) }
											help={ __( 'A description of the input', 'newspack-plugin' ) }
											placeholder={ __( 'A placeholder for the input', 'newspack-plugin' ) }
											value={ '' }
										/>
									</CardBody>
								</>
							</CardSettingsGroup>
						</VStack>
					</Card>
					<Card>
						<h2>{ __( 'Plugin Settings Section', 'newspack-plugin' ) }</h2>
						<PluginSettings.Section
							sectionKey="example"
							title={ __( 'Example plugin settings', 'newspack-plugin' ) }
							description={ __( 'Example plugin settings description', 'newspack-plugin' ) }
							active={ true }
							fields={ [
								{
									key: 'example_field',
									type: 'string',
									description: __( 'Example Text Field', 'newspack-plugin' ),
									help: __( 'Example text field help text', 'newspack-plugin' ),
									value: __( 'Example Value', 'newspack-plugin' ),
								},
								{
									key: 'example_checkbox_field',
									type: 'boolean',
									description: __( 'Example checkbox Field', 'newspack-plugin' ),
									help: __( 'Example checkbox field help text', 'newspack-plugin' ),
									value: false,
								},
								{
									key: 'example_options_field',
									type: 'string',
									description: __( 'Example options field', 'newspack-plugin' ),
									help: __( 'Example options field help text', 'newspack-plugin' ),
									options: [
										{
											value: 'example_value_1',
											name: __( 'Example Value 1', 'newspack-plugin' ),
										},
										{
											value: 'example_value_2',
											name: __( 'Example Value 2', 'newspack-plugin' ),
										},
									],
								},
								{
									key: 'example_multi_options_field',
									type: 'string',
									description: __( 'Example multiple options field', 'newspack-plugin' ),
									help: __( 'Example multiple options field help text', 'newspack-plugin' ),
									multiple: true,
									options: [
										{
											value: 'example_value_1',
											name: __( 'Example Value 1', 'newspack-plugin' ),
										},
										{
											value: 'example_value_2',
											name: __( 'Example Value 2', 'newspack-plugin' ),
										},
									],
								},
							] }
							onUpdate={ data => {
								console.log( 'Plugin Settings Section Updated', data );
							} }
							onChange={ ( key, val ) => {
								console.log( 'Plugin Settings Section Changed', { key, val } );
							} }
						/>
					</Card>
					<Card>
						<h2>{ __( 'Draggable Action Cards', 'newspack-plugin' ) }</h2>
						<div ref={ this.dragWrapperRef }>
							{ this.state.draggableList.map( ( { id, title }, index ) => (
								<ActionCard
									key={ id }
									id={ id }
									draggable
									dragIndex={ index }
									dragWrapperRef={ this.dragWrapperRef }
									onDragCallback={ newIndex => {
										const newList = [ ...this.state.draggableList ];
										const [ movedItem ] = newList.splice( index, 1 );
										newList.splice( newIndex, 0, movedItem );
										this.setState( { draggableList: newList } );
									} }
									title={ title }
									description={ __( 'An example of an action card that is draggable.', 'newspack-plugin' ) }
								/>
							) ) }
						</div>
					</Card>
					<Card>
						<h2>{ __( 'Box Contrast', 'newspack-plugin' ) }</h2>
						<p>
							Component for adding color black/white depending on contrast ratio for <code>hexColor</code> prop value.
						</p>
						<h3>{ __( 'Demo 1:', 'newspack-plugin' ) }</h3>
						<BoxContrast hexColor="#e5bd13">#e5bd13</BoxContrast>
						<BoxContrast hexColor="#e5bd13" isInverted>
							#e5bd13 / Inverted
						</BoxContrast>
						<h3>{ __( 'Demo 2:', 'newspack-plugin' ) }</h3>
						<BoxContrast hexColor="#003da5">#003da5</BoxContrast>
						<BoxContrast hexColor="#003da5" isInverted>
							#003da5 / Inverted
						</BoxContrast>
						<h3>{ __( 'Demo 3:', 'newspack-plugin' ) }</h3>
						<BoxContrast hexColor="#51f1ff">#e5bd13</BoxContrast>
						<BoxContrast hexColor="#51f1ff" isInverted>
							#51f1ff / Inverted
						</BoxContrast>
					</Card>
					<Card>
						<h2>{ __( 'CardFeature', 'newspack-plugin' ) }</h2>
						<p>
							{ __(
								'A state-driven feature card with a predictable action model. The button, dropdown, and badge are all derived from the enabled and requirements props.',
								'newspack-plugin'
							) }
						</p>
						<h3>{ __( 'States', 'newspack-plugin' ) }</h3>
						<Grid columns={ 2 } gutter={ 16 }>
							<CardFeature
								title={ __( 'Metered countdown', 'newspack-plugin' ) }
								description={ __(
									'Show a countdown banner letting readers know how many free views they have left.',
									'newspack-plugin'
								) }
								requirements={ __( 'Requires metering', 'newspack-plugin' ) }
								onEnable={ () => {} }
								onConfigure={ () => {} }
							/>
							<CardFeature
								title={ __( 'Metered countdown', 'newspack-plugin' ) }
								description={ __(
									'Show a countdown banner letting readers know how many free views they have left.',
									'newspack-plugin'
								) }
								enabled={ true }
								onEnable={ () => {} }
								onConfigure={ () => {} }
								moreControls={ [ { title: __( 'Disable', 'newspack-plugin' ), onClick: () => {} } ] }
							/>
						</Grid>
						<h3>{ __( 'Interactive toggle', 'newspack-plugin' ) }</h3>
						<Grid columns={ 2 } gutter={ 16 }>
							<CardFeature
								title={ __( 'Metered countdown', 'newspack-plugin' ) }
								description={ __(
									'Show a countdown banner letting readers know how many free views they have left.',
									'newspack-plugin'
								) }
								enabled={ this.state.cardFeatureEnabled }
								onEnable={ () => this.setState( { cardFeatureEnabled: true } ) }
								onConfigure={ () => {} }
								moreControls={ [
									{ title: __( 'Disable', 'newspack-plugin' ), onClick: () => this.setState( { cardFeatureEnabled: false } ) },
								] }
							/>
						</Grid>
						<h3>{ __( 'With a custom icon', 'newspack-plugin' ) }</h3>
						<Grid columns={ 2 } gutter={ 16 }>
							<CardFeature
								title={ __( 'Content gifting', 'newspack-plugin' ) }
								description={ __( 'Let subscribers share gated articles with non-subscribers.', 'newspack-plugin' ) }
								icon={ { node: <Icon icon={ settings } />, fill: '#757575', backgroundColor: '#f0f0f0' } }
								enabled={ false }
								onEnable={ () => {} }
								onConfigure={ () => {} }
							/>
							<CardFeature
								title={ __( 'Content gifting', 'newspack-plugin' ) }
								description={ __( 'Let subscribers share gated articles with non-subscribers.', 'newspack-plugin' ) }
								icon={ { node: <Icon icon={ settings } />, fill: '#003da5', backgroundColor: '#dfe7f4', radius: 'full' } }
								enabled={ true }
								onEnable={ () => {} }
								onConfigure={ () => {} }
								moreControls={ [ { title: __( 'Disable', 'newspack-plugin' ), onClick: () => {} } ] }
							/>
						</Grid>
						<h3>{ __( 'With custom button labels', 'newspack-plugin' ) }</h3>
						<Grid columns={ 2 } gutter={ 16 }>
							<CardFeature
								title={ __( 'Apple News', 'newspack-plugin' ) }
								description={ __( 'Automatically publish articles to Apple News.', 'newspack-plugin' ) }
								enabled={ this.state.cardFeatureCustomEnabled }
								enableLabel={ __( 'Connect', 'newspack-plugin' ) }
								configureLabel={ __( 'Manage connection', 'newspack-plugin' ) }
								onEnable={ () => this.setState( { cardFeatureCustomEnabled: true } ) }
								onConfigure={ () => {} }
								moreControls={ [
									{
										title: __( 'Disconnect', 'newspack-plugin' ),
										onClick: () => this.setState( { cardFeatureCustomEnabled: false } ),
									},
								] }
							/>
						</Grid>
						<h3>{ __( 'With a custom badge', 'newspack-plugin' ) }</h3>
						<Grid columns={ 2 } gutter={ 16 }>
							<CardFeature
								title={ __( 'Stripe', 'newspack-plugin' ) }
								description={ __( 'Accept payments via Stripe.', 'newspack-plugin' ) }
								enabled={ true }
								badgeText={ __( 'Live mode', 'newspack-plugin' ) }
								badgeLevel="info"
								onEnable={ () => {} }
								onConfigure={ () => {} }
								moreControls={ [ { title: __( 'Disable', 'newspack-plugin' ), onClick: () => {} } ] }
							/>
						</Grid>
						<h3>{ __( 'With multiple dropdown controls', 'newspack-plugin' ) }</h3>
						<Grid columns={ 2 } gutter={ 16 }>
							<CardFeature
								title={ __( 'Newsletters', 'newspack-plugin' ) }
								description={ __( 'Send newsletters directly from the WordPress editor.', 'newspack-plugin' ) }
								enabled={ true }
								onEnable={ () => {} }
								onConfigure={ () => {} }
								moreControls={ [
									{ title: __( 'Edit', 'newspack-plugin' ), onClick: () => {} },
									{ title: __( 'Preview', 'newspack-plugin' ), onClick: () => {} },
									{ title: __( 'Disable', 'newspack-plugin' ), onClick: () => {} },
								] }
							/>
						</Grid>
					</Card>
					<Card>
						<h2>{ __( 'CardForm', 'newspack-plugin' ) }</h2>
						<p>
							{ __(
								'An expandable inline form card with title, description, optional badge, and an actions slot. Handles ESC key via onRequestClose.',
								'newspack-plugin'
							) }
						</p>
						<h3>{ __( 'Enable / Edit flow', 'newspack-plugin' ) }</h3>
						<VStack spacing={ 2 }>
							<CardForm
								title={ __( 'Above Header', 'newspack-plugin' ) }
								description={ __( 'Displays an ad above the site header.', 'newspack-plugin' ) }
								badge={ this.state.cardFormEnabled ? { level: 'success', text: __( 'Enabled', 'newspack-plugin' ) } : undefined }
								actions={
									this.state.cardFormEnabled ? (
										<Button
											variant="tertiary"
											size="compact"
											onClick={ () =>
												this.setState( s => ( {
													cardFormOpen: ! s.cardFormOpen,
												} ) )
											}
										>
											{ this.state.cardFormOpen ? __( 'Cancel', 'newspack-plugin' ) : __( 'Edit', 'newspack-plugin' ) }
										</Button>
									) : (
										<Button
											variant="secondary"
											size="compact"
											onClick={ () => this.setState( { cardFormEnabled: true, cardFormOpen: true } ) }
										>
											{ __( 'Enable', 'newspack-plugin' ) }
										</Button>
									)
								}
								isOpen={ this.state.cardFormOpen }
								onRequestClose={ () => this.setState( { cardFormOpen: false } ) }
							>
								<VStack spacing={ 4 }>
									<TextControl label={ __( 'Ad Unit ID', 'newspack-plugin' ) } value="" onChange={ () => {} } />
									<Button variant="primary" size="compact" onClick={ () => this.setState( { cardFormOpen: false } ) }>
										{ this.state.cardFormEnabled ? __( 'Update', 'newspack-plugin' ) : __( 'Enable', 'newspack-plugin' ) }
									</Button>
								</VStack>
							</CardForm>
							<CardForm
								title={ __( 'Below Footer', 'newspack-plugin' ) }
								description={ __( 'Displays an ad below the site footer.', 'newspack-plugin' ) }
								actions={
									<Button variant="secondary" size="compact" disabled={ !! this.state.cardFormOpen }>
										{ __( 'Enable', 'newspack-plugin' ) }
									</Button>
								}
								isOpen={ false }
							/>
						</VStack>
						<h3>{ __( 'Badge levels', 'newspack-plugin' ) }</h3>
						<VStack spacing={ 2 }>
							{ [ 'success', 'info', 'warning', 'error' ].map( level => (
								<CardForm
									key={ level }
									title={ __( 'Example placement', 'newspack-plugin' ) }
									description={ __( 'Badge level: ', 'newspack-plugin' ) + level }
									badge={ { level, text: level.charAt( 0 ).toUpperCase() + level.slice( 1 ) } }
									actions={
										<Button variant="tertiary" size="compact">
											{ __( 'Edit', 'newspack-plugin' ) }
										</Button>
									}
									isOpen={ false }
								/>
							) ) }
						</VStack>
					</Card>
					<Card>
						<h2>{ __( 'Newspack Icons', 'newspack-plugin' ) }</h2>
						<p>
							{ createInterpolateElement(
								__(
									"This is a collection of custom SVG icons for Newspack projects.<br/>They are available in <code>newspack-plugin</code> in the <code>newspack-icons</code> package.<br/>There's also an <link>npm package</link> that you can install to use them in your projects.",
									'newspack-plugin'
								),
								{
									br: <br />,
									code: <code />,
									link: <ExternalLink href="https://npmjs.com/package/newspack-icons" />,
								}
							) }
						</p>
						<Grid columns={ 4 } gutter={ 16 } className="items-start">
							{ Object.entries( newspackIcons ).map( ( [ name, icon ] ) => (
								<div key={ name } className="flex flex-column items-center tc">
									<Icon icon={ icon } />
									<code style={ { marginTop: 8 } }>{ name }</code>
								</div>
							) ) }
						</Grid>
					</Card>
				</div>
				<Footer />
			</Fragment>
		);
	}
}

render( <ComponentsDemo />, document.getElementById( 'newspack-components-demo' ) );

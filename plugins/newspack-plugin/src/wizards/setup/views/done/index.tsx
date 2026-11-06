import { createInterpolateElement, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { category, currencyDollar, envelope, home, megaphone } from '@wordpress/icons';
import { Link, Stack, Text } from '@wordpress/ui';

import colors from '../../../../../packages/colors/colors.module.scss';

import { fetchInitialCheck, type NextSteps } from '../../api';
import LinkList, { type LinkListItem } from '../../components/link-list';
import NarrowLayout from '../../components/narrow-layout';
import StepHeading from '../../components/step-heading';

const PAGES_LIST_URL = 'edit.php?post_type=page';
const AUDIENCE_URL = 'admin.php?page=newspack-audience';

type DoneData = {
	nextSteps: NextSteps;
	homepageUrl: string;
};

const fallbackData = (): DoneData => ( {
	nextSteps: { reader_revenue: AUDIENCE_URL, newsletters: null, ads: null },
	homepageUrl: PAGES_LIST_URL,
} );

const nextStepItems = ( { nextSteps, homepageUrl }: DoneData, dashboardUrl: string ): LinkListItem[] => [
	{
		key: 'reader-revenue',
		title: __( 'Set up reader revenue', 'newspack-plugin' ),
		description: __( 'Donations, subscriptions, and reader accounts.', 'newspack-plugin' ),
		icon: currencyDollar,
		href: nextSteps.reader_revenue,
	},
	...( nextSteps.newsletters
		? [
				{
					key: 'newsletters',
					title: __( 'Start a newsletter', 'newspack-plugin' ),
					description: __( 'Connect your email provider and write your first issue.', 'newspack-plugin' ),
					icon: envelope,
					href: nextSteps.newsletters,
				},
		  ]
		: [] ),
	...( nextSteps.ads
		? [
				{
					key: 'ads',
					title: __( 'Sell ads', 'newspack-plugin' ),
					description: __( 'Place Google Ad Manager ads across your site.', 'newspack-plugin' ),
					icon: megaphone,
					href: nextSteps.ads,
				},
		  ]
		: [] ),
	{
		key: 'homepage',
		title: __( 'Edit your homepage', 'newspack-plugin' ),
		description: __( 'Choose which stories appear and how they’re arranged.', 'newspack-plugin' ),
		icon: home,
		href: homepageUrl,
	},
	{
		key: 'dashboard',
		title: __( 'Open the dashboard', 'newspack-plugin' ),
		description: __( 'Settings, plugins, and everything else.', 'newspack-plugin' ),
		icon: category,
		href: dashboardUrl,
	},
];

export default function Done() {
	const [ data, setData ] = useState< DoneData | null >( null );
	const siteUrl = window.newspack_urls?.site ?? '/';
	const dashboardUrl = window.newspack_urls?.dashboard ?? 'admin.php?page=newspack-dashboard';

	const listRef = useRef< HTMLDivElement >( null );

	useEffect( () => {
		if ( ! data ) {
			return;
		}
		let active = true;
		import( 'canvas-confetti' ).then( ( { default: confetti } ) => {
			const rect = listRef.current?.getBoundingClientRect();
			if ( ! active || ! rect ) {
				return;
			}
			const palette = [
				colors[ 'primary-600' ],
				colors[ 'primary-300' ],
				colors[ 'secondary-500' ],
				colors[ 'tertiary-500' ],
				colors[ 'quaternary-500' ],
			].filter( Boolean );
			confetti( {
				particleCount: 160,
				angle: 90,
				spread: 120,
				startVelocity: 55,
				scalar: 1.2,
				colors: palette.length ? palette : undefined,
				origin: { x: ( rect.left + rect.width / 2 ) / window.innerWidth, y: rect.bottom / window.innerHeight },
				disableForReducedMotion: true,
			} );
		} );
		return () => {
			active = false;
		};
	}, [ data ] );

	useEffect( () => {
		let active = true;
		fetchInitialCheck()
			.then( ( { next_steps, homepage_edit_url } ) => {
				if ( active ) {
					setData( {
						nextSteps: next_steps ?? fallbackData().nextSteps,
						homepageUrl: homepage_edit_url || PAGES_LIST_URL,
					} );
				}
			} )
			.catch( () => active && setData( fallbackData() ) );
		return () => {
			active = false;
		};
	}, [] );

	return (
		<NarrowLayout>
			<StepHeading
				title={ __( 'Your site is set up', 'newspack-plugin' ) }
				lede={ createInterpolateElement( __( 'Pick what to set up first, or <a>view your site</a>.', 'newspack-plugin' ), {
					a: <Link href={ siteUrl } openInNewTab />,
				} ) }
			/>
			{ data && (
				<Stack direction="column" gap="lg">
					<div ref={ listRef }>
						<LinkList label={ __( 'Pick where to start', 'newspack-plugin' ) } items={ nextStepItems( data, dashboardUrl ) } />
					</div>
					<Text variant="body-md" className="setup-muted" render={ <p /> }>
						{ createInterpolateElement( __( 'New to Newspack? <a>Read the documentation</a>', 'newspack-plugin' ), {
							a: <Link href={ window.newspack_urls?.support } openInNewTab />,
						} ) }
					</Text>
				</Stack>
			) }
		</NarrowLayout>
	);
}

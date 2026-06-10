import { __ } from '@wordpress/i18n';

const sampleAsset = name => `${ window.newspack_email_editor_data?.sample_assets_url || '' }${ name }`;

const buildSample = ( id, image, title, excerpt ) => ( {
	id: `sample-${ id }`,
	title: { rendered: title },
	excerpt: { rendered: `<p>${ excerpt }</p>` },
	link: '#',
	date: new Date().toISOString(),
	featured_media: id,
	featured_media_info: {
		large_url: image,
		medium_url: image,
	},
	meta: {},
	newspack_sponsors_info: [],
	newspack_author_info: [],
} );

export const getSamplePosts = () => [
	buildSample(
		1,
		sampleAsset( '1.jpg' ),
		__( 'A compelling headline for the lead story', 'newspack-newsletters' ),
		__(
			'Sample excerpt — replace this with a brief teaser that gives readers a reason to keep reading. Two short sentences usually does the job.',
			'newspack-newsletters'
		)
	),
	buildSample(
		2,
		sampleAsset( '2.jpg' ),
		__( 'Second story, a notable update from the week', 'newspack-newsletters' ),
		__(
			'Sample excerpt — the second post often pairs with the lead to round out the top of the newsletter. Keep the tone consistent with the lead.',
			'newspack-newsletters'
		)
	),
	buildSample(
		3,
		sampleAsset( '3.jpg' ),
		__( 'Third pick, something readers might have missed', 'newspack-newsletters' ),
		__(
			'Sample excerpt — a third entry to flesh out the section, useful when a layout shows multiple posts side by side.',
			'newspack-newsletters'
		)
	),
	buildSample(
		4,
		sampleAsset( '4.jpg' ),
		__( 'A fourth article to fill out the grid', 'newspack-newsletters' ),
		__(
			'Sample excerpt — additional padding for grid layouts that ask for four posts. The first three are usually plenty.',
			'newspack-newsletters'
		)
	),
];

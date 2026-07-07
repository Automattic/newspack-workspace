/**
 * Mock store catalog for the Subscribers Discounts Demo — shaped like a real
 * news publisher's storefront (books, events, courses), entirely separate from
 * the subscription products that grant site access.
 */

export const CATEGORIES = [ 'Books', 'Events', 'Courses' ];

export const PRODUCTS = [
	{ id: 'prod_book_v1', name: 'Centennial History, Vol. 1', price: 450, category: 'Books' },
	{ id: 'prod_book_v2', name: 'Centennial History, Vol. 2', price: 450, category: 'Books' },
	{ id: 'prod_book_v3', name: 'Centennial History, Vol. 3', price: 450, category: 'Books' },
	{ id: 'prod_book_city', name: 'City at Dawn: A Portrait', price: 450, category: 'Books' },
	{ id: 'prod_book_recipes', name: 'Recipes from the Archive', price: 450, category: 'Books' },
	{
		id: 'prod_album',
		name: 'Anniversary Photo Album',
		price: 520,
		category: 'Books',
		variations: [
			{ id: 'prod_album_hard', name: 'Hardcover', price: 520 },
			{ id: 'prod_album_soft', name: 'Paperback', price: 380 },
		],
	},
	{ id: 'prod_talk_quarter', name: 'Guided Talk: The Old Quarter', price: 120, category: 'Events' },
	{ id: 'prod_talk_newsroom', name: 'Guided Talk: Inside the Newsroom', price: 120, category: 'Events' },
	{ id: 'prod_gala', name: 'Annual Gala Ticket', price: 250, category: 'Events' },
	{ id: 'prod_photo_walk', name: 'Photography Walk', price: 85, category: 'Events' },
	{ id: 'prod_course_journalism', name: 'Diploma: Narrative Journalism', price: 2900, category: 'Courses' },
	{ id: 'prod_course_photo', name: 'Diploma: Documentary Photography', price: 3400, category: 'Courses' },
	{ id: 'prod_course_writing', name: 'Short Course: Feature Writing', price: 950, category: 'Courses' },
];

// Variations are addressable too, so exclusion lists and previews can target them.
const BY_ID = {};
PRODUCTS.forEach( p => {
	BY_ID[ p.id ] = p;
	( p.variations || [] ).forEach( v => {
		BY_ID[ v.id ] = { ...v, name: `${ p.name } — ${ v.name }`, category: p.category, parentId: p.id };
	} );
} );

export const getProductById = id => BY_ID[ id ] || null;

export const searchProducts = term => {
	const q = ( term || '' ).trim().toLowerCase();
	if ( ! q ) {
		return PRODUCTS;
	}
	return PRODUCTS.filter( p => p.name.toLowerCase().includes( q ) );
};

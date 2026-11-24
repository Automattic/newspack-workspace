import type { ReactNode } from 'react';
import { NewspackLogo } from '@/components/brand/newspack-logo';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { DeckSlide } from './deck';
import { DeckClosingLogo } from './deck-closing-logo';

type Image = { src: string; alt: string };

/**
 * `tone="brand"` makes the slide blue (the Cobalt surface of `.newspack-brand-inverse`), as on `DeckSlide`. `className` is merged last, so it can override the layout's own classes.
 */
type SlideProps = { id: string; title: string; tone?: 'default' | 'brand'; className?: string };

/**
 * The slide padding every layout uses. The right padding never drops below 3.5rem, so content clears the dot navigation, which sits on the right, on phones.
 */
export const SLIDE_PADDING = 'py-16 pl-[8vw] pr-[max(8vw,3.5rem)]';

/**
 * The classes every content slide shares: a vertically centered column, its gap and `SLIDE_PADDING`. Put them on a custom `DeckSlide` so it lines up with the layouts, or use `ContentSlide`.
 */
export const SLIDE = `flex flex-col justify-center gap-8 ${ SLIDE_PADDING }`;

/**
 * Fixed first slide of every deck: Cobalt, the small reversed logo top left with clearspace equal to the symbol size (32px), the title as the h1 at the brand h1 size. `heading` replaces the visible h1 content, for example to keep a publication's name on one line with `<span className="inline-block">`; `title` stays the slide's accessible name.
 */
export function OpeningSlide( { id, title, heading, subtitle }: { id: string; title: string; heading?: ReactNode; subtitle?: string } ) {
	return (
		<DeckSlide id={ id } title={ title } tone="brand" className="flex flex-col justify-between sm:p-8 lg:p-16">
			<div className="self-start p-8">
				<NewspackLogo variant="reversed" className="h-8 w-auto" />
			</div>
			<div className="flex max-w-5xl flex-col gap-6 px-8 pb-8">
				<h1 className="text-balance">{ heading ?? title }</h1>
				{ subtitle && <p className="font-heading text-2xl text-balance">{ subtitle }</p> }
			</div>
		</DeckSlide>
	);
}

/**
 * Fixed last slide of every deck: Cobalt with the reversed logo, large and centered, and nothing else. Clearspace equals the rendered logo height, so the logo and its padding share one clamp(): 160px at full size, smaller on narrow windows. The logo fades in each time the slide arrives, once it has settled (`DeckClosingLogo`). `title` is only the slide's accessible name.
 */
export function ClosingSlide( { id, title = 'Newspack' }: { id: string; title?: string } ) {
	return (
		<DeckSlide id={ id } title={ title } tone="brand" className="flex items-center justify-center p-4 sm:p-16">
			<DeckClosingLogo className="p-[clamp(4rem,10vw,10rem)]">
				<NewspackLogo variant="reversed" title="" className="h-[clamp(4rem,10vw,10rem)] w-auto max-w-full" />
			</DeckClosingLogo>
		</DeckSlide>
	);
}

/**
 * A slide with the layouts' padding and nothing in it, for content the layouts do not cover.
 */
export function ContentSlide( { id, title, tone, className, children }: SlideProps & { children: ReactNode } ) {
	return (
		<DeckSlide id={ id } title={ title } tone={ tone } className={ cn( SLIDE, className ) }>
			{ children }
		</DeckSlide>
	);
}

export function StatementSlide( { id, title, tone, className, children }: SlideProps & { children: ReactNode } ) {
	return (
		<DeckSlide id={ id } title={ title } tone={ tone } className={ cn( SLIDE, className ) }>
			<p className="font-display max-w-6xl text-[clamp(3rem,7vw,6rem)] leading-none text-balance">{ children }</p>
		</DeckSlide>
	);
}

/**
 * An image beside its text. `layout="image-led"` gives the image five eighths of the width from 1024px, for product screenshots, and never crops it; the default splits the slide in half from 768px.
 */
export function ImageTextSlide( {
	id,
	title,
	image,
	children,
	reverse = false,
	layout = 'split',
	tone,
	className,
}: SlideProps & {
	image: Image;
	children: ReactNode;
	reverse?: boolean;
	layout?: 'split' | 'image-led';
} ) {
	const imageLed = layout === 'image-led';
	const columns = imageLed ? ( reverse ? 'lg:grid-cols-[minmax(0,3fr)_minmax(0,5fr)]' : 'lg:grid-cols-[minmax(0,5fr)_minmax(0,3fr)]' ) : 'md:grid-cols-2';
	const imageOrder = reverse ? ( imageLed ? 'lg:order-2' : 'md:order-2' ) : '';
	return (
		<DeckSlide id={ id } title={ title } tone={ tone } className={ cn( 'grid items-center gap-12', SLIDE_PADDING, columns, className ) }>
			<img
				src={ image.src }
				alt={ image.alt }
				className={ cn(
					'max-h-[70svh] rounded-(--newspack-ui-radius-surface)',
					imageLed ? 'h-auto w-auto max-w-full justify-self-center' : 'w-full object-cover',
					imageOrder
				) }
			/>
			<div className="flex flex-col gap-4">
				<h2 className="text-balance">{ title }</h2>
				<div className="text-xl sm:text-2xl">{ children }</div>
			</div>
		</DeckSlide>
	);
}

/**
 * Two to four stats: one row from 1024px, two columns below it. The values scale with their card: in American Grotesk Compressed up to nine characters fit on one line; the wider Inter fallback fits about five.
 */
export function StatsSlide( { id, title, stats, tone, className }: SlideProps & { stats: Array< { value: string; label: string } > } ) {
	return (
		<DeckSlide id={ id } title={ title } tone={ tone } className={ cn( SLIDE, className ) }>
			<h2 className="text-balance">{ title }</h2>
			<div className="grid grid-cols-2 gap-4 sm:gap-6 lg:auto-cols-fr lg:grid-flow-col lg:grid-cols-none">
				{ stats.map( ( stat, index ) => (
					<Card key={ `${ index }-${ stat.label }` } className="@container min-w-0 gap-2 p-4 sm:p-8">
						<p className="font-display text-(color:--newspack-ui-color-brand-text) text-[clamp(1.5rem,25cqi,5.5rem)] leading-none break-words">{ stat.value }</p>
						<p className="text-base sm:text-xl">{ stat.label }</p>
					</Card>
				) ) }
			</div>
		</DeckSlide>
	);
}

export function QuoteSlide( { id, title, quote, cite, tone, className }: SlideProps & { quote: string; cite: string } ) {
	return (
		<DeckSlide id={ id } title={ title } tone={ tone } className={ cn( SLIDE, className ) }>
			<figure className="flex max-w-5xl flex-col gap-8">
				<blockquote className="font-heading text-3xl leading-tight text-pretty md:text-5xl">{ quote }</blockquote>
				<figcaption className="text-(color:--newspack-ui-color-text-muted) text-xl">{ cite }</figcaption>
			</figure>
		</DeckSlide>
	);
}

export function LogosSlide( { id, title, logos, tone, className }: SlideProps & { logos: Image[] } ) {
	return (
		<DeckSlide id={ id } title={ title } tone={ tone } className={ cn( SLIDE, className ) }>
			<h2 className="text-balance">{ title }</h2>
			<ul className="grid grid-cols-2 items-center gap-12 md:grid-cols-4">
				{ logos.map( ( logo, index ) => (
					<li key={ `${ index }-${ logo.src }` } className="flex justify-center">
						<img src={ logo.src } alt={ logo.alt } className="max-h-16 w-auto object-contain" />
					</li>
				) ) }
			</ul>
		</DeckSlide>
	);
}

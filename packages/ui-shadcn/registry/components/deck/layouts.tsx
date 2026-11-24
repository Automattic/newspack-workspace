import type { ReactNode } from 'react';
import { NewspackLogo } from '@/components/brand/newspack-logo';
import { Card } from '@/components/ui/card';
import { DeckSlide } from './deck';

type Image = { src: string; alt: string };

const SLIDE = 'flex flex-col justify-center gap-8 px-[8vw] py-16';

/**
 * Fixed first slide of every deck: Cobalt, the small reversed logo top left with clearspace equal to the symbol size (32px), the title as the h1.
 */
export function OpeningSlide( { id, title, subtitle }: { id: string; title: string; subtitle?: string } ) {
	return (
		<DeckSlide id={ id } title={ title } tone="brand" className="flex flex-col justify-between p-16">
			<div className="self-start p-8">
				<NewspackLogo variant="reversed" className="h-8 w-auto" />
			</div>
			<div className="flex max-w-5xl flex-col gap-6 px-8 pb-8">
				<h1 className="font-display text-7xl leading-none">{ title }</h1>
				{ subtitle && <p className="font-heading text-2xl">{ subtitle }</p> }
			</div>
		</DeckSlide>
	);
}

/**
 * Fixed last slide of every deck: Cobalt with the reversed logo, large and centered, and nothing else. Clearspace equals the rendered logo height, so the logo and its padding share one clamp(): 160px at full size, smaller on narrow windows. `title` is only the slide's accessible name.
 */
export function ClosingSlide( { id, title = 'Newspack' }: { id: string; title?: string } ) {
	return (
		<DeckSlide id={ id } title={ title } tone="brand" className="flex items-center justify-center p-16">
			<div className="p-[clamp(4rem,10vw,10rem)]">
				<NewspackLogo variant="reversed" title="" className="h-[clamp(4rem,10vw,10rem)] w-auto max-w-full" />
			</div>
		</DeckSlide>
	);
}

export function StatementSlide( { id, title, children }: { id: string; title: string; children: ReactNode } ) {
	return (
		<DeckSlide id={ id } title={ title } className={ SLIDE }>
			<p className="font-display max-w-6xl text-[clamp(3rem,7vw,6rem)] leading-none">{ children }</p>
		</DeckSlide>
	);
}

export function ImageTextSlide( {
	id,
	title,
	image,
	children,
	reverse = false,
}: {
	id: string;
	title: string;
	image: Image;
	children: ReactNode;
	reverse?: boolean;
} ) {
	return (
		<DeckSlide id={ id } title={ title } className="grid items-center gap-12 px-[8vw] py-16 md:grid-cols-2">
			<img src={ image.src } alt={ image.alt } className={ [ 'max-h-[70svh] w-full rounded-(--newspack-ui-radius-surface) object-cover', reverse ? 'md:order-2' : '' ].join( ' ' ) } />
			<div className="flex flex-col gap-4">
				<h2>{ title }</h2>
				<div className="text-2xl">{ children }</div>
			</div>
		</DeckSlide>
	);
}

/**
 * Two to four stats.
 */
export function StatsSlide( { id, title, stats }: { id: string; title: string; stats: Array< { value: string; label: string } > } ) {
	return (
		<DeckSlide id={ id } title={ title } className={ SLIDE }>
			<h2>{ title }</h2>
			<div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-[repeat(auto-fit,minmax(14rem,1fr))]">
				{ stats.map( ( stat, index ) => (
					<Card key={ `${ index }-${ stat.label }` } className="gap-2 p-8">
						<p className="font-display text-(color:--newspack-ui-color-brand-text) text-[clamp(3rem,6vw,5.5rem)] leading-none break-words">{ stat.value }</p>
						<p className="text-xl">{ stat.label }</p>
					</Card>
				) ) }
			</div>
		</DeckSlide>
	);
}

export function QuoteSlide( { id, title, quote, cite }: { id: string; title: string; quote: string; cite: string } ) {
	return (
		<DeckSlide id={ id } title={ title } className={ SLIDE }>
			<figure className="flex max-w-5xl flex-col gap-8">
				<blockquote className="font-heading text-5xl leading-tight">{ quote }</blockquote>
				<figcaption className="text-(color:--newspack-ui-color-text-muted) text-xl">{ cite }</figcaption>
			</figure>
		</DeckSlide>
	);
}

export function LogosSlide( { id, title, logos }: { id: string; title: string; logos: Image[] } ) {
	return (
		<DeckSlide id={ id } title={ title } className={ SLIDE }>
			<h2>{ title }</h2>
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

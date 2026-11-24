import type { ReactNode } from 'react';
import { FOOTER_LINKS, SITE_NAME } from '@/lib/site';
import { linkRel } from './drawer-nav';

/** The site footer: a line of text (`children`, or the site name) and `FOOTER_LINKS`. */
export function SiteFooter( { children }: { children?: ReactNode } ) {
	return (
		<footer className="pt-(--newspack-ui-spacer-9) pb-(--newspack-ui-spacer-8) text-(length:--newspack-ui-font-size-small) leading-(--newspack-ui-line-height-small) text-(color:--newspack-ui-color-text-muted) min-[782px]:pt-(--newspack-ui-spacer-12)">
			<div className="mx-auto box-content flex max-w-(--site-wide-width) flex-wrap justify-between gap-(--newspack-ui-spacer-2) px-(--newspack-ui-spacer-3) min-[782px]:px-(--newspack-ui-spacer-6)">
				<p className="m-0 text-pretty">{ children ?? SITE_NAME }</p>
				{ FOOTER_LINKS.length > 0 && (
					<nav aria-label="Footer">
						<ul className="m-0 flex list-none flex-wrap gap-x-(--newspack-ui-spacer-3) gap-y-(--newspack-ui-spacer-1) p-0">
							{ FOOTER_LINKS.map( ( link ) => (
								<li key={ link.href }>
									<a
										href={ link.href }
										rel={ linkRel( link ) }
										className="text-inherit underline-offset-3 hover:text-(color:--newspack-ui-color-text)"
									>
										{ link.text }
									</a>
								</li>
							) ) }
						</ul>
					</nav>
				) }
			</div>
		</footer>
	);
}

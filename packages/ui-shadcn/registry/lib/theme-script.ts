import { DARK_MODE, MOBILE_MENU_QUERY } from '@/lib/site';
import { THEME_KEY } from '@/lib/theme';

/**
 * The pre-paint theme script, as a string for an inline `<script>` in the document head.
 * It sets `.dark` on `html` from the stored choice on desktop and from the system setting below
 * `MOBILE_MENU_QUERY`, then follows system, breakpoint and cross-tab changes, and Astro view
 * transitions when the site uses them. With `DARK_MODE` off it keeps the page light.
 */
export const THEME_SCRIPT = `(function (key, mobileQuery, darkMode) {
	var query = window.matchMedia('(prefers-color-scheme: dark)');
	var mobile = window.matchMedia(mobileQuery);
	function stored() {
		try {
			var value = localStorage.getItem(key);
			return value === 'dark' || value === 'light' ? value : null;
		} catch (error) {
			return null;
		}
	}
	function apply(target) {
		var root = target || document.documentElement;
		var system = query.matches ? 'dark' : 'light';
		var dark = darkMode && (mobile.matches ? system : stored() || system) === 'dark';
		root.classList.toggle('dark', dark);
		root.style.colorScheme = dark ? 'dark' : 'light';
	}
	apply();
	query.addEventListener('change', function () { apply(); });
	mobile.addEventListener('change', function () { apply(); });
	window.addEventListener('storage', function (event) {
		if (event.key === key || event.key === null) {
			apply();
		}
	});
	document.addEventListener('astro:before-swap', function (event) {
		apply(event.newDocument.documentElement);
	});
})(${ JSON.stringify( THEME_KEY ) }, ${ JSON.stringify( MOBILE_MENU_QUERY ) }, ${ JSON.stringify( DARK_MODE ) });`;

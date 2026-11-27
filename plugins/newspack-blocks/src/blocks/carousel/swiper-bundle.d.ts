/**
 * `swiper/bundle`'s type declarations are only reachable via the `swiper`
 * package's "exports" map ("./bundle": { "types": "./swiper.d.ts", ... }),
 * which this project's `moduleResolution: "node"` setting doesn't consult -
 * so the deep import resolves at runtime (via Node's own resolver) but not
 * for types. Point it at the same class type the main `swiper` entry
 * exports, since `swiper/bundle` is the identical Swiper class, just
 * pre-loaded with all modules.
 */
declare module 'swiper/bundle' {
	import SwiperClass from 'swiper';
	export default SwiperClass;
}

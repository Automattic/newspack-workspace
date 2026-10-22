import './segmented-control';
import './tabs';
import notices from './notices';
import { announce } from './announce';
import './modals';
import './dropdowns';
import './accordions';
import './password-toggle';

// Public API.
window.newspackUI = window.newspackUI || {};
window.newspackUI.notices = notices;
window.newspackUI.announce = announce;

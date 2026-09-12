/**
 * A proxy for react-router-dom.
 * Ensures that both components and the plugin use the same instance of
 * React Router, even if there are node_modules in components.
 */

import * as ReactRouterDOM from 'react-router-dom';

export default ReactRouterDOM;

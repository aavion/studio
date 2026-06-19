import { registerVueControllerComponents } from '@symfony/ux-vue';
import { registerReactControllerComponents } from '@symfony/ux-react';
import './stimulus_bootstrap.js';
import './styles/app.css';
import './js/frontend/index.js';
import './js/backend/index.js';
import './js/backend/admin/index.js';
import './js/backend/editor/index.js';
import './js/backend/setup/index.js';
import './js/extensions/extension.js';
import './js/extensions/frontend-theme.js';
import './js/extensions/backend-theme.js';

registerReactControllerComponents();
registerVueControllerComponents();

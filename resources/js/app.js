import Alpine from 'alpinejs';

import { poolDashboard } from './dashboard.js';

window.Alpine = Alpine;

Alpine.data('poolDashboard', poolDashboard);

Alpine.start();

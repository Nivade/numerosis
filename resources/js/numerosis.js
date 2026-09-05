/**
 * Build source for `dist/numerosis.js` (see vite.config.js and
 * package.json's `build:app-js`) — NOT run per-host. Bundles the two Stripe
 * checkout modules into the one prebuilt `<script>` tag a host's Blade
 * layout loads unconditionally (`resources/views/partials/styles.blade.php`).
 */
import './stripe-checkout.js';
import './stripe-confirm.js';

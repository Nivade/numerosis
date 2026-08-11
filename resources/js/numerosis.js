/**
 * Build source for `dist/numerosis.js` (see vite.config.js and
 * package.json's `build:app-js`) — NOT run per-host. Replaces the former
 * separate central.js/tenant.js: both configured the same Echo connection
 * and joined the same 'online' presence channel, differing only in that
 * tenant.js also joined a per-tenant chat channel and central.js also
 * pulled in the two Stripe modules. One file, self-gated on
 * `window.Numerosis.tenantId`, means a host's Blade layout loads exactly
 * one <script> unconditionally instead of branching on
 * `tenancy()->initialized` — see resources/views/partials/styles.blade.php.
 *
 * Reads `window.Numerosis` (resources/views/partials/script-config.blade.php),
 * never `import.meta.env` — this file ships prebuilt, so anything read from
 * `import.meta.env` would be frozen at the package maintainer's build time
 * instead of resolved per-request by the host. See
 * config('numerosis.broadcasting.reverb') for where those values come from.
 */
import { configureEcho, echo } from '@laravel/echo-vue';
import './stripe-checkout.js';
import './stripe-confirm.js';

const { reverb, tenantId } = window.Numerosis;

configureEcho({
  broadcaster: 'reverb',
  key: reverb.key,
  wsHost: reverb.host,
  wsPort: reverb.port,
  wssPort: reverb.port,
  forceTLS: reverb.scheme === 'https',
  enabledTransports: ['ws', 'wss'],
  authEndpoint: '/broadcasting/auth',
});

window.Echo = echo();

echo().join('online');

if (tenantId) {
  echo()
    .join(`chat.${tenantId}`)
    .here(members => Livewire.dispatch('chat-here', { members }))
    .joining(member => Livewire.dispatch('chat-joining', { member }))
    .leaving(member => Livewire.dispatch('chat-leaving', { member }));
}

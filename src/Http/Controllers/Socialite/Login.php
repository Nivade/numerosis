<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Socialite;

use Nvade\Numerosis\Actions\Auth\ConnectSocialAccount;
use Nvade\Numerosis\Actions\Auth\LoginUser;
use Nvade\Numerosis\Actions\Invitations\AcceptInvitation;
use Nvade\Numerosis\Exceptions\ProviderNotFoundException;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\SocialiteLogin;
use Nvade\Numerosis\Models\Tenant\Invitation;
use Nvade\Numerosis\Support\Routes\RouteNames;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Collection;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;

class Login extends Controller
{
    public function __construct(private readonly Redirector $redirector, private readonly UrlGenerator $urlGenerator, private readonly Repository $repository) {}

    /**
     * Handle the OAuth callback from a Socialite provider.
     */
    public function __invoke(Request $request, string $provider, LoginUser $loginUser, ConnectSocialAccount $connectSocialAccount): RedirectResponse
    {
        $this->ensureProviderIsConfigured($provider);

        $socialUser = $this->getSocialUser($provider);

        $user = SocialiteLogin::where('provider', $provider)
            ->firstWhere('provider_id', (string) $socialUser->getId())?->user;

        if (! $user) {
            $user = CentralUser::firstOrCreate(
                ['email' => $socialUser->getEmail()],
                ['name' => $socialUser->name]
            );

            $connectSocialAccount($user, $provider, (string) $socialUser->getId());
        }

        // Retrieve OAuth context from session
        /** @var array<string, mixed> $context */
        $context = $request->session()->pull('socialite_context', []);

        if ($redirect = $this->handleInvitationIfPresent($context, $user)) {
            return $redirect;
        }

        $loginUser($user);

        $returnUrl = $context['return_url'] ?? $request->session()->get('url.intended');

        if (! is_string($returnUrl) && ! empty($context['tenant'])) {
            $returnUrl = $this->tenantDashboardUrl($context['tenant']);
        }

        /** @var string $redirectRoute */
        $redirectRoute = $this->repository->get('auth.defaults.redirect-route');

        return is_string($returnUrl)
            ? $this->redirector->to($returnUrl)
            : $this->redirector->to($this->urlGenerator->route($redirectRoute));
    }

    /**
     * Ensure the requested provider exists in configuration.
     *
     * @throws ProviderNotFoundException
     */
    private function ensureProviderIsConfigured(string $provider): void
    {
        /** @var array<string, mixed> $servicesConfig */
        $servicesConfig = $this->repository->get('services', []);
        $services = new Collection($servicesConfig);
        throw_if($services->keys()->doesntContain($provider), ProviderNotFoundException::class, 'Provider not found');
    }

    /**
     * Retrieve the Socialite user for the given provider.
     */
    private function getSocialUser(string $provider): User
    {
        /** @var AbstractProvider $driver */
        $driver = Socialite::driver($provider);

        /** @var User $user */
        $user = $driver->stateless()->user();

        return $user;
    }

    /**
     * The OAuth round-trip always callbacks on the central domain (OAuth
     * apps require a fixed redirect URI), so a social login started from a
     * tenant subdomain has no `url.intended` pointing back at it unless the
     * user was first bounced off a protected page. Without this, a direct
     * visit to a tenant's `/login` followed by "Log in with Google" landed
     * the user on the central redirect route instead of their tenant.
     *
     * Deliberately resolves `route('home')`, not a tenantAdmin panel route
     * name. `TenantAdminPanelProvider::register()` only registers that panel
     * when the current request isn't on the central domain — precisely
     * because its `{tenant}.<domain>` pattern also matches the central
     * subdomain itself (e.g. "saasm" fits `{tenant}` as well as a real tenant
     * id), so registering it unconditionally let the central domain's own
     * `/` route lose the match to the tenant panel's. This callback always
     * runs on the central domain, so a tenantAdmin route name never exists
     * here regardless. `home` is central-only but shares the panel's `/`
     * path, so swapping its host via `tenant_route()` produces the same URL
     * without depending on a panel that can't be registered for this request.
     */
    private function tenantDashboardUrl(mixed $tenantId): ?string
    {
        if (! is_string($tenantId)) {
            return null;
        }

        $tenant = Tenant::find($tenantId);

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return tenant_route($this->domainFor($tenant), RouteNames::home());
    }

    /**
     * If the session context indicates an invitation acceptance, complete it.
     */
    /**
     * @param  array<string, mixed>  $context
     */
    private function handleInvitationIfPresent(array $context, CentralUser $user): ?RedirectResponse
    {
        if (($context['intent'] ?? null) !== 'accept_invitation' || empty($context['invitation'])) {
            return null;
        }

        $tenant = Tenant::find($context['tenant'] ?? null);
        if (! $tenant instanceof Tenant) {
            return to_route(RouteNames::invitationShow(), ['token' => $context['token'] ?? ''])
                ->with('error', __('Invalid tenant for invitation.'));
        }

        if (! tenancy()->initialized) {
            tenancy()->initialize($tenant);
        }

        /** @var Invitation|null $invitation */
        $invitation = $tenant->run(fn () => Invitation::find($context['invitation']));

        if ($invitation === null) {
            return to_route(RouteNames::invitationShow(), ['token' => $context['token'] ?? ''])
                ->with('error', __('Invitation not found.'));
        }

        try {
            AcceptInvitation::run($invitation, $user);
        } catch (ShowsMessageToUser $e) {
            return to_route(RouteNames::invitationShow(), ['token' => $invitation->token])
                ->with('error', $e->getMessage());
        }

        // Log the user in after accepting the invitation
        LoginUser::run($user);

        return $this->redirector->to(tenant_route($this->domainFor($tenant), RouteNames::home()))
            ->with('success', __('Invitation accepted successfully.'));
    }

    /**
     * The tenant's own domain if provisioned, its id otherwise — same
     * fallback `tenant_route()` needs on both callers of this method.
     */
    private function domainFor(Tenant $tenant): string
    {
        return $tenant->primaryDomain()?->getHost() ?? (string) $tenant->id;
    }
}

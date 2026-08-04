<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Billing;

use Nvade\Numerosis\Actions\Billing\Checkout\FinalizeCheckoutSubscription;
use Nvade\Numerosis\Actions\Billing\Checkout\ResolveAttachedPaymentMethod;
use Nvade\Numerosis\Actions\Billing\FindTenantByStripeCustomer;
use Nvade\Numerosis\Actions\Billing\SyncBillingAddress;
use Nvade\Numerosis\Actions\Modules\ReconcileModuleSubscriptionItems;
use Nvade\Numerosis\Actions\Tenancy\RestoreTenant;
use Nvade\Numerosis\Actions\Tenancy\SuspendTenant;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\TenantProvisionStatus;
use Nvade\Numerosis\Events\Billing\PaymentFailed;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Nvade\Numerosis\Models\Central\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends CashierWebhookController
{
    public function __construct(private readonly ProvisionsTenant $provisioning)
    {
        parent::__construct();
    }

    /**
     * Handle a subscription being created.
     *
     * @param  array{data: array{object: array{id?: string, customer?: string, metadata?: array<string, mixed>}}}  $payload
     */
    protected function handleCustomerSubscriptionCreated(array $payload): Response
    {
        $stripeSubscription = $payload['data']['object'];
        $metadata = $stripeSubscription['metadata'] ?? [];
        $stripeSubscriptionId = $stripeSubscription['id'] ?? null;

        // Same lock key LinkSubscriptionToTenant blocks on, so Cashier's own
        // write here and our manual write there can never race each other
        // for the same subscription. Does NOT protect against
        // CreateInlineSubscription's own synchronous, unlocked local-row
        // write on the checkout request — Stripe's webhook can arrive before
        // that request's transaction commits, so Cashier's updateOrCreate
        // here can still lose a insert-vs-insert race against it. Treat the
        // resulting duplicate as "already synced by the checkout request",
        // not a failure.
        $handle = function () use ($payload): Response {
            try {
                return parent::handleCustomerSubscriptionCreated($payload);
            } catch (UniqueConstraintViolationException) {
                return $this->successMethod();
            }
        };

        /** @var Response $response */
        $response = $stripeSubscriptionId !== null
            ? Cache::lock("reconcile-subscription:{$stripeSubscriptionId}", 10)->block(5, $handle)
            : $handle();

        // The inline checkout only ever puts the domain in Stripe metadata
        // (see CreateInlineSubscription) — the rest of the registration
        // payload lives on the pending row. A subscription created directly
        // in the Stripe Dashboard carries no such metadata at all. If
        // CompleteRedirectCheckout already ran, the pending row is gone and
        // this is a no-op fallback.
        $domain = $metadata['domain'] ?? null;
        $pending = is_string($domain) ? PendingTenantProvision::find($domain) : null;

        if ($pending !== null) {
            $registration = new TenantRegistrationData(
                company_name: $pending->company_name,
                domain: $pending->domain,
                global_id: $pending->global_id,
                payment_plan: $pending->payment_plan,
                billing_cycle: $pending->billing_cycle,
            );

            /** @var int|null $userId */
            $userId = CentralUser::where('global_id', $registration->global_id)->value('id');

            // Queued rather than provisioned inline: Stripe times out webhook
            // responses and retries, and creating/migrating/seeding a tenant
            // database comfortably exceeds that budget. ProvisionTenant is
            // unique-per-domain, so this is a no-op when the redirect already
            // dispatched it.
            $this->provisioning->queue(new TenantProvisionData(
                registration: $registration,
                stripeCustomerId: $stripeSubscription['customer'] ?? null,
                stripeSubscriptionId: $stripeSubscription['id'] ?? null,
                centralUserId: $userId !== null ? (string) $userId : null,
            ));
        }

        Log::info('Subscription created', [
            'subscription_id' => $stripeSubscription['id'] ?? null,
        ]);

        return $response;
    }

    /**
     * Completes a redirect-flavoured checkout (iDEAL, Bancontact, ...) once
     * Stripe attaches the reusable PaymentMethod it generated for future
     * off-session use — the thing CompleteRedirectCheckout cannot wait for
     * synchronously. See FinalizeCheckoutSubscription's and
     * ResolveAttachedPaymentMethod's doc comments for the surrounding
     * mechanics.
     *
     * NOT `setup_intent.succeeded`: confirmed directly against the Stripe
     * API during the 2026-07-30 incident that this handler exists for that
     * `setup_intent.succeeded` fires before this conversion/attach
     * completes for iDEAL/Bancontact, and that `$setupIntent->payment_method`
     * never comes to reference the generated PaymentMethod at all — the
     * only link is this event, on the generated PaymentMethod itself, back
     * to the SetupAttempt that produced it.
     *
     * Does not resolve that SetupAttempt directly:
     * `Stripe\Service\SetupAttemptService` has no `retrieve()` — Stripe's API
     * only supports listing SetupAttempts *by* `setup_intent`, not looking
     * one up by its own id, so there is no way to go from a SetupAttempt id
     * back to its SetupIntent. Matches by customer instead (an event this
     * app didn't cause has `customer` on the PaymentMethod object directly),
     * finds every one of that customer's pending checkouts still missing a
     * subscription, and asks ResolveAttachedPaymentMethod — the same
     * resolution CompleteRedirectCheckout's synchronous path already trusts
     * — which one (if any) this newly-attached PaymentMethod actually
     * belongs to.
     *
     * @param  array{data: array{object: array{id?: string, customer?: string, sepa_debit?: array{generated_from?: array{setup_attempt?: string}}}}}  $payload
     */
    protected function handlePaymentMethodAttached(array $payload): Response
    {
        $object = $payload['data']['object'];
        $paymentMethodId = $object['id'] ?? null;
        $customerId = $object['customer'] ?? null;
        $setupAttemptId = $object['sepa_debit']['generated_from']['setup_attempt'] ?? null;

        // Not a PaymentMethod generated from one of our SetupIntents (e.g. a
        // plain card attach, or a sepa_debit set up directly rather than
        // via iDEAL/Bancontact conversion) — nothing for this handler to do.
        if (! is_string($paymentMethodId) || ! is_string($customerId) || ! is_string($setupAttemptId)) {
            return $this->successMethod();
        }

        $billable = CentralUser::where('stripe_id', $customerId)->first();

        if ($billable === null) {
            return $this->successMethod();
        }

        $candidates = PendingTenantProvision::where('global_id', $billable->global_id)
            ->whereNull('stripe_subscription_id')
            ->get();

        if ($candidates->isEmpty()) {
            return $this->successMethod();
        }

        $handle = function () use ($candidates, $billable, $paymentMethodId): Response {
            foreach ($candidates as $pending) {
                if ($pending->stripe_setup_intent_id === null) {
                    continue;
                }

                $setupIntent = Cashier::stripe()->setupIntents->retrieve(
                    $pending->stripe_setup_intent_id,
                    ['expand' => ['payment_method']],
                );

                $paymentMethod = ResolveAttachedPaymentMethod::run($setupIntent);
                if ($paymentMethod === null) {
                    continue;
                }
                if ($paymentMethod->id !== $paymentMethodId) {
                    continue;
                }

                SyncBillingAddress::run($billable, $paymentMethod);

                try {
                    FinalizeCheckoutSubscription::run($pending, $paymentMethod, $billable);
                } catch (IncompletePayment $e) {
                    // The first invoice needs a 3DS challenge with no browser
                    // listening for it here — same known gap
                    // CompleteRedirectCheckout already documents for the
                    // redirect path. Log and acknowledge; the customer sees
                    // requires_verification copy on their next visit to
                    // tenants.mine via the subscription's own stripe_status.
                    report($e);
                } catch (ApiErrorException $e) {
                    report($e);
                }

                return $this->successMethod();
            }

            // None of this customer's still-open checkouts resolve to this
            // PaymentMethod — either it belongs to a checkout that already
            // completed elsewhere, or Stripe hasn't finished linking it yet
            // and a later duplicate delivery will find it.
            return $this->successMethod();
        };

        /** @var Response $response */
        $response = Cache::lock("checkout-settle:{$paymentMethodId}", 10)->block(5, $handle);

        return $response;
    }

    /**
     * Handle a subscription being cancelled.
     *
     * @param  array{data: array{object: array{id?: string, customer?: string}}}  $payload
     */
    protected function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        $response = parent::handleCustomerSubscriptionDeleted($payload);

        $this->suspendBillableFor($payload['data']['object']['customer'] ?? null);

        Log::info('Subscription deleted', [
            'subscription_id' => $payload['data']['object']['id'] ?? null,
        ]);

        return $response;
    }

    /**
     * Handle a subscription being updated — status transitions drive
     * suspension and recovery. `past_due`/`unpaid` are the grace-period
     * states Stripe's retry schedule visits before giving up;
     * `incomplete_expired` is the initial-payment-never-completed case,
     * where Cashier's own handler already deletes the local subscription
     * row and returns null before this override sees it.
     *
     * @param  array{data: array{object: array{id?: string, customer?: string, status?: string, items?: array{data?: list<array{id?: string}>}}}}  $payload
     */
    protected function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        $response = parent::handleCustomerSubscriptionUpdated($payload) ?? $this->successMethod();

        $stripeSubscription = $payload['data']['object'];
        $customerId = $stripeSubscription['customer'] ?? null;
        $status = $stripeSubscription['status'] ?? null;
        $tenant = FindTenantByStripeCustomer::run(is_string($customerId) ? $customerId : null);

        if ($tenant !== null) {
            match ($status) {
                'past_due', 'unpaid', 'incomplete_expired' => SuspendTenant::run($tenant),
                'active', 'trialing' => RestoreTenant::run($tenant),
                default => null,
            };

            ReconcileModuleSubscriptionItems::run($tenant, $stripeSubscription);
        }

        Log::info('Subscription updated', [
            'subscription_id' => $payload['data']['object']['id'] ?? null,
            'status' => $status,
        ]);

        return $response;
    }

    /**
     * Handle an invoice payment failing — the dunning notice, sent while
     * the tenant is still in Stripe's retry/grace period. Suspension itself
     * is driven by handleCustomerSubscriptionUpdated once Stripe gives up
     * and moves the subscription to past_due/unpaid, not by this event.
     *
     * @param  array{data: array{object: array{id?: string, customer?: string}}}  $payload
     */
    protected function handleInvoicePaymentFailed(array $payload): Response
    {
        $this->notifyOfFailedPaymentFor($payload['data']['object']['customer'] ?? null);

        Log::warning('Invoice payment failed', [
            'invoice_id' => $payload['data']['object']['id'] ?? null,
        ]);

        return $this->successMethod();
    }

    /**
     * Only suspends once the customer has no surviving subscription that
     * still grants access. Cashier has already deleted the cancelled
     * subscription's local row by the time this runs (its own
     * handleCustomerSubscriptionDeleted), so what remains here is the honest
     * answer to "is anything still active?" — previously this suspended on
     * *any* subscription being deleted, which locked a tenant out of a
     * workspace they were still paying for whenever one of several
     * subscriptions ended.
     */
    private function suspendBillableFor(?string $customerId): void
    {
        $tenant = FindTenantByStripeCustomer::run($customerId);

        if ($tenant === null) {
            return;
        }

        $stillEntitled = $tenant->subscriptions()
            ->get()
            ->contains(fn (Subscription $subscription): bool => $subscription->valid());

        if ($stillEntitled) {
            return;
        }

        SuspendTenant::run($tenant);
    }

    private function notifyOfFailedPaymentFor(?string $customerId): void
    {
        $tenant = FindTenantByStripeCustomer::run($customerId);

        if ($tenant !== null) {
            event(new PaymentFailed($tenant));
        }
    }

    /**
     * Handle an invoice payment succeeded event.
     *
     * @param  array{data: array{object: array{id?: string, subscription?: string, parent?: array{subscription_details?: array{subscription?: string}}}}}  $payload
     */
    protected function handleInvoicePaymentSucceeded(array $payload): Response
    {
        $response = parent::handleInvoicePaymentSucceeded($payload);

        $invoice = $payload['data']['object'];
        $subscriptionId = $invoice['subscription']
            ?? $invoice['parent']['subscription_details']['subscription']
            ?? null;

        // Clears the AwaitingPayment badge SettleCheckout set for a still-
        // settling async payment method. A no-op for cards, which never
        // leave the pending row in AwaitingPayment — see SettleCheckout.
        if ($subscriptionId !== null) {
            PendingTenantProvision::where('stripe_subscription_id', $subscriptionId)
                ->where('status', TenantProvisionStatus::AwaitingPayment)
                ->update(['status' => TenantProvisionStatus::Provisioning]);
        }

        Log::info('Invoice payment succeeded', [
            'invoice_id' => $invoice['id'] ?? null,
        ]);

        return $response;
    }

    /**
     * Handle an invoice payment action required event.
     *
     * @param  array{data: array{object: array{id?: string}}}  $payload
     */
    protected function handleInvoicePaymentActionRequired(array $payload): Response
    {
        $response = parent::handleInvoicePaymentActionRequired($payload);

        // Optionally notify customer about payment action required
        Log::warning('Invoice payment action required', [
            'invoice_id' => $payload['data']['object']['id'] ?? null,
        ]);

        return $response;
    }
}

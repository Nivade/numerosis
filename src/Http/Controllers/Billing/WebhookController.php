<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Billing;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Laravel\Cashier\Subscription;
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
use Nvade\Numerosis\Support\Numerosis;
use Override;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends CashierWebhookController
{
    public function __construct(private readonly ProvisionsTenant $provisioning)
    {
        parent::__construct();
    }

    /**
     * @param  array{data: array{object: array{id?: string, customer?: string, metadata?: array<string, mixed>}}}  $payload
     */
    #[Override]
    protected function handleCustomerSubscriptionCreated(array $payload): Response
    {
        $stripeSubscription = $payload['data']['object'];
        $metadata = $stripeSubscription['metadata'] ?? [];
        $stripeSubscriptionId = $stripeSubscription['id'] ?? null;

        // Locked against the provisioning path, which writes the same
        // subscription. The checkout request itself is not covered — a
        // webhook can arrive before it commits — so a duplicate here means
        // already synced, not failed.
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

        // The inline checkout only puts the domain in Stripe metadata — the
        // rest of the registration lives on the pending row. A subscription
        // created directly in the Stripe Dashboard carries none. No-op if
        // CompleteRedirectCheckout already consumed the pending row.
        $domain = $metadata['domain'] ?? null;
        $pendingClass = Numerosis::model(PendingTenantProvision::class);

        /** @var PendingTenantProvision|null $pending */
        $pending = is_string($domain) ? $pendingClass::find($domain) : null;

        if ($pending !== null) {
            $registration = new TenantRegistrationData(
                company_name: $pending->company_name,
                domain: $pending->domain,
                global_id: $pending->global_id,
                payment_plan: $pending->payment_plan,
                billing_cycle: $pending->billing_cycle,
                custom_domain: $pending->custom_domain,
            );

            $centralUserClass = Numerosis::model(CentralUser::class);

            /** @var int|null $userId */
            $userId = $centralUserClass::where('global_id', $registration->global_id)->value('id');

            // Queued, not provisioned inline: Stripe retries a webhook
            // that doesn't respond fast, and creating/migrating/seeding a
            // tenant database exceeds that budget. Unique per domain, so
            // a no-op when the redirect path already dispatched it.
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
     * Finishes a redirect checkout (iDEAL, Bancontact and similar) once
     * Stripe attaches the reusable payment method it generated — which the
     * customer's return from their bank cannot wait for.
     *
     * Deliberately not `setup_intent.succeeded`, which fires before that
     * attach and never comes to reference the generated payment method.
     *
     * Matched by customer, because Stripe offers no way to look a setup
     * attempt up directly: this finds that customer's still-open checkouts
     * and asks which one the payment method belongs to.
     *
     * @param  array{data: array{object: array{id?: string, customer?: string, sepa_debit?: array{generated_from?: array{setup_attempt?: string}}}}}  $payload
     */
    protected function handlePaymentMethodAttached(array $payload): Response
    {
        $object = $payload['data']['object'];
        $paymentMethodId = $object['id'] ?? null;
        $customerId = $object['customer'] ?? null;
        $setupAttemptId = $object['sepa_debit']['generated_from']['setup_attempt'] ?? null;

        // Not a PaymentMethod generated from one of our SetupIntents (e.g.
        // a plain card attach) — nothing for this handler to do.
        if (! is_string($paymentMethodId) || ! is_string($customerId) || ! is_string($setupAttemptId)) {
            return $this->successMethod();
        }

        $centralUserClass = Numerosis::model(CentralUser::class);

        /** @var CentralUser|null $billable */
        $billable = $centralUserClass::where('stripe_id', $customerId)->first();

        if ($billable === null) {
            return $this->successMethod();
        }

        $pendingClass = Numerosis::model(PendingTenantProvision::class);

        /** @var Collection<int, PendingTenantProvision> $candidates */
        $candidates = $pendingClass::where('global_id', $billable->global_id)
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
                    // The first invoice needs a 3DS challenge and there is no
                    // browser to show it in. Acknowledge; the customer is
                    // prompted on their next visit.
                    report($e);
                } catch (ApiErrorException $e) {
                    report($e);
                }

                return $this->successMethod();
            }

            // None of this customer's open checkouts resolve to this
            // PaymentMethod — either it belongs to one that already
            // completed, or Stripe hasn't finished linking it yet.
            return $this->successMethod();
        };

        /** @var Response $response */
        $response = Cache::lock("checkout-settle:{$paymentMethodId}", 10)->block(5, $handle);

        return $response;
    }

    /**
     * @param  array{data: array{object: array{id?: string, customer?: string}}}  $payload
     */
    #[Override]
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
     * Suspends and restores tenants as their subscription status moves.
     * `past_due` and `unpaid` are Stripe's grace-period states before it
     * gives up; `incomplete_expired` means the first payment never completed.
     *
     * @param  array{data: array{object: array{id?: string, customer?: string, status?: string, items?: array{data?: list<array{id?: string}>}}}}  $payload
     */
    #[Override]
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
     * The dunning notice, sent while still in Stripe's retry/grace period.
     * Suspension itself happens in handleCustomerSubscriptionUpdated once
     * Stripe gives up, not here.
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
     * Suspends only once no subscription still grants access.
     *
     * The question is whether anything valid remains, not whether something
     * just ended — a tenant holding several subscriptions must not be locked
     * out of a workspace they are still paying for.
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
     * @param  array{data: array{object: array{id?: string, subscription?: string, parent?: array{subscription_details?: array{subscription?: string}}}}}  $payload
     */
    #[Override]
    protected function handleInvoicePaymentSucceeded(array $payload): Response
    {
        $response = parent::handleInvoicePaymentSucceeded($payload);

        $invoice = $payload['data']['object'];
        $subscriptionId = $invoice['subscription']
            ?? $invoice['parent']['subscription_details']['subscription']
            ?? null;

        // Clears the "awaiting payment" state an asynchronous payment method
        // leaves behind. Cards never enter it.
        if ($subscriptionId !== null) {
            $pendingClass = Numerosis::model(PendingTenantProvision::class);

            $pendingClass::where('stripe_subscription_id', $subscriptionId)
                ->where('status', TenantProvisionStatus::AwaitingPayment)
                ->update(['status' => TenantProvisionStatus::Provisioning]);
        }

        Log::info('Invoice payment succeeded', [
            'invoice_id' => $invoice['id'] ?? null,
        ]);

        return $response;
    }

    /**
     * @param  array{data: array{object: array{id?: string}}}  $payload
     */
    #[Override]
    protected function handleInvoicePaymentActionRequired(array $payload): Response
    {
        $response = parent::handleInvoicePaymentActionRequired($payload);

        Log::warning('Invoice payment action required', [
            'invoice_id' => $payload['data']['object']['id'] ?? null,
        ]);

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Billing;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
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
use Nvade\Numerosis\Actions\Tenancy\RestoreTenant;
use Nvade\Numerosis\Actions\Tenancy\SuspendTenant;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Enums\Billing\PlanChangeDirection;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Events\Billing\PaymentFailed;
use Nvade\Numerosis\Events\Billing\SubscriptionCancelled;
use Nvade\Numerosis\Events\Billing\SubscriptionPlanChanged;
use Nvade\Numerosis\Facades\Billing;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Nvade\Numerosis\Models\Central\Subscription as CentralSubscription;
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
        // subscription. The checkout request is not covered, since a webhook
        // can arrive before it commits, so a duplicate here means synced.
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

        // Inline checkout puts only the domain in Stripe metadata; the rest of
        // the registration is on the pending row, and a subscription made in
        // the Stripe Dashboard carries neither.
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

            // Queued: Stripe retries a webhook that answers slowly, and
            // building a tenant database exceeds that budget. Unique per
            // domain, so the redirect path cannot double-dispatch.
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
     * Finishes a redirect checkout (iDEAL, Bancontact and similar) once Stripe
     * attaches the reusable payment method it generated. Matched by customer,
     * since Stripe offers no way to look a setup attempt up directly: this
     * finds that customer's still-open checkouts and asks which one the
     * payment method belongs to.
     *
     * @param  array{data: array{object: array{id?: string, customer?: string, sepa_debit?: array{generated_from?: array{setup_attempt?: string}}}}}  $payload
     */
    protected function handlePaymentMethodAttached(array $payload): Response
    {
        $object = $payload['data']['object'];
        $paymentMethodId = $object['id'] ?? null;
        $customerId = $object['customer'] ?? null;
        $setupAttemptId = $object['sepa_debit']['generated_from']['setup_attempt'] ?? null;

        // Not a PaymentMethod generated from one of our SetupIntents (a plain
        // card attach, say), so there is nothing for this handler to do.
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
            // PaymentMethod. It belongs to one that already completed, or
            // Stripe hasn't finished linking it yet.
            return $this->successMethod();
        };

        /** @var Response $response */
        $response = Cache::lock("checkout-settle:{$paymentMethodId}", 10)->block(5, $handle);

        return $response;
    }

    /**
     * @param  array{data: array{object: array{id?: string, customer?: string, current_period_end?: int}}}  $payload
     */
    #[Override]
    protected function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        $response = parent::handleCustomerSubscriptionDeleted($payload);

        $customerId = $payload['data']['object']['customer'] ?? null;

        $this->suspendBillableFor($customerId);

        $tenant = FindTenantByStripeCustomer::run($customerId);
        $periodEnd = $payload['data']['object']['current_period_end'] ?? null;

        if ($tenant !== null) {
            event(new SubscriptionCancelled(
                $tenant,
                is_int($periodEnd) ? Carbon::createFromTimestamp($periodEnd) : null,
                (string) $tenant->getTenantKey(),
            ));
        }

        Log::info('Subscription deleted', [
            'subscription_id' => $payload['data']['object']['id'] ?? null,
        ]);

        return $response;
    }

    /**
     * Suspends and restores tenants as their subscription status moves, and is
     * the only place a price change is reported as
     * `Events\Billing\SubscriptionPlanChanged`, since Stripe raises
     * `customer.subscription.updated` whoever made it. `past_due`/`unpaid` are
     * grace-period states; `incomplete_expired` means the first payment failed.
     *
     * @param  array{data: array{object: array{id?: string, customer?: string, status?: string, items?: array{data: list<array{price?: array{id?: string}}>}}}}  $payload
     */
    #[Override]
    protected function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        $stripeSubscription = $payload['data']['object'];
        $subscriptionId = $stripeSubscription['id'] ?? null;

        // Read before Cashier's handler, which overwrites `stripe_price` with
        // the payload's own value.
        $previousPriceId = $this->localPriceIdFor($subscriptionId);

        $response = parent::handleCustomerSubscriptionUpdated($payload) ?? $this->successMethod();

        $customerId = $stripeSubscription['customer'] ?? null;
        $status = $stripeSubscription['status'] ?? null;
        $tenant = FindTenantByStripeCustomer::run(is_string($customerId) ? $customerId : null);

        if ($tenant !== null) {
            match ($status) {
                'past_due', 'unpaid', 'incomplete_expired' => SuspendTenant::run($tenant),
                'active', 'trialing' => RestoreTenant::run($tenant),
                default => null,
            };
        }

        $items = $stripeSubscription['items']['data'] ?? [];
        $newPriceId = count($items) === 1 ? ($items[0]['price']['id'] ?? null) : null;

        // A null previous price means this row was created by this very
        // webhook: a subscription appearing, where no plan has changed.
        if ($tenant !== null && is_string($newPriceId) && is_string($previousPriceId) && $newPriceId !== $previousPriceId) {
            event(new SubscriptionPlanChanged(
                $tenant,
                $previousPriceId,
                $newPriceId,
                $this->planChangeDirection($previousPriceId, $newPriceId),
                (string) $tenant->getTenantKey(),
            ));
        }

        Log::info('Subscription updated', [
            'subscription_id' => $payload['data']['object']['id'] ?? null,
            'status' => $status,
        ]);

        return $response;
    }

    private function localPriceIdFor(mixed $stripeSubscriptionId): ?string
    {
        if (! is_string($stripeSubscriptionId)) {
            return null;
        }

        $price = Numerosis::model(CentralSubscription::class)::query()
            ->where('stripe_id', $stripeSubscriptionId)
            ->value('stripe_price');

        return is_string($price) ? $price : null;
    }

    /**
     * Compared within whichever billing cycle the new price belongs to, so a
     * monthly figure is never weighed against a yearly one. Falls back to
     * `Upgrade` when either price resolves to no configured plan, since every
     * consumer of this is copy or a heuristic and a directionless event serves
     * them worse than an optimistic one.
     */
    private function planChangeDirection(string $fromPriceId, string $toPriceId): PlanChangeDirection
    {
        $from = Billing::planForPrice($fromPriceId);
        $to = Billing::planForPrice($toPriceId);

        $cycle = $to?->priceId(BillingCycle::Yearly) === $toPriceId
            ? BillingCycle::Yearly
            : BillingCycle::Monthly;

        $fromPrice = $from?->price($cycle);
        $toPrice = $to?->price($cycle);

        return $fromPrice !== null && $toPrice !== null && $toPrice < $fromPrice
            ? PlanChangeDirection::Downgrade
            : PlanChangeDirection::Upgrade;
    }

    /**
     * The dunning notice, sent while still in Stripe's retry/grace period.
     * Suspension itself happens in handleCustomerSubscriptionUpdated once
     * Stripe gives up.
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
     * The question is whether anything valid remains, never whether something
     * just ended: a tenant holding several subscriptions must not be locked out
     * of a workspace they are still paying for.
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

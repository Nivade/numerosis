<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Billing;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Laravel\Cashier\Subscription;
use Nvade\Numerosis\Actions\Billing\Checkout\SettleAttachedPaymentMethod;
use Nvade\Numerosis\Actions\Billing\FindTenantByStripeCustomer;
use Nvade\Numerosis\Actions\Billing\ResolvePlanChangeDirection;
use Nvade\Numerosis\Actions\Tenancy\RestoreTenant;
use Nvade\Numerosis\Actions\Tenancy\SuspendTenant;
use Nvade\Numerosis\Actions\Tenancy\SuspendUnlessEntitled;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Enums\Billing\SubscriptionStatus;
use Nvade\Numerosis\Events\Billing\PaymentFailed;
use Nvade\Numerosis\Events\Billing\PaymentSettled;
use Nvade\Numerosis\Events\Billing\SubscriptionCancelled;
use Nvade\Numerosis\Events\Billing\SubscriptionPlanChanged;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Subscription as CentralSubscription;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;
use Override;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends CashierWebhookController
{
    public function __construct(private readonly ProvisionsTenant $provisioning)
    {
        parent::__construct();
    }

    /**
     * {@see self::withFlattenedCustomer()} for why the payload is rewritten
     * before any handler sees it. The signature was verified by middleware
     * against the original body, so rebuilding the request here cannot weaken
     * it.
     */
    #[Override]
    public function handleWebhook(Request $request): mixed
    {
        $payload = json_decode((string) $request->getContent(), true);

        if (! is_array($payload)) {
            return parent::handleWebhook($request);
        }

        $flattened = self::withFlattenedCustomer($payload);

        if ($flattened === $payload) {
            return parent::handleWebhook($request);
        }

        return parent::handleWebhook(Request::create(
            $request->getRequestUri(),
            $request->getMethod(),
            server: $request->server->all(),
            content: (string) json_encode($flattened),
        ));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    #[Override]
    protected function handleCustomerSubscriptionCreated(array $payload): Response
    {
        /** @var array{data: array{object: array{id?: string, customer?: string, metadata?: array<string, mixed>}}} $payload */
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
            ? GlobalCache::lock("reconcile-subscription:{$stripeSubscriptionId}", 10)->block(5, $handle)
            : $handle();

        // Inline checkout puts only the slug in Stripe metadata; the rest of
        // the registration is on the provision row, and a subscription made in
        // the Stripe Dashboard carries neither.
        $slug = $metadata['slug'] ?? null;
        $pendingClass = Numerosis::model(TenantProvision::class);

        /** @var TenantProvision|null $pending */
        $pending = is_string($slug) ? $pendingClass::find($slug) : null;

        if ($pending !== null) {
            $centralUserClass = Numerosis::model(CentralUser::class);

            /** @var int|null $userId */
            $userId = $centralUserClass::where('global_id', $pending->global_id)->value('id');

            $pending->update([
                'stripe_subscription_id' => $stripeSubscriptionId,
                'stripe_customer_id' => $stripeSubscription['customer'] ?? null,
                'central_user_id' => $userId !== null ? (string) $userId : null,
            ]);

            // Queued: Stripe retries a webhook that answers slowly, and
            // building a tenant database exceeds that budget. Unique per
            // slug, so the redirect path cannot double-dispatch.
            $this->provisioning->queue(TenantProvisionData::fromProvision($pending));
        }

        Log::info('Subscription created', [
            'subscription_id' => $stripeSubscriptionId,
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

        $pendingClass = Numerosis::model(TenantProvision::class);

        /** @var Collection<int, TenantProvision> $candidates */
        $candidates = $pendingClass::where('global_id', $billable->global_id)
            ->whereNull('stripe_subscription_id')
            ->get();

        if ($candidates->isEmpty()) {
            return $this->successMethod();
        }

        $handle = function () use ($candidates, $billable, $paymentMethodId): Response {
            foreach ($candidates as $pending) {
                if (SettleAttachedPaymentMethod::run($pending, $billable, $paymentMethodId)) {
                    break;
                }
            }

            // Answering success either way: a PaymentMethod matching none of
            // this customer's open checkouts belongs to one that already
            // completed, or Stripe has not finished linking it yet.
            return $this->successMethod();
        };

        /** @var Response $response */
        $response = GlobalCache::lock("checkout-settle:{$paymentMethodId}", 10)->block(5, $handle);

        return $response;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    #[Override]
    protected function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        $response = parent::handleCustomerSubscriptionDeleted($payload);

        /** @var array{data: array{object: array{id?: string, customer?: string, current_period_end?: int, items?: array{data: list<array{current_period_end?: int}>}}}} $payload */
        $stripeSubscription = $payload['data']['object'];
        $tenant = FindTenantByStripeCustomer::run($stripeSubscription['customer'] ?? null);

        if ($tenant !== null) {
            SuspendUnlessEntitled::run($tenant);

            $periodEnd = self::periodEndOf($stripeSubscription);

            event(new SubscriptionCancelled(
                $tenant,
                $periodEnd !== null ? Date::createFromTimestamp($periodEnd) : null,
                (string) $tenant->getTenantKey(),
            ));
        }

        Log::info('Subscription deleted', [
            'subscription_id' => $stripeSubscription['id'] ?? null,
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
     * @param  array<array-key, mixed>  $payload
     */
    #[Override]
    protected function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        /** @var array{data: array{object: array{id?: string, customer?: string, status?: string, items?: array{data: list<array{price?: array{id?: string}}>}}}} $payload */
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
            $subscriptionStatus = is_string($status) ? SubscriptionStatus::tryFrom($status) : null;

            match (true) {
                $subscriptionStatus?->isDelinquent() ?? false => SuspendTenant::run($tenant),
                $subscriptionStatus?->isSettled() ?? false => RestoreTenant::run($tenant),
                default => null,
            };
        }

        // Every subscription this package creates carries exactly one item, so
        // a multi-item one was built outside it and has no single "the price"
        // to report a change against.
        $items = $stripeSubscription['items']['data'] ?? [];
        $newPriceId = count($items) === 1 ? ($items[0]['price']['id'] ?? null) : null;

        // A null previous price means this row was created by this very
        // webhook: a subscription appearing, where no plan has changed.
        if ($tenant !== null && is_string($newPriceId) && is_string($previousPriceId) && $newPriceId !== $previousPriceId) {
            event(new SubscriptionPlanChanged(
                $tenant,
                $previousPriceId,
                $newPriceId,
                ResolvePlanChangeDirection::run($previousPriceId, $newPriceId),
                (string) $tenant->getTenantKey(),
            ));
        }

        Log::info('Subscription updated', [
            'subscription_id' => $subscriptionId,
            'status' => $status,
        ]);

        return $response;
    }

    /**
     * Every handler here and in Cashier reads `data.object.customer` as an id,
     * but Stripe sends the whole object whenever the account or the endpoint
     * expands it. Flattened once at the front door rather than per handler:
     * Cashier reads it in six handlers of its own, one of which
     * (`payment_method.automatically_updated`) this class does not override.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private static function withFlattenedCustomer(array $payload): array
    {
        /** @var array{data?: array{object?: array<string, mixed>}} $payload */
        $customer = $payload['data']['object']['customer'] ?? null;

        if (is_array($customer)) {
            $id = $customer['id'] ?? null;
            $payload['data']['object']['customer'] = is_string($id) ? $id : null;
        }

        return $payload;
    }

    /**
     * Stripe moved `current_period_end` off the subscription onto its items in
     * API version `2025-03-31.basil`; Cashier v16 pins a later one, so the
     * top-level read is the fallback for an account pinned before it.
     *
     * @param  array{current_period_end?: int, items?: array{data: list<array{current_period_end?: int}>}}  $stripeSubscription
     */
    private static function periodEndOf(array $stripeSubscription): ?int
    {
        $periodEnd = $stripeSubscription['items']['data'][0]['current_period_end']
            ?? $stripeSubscription['current_period_end']
            ?? null;

        return is_int($periodEnd) ? $periodEnd : null;
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

    private function announceSettlementFor(?string $customerId): void
    {
        $tenant = FindTenantByStripeCustomer::run($customerId);
        $owner = $tenant?->owner();

        if ($tenant !== null && $owner !== null) {
            event(new PaymentSettled($tenant, $owner->id, (string) $tenant->getTenantKey()));
        }
    }

    private function notifyOfFailedPaymentFor(?string $customerId): void
    {
        $tenant = FindTenantByStripeCustomer::run($customerId);

        if ($tenant !== null) {
            event(new PaymentFailed($tenant, (string) $tenant->getTenantKey()));
        }
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    #[Override]
    protected function handleInvoicePaymentSucceeded(array $payload): Response
    {
        $response = parent::handleInvoicePaymentSucceeded($payload);

        /** @var array{data: array{object: array{id?: string, customer?: string, subscription?: string, parent?: array{subscription_details?: array{subscription?: string}}}}} $payload */
        $invoice = $payload['data']['object'];
        $subscriptionId = $invoice['subscription']
            ?? $invoice['parent']['subscription_details']['subscription']
            ?? null;

        // Clears the "awaiting payment" state an asynchronous payment method
        // leaves behind. Cards never enter it.
        if ($subscriptionId !== null) {
            $pendingClass = Numerosis::model(TenantProvision::class);

            $settled = $pendingClass::where('stripe_subscription_id', $subscriptionId)
                ->whereNull('settled_at')
                ->update(['settled_at' => now()]);

            // Zero rows means this delivery is a redelivery, so the
            // announcement is idempotent without a second guard.
            if ($settled > 0) {
                $this->announceSettlementFor($invoice['customer'] ?? null);
            }
        }

        Log::info('Invoice payment succeeded', [
            'invoice_id' => $invoice['id'] ?? null,
        ]);

        return $response;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    #[Override]
    protected function handleInvoicePaymentActionRequired(array $payload): Response
    {
        $response = parent::handleInvoicePaymentActionRequired($payload);

        /** @var array{data: array{object: array{id?: string}}} $payload */
        $invoice = $payload['data']['object'];

        Log::warning('Invoice payment action required', [
            'invoice_id' => $invoice['id'] ?? null,
        ]);

        return $response;
    }
}

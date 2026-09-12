<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Models\Central;

use Nvade\Numerosis\Enums\Billing\SubscriptionStatus;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\Finder;

/**
 * "Settled" was decided at five call sites, and one of them had already
 * diverged — `DefaultUnpaidTenantQuota` omitted `trialing`, so a trialing
 * tenant counted as unpaid. Four now read `SubscriptionStatus::isSettled()`;
 * the fifth keeps a narrower predicate on purpose, and says so in a comment.
 * The scan below is what stops a sixth copy appearing.
 */
class SettledSubscriptionTest extends TestCase
{
    /**
     * @return array<string, array{0: SubscriptionStatus, 1: bool}>
     */
    public static function statuses(): array
    {
        return [
            'active' => [SubscriptionStatus::Active, true],
            'trialing' => [SubscriptionStatus::Trialing, true],
            'past_due' => [SubscriptionStatus::PastDue, false],
            'unpaid' => [SubscriptionStatus::Unpaid, false],
            'incomplete' => [SubscriptionStatus::Incomplete, false],
            'incomplete_expired' => [SubscriptionStatus::IncompleteExpired, false],
            'canceled' => [SubscriptionStatus::Canceled, false],
            'paused' => [SubscriptionStatus::Paused, false],
        ];
    }

    #[DataProvider('statuses')]
    public function test_the_allowlist_answers_the_same_way_from_both_entry_points(SubscriptionStatus $status, bool $settled): void
    {
        $subscription = new Subscription(['stripe_status' => $status->value]);

        $this->assertSame($settled, $status->isSettled());
        $this->assertSame($settled, $subscription->isSettled());
    }

    public function test_an_unknown_status_is_never_settled(): void
    {
        $subscription = new Subscription(['stripe_status' => 'something_stripe_added_later']);

        $this->assertNull($subscription->status());
        $this->assertFalse($subscription->isSettled());
    }

    public function test_cashier_reads_the_uncast_column_correctly(): void
    {
        $incomplete = new Subscription(['stripe_status' => SubscriptionStatus::Incomplete->value]);
        $pastDue = new Subscription(['stripe_status' => SubscriptionStatus::PastDue->value]);

        $this->assertTrue($incomplete->incomplete());
        $this->assertTrue($pastDue->pastDue());
    }

    public function test_no_second_copy_of_the_allowlist_exists(): void
    {
        $finder = Finder::create()
            ->files()
            ->in([
                dirname(__DIR__, 4).'/src',
                dirname(__DIR__, 4).'/resources',
                dirname(__DIR__, 4).'/config',
                dirname(__DIR__, 4).'/routes',
            ])
            ->name('*.php');

        $offenders = [];

        foreach ($finder as $file) {
            $path = $file->getRealPath();

            if ($path === false || str_ends_with($path, 'src/Models/Central/Subscription.php')) {
                continue;
            }

            if (preg_match('/([\'"])active\1\s*,\s*([\'"])trialing\2/', (string) file_get_contents($path)) === 1) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, 'The settled-status allowlist belongs to Subscription::SETTLED_STATUSES only.');
    }
}

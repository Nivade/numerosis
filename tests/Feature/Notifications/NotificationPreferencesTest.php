<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Notifications;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\URL;
use Nvade\Numerosis\Actions\Notifications\SaveNotificationPreference;
use Nvade\Numerosis\Enums\Notifications\NotificationType;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Models\Central\NotificationPreference;
use Nvade\Numerosis\Models\Central\Tenant as BaseTenant;
use Nvade\Numerosis\Notifications\Billing\PaymentConfirmed;
use Nvade\Numerosis\Notifications\Billing\PaymentFailed;
use Nvade\Numerosis\Notifications\Billing\TenantSuspended;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The spine of the design is the mail nobody may switch off. These assert it
 * from both directions: a stored override that says otherwise is ignored, and
 * the unsubscribe link for such a type changes nothing.
 */
class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_channels_follow_the_defaults_when_nothing_is_stored(): void
    {
        [$user, $tenant] = $this->recipient();

        $this->assertSame(['mail', 'database'], new PaymentConfirmed($tenant)->via($user));
    }

    public function test_a_user_can_turn_off_a_disableable_mail(): void
    {
        [$user, $tenant] = $this->recipient();

        SaveNotificationPreference::run($user->global_id, NotificationType::PaymentConfirmed, mail: false, database: true);

        $this->assertSame(['database'], new PaymentConfirmed($tenant)->via($user));
    }

    /** Muting everything must not mute the card that failed. */
    public function test_a_non_disableable_mail_survives_an_override_that_says_otherwise(): void
    {
        [$user, $tenant] = $this->recipient();

        foreach (NotificationType::cases() as $type) {
            SaveNotificationPreference::run($user->global_id, $type, mail: false, database: false);
        }

        $this->assertContains('mail', new PaymentFailed($tenant)->via($user));
        $this->assertContains('mail', new TenantSuspended($tenant)->via($user));
        $this->assertNotContains('mail', new PaymentConfirmed($tenant)->via($user));
    }

    public function test_a_stored_row_for_a_locked_type_is_written_as_on_whatever_was_asked(): void
    {
        [$user] = $this->recipient();

        SaveNotificationPreference::run($user->global_id, NotificationType::PaymentFailed, mail: false, database: true);

        $preference = NotificationPreference::query()
            ->where('global_id', $user->global_id)
            ->where('type', NotificationType::PaymentFailed->value)
            ->firstOrFail();

        $this->assertTrue($preference->mail);
    }

    public function test_the_database_payload_is_rendered_copy_rather_than_raw_keys(): void
    {
        [$user, $tenant] = $this->recipient();

        $user->notify(new PaymentConfirmed($tenant));

        /** @var DatabaseNotification $row */
        $row = $user->notifications()->firstOrFail();
        $data = $row->getAttribute('data');

        $this->assertIsArray($data);
        $this->assertSame(NotificationType::PaymentConfirmed->label(), $data['title']);
        $this->assertIsString($data['body']);
        $this->assertStringContainsString((string) $tenant->name, $data['body']);
        $this->assertSame((string) $tenant->getTenantKey(), $data['tenant_id']);
    }

    public function test_the_unsubscribe_link_disables_exactly_one_type_while_logged_out(): void
    {
        [$user] = $this->recipient();

        $url = URL::signedRoute('notifications.unsubscribe', [
            'globalId' => $user->global_id,
            'type' => NotificationType::PaymentConfirmed->value,
        ]);

        $this->get($url)->assertOk();

        $this->assertFalse((bool) NotificationPreference::query()
            ->where('global_id', $user->global_id)
            ->where('type', NotificationType::PaymentConfirmed->value)
            ->value('mail'));

        $this->assertSame(1, NotificationPreference::query()->where('global_id', $user->global_id)->count());
    }

    public function test_an_unsigned_unsubscribe_link_is_refused(): void
    {
        [$user] = $this->recipient();

        $this->get('/notifications/unsubscribe/'.$user->global_id.'/'.NotificationType::PaymentConfirmed->value)
            ->assertForbidden();

        $this->assertSame(0, NotificationPreference::query()->count());
    }

    public function test_unsubscribing_from_a_locked_type_changes_nothing(): void
    {
        [$user, $tenant] = $this->recipient();

        $url = URL::signedRoute('notifications.unsubscribe', [
            'globalId' => $user->global_id,
            'type' => NotificationType::PaymentFailed->value,
        ]);

        $this->get($url)->assertOk();

        $this->assertSame(0, NotificationPreference::query()->count());
        $this->assertContains('mail', new PaymentFailed($tenant)->via($user));
    }

    /** A person in two workspaces keeps their preference after leaving one. */
    public function test_preferences_are_keyed_by_the_person_rather_than_the_membership(): void
    {
        [$user] = $this->recipient();

        SaveNotificationPreference::run($user->global_id, NotificationType::PaymentConfirmed, mail: false, database: true);

        Tenant::factory()->create();

        $this->assertSame(1, NotificationPreference::query()->where('global_id', $user->global_id)->count());
    }

    /**
     * @return array{0: BaseCentralUser, 1: BaseTenant}
     */
    private function recipient(): array
    {
        Tenant::unsetEventDispatcher();

        return [CentralUser::factory()->create(), Tenant::factory()->create(['name' => 'Acme'])];
    }
}

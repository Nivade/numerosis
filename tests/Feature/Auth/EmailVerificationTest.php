<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Auth;

use Nvade\Numerosis\Contracts\Auth\SendsEmailVerificationNotification;
use Nvade\Numerosis\Models\Central\CentralUser;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification as NotificationBase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Nvade\Numerosis\Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_notification_uses_current_domain(): void
    {
        // Arrange
        Notification::fake();

        $user = CentralUser::factory()->create([
            'email_verified_at' => null,
            'global_id' => 'test-global-id-'.uniqid(),
        ]);

        // Act - send verification notification
        $user->sendEmailVerificationNotification();

        // Assert
        Notification::assertSentTo($user, \Nvade\Numerosis\Notifications\Auth\VerifyEmail::class);
    }

    /**
     * A consumer rebinds this to change the notification (copy, channel)
     * without overriding sendEmailVerificationNotification() on the shared
     * abstract user model.
     */
    public function test_a_consumer_can_override_the_email_verification_notification(): void
    {
        Notification::fake();

        $customNotification = new class extends NotificationBase
        {
            /**
             * @return array<int, string>
             */
            public function via(mixed $notifiable): array
            {
                return ['mail'];
            }
        };

        $this->app->singleton(SendsEmailVerificationNotification::class, fn () => new class($customNotification) implements SendsEmailVerificationNotification
        {
            public function __construct(private readonly NotificationBase $notification) {}

            public function send(MustVerifyEmail $notifiable): void
            {
                throw_unless($notifiable instanceof CentralUser, RuntimeException::class, 'Expected an Nvade\Numerosis\Models\Central\CentralUser instance.');

                $notifiable->notify($this->notification);
            }
        });

        $user = CentralUser::factory()->create([
            'email_verified_at' => null,
            'global_id' => 'test-global-id-'.uniqid(),
        ]);

        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, $customNotification::class);
        Notification::assertNotSentTo($user, \Nvade\Numerosis\Notifications\Auth\VerifyEmail::class);
    }

    public function test_user_can_verify_email_with_valid_signature(): void
    {
        // Arrange
        $user = CentralUser::factory()->create([
            'email_verified_at' => null,
            'global_id' => 'test-global-id-'.uniqid(),
        ]);

        // Generate a signed URL for email verification
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->global_id,
                'hash' => sha1($user->getEmailForVerification()),
            ]
        );

        // Act - visit the verification URL
        $response = $this->actingAs($user)->get($verificationUrl);

        // Assert
        $response->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_user_cannot_verify_email_with_invalid_signature(): void
    {
        // Arrange
        $user = CentralUser::factory()->create([
            'email_verified_at' => null,
            'global_id' => 'test-global-id-'.uniqid(),
        ]);

        // Create an invalid URL (without signature)
        $invalidUrl = route('verification.verify', [
            'id' => $user->global_id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        // Act - visit the invalid verification URL
        $response = $this->actingAs($user)->get($invalidUrl);

        // Assert
        $response->assertStatus(403); // Forbidden due to invalid signature
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_user_cannot_verify_email_with_wrong_hash(): void
    {
        // Arrange
        $user = CentralUser::factory()->create([
            'email_verified_at' => null,
            'global_id' => 'test-global-id-'.uniqid(),
        ]);

        // Generate a signed URL with wrong hash
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->global_id,
                'hash' => 'wrong-hash',
            ]
        );

        // Act - visit the verification URL with wrong hash
        $response = $this->actingAs($user)->get($verificationUrl);

        // Assert
        $response->assertStatus(403); // Forbidden due to hash mismatch
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_guest_cannot_verify_email(): void
    {
        // Arrange
        $user = CentralUser::factory()->create([
            'email_verified_at' => null,
            'global_id' => 'test-global-id-'.uniqid(),
        ]);

        // Generate a signed URL for email verification
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->global_id,
                'hash' => sha1($user->getEmailForVerification()),
            ]
        );

        // Act - visit the verification URL without authentication
        $response = $this->get($verificationUrl);

        // Assert
        $response->assertRedirect(route('login'));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }
}

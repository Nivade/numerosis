<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Auth;

use App\Models\Central\CentralUser;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification as NotificationBase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Nvade\Numerosis\Contracts\Auth\SendsEmailVerificationNotification;
use Nvade\Numerosis\Features\Auth\EmailVerificationFeature;
use Nvade\Numerosis\Notifications\Auth\VerifyEmail;
use Nvade\Numerosis\Tests\TestCase;
use RuntimeException;

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
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    /**
     * With no `createUrlCallback` registered, `VerifyEmail` builds the link
     * itself. It has to agree with what
     * `NumerosisVerifyEmailRequest::authorize()` compares against — the global
     * identifier and `sha1()` of the address — or the link 403s. A salted hash
     * there can never compare equal.
     */
    public function test_the_built_in_verification_url_matches_what_the_verify_route_checks(): void
    {
        $user = CentralUser::factory()->create([
            'email_verified_at' => null,
            'global_id' => 'verify-url-'.uniqid(),
        ]);

        VerifyEmail::$createUrlCallback = null;

        try {
            $url = (new VerifyEmail)->toMail($user)->actionUrl;
        } finally {
            (new EmailVerificationFeature)->bootstrap();
        }

        $this->assertStringContainsString(
            $user->global_id.'/'.sha1((string) $user->email),
            (string) parse_url((string) $url, PHP_URL_PATH),
        );
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

        app()->singleton(SendsEmailVerificationNotification::class, fn () => new readonly class($customNotification) implements SendsEmailVerificationNotification
        {
            public function __construct(private NotificationBase $notification) {}

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
        Notification::assertNotSentTo($user, VerifyEmail::class);
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
        $this->assertTrue($user->refresh()->hasVerifiedEmail());
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
        $response->assertForbidden(); // Forbidden due to invalid signature
        $this->assertFalse($user->refresh()->hasVerifiedEmail());
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
        $response->assertForbidden(); // Forbidden due to hash mismatch
        $this->assertFalse($user->refresh()->hasVerifiedEmail());
    }

    /**
     * Fortify's verify route carries `auth:<guard>` ahead of `signed`, so an
     * unauthenticated hit never reaches the signature check — it redirects to
     * `login`, which is Fortify's own route now.
     */
    public function test_guest_cannot_verify_email(): void
    {
        // Arrange
        $user = CentralUser::factory()->create([
            'email_verified_at' => null,
            'global_id' => 'test-global-id-'.uniqid(),
        ]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->global_id,
                'hash' => sha1($user->getEmailForVerification()),
            ]
        );

        // Act — visit the verification URL without authenticating first.
        $response = $this->get($verificationUrl);

        // Assert
        $response->assertRedirect(route('login'));
        $this->assertFalse($user->refresh()->hasVerifiedEmail());
    }
}

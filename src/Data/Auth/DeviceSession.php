<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

class DeviceSession extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly CarbonImmutable $lastActiveAt,
        public readonly ?string $tenantId,
    ) {}

    /** Presentation only: the stored user agent is never parsed on the way in. */
    public function deviceLabel(): string
    {
        if ($this->userAgent === null || $this->userAgent === '') {
            return __('Unknown device');
        }

        $browser = match (true) {
            Str::contains($this->userAgent, 'Edg/') => 'Edge',
            Str::contains($this->userAgent, 'OPR/') => 'Opera',
            Str::contains($this->userAgent, 'Firefox/') => 'Firefox',
            Str::contains($this->userAgent, 'Chrome/') => 'Chrome',
            Str::contains($this->userAgent, 'Safari/') => 'Safari',
            default => __('Unknown browser'),
        };

        $platform = match (true) {
            Str::contains($this->userAgent, ['iPhone', 'iPad']) => 'iOS',
            Str::contains($this->userAgent, 'Android') => 'Android',
            Str::contains($this->userAgent, 'Mac OS X') => 'macOS',
            Str::contains($this->userAgent, 'Windows') => 'Windows',
            Str::contains($this->userAgent, ['Linux', 'X11']) => 'Linux',
            default => __('unknown platform'),
        };

        return "{$browser} on {$platform}";
    }
}

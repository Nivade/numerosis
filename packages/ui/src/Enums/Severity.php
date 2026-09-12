<?php

declare(strict_types=1);

namespace Nvade\NumerosisUi\Enums;

/**
 * The one semantic-token palette `ui/alert`, `ui/badge` and `ui/toast` all
 * render through, so the three can no longer disagree on which tokens a
 * severity maps to the way they used to.
 *
 * Every class string below is spelled out literally, never built through
 * interpolation — Tailwind's `@source` scanner extracts complete class
 * candidates as raw text, and `resources/theme-src/app.css` /
 * `resources/css/app.css` both carry a `@source` line naming this file for
 * that reason. An interpolated `"bg-{$prefix}-bg"` would never appear in
 * generated CSS.
 */
enum Severity: string
{
    case Success = 'success';
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';

    /**
     * `danger` is `ui/info-box`'s and `ui/badge`'s name for `Error`;
     * `message` is the legacy flash key for `Info`. Returns null for
     * anything else, including `default` — that is not a severity.
     */
    public static function fromAlias(?string $value): ?self
    {
        return match ($value) {
            'danger' => self::Error,
            'message' => self::Info,
            default => self::tryFrom((string) $value),
        };
    }

    public function classes(): string
    {
        return match ($this) {
            self::Success => 'bg-success-bg text-success-text',
            self::Error => 'bg-danger-bg text-danger-text',
            self::Warning => 'bg-warning-bg text-warning-text',
            self::Info => 'bg-info-bg text-info-text',
        };
    }

    public function surfaceClasses(): string
    {
        return match ($this) {
            self::Success => 'bg-success-bg border-success-border',
            self::Error => 'bg-danger-bg border-danger-border',
            self::Warning => 'bg-warning-bg border-warning-border',
            self::Info => 'bg-info-bg border-info-border',
        };
    }

    public function textClasses(): string
    {
        return match ($this) {
            self::Success => 'text-success-text',
            self::Error => 'text-danger-text',
            self::Warning => 'text-warning-text',
            self::Info => 'text-info-text',
        };
    }

    public function iconColorClasses(): string
    {
        return match ($this) {
            self::Success => 'text-success-icon',
            self::Error => 'text-danger-icon',
            self::Warning => 'text-warning-icon',
            self::Info => 'text-info-icon',
        };
    }

    public function buttonClasses(): string
    {
        return match ($this) {
            self::Success => 'text-success-icon hover:text-success-text',
            self::Error => 'text-danger-icon hover:text-danger-text',
            self::Warning => 'text-warning-icon hover:text-warning-text',
            self::Info => 'text-info-icon hover:text-info-text',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Success => 'check-circle',
            self::Warning => 'exclamation-triangle',
            self::Error => 'x-circle',
            self::Info => 'information-circle',
        };
    }
}

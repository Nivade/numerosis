<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Tenancy;

use Nvade\Numerosis\Livewire\Tenant\Registration\Steps as Wizard;

enum WizardStep
{
    case CompanyInfo;
    case TechnicalSetup;
    case Plan;
    case Payment;

    /**
     * @return class-string
     */
    public function componentClass(): string
    {
        return match ($this) {
            self::CompanyInfo => Wizard\CompanyInfo::class,
            self::TechnicalSetup => Wizard\TechnicalSetup::class,
            self::Plan => Wizard\Plan::class,
            self::Payment => Wizard\Payment::class,
        };
    }

    /**
     * The Livewire component alias each step registers under, or null for a
     * step that resolves by FQCN instead — `Payment`'s natural alias collides
     * with Cashier's published `payment.blade.php`.
     */
    public function alias(): ?string
    {
        return match ($this) {
            self::CompanyInfo => 'company-info',
            self::TechnicalSetup => 'technical-setup',
            self::Plan => 'plan',
            self::Payment => null,
        };
    }

    /**
     * @param  class-string  $class
     */
    public static function fromComponentClass(string $class): ?self
    {
        foreach (self::cases() as $step) {
            if ($step->componentClass() === $class) {
                return $step;
            }
        }

        return null;
    }
}

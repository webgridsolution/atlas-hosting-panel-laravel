<?php

namespace App\Enums;

enum LicenseStatus: string
{
    case PendingActivation = 'pending_activation';
    case Active = 'active';
    case Grace = 'grace';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::PendingActivation => 'Pending activation',
            self::Active => 'Active',
            self::Grace => 'In grace period',
            self::Expired => 'Expired',
            self::Suspended => 'Suspended',
            self::Revoked => 'Revoked',
        };
    }

    /** Maps to a design-system badge modifier. */
    public function badge(): string
    {
        return match ($this) {
            self::Active => 'badge-ok',
            self::PendingActivation, self::Grace => 'badge-warn',
            self::Expired, self::Suspended, self::Revoked => 'badge-danger',
        };
    }

    /** Licence states that still permit normal panel operation. */
    public function allowsFullOperation(): bool
    {
        return in_array($this, [self::Active, self::Grace, self::PendingActivation], true);
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}

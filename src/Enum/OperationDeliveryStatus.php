<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum OperationDeliveryStatus: string
{
    case Prepared = 'prepared';
    case Pending = 'pending';
    case Processing = 'processing';
    case Retry = 'retry';
    case Delivered = 'delivered';
    case Dead = 'dead';
    case Cancelled = 'cancelled';

    public function isUnresolved(): bool
    {
        return in_array($this, [self::Prepared, self::Pending, self::Processing, self::Retry], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Dead, self::Cancelled], true);
    }
}

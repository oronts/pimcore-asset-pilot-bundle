<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum OperationDeliveryOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';
}

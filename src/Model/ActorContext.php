<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\ActorType;

final readonly class ActorContext
{
    public function __construct(
        public ActorType $type,
        public ?int $userId = null,
    ) {
        if ($type === ActorType::User && ($userId ?? 0) <= 0) {
            throw new \InvalidArgumentException('A user actor requires a positive user ID.');
        }

        if ($type !== ActorType::User && $userId !== null) {
            throw new \InvalidArgumentException('Only a user actor may carry a user ID.');
        }
    }

    public static function user(int $userId): self
    {
        return new self(ActorType::User, $userId);
    }

    public static function system(): self
    {
        return new self(ActorType::System);
    }

    public static function anonymous(): self
    {
        return new self(ActorType::Anonymous);
    }
}

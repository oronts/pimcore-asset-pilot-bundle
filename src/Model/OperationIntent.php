<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Enum\OperationKind;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;

readonly class OperationIntent
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public OperationKind $kind,
        public int $assetId,
        public string $sourcePath,
        public string $targetPath,
        public int $objectId,
        public string $objectClass,
        public string $ruleName,
        public TriggerType $triggerType,
        public ActorContext $actor,
        public array $context = [],
        public ?int $parentOperationId = null,
        public int $schemaVersion = 1,
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        if ($assetId <= 0) {
            throw new \InvalidArgumentException('An operation intent requires a positive asset ID.');
        }
        if ($objectId < 0) {
            throw new \InvalidArgumentException('An operation intent object ID cannot be negative.');
        }
        if ($sourcePath === '' || $targetPath === '') {
            throw new \InvalidArgumentException('An operation intent requires source and target paths.');
        }
        if ($schemaVersion <= 0) {
            throw new \InvalidArgumentException('An operation intent schema version must be positive.');
        }
        if ($parentOperationId !== null && $parentOperationId <= 0) {
            throw new \InvalidArgumentException('A parent operation ID must be positive.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schemaVersion' => $this->schemaVersion,
            'kind' => $this->kind->value,
            'assetId' => $this->assetId,
            'sourcePath' => $this->sourcePath,
            'targetPath' => $this->targetPath,
            'objectId' => $this->objectId,
            'objectClass' => $this->objectClass,
            'ruleName' => $this->ruleName,
            'triggerType' => $this->triggerType->value,
            'actorType' => $this->actor->type->value,
            'actorUserId' => $this->actor->userId,
            'context' => $this->context,
            'parentOperationId' => $this->parentOperationId,
            'createdAt' => $this->createdAt->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $actorType = ActorType::from((string) ($payload['actorType'] ?? ''));
        $actorUserId = isset($payload['actorUserId']) ? (int) $payload['actorUserId'] : null;
        $context = $payload['context'] ?? [];

        return new self(
            kind: OperationKind::from((string) ($payload['kind'] ?? '')),
            assetId: (int) ($payload['assetId'] ?? 0),
            sourcePath: (string) ($payload['sourcePath'] ?? ''),
            targetPath: (string) ($payload['targetPath'] ?? ''),
            objectId: (int) ($payload['objectId'] ?? 0),
            objectClass: (string) ($payload['objectClass'] ?? ''),
            ruleName: (string) ($payload['ruleName'] ?? ''),
            triggerType: TriggerType::from((string) ($payload['triggerType'] ?? '')),
            actor: new ActorContext($actorType, $actorUserId),
            context: is_array($context) ? $context : [],
            parentOperationId: isset($payload['parentOperationId']) ? (int) $payload['parentOperationId'] : null,
            schemaVersion: (int) ($payload['schemaVersion'] ?? 0),
            createdAt: new \DateTimeImmutable((string) ($payload['createdAt'] ?? '')),
        );
    }

    public function toMoveOperation(
        OperationStatus $status,
        ?string $errorMessage = null,
        ?int $durationMs = null,
    ): MoveOperation {
        return new MoveOperation(
            assetId: $this->assetId,
            sourcePath: $this->sourcePath,
            targetPath: $this->targetPath,
            objectId: $this->objectId,
            objectClass: $this->objectClass,
            ruleName: $this->ruleName,
            status: $status,
            triggerType: $this->triggerType,
            errorMessage: $errorMessage,
            durationMs: $durationMs,
            userId: $this->actor->userId,
            createdAt: $this->createdAt,
        );
    }
}

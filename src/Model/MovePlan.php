<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

/**
 * The decision of evaluating the move gates for one asset: either proceed with a move
 * (folderPath + targetFilename) or skip with a reason. Produced once by MovePlanner so the
 * dry-run preview and the live move can never drift on which gates apply or in what order.
 */
class MovePlan
{
    private function __construct(
        public readonly string $targetPath,
        public readonly ?string $folderPath,
        public readonly ?string $targetFilename,
        public readonly ?string $skipReason,
    ) {}

    public static function proceed(string $folderPath, string $targetFilename, string $fullTargetPath): self
    {
        return new self($fullTargetPath, $folderPath, $targetFilename, null);
    }

    public static function skip(string $targetPath, string $reason): self
    {
        return new self($targetPath, null, null, $reason);
    }

    public function isSkip(): bool
    {
        return $this->skipReason !== null;
    }
}

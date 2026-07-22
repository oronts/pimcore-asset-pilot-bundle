<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Zip;

readonly class ZipBuildResult
{
    public function __construct(
        public ?string $path,
        public int $requested,
        public int $added,
        public int $skipped,
        public bool $truncated = false,
    ) {
        if ($this->requested < 0 || $this->added < 0 || $this->skipped < 0) {
            throw new \InvalidArgumentException('ZIP build counts must not be negative.');
        }
        if ($this->added + $this->skipped > $this->requested) {
            throw new \InvalidArgumentException('ZIP build counts must not exceed the requested count.');
        }
        if (!$this->truncated && $this->added + $this->skipped !== $this->requested) {
            throw new \InvalidArgumentException('A complete ZIP build must account for every requested asset.');
        }
        if (($this->added === 0) !== ($this->path === null)) {
            throw new \InvalidArgumentException('A ZIP build path is required exactly when assets were added.');
        }
        if ($this->path === '') {
            throw new \InvalidArgumentException('A ZIP build path must not be empty.');
        }
    }

    public function hasArchive(): bool
    {
        return $this->path !== null;
    }
}

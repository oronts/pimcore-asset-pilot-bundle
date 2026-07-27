<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Merge;

readonly class ReferrerSnapshot
{
    public function __construct(
        public string $type,
        public int $id,
        public string $fingerprint,
    ) {
        if ($this->type === '' || $this->id <= 0 || $this->fingerprint === '') {
            throw new \InvalidArgumentException('A referrer snapshot requires a type, positive ID, and fingerprint.');
        }
    }

    public function key(): string
    {
        return $this->type . ':' . $this->id;
    }

    /** @return array{type: string, id: int, fingerprint: string} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id, 'fingerprint' => $this->fingerprint];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['type'] ?? ''), (int) ($data['id'] ?? 0), (string) ($data['fingerprint'] ?? ''));
    }
}

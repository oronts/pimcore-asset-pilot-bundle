<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;

final class ApplyPlanService implements ApplyPlanServiceInterface
{
    private const string VERSION = 'v1';

    private readonly string $secret;
    private readonly int $ttlSeconds;
    private readonly ?\Closure $clock;

    public function __construct(
        #[\SensitiveParameter]
        string $secret,
        private readonly ApplyPlanClaimStoreInterface $claims,
        int $ttlSeconds = 300,
        ?\Closure $clock = null,
    ) {
        if ($secret === '') {
            throw new \InvalidArgumentException('The apply plan signing secret must not be empty.');
        }
        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('The apply plan token lifetime must be positive.');
        }
        $this->secret = $secret;
        $this->ttlSeconds = $ttlSeconds;
        $this->clock = $clock;
    }

    public function issue(ApplyPlan $plan): string
    {
        $payload = $this->encode([
            'expiresAt' => $this->now() + $this->ttlSeconds,
            'nonce' => $this->base64UrlEncode(random_bytes(12)),
            'binding' => $this->binding($plan),
        ]);
        $encodedPayload = $this->base64UrlEncode($payload);
        $signed = self::VERSION . '.' . $encodedPayload;
        $signature = hash_hmac('sha256', $signed, $this->secret, true);

        return $signed . '.' . $this->base64UrlEncode($signature);
    }

    public function verify(string $token, ApplyPlan $plan): ApplyPlanStatus
    {
        return $this->inspect($token, $plan)['status'];
    }

    public function claim(string $token, ApplyPlan $plan): ApplyPlanStatus
    {
        $inspection = $this->inspect($token, $plan);
        if ($inspection['status'] !== ApplyPlanStatus::Valid) {
            return $inspection['status'];
        }

        $claimId = hash('sha256', $token);
        $claimedAt = $this->now();
        if ($inspection['expiresAt'] <= $claimedAt) {
            return ApplyPlanStatus::Stale;
        }

        return $this->claims->claim(
            $claimId,
            $this->timestamp($claimedAt),
            $this->timestamp($inspection['expiresAt']),
        ) ? ApplyPlanStatus::Claimed : ApplyPlanStatus::AlreadyClaimed;
    }

    /** @return array{status: ApplyPlanStatus, expiresAt: int} */
    private function inspect(string $token, ApplyPlan $plan): array
    {
        $segments = explode('.', $token);
        if (count($segments) !== 3 || $segments[0] !== self::VERSION) {
            return $this->inspection(ApplyPlanStatus::Malformed);
        }

        [, $encodedPayload, $encodedSignature] = $segments;
        $payloadJson = $this->base64UrlDecode($encodedPayload);
        $signature = $this->base64UrlDecode($encodedSignature);
        if ($payloadJson === null || $signature === null || strlen($signature) !== 32) {
            return $this->inspection(ApplyPlanStatus::Malformed);
        }

        $expectedSignature = hash_hmac(
            'sha256',
            self::VERSION . '.' . $encodedPayload,
            $this->secret,
            true,
        );
        if (!hash_equals($expectedSignature, $signature)) {
            return $this->inspection(ApplyPlanStatus::Malformed);
        }

        try {
            $payload = json_decode($payloadJson, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->inspection(ApplyPlanStatus::Malformed);
        }

        if (!$this->isValidPayload($payload)) {
            return $this->inspection(ApplyPlanStatus::Malformed);
        }
        if ($payload['expiresAt'] <= $this->now()) {
            return $this->inspection(ApplyPlanStatus::Stale, $payload['expiresAt']);
        }

        $status = hash_equals($this->binding($plan), $payload['binding'])
            ? ApplyPlanStatus::Valid
            : ApplyPlanStatus::Stale;

        return $this->inspection($status, $payload['expiresAt']);
    }

    /** @return array{status: ApplyPlanStatus, expiresAt: int} */
    private function inspection(ApplyPlanStatus $status, int $expiresAt = 0): array
    {
        return ['status' => $status, 'expiresAt' => $expiresAt];
    }

    private function binding(ApplyPlan $plan): string
    {
        return hash('sha256', $this->encode([
            'actor' => [
                'type' => $plan->actor->type->value,
                'userId' => $plan->actor->userId,
            ],
            'config' => hash('sha256', $this->encode($plan->config)),
            'kind' => $plan->kind,
            'request' => hash('sha256', $this->encode($plan->request)),
            'targets' => $this->targetsIdentity($plan->targets),
        ]));
    }

    /** @param list<ApplyPlanTarget> $targets */
    private function targetsIdentity(array $targets): string
    {
        $snapshot = array_map(static fn (ApplyPlanTarget $target): array => [
            'fingerprint' => $target->fingerprint,
            'id' => $target->id,
        ], $targets);
        usort($snapshot, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return hash('sha256', $this->encode($snapshot));
    }

    private function encode(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1 || strlen($value) % 4 === 1) {
            return null;
        }

        $padded = str_pad($value, strlen($value) + ((4 - strlen($value) % 4) % 4), '=');
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    private function isValidPayload(mixed $payload): bool
    {
        return is_array($payload)
            && array_keys($payload) === ['binding', 'expiresAt', 'nonce']
            && is_string($payload['binding'])
            && preg_match('/^[a-f0-9]{64}$/D', $payload['binding']) === 1
            && is_int($payload['expiresAt'])
            && is_string($payload['nonce'])
            && $payload['nonce'] !== '';
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }

    private function timestamp(int $value): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $value))->setTimezone(new \DateTimeZone('UTC'));
    }
}

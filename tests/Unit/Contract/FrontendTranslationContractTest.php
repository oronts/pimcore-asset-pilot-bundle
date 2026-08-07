<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Contract;

use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Enum\ConfidenceLevel;
use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Enum\DriftEligibility;
use Oronts\AssetPilotBundle\Enum\HealOutcome;
use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;
use Oronts\AssetPilotBundle\Enum\OperationKind;
use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\UndoHealReason;
use Oronts\AssetPilotBundle\Service\IntegrityHealLog;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the Studio's dynamically translated keys in lockstep with the backend. The frontend
 * translations contract test proves every key in backend-contract.ts is localized in both locales;
 * this test proves backend-contract.ts still lists exactly the real backend values, so adding an
 * OperationStatus case or a built-in health check fails here until the Studio contract catches up.
 */
#[CoversNothing]
final class FrontendTranslationContractTest extends TestCase
{
    /**
     * @return iterable<string, array{string, class-string<\BackedEnum>}>
     */
    public static function backendEnumFamilies(): iterable
    {
        yield 'operation status' => ['OPERATION_STAT_KEYS', OperationStatus::class];
        yield 'duplicate disposition outcome' => ['DISPOSITION_OUTCOME_KEYS', DispositionOutcome::class];
        yield 'operation run status' => ['OPERATION_RUN_STATUS_KEYS', OperationRunStatus::class];
        yield 'operation run item status' => ['OPERATION_RUN_ITEM_STATUS_KEYS', OperationRunItemStatus::class];
        yield 'undo heal reason' => ['UNDO_HEAL_REASON_KEYS', UndoHealReason::class];
        yield 'recovery kind' => ['RECOVERY_KIND_KEYS', OperationKind::class];
        yield 'confidence level' => ['CONFIDENCE_LEVEL_KEYS', ConfidenceLevel::class];
        yield 'delivery outcome' => ['DELIVERY_OUTCOME_KEYS', OperationDeliveryOutcome::class];
        yield 'health status' => ['HEALTH_STATUS_KEYS', HealthStatus::class];
        yield 'heal outcome' => ['HEAL_OUTCOME_KEYS', HealOutcome::class];
        yield 'actor type' => ['ACTOR_TYPE_KEYS', ActorType::class];
        yield 'drift eligibility' => ['DRIFT_ELIGIBILITY_KEYS', DriftEligibility::class];
    }

    /**
     * @param class-string<\BackedEnum> $enumClass
     */
    #[Test]
    #[DataProvider('backendEnumFamilies')]
    public function studioContractMirrorsBackendEnum(string $constName, string $enumClass): void
    {
        $documented = self::tsConstArray($constName);
        $backend = array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enumClass::cases());
        sort($documented);
        sort($backend);

        self::assertSame(
            $backend,
            $documented,
            sprintf('backend-contract.ts %s must list exactly %s::cases() so every value stays localized.', $constName, $enumClass),
        );
    }

    #[Test]
    public function studioHealHistoryStatusKeysMirrorTheLogConstants(): void
    {
        $documented = self::tsConstArray('HEAL_HISTORY_STATUS_KEYS');
        $backend = [IntegrityHealLog::STATUS_HEALED, IntegrityHealLog::STATUS_UNDONE];
        sort($documented);
        sort($backend);

        self::assertSame(
            $backend,
            $documented,
            'backend-contract.ts HEAL_HISTORY_STATUS_KEYS must list exactly the two statuses the heal-history query returns.',
        );
    }

    #[Test]
    public function studioHealthCheckKeysMirrorTheBuiltInChecks(): void
    {
        $documented = self::tsConstArray('BUILTIN_HEALTH_CHECK_KEYS');
        $backend = self::builtInHealthCheckNames();
        sort($documented);
        sort($backend);

        self::assertSame(
            $backend,
            $documented,
            'backend-contract.ts BUILTIN_HEALTH_CHECK_KEYS must list exactly the built-in health-check names so every check stays localized.',
        );
    }

    /** @return list<string> */
    private static function tsConstArray(string $name): array
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/assets/studio/js/src/i18n/backend-contract.ts');
        self::assertIsString($source);
        self::assertSame(1, preg_match('/export const ' . preg_quote($name, '/') . ' = \[(.*?)] as const/s', $source, $block));
        preg_match_all("/'([a-z_]+)'/", $block[1], $values);

        return $values[1];
    }

    /** @return list<string> */
    private static function builtInHealthCheckNames(): array
    {
        $names = [];
        foreach (glob(dirname(__DIR__, 3) . '/src/Health/Check/*HealthCheck.php') ?: [] as $file) {
            $code = file_get_contents($file);
            self::assertIsString($code);
            if (preg_match('/function name\(\): string.*?return \'([a-z_]+)\';/s', $code, $match) === 1) {
                $names[] = $match[1];
            }
        }

        return array_values(array_unique($names));
    }
}

<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Contract;

use Oronts\AssetPilotBundle\Enum\OperationStatus;
use PHPUnit\Framework\Attributes\CoversNothing;
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
    #[Test]
    public function studioOperationStatKeysMirrorTheBackendEnum(): void
    {
        $documented = self::tsConstArray('OPERATION_STAT_KEYS');
        $backend = array_map(static fn (OperationStatus $status): string => $status->value, OperationStatus::cases());
        sort($documented);
        sort($backend);

        self::assertSame(
            $backend,
            $documented,
            'backend-contract.ts OPERATION_STAT_KEYS must list exactly the OperationStatus enum values so every status stays localized.',
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

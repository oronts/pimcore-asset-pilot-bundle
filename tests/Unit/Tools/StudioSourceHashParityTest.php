<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Tools;

use function Oronts\AssetPilotBundle\Tools\computeStudioSourceHash;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/release-manifest-assets.php';

#[CoversNothing]
final class StudioSourceHashParityTest extends TestCase
{
    #[Test]
    public function phpHasherMatchesTheSharedGoldenContractWithTheJsHasher(): void
    {
        // Pins the PHP hasher to the same golden as source-hash.test.mjs; drift on either side fails CI.
        $parity = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/tests/Fixtures/studio-source-hash-parity.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame($parity['sha256'], computeStudioSourceHash($parity['files']));
    }
}

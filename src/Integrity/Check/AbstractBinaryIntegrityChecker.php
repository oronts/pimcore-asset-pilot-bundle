<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Integrity\Check;

use Oronts\AssetPilotBundle\Enum\IntegrityStatus;
use Oronts\AssetPilotBundle\Integrity\IntegrityCheckerInterface;
use Oronts\AssetPilotBundle\Model\IntegrityResult;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Base for checkers that need the raw binary: check() reads the live asset's stream into a string
 * (a missing/empty binary is Broken without further work) and delegates to checkBinary(), which the
 * same code path uses to test a historical version's bytes during a heal.
 *
 * verifyWithImagick() is the shared decode path. Its one job beyond decoding is to keep the
 * Renderable | Broken | Unverifiable invariant safe: a missing tool, an unsupported format in this
 * build, a failed verifier init, or a policy/delegate/resource error during decode are all
 * Unverifiable, never Broken — so a tool that is absent or restricted can never read as a corrupt
 * asset (which would later drive a destructive heal). Only a genuine decode failure is Broken.
 */
abstract class AbstractBinaryIntegrityChecker implements IntegrityCheckerInterface
{
    /**
     * Decode failures that come from the tool or its configuration, never from the asset bytes: an
     * ImageMagick security-policy denial (the common PDF policy.xml case), a delegate/Ghostscript
     * invocation failure, or a resource-limit failure. Matched case-insensitively against the
     * exception message. "no decode delegate" is deliberately absent: raw garbage produces it too,
     * so genuinely-unsupported formats are caught earlier by queryFormats() instead.
     */
    private const array TOOL_FAILURE_MARKERS = [
        'not authorized',
        'security policy',
        'failedtoexecutecommand',
        'delegate failed',
        'postscriptdelegatefailed',
        'ghostscript',
        'unable to create temporary file',
        'cache resources exhausted',
    ];

    /** @var array<string, bool> per-extension format-support cache (ImageMagick's format set is process-stable) */
    private array $formatSupport = [];

    public function __construct(
        protected readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function check(Asset $asset): IntegrityResult
    {
        $stream = $asset->getStream();
        if (!is_resource($stream)) {
            return new IntegrityResult(IntegrityStatus::Broken, $this->name(), 'Asset has no readable binary.');
        }

        try {
            $binary = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        if (!is_string($binary) || $binary === '') {
            return new IntegrityResult(IntegrityStatus::Broken, $this->name(), 'Asset binary is empty.');
        }

        return $this->checkBinary($binary, strtolower(pathinfo((string) $asset->getFilename(), PATHINFO_EXTENSION)));
    }

    abstract protected function name(): string;

    /**
     * Decode $binary with Imagick via $decode (which returns the frame/page count) and map the
     * outcome to a verdict. Tool/infrastructure failures are Unverifiable; a genuine decode failure
     * is Broken with the given generic reason (the underlying error is logged, never returned, since
     * the reason is surfaced over the REST API).
     *
     * @param callable(\Imagick, string): int $decode
     */
    protected function verifyWithImagick(string $binary, string $extension, callable $decode, string $brokenReason): IntegrityResult
    {
        if (!$this->imagickAvailable()) {
            return new IntegrityResult(IntegrityStatus::Unverifiable, $this->name(), 'Imagick is not available to verify this asset.');
        }

        if (!$this->formatSupported($extension)) {
            return new IntegrityResult(IntegrityStatus::Unverifiable, $this->name(), 'Imagick cannot verify this format in the current environment.');
        }

        try {
            $imagick = $this->newImagick();
        } catch (\Throwable $e) {
            $this->logger->warning('Integrity verifier could not be initialized.', ['checker' => $this->name(), 'exception' => $e]);

            return new IntegrityResult(IntegrityStatus::Unverifiable, $this->name(), 'The verifier could not be initialized.');
        }

        try {
            $count = $decode($imagick, $binary);
        } catch (\Throwable $e) {
            if ($this->isToolFailure($e)) {
                $this->logger->warning('Integrity verifier hit a tool/policy error; reporting Unverifiable.', ['checker' => $this->name(), 'exception' => $e]);

                return new IntegrityResult(IntegrityStatus::Unverifiable, $this->name(), 'The asset could not be verified due to a tool or policy error.');
            }

            $this->logger->info('Integrity verifier could not decode the asset binary.', ['checker' => $this->name(), 'exception' => $e]);

            return new IntegrityResult(IntegrityStatus::Broken, $this->name(), $brokenReason);
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }

        return $count >= 1
            ? new IntegrityResult(IntegrityStatus::Renderable, $this->name())
            : new IntegrityResult(IntegrityStatus::Broken, $this->name(), $brokenReason);
    }

    protected function imagickAvailable(): bool
    {
        return extension_loaded('imagick');
    }

    protected function newImagick(): \Imagick
    {
        return new \Imagick();
    }

    /**
     * @return list<string>
     */
    protected function queryFormats(string $pattern): array
    {
        return \Imagick::queryFormats($pattern);
    }

    private function formatSupported(string $extension): bool
    {
        if ($extension === '') {
            return true;
        }

        $key = strtoupper($extension);
        if (!array_key_exists($key, $this->formatSupport)) {
            try {
                $this->formatSupport[$key] = $this->queryFormats($key) !== [];
            } catch (\Throwable) {
                $this->formatSupport[$key] = true;
            }
        }

        return $this->formatSupport[$key];
    }

    private function isToolFailure(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        foreach (self::TOOL_FAILURE_MARKERS as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }

        return false;
    }
}

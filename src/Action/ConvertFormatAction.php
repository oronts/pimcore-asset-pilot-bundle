<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Action;

use Oronts\AssetPilotBundle\Service\Conversion\AssetConverterResolverInterface;
use Oronts\AssetPilotBundle\Service\Conversion\FormatName;
use Oronts\AssetPilotBundle\Service\LoopGuardedAssetSaver;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\LoggerInterface;

/**
 * Post-move rule action that re-encodes an image asset to a target format (png, jpeg, gif, webp by
 * default) through the pluggable converter seam. It is deliberately best-effort: a non-image asset, an
 * asset already in the target format, no available converter, or a converter that cannot decode the
 * source all skip silently so a missing binary never fails the organize. The re-encode and filename
 * extension change run under the shared loop-guarded saver so they do not re-trigger organization.
 */
class ConvertFormatAction implements RuleActionInterface, RuleActionConfigValidatorInterface
{
    public function __construct(
        protected readonly AssetConverterResolverInterface $converters,
        protected readonly LoopGuardedAssetSaver $assetSaver,
        protected readonly LoggerInterface $logger,
    ) {}

    public function getType(): string
    {
        return 'convert_format';
    }

    public function prepare(Asset $asset, AbstractObject $object, array $config): array
    {
        unset($asset, $object);
        $format = FormatName::normalize((string) ($config['format'] ?? ''));
        if ($format === '') {
            throw new \InvalidArgumentException('The convert_format action requires a "format".');
        }
        // Re-enforce the whitelist at prepare time (not only in validateConfig) so a persisted rule can
        // never carry an unsanitized token into the filename the payload later drives.
        if (preg_match('/^[a-z0-9]{2,8}$/', $format) !== 1) {
            throw new \InvalidArgumentException('The convert_format action "format" must be a short alphanumeric image format such as png, jpeg, gif, or webp.');
        }

        $options = is_array($config['options'] ?? null) ? $config['options'] : [];
        if (array_key_exists('quality', $config)) {
            $options['quality'] = (int) $config['quality'];
        }

        return ['format' => $format, 'options' => $options];
    }

    public function applyPrepared(Asset $asset, array $payload, RuleActionDeliveryContextInterface $delivery): void
    {
        $format = FormatName::normalize((string) ($payload['format'] ?? ''));
        if ($format === '' || !$asset instanceof Asset\Image) {
            return;
        }

        $currentExtension = FormatName::normalize((string) pathinfo((string) $asset->getFilename(), PATHINFO_EXTENSION));
        if ($currentExtension === $format) {
            return;
        }

        $converter = $this->converters->resolve($format);
        if ($converter === null) {
            $this->logger->warning('Asset Pilot: no available converter for format {format}; leaving asset {id} unchanged', [
                'format' => $format,
                'id' => $asset->getId(),
            ]);

            return;
        }

        $delivery->heartbeat();
        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
        try {
            $encoded = $converter->convert($asset, $format, $options);
        } catch (\Throwable $exception) {
            // A custom converter (Imagick, vips, external binary) may throw on a corrupt or unreadable
            // source. Contain it so a conversion never fails the organize, exactly as a null return does.
            $this->logger->warning('Asset Pilot: converter threw re-encoding asset {id} to {format} ({error}); leaving it unchanged', [
                'id' => $asset->getId(),
                'format' => $format,
                'error' => $exception->getMessage(),
            ]);

            return;
        }
        if ($encoded === null) {
            $this->logger->warning('Asset Pilot: converter could not re-encode asset {id} to {format}; leaving it unchanged', [
                'id' => $asset->getId(),
                'format' => $format,
            ]);

            return;
        }

        $newFilename = $this->retargetExtension((string) $asset->getFilename(), FormatName::extension($format));
        if (!$this->targetFilenameIsFree($asset, $newFilename)) {
            // A different sibling already owns the retargeted key; renaming would make Pimcore throw a
            // duplicate-path error and fail the organize, so skip rather than clobber or crash.
            $this->logger->warning('Asset Pilot: cannot convert asset {id}; another asset already occupies {name} in the same folder', [
                'id' => $asset->getId(),
                'name' => $newFilename,
            ]);

            return;
        }

        $this->assetSaver->save($asset, static function (Asset $target) use ($encoded, $newFilename): void {
            $target->setData($encoded);
            $target->setFilename($newFilename);
        });
    }

    /** Whether $filename is free in the asset's folder (nothing there, or only this asset itself). */
    protected function targetFilenameIsFree(Asset $asset, string $filename): bool
    {
        $parent = $asset->getParent();
        $parentPath = $parent instanceof Asset ? rtrim((string) $parent->getRealFullPath(), '/') : '';
        $existing = Asset::getByPath($parentPath . '/' . $filename);

        return !$existing instanceof Asset || (int) $existing->getId() === (int) $asset->getId();
    }

    public function validateConfig(array $config): array
    {
        $errors = [];
        $format = FormatName::normalize((string) ($config['format'] ?? ''));
        if ($format === '') {
            $errors[] = 'requires a non-empty "format"';
        } elseif (preg_match('/^[a-z0-9]{2,8}$/', $format) !== 1) {
            $errors[] = 'format must be a short alphanumeric image format such as png, jpeg, gif, or webp';
        }
        if (array_key_exists('quality', $config) && (!is_numeric($config['quality']) || (int) $config['quality'] < 1 || (int) $config['quality'] > 100)) {
            $errors[] = 'quality must be an integer between 1 and 100';
        }

        return $errors;
    }

    protected function retargetExtension(string $filename, string $extension): string
    {
        $base = preg_replace('/\.[^.]+$/', '', $filename);

        return ($base === '' || $base === null ? $filename : $base) . '.' . $extension;
    }
}

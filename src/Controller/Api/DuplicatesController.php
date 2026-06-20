<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Controller\Api\Support\StreamsCsv;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Service\AssetSearchServiceInterface;
use Oronts\AssetPilotBundle\Service\DuplicateDetectionService;
use Oronts\AssetPilotBundle\Service\DuplicateMergeService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DuplicatesController
{
    use StreamsCsv;

    private const int MAX_LIMIT = 100;
    private const int EXPORT_PAGE = 200;

    public function __construct(
        protected readonly DuplicateDetectionService $duplicates,
        protected readonly DuplicateMergeService $merge,
        protected readonly AssetSearchServiceInterface $assets,
        protected readonly LoggerInterface $logger,
    ) {}

    /**
     * Read-only report from the content-hash index. It never scans/hashes (that is the
     * asset-pilot:find-duplicates --scan command), so a request cannot block.
     */
    #[Route('/duplicates', name: 'oronts_asset_pilot_duplicates', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(self::MAX_LIMIT, max(1, $request->query->getInt('limit', 50)));

        try {
            $groups = $this->duplicates->findDuplicates($page, $limit);
            $reps = $this->assets->summarize(array_values(array_filter(
                array_map(static fn ($group): ?int => $group->assetIds[0] ?? null, $groups),
            )));

            return new JsonResponse([
                'items' => array_map(static function ($group) use ($reps): array {
                    $repId = $group->assetIds[0] ?? null;
                    $rep = $repId !== null ? ($reps[$repId] ?? null) : null;

                    return [
                        'checksum' => $group->checksum,
                        'fileSize' => $group->fileSize,
                        'count' => $group->count,
                        'assetIds' => $group->assetIds,
                        'representative' => $rep === null ? null : [
                            'id' => (int) $rep['id'],
                            'filename' => $rep['filename'],
                            'fullPath' => $rep['full_path'],
                            'fileSize' => (int) $rep['file_size'],
                            'type' => $rep['type'],
                        ],
                    ];
                }, $groups),
                'total' => $this->duplicates->countDuplicateGroups(),
                'page' => $page,
                'limit' => $limit,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list duplicate assets.', ['exception' => $e]);

            return new JsonResponse(['error' => 'Failed to list duplicate assets.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Stream the whole duplicate report as CSV (checksum, copies, size, wasted bytes, asset ids), paged
     * lazily so the export stays memory-flat regardless of how many groups exist.
     */
    #[Route('/duplicates/export', name: 'oronts_asset_pilot_duplicates_export', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function export(): StreamedResponse
    {
        $rows = (function (): \Generator {
            $page = 1;
            do {
                $groups = $this->duplicates->findDuplicates($page, self::EXPORT_PAGE);
                foreach ($groups as $group) {
                    yield [
                        $group->checksum,
                        $group->count,
                        $group->fileSize,
                        $group->fileSize * max(0, $group->count - 1),
                        implode(';', $group->assetIds),
                    ];
                }
                ++$page;
            } while (count($groups) === self::EXPORT_PAGE);
        })();

        return $this->streamCsv(
            'asset-pilot-duplicates-' . date('Y-m-d') . '.csv',
            ['Checksum', 'Copies', 'File Size (bytes)', 'Wasted (bytes)', 'Asset IDs'],
            $rows,
        );
    }

    /**
     * The selectable merge strategies (built-in plus any tagged custom ones) and the configured
     * default, so the UI can offer them without hard-coding the list.
     */
    #[Route('/duplicates/strategies', name: 'oronts_asset_pilot_duplicates_strategies', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function strategies(): JsonResponse
    {
        return new JsonResponse([
            'strategies' => $this->merge->availableStrategies(),
            'default' => $this->merge->defaultStrategyName(),
        ]);
    }

    /**
     * Consolidate one duplicate group (by content hash) onto a canonical asset and dispose of the
     * copies via the configured (or requested) strategy. Destructive, so Admin-only; pass
     * "dryRun": true to preview the plan without repointing or disposing anything.
     */
    #[Route('/duplicates/merge', name: 'oronts_asset_pilot_duplicates_merge', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Admin->value)]
    public function merge(Request $request): JsonResponse
    {
        $data = json_decode((string) ($request->getContent() ?: '{}'), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'A JSON body is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $checksum = is_string($data['checksum'] ?? null) ? $data['checksum'] : '';
        if ($checksum === '') {
            return new JsonResponse(['error' => 'A checksum is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $canonicalId = isset($data['canonicalId']) ? (int) $data['canonicalId'] : null;
        $strategy = is_string($data['strategy'] ?? null) ? $data['strategy'] : null;
        $dryRun = ($data['dryRun'] ?? false) === true;

        $group = $this->duplicates->groupForChecksum($checksum);
        if ($group === null) {
            return new JsonResponse(['error' => 'No duplicate group with at least two live assets for that checksum.'], JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $outcome = $this->merge->merge($group, $canonicalId, $strategy, $dryRun);

            return new JsonResponse([
                'checksum' => $outcome->checksum,
                'canonicalId' => $outcome->canonicalId,
                'dryRun' => $dryRun,
                'dispositions' => array_map(static fn ($disposition): array => [
                    'copyId' => $disposition->copyId,
                    'outcome' => $disposition->outcome->value,
                    'reason' => $disposition->reason,
                ], $outcome->dispositions),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to merge duplicates.', ['exception' => $e]);

            return new JsonResponse(['error' => 'Failed to merge duplicates.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

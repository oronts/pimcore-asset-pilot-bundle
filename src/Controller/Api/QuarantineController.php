<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Api\Serialization\ApiDateFormatterInterface;
use Oronts\AssetPilotBundle\Controller\Api\Support\StreamsCsv;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Exception\NotPermittedException;
use Oronts\AssetPilotBundle\Service\QuarantineServiceInterface;
use Oronts\AssetPilotBundle\Service\Query\Pagination;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class QuarantineController
{
    use StreamsCsv;


    public function __construct(
        protected readonly QuarantineServiceInterface $quarantineService,
        protected readonly LoggerInterface $logger,
        private readonly ApiDateFormatterInterface $dates,
    ) {}

    #[Route('/quarantine', name: 'oronts_asset_pilot_quarantine_list', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function list(Request $request): JsonResponse
    {
        [$page, $limit] = Pagination::fromRequest($request, 200);
        $result = $this->quarantineService->listQuarantined($page, $limit, $this->filters($request));
        $result['items'] = array_map(fn (array $item): array => [
            ...$item,
            'quarantined_at' => $this->dates->fromDatabase((string) $item['quarantined_at']),
        ], $result['items']);

        return new JsonResponse($result);
    }

    /** @return array{type?: string, before?: string, after?: string} */
    private function filters(Request $request): array
    {
        return array_filter([
            'type' => $request->query->get('type'),
            'before' => $request->query->get('before'),
            'after' => $request->query->get('after'),
        ], static fn ($v): bool => is_string($v) && $v !== '');
    }

    /**
     * Stream the quarantine list as CSV (asset id, filename, original path, type, quarantined-at).
     */
    #[Route('/quarantine/export', name: 'oronts_asset_pilot_quarantine_export', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);

        $rows = (function () use ($filters): \Generator {
            $source = $this->quarantineService->iterateForExport($filters);
            foreach ($source as $item) {
                yield [
                    $item['asset_id'] ?? '',
                    $item['filename'] ?? '',
                    $item['original_path'] ?? '',
                    $item['type'] ?? '',
                    $item['quarantined_at'] ?? '',
                ];
            }

            return $source->getReturn();
        })();

        return $this->streamCsv(
            'asset-pilot-quarantine-' . date('Y-m-d') . '.csv',
            ['Asset ID', 'Filename', 'Original Path', 'Type', 'Quarantined At'],
            $rows,
        );
    }

    #[Route('/quarantine/{assetId}/restore', name: 'oronts_asset_pilot_quarantine_restore', methods: ['POST'], requirements: ['assetId' => '\d+'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function restore(int $assetId): JsonResponse
    {
        try {
            if (!$this->quarantineService->restore($assetId)) {
                return new JsonResponse(['error' => 'Asset is not quarantined or no longer exists.'], JsonResponse::HTTP_NOT_FOUND);
            }

            return new JsonResponse(['message' => 'Asset restored.', 'assetId' => $assetId]);
        } catch (NotPermittedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to restore asset {id} from quarantine.', ['id' => $assetId, 'exception' => $e]);

            return new JsonResponse(['error' => 'Failed to restore the asset.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

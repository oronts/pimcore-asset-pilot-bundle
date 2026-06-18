<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Service\DuplicateDetectionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DuplicatesController
{
    private const int MAX_LIMIT = 100;

    public function __construct(
        protected readonly DuplicateDetectionService $duplicates,
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

            return new JsonResponse([
                'items' => array_map(static fn ($group): array => [
                    'checksum' => $group->checksum,
                    'fileSize' => $group->fileSize,
                    'count' => $group->count,
                    'assetIds' => $group->assetIds,
                ], $groups),
                'total' => $this->duplicates->countDuplicateGroups(),
                'page' => $page,
                'limit' => $limit,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list duplicate assets.', ['exception' => $e]);

            return new JsonResponse(['error' => 'Failed to list duplicate assets.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

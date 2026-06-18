<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Controller\Api\Support\BulkIds;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Service\EmptyFolderSweepService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class FoldersController
{
    private const int MAX_DELETE = 200;

    public function __construct(
        protected readonly EmptyFolderSweepService $sweep,
        protected readonly LoggerInterface $logger,
    ) {}

    #[Route('/folders/empty', name: 'oronts_asset_pilot_folders_empty', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function empty(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(self::MAX_DELETE, max(1, $request->query->getInt('limit', 50)));
        $folder = $request->query->get('folder');

        try {
            return new JsonResponse($this->sweep->findEmpty($folder !== null && $folder !== '' ? (string) $folder : null, $page, $limit));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list empty folders.', ['exception' => $e]);

            return new JsonResponse(['error' => 'Failed to list empty folders.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/folders/empty/delete', name: 'oronts_asset_pilot_folders_empty_delete', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function deleteEmpty(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return new JsonResponse(['error' => 'Request body must be a JSON object.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        // Strict positive-int parse (rejects "5abc", dedupes), shared with the other bulk endpoints.
        $ids = BulkIds::clean($body['ids'] ?? null);
        if ($ids === []) {
            return new JsonResponse(['error' => 'ids must list one or more positive folder ids.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if (count($ids) > self::MAX_DELETE) {
            return new JsonResponse(['error' => sprintf('Too many ids; delete at most %d at once.', self::MAX_DELETE)], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            return new JsonResponse($this->sweep->deleteEmpty($ids));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete empty folders.', ['exception' => $e]);

            return new JsonResponse(['error' => 'Failed to delete empty folders.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

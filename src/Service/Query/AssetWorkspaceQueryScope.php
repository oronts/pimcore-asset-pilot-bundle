<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Security\ActorContextProvider;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;

class AssetWorkspaceQueryScope
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ElementAuthorizationInterface $authorization,
        private readonly ActorContextProvider $actors,
    ) {}

    /**
     * True only for the supervised System actor, which is the sole context allowed to skip native
     * per-row authorization and trust the raw SQL total. An admin is deliberately excluded: Pimcore's
     * {@see \Pimcore\Model\Element\AbstractElement::isAllowed()} evaluates workflow denial even for an
     * admin (it grants admin only when the workflow did not deny the operation), so an admin's SQL count
     * is not the native truth. Admin and scoped users both go through the authorized pager's native
     * per-row filter, which is why the SQL total is withheld from them.
     */
    public function bypassesNativeAuthorization(): bool
    {
        return $this->authorization->currentActor()->type === ActorType::System;
    }

    public function applyView(QueryBuilder $query, string $assetAlias = 'a', string $parameterPrefix = 'assetWorkspace'): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $assetAlias) !== 1
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $parameterPrefix) !== 1) {
            throw new \InvalidArgumentException('Workspace query aliases must be SQL identifiers.');
        }

        $actor = $this->authorization->currentActor();
        if ($actor->type === ActorType::System) {
            return;
        }

        $user = $actor->type === ActorType::User ? $this->actors->resolveUser($actor) : null;
        if ($user === null || !$user->isAllowed('assets')) {
            $query->andWhere('1 = 0');

            return;
        }
        if ($user->isAdmin()) {
            return;
        }

        $userIds = array_values(array_unique([
            ...array_map('intval', $user->getRoles()),
            (int) $user->getId(),
        ]));
        $actorParameter = $parameterPrefix . 'ActorId';
        $usersParameter = $parameterPrefix . 'UserIds';
        $allowedAlias = $parameterPrefix . 'Allowed';
        $closerAlias = $parameterPrefix . 'Closer';
        $fullPath = $this->connection->getDatabasePlatform()->getConcatExpression("$assetAlias.path", "$assetAlias.filename");
        $allowedPath = $this->pathMatch($fullPath, $allowedAlias);
        $closerPath = $this->pathMatch($fullPath, $closerAlias);

        $query->andWhere(<<<SQL
            EXISTS (
                SELECT 1 FROM users_workspaces_asset $allowedAlias
                WHERE $allowedAlias.userId IN (:$usersParameter)
                  AND $allowedAlias.view = 1
                  AND $allowedPath
                  AND NOT EXISTS (
                      SELECT 1 FROM users_workspaces_asset $closerAlias
                      WHERE $closerAlias.userId IN (:$usersParameter)
                        AND $closerPath
                        AND (
                            LENGTH($closerAlias.cpath) > LENGTH($allowedAlias.cpath)
                            OR (
                                $closerAlias.cpath = $allowedAlias.cpath
                                AND $closerAlias.userId = :$actorParameter
                                AND $allowedAlias.userId <> :$actorParameter
                            )
                        )
                  )
            )
            SQL)
            ->setParameter($actorParameter, (int) $user->getId())
            ->setParameter($usersParameter, $userIds, ArrayParameterType::INTEGER);
    }

    private function pathMatch(string $assetPath, string $workspaceAlias): string
    {
        $childPrefix = $this->connection->getDatabasePlatform()->getConcatExpression("$workspaceAlias.cpath", "'/'");

        return "($workspaceAlias.cpath = '/' OR $assetPath = $workspaceAlias.cpath OR SUBSTRING($assetPath, 1, LENGTH($workspaceAlias.cpath) + 1) = $childPrefix)";
    }
}

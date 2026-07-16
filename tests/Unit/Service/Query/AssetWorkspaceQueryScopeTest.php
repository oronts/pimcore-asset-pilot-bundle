<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service\Query;

use Doctrine\DBAL\DriverManager;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Security\ActorContextProvider;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\Query\AssetWorkspaceQueryScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\User;

#[CoversClass(AssetWorkspaceQueryScope::class)]
final class AssetWorkspaceQueryScopeTest extends TestCase
{
    #[Test]
    public function systemActorLeavesTheQueryUnrestricted(): void
    {
        [$scope, $query] = $this->scope(ActorContext::system());

        $scope->applyView($query);

        self::assertStringNotContainsString('users_workspaces_asset', $query->getSQL());
    }

    #[Test]
    public function anonymousActorFailsClosed(): void
    {
        [$scope, $query] = $this->scope(ActorContext::anonymous());

        $scope->applyView($query);

        self::assertStringContainsString('1 = 0', $query->getSQL());
    }

    #[Test]
    public function userScopeAppliesNearestWorkspaceAndDirectUserPrecedence(): void
    {
        $user = (new User())
            ->setId(7)
            ->setActive(true)
            ->setAdmin(false)
            ->setRoles([4, 5])
            ->setPermissions(['assets']);
        [$scope, $query] = $this->scope(ActorContext::user(7), $user);

        $scope->applyView($query);

        $sql = $query->getSQL();
        self::assertStringContainsString('users_workspaces_asset', $sql);
        self::assertStringContainsString('assetWorkspaceAllowed.view = 1', $sql);
        self::assertStringContainsString('LENGTH(assetWorkspaceCloser.cpath) > LENGTH(assetWorkspaceAllowed.cpath)', $sql);
        self::assertStringContainsString('assetWorkspaceCloser.userId = :assetWorkspaceActorId', $sql);
        self::assertSame(7, $query->getParameter('assetWorkspaceActorId'));
        self::assertSame([4, 5, 7], $query->getParameter('assetWorkspaceUserIds'));
    }

    /** @return array{AssetWorkspaceQueryScope, \Doctrine\DBAL\Query\QueryBuilder} */
    private function scope(ActorContext $actor, ?User $user = null): array
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => '127.0.0.1',
            'dbname' => 'x',
            'user' => 'x',
            'password' => 'x',
            'serverVersion' => '8.0.0',
        ]);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn($actor);
        $actors = $this->createMock(ActorContextProvider::class);
        $actors->method('resolveUser')->willReturn($user);

        return [
            new AssetWorkspaceQueryScope($connection, $authorization, $actors),
            $connection->createQueryBuilder()->select('a.id')->from('assets', 'a'),
        ];
    }
}

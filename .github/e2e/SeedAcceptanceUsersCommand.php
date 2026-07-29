<?php

declare(strict_types=1);

namespace App\Command;

use Pimcore\Model\Asset\Folder;
use Pimcore\Model\User;
use Pimcore\Model\User\Workspace\Asset as AssetWorkspace;
use Pimcore\Tool\Authentication;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * E2E acceptance scaffolding, copied into the CI Pimcore application by .github/workflows/e2e.yml.
 * It is NOT part of the shipped bundle (that is why it lives under .github/e2e and uses the App
 * namespace of the skeleton).
 *
 * Seeds the Asset Pilot role matrix (docs/e2e-acceptance.md rows 4-7): an Operate user and a View
 * user, each on its own disjoint asset workspace, so both the permission gate (layer 1) and the
 * element/workspace authorization (layer 2) can be exercised in a browser. The Admin role uses the
 * Pimcore install admin, so the workflow points E2E_ADMIN_USER/PASS at that account. Idempotent:
 * re-running replaces the users and reuses the folders. Passwords come from the environment
 * (E2E_OPERATE_PASS / E2E_VIEW_PASS) so nothing is hard-coded.
 */
#[AsCommand(name: 'app:asset-pilot:seed-acceptance-users')]
final class SeedAcceptanceUsersCommand extends Command
{
    /** @var array<string, array{perms: list<string>, folder: string, passEnv: string}> */
    private const USERS = [
        'e2e-operate' => ['perms' => ['asset_pilot_view', 'asset_pilot_operate'], 'folder' => '/workspace-operate', 'passEnv' => 'E2E_OPERATE_PASS'],
        'e2e-view' => ['perms' => ['asset_pilot_view'], 'folder' => '/workspace-view', 'passEnv' => 'E2E_VIEW_PASS'],
    ];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Resolve and validate EVERY password before mutating anything: a missing one must not delete an
        // existing account and then fail, leaving a half-seeded matrix.
        $passwords = [];
        foreach (self::USERS as $name => $spec) {
            $password = (string) ($_SERVER[$spec['passEnv']] ?? $_ENV[$spec['passEnv']] ?? '');
            if ($password === '') {
                $output->writeln(sprintf('<error>%s is not set; refusing to seed %s with an empty password.</error>', $spec['passEnv'], $name));

                return Command::FAILURE;
            }
            $passwords[$name] = $password;
        }

        foreach (self::USERS as $name => $spec) {
            $folder = $this->ensureFolder($spec['folder']);

            $existing = User::getByName($name);
            if ($existing instanceof User) {
                $existing->delete();
            }

            $user = new User();
            $user->setParentId(0);
            $user->setName($name);
            $user->setPassword(Authentication::getPasswordHash($name, $passwords[$name]));
            $user->setActive(true);
            $user->setAdmin(false);
            foreach ($spec['perms'] as $perm) {
                $user->setPermission($perm, true);
            }

            // Disjoint asset workspace: rights on this user's own folder only, so an out-of-workspace
            // asset is rejected by the element/workspace authorization even when the flat permission passes.
            $workspace = new AssetWorkspace();
            $workspace->setCid($folder->getId());
            $workspace->setCpath($folder->getRealFullPath());
            $workspace->setList(true);
            $workspace->setView(true);
            $workspace->setPublish(true);
            $workspace->setDelete(true);
            $user->setWorkspacesAsset([$workspace]);

            $user->save();

            $output->writeln(sprintf(
                'seeded %s (id %d) perms=%s workspace=%s',
                $name,
                $user->getId(),
                implode(',', $spec['perms']),
                $folder->getRealFullPath(),
            ));
        }

        return Command::SUCCESS;
    }

    private function ensureFolder(string $path): Folder
    {
        $existing = Folder::getByPath($path);
        if ($existing instanceof Folder) {
            return $existing;
        }

        $folder = new Folder();
        $folder->setParentId(1);
        $folder->setKey(ltrim($path, '/'));
        $folder->save();

        return $folder;
    }
}

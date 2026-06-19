<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Enforces the bundle-wide invariant (CLAUDE.md non-negotiable): every routed Studio API action
 * carries an #[IsGranted] check, at the method or the class level. A new endpoint that forgets the
 * attribute fails here instead of shipping an unguarded route.
 */
class RouteAuthorizationTest extends TestCase
{
    #[Test]
    public function everyRoutedApiActionCarriesIsGranted(): void
    {
        $unguarded = [];
        $inspected = [];

        foreach ($this->apiControllerClasses() as $class) {
            $reflection = new \ReflectionClass($class);
            $classGuarded = $reflection->getAttributes(IsGranted::class) !== [];

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class || $method->getAttributes(Route::class) === []) {
                    continue;
                }
                $inspected[$class] = true;
                if (!$classGuarded && $method->getAttributes(IsGranted::class) === []) {
                    $unguarded[] = $class . '::' . $method->getName();
                }
            }
        }

        self::assertSame([], $unguarded, 'unguarded routed actions: ' . implode(', ', $unguarded));

        // Guard against a silent autoload failure that would make the loop above inspect nothing:
        // the core controllers must have been reached.
        foreach (['DashboardController', 'DuplicatesController', 'UnusedAssetsController', 'AuditController'] as $core) {
            self::assertArrayHasKey(
                'Oronts\\AssetPilotBundle\\Controller\\Api\\' . $core,
                $inspected,
                $core . ' was not inspected (autoload or discovery gap)',
            );
        }
    }

    /**
     * Every concrete class under src/Controller/Api (recursively), so a future nested controller is
     * covered too.
     *
     * @return list<class-string>
     */
    private function apiControllerClasses(): array
    {
        $root = realpath(__DIR__ . '/../../../src/Controller/Api');
        self::assertIsString($root, 'API controller directory not found');

        $classes = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr((string) $file->getPathname(), strlen($root) + 1, -4);
            $class = 'Oronts\\AssetPilotBundle\\Controller\\Api\\' . str_replace('/', '\\', $relative);
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}

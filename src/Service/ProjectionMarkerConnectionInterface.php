<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * Supplies the connection the dependency projection must use to publish its dirty marker, its edges, and to
 * read the deletion fence. When a consumer wraps an element save in its own outer transaction, the primary
 * connection cannot publish those rows until the consumer commits, and its fence read sees a stale snapshot
 * (REPEATABLE READ). Routing them onto a dedicated autocommit connection keeps the deletion-fence handshake
 * correct without rejecting the consumer's transaction. Non-final so a consumer can supply its own policy.
 */
interface ProjectionMarkerConnectionInterface
{
    public function forMarker(): Connection;

    public function publicationIsDeferred(): bool;
}

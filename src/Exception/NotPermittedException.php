<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Exception;

/**
 * Thrown when a request passes the endpoint permission but the underlying Pimcore element ACL refuses
 * the action (e.g. no publish/create right on the target). Lets a controller answer 403 rather than
 * collapsing an authorization refusal into a generic 500.
 */
class NotPermittedException extends \RuntimeException {}

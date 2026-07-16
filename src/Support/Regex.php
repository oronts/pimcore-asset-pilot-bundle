<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Support;

final class Regex
{
    public static function matches(string $pattern, string $subject): bool
    {
        $result = @preg_match($pattern, $subject);
        if ($result === false) {
            throw new \InvalidArgumentException(sprintf('Invalid regular expression "%s": %s', $pattern, preg_last_error_msg()));
        }

        return $result === 1;
    }

    public static function assertValid(string $pattern): void
    {
        self::matches($pattern, '');
    }
}

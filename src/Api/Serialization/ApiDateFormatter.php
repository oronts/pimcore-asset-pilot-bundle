<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Api\Serialization;

class ApiDateFormatter implements ApiDateFormatterInterface
{
    public function fromDatabase(?string $databaseValue): ?string
    {
        if ($databaseValue === null || $databaseValue === '') {
            return null;
        }

        // "!" resets every field to the epoch so no ambient-time component bleeds into a partial value,
        // and the explicit UTC zone fixes the interpretation of the timezone-free stored grammar.
        $instant = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $databaseValue, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($instant === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \UnexpectedValueException(sprintf(
                'Cannot serialize stored timestamp "%s": expected a UTC "Y-m-d H:i:s" value.',
                $databaseValue,
            ));
        }

        return $instant->format(\DateTimeInterface::ATOM);
    }

    public function fromInstant(?\DateTimeInterface $instant): ?string
    {
        if ($instant === null) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface($instant)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(\DateTimeInterface::ATOM);
    }
}

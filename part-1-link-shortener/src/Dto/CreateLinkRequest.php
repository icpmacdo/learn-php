<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The validated shape of a POST /links body.
 *
 * Validation lives on this small DTO, not on the entity: input validation is
 * its own layer, and the entity never holds invalid state to begin with.
 */
final class CreateLinkRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Url(protocols: ['http', 'https'], requireTld: true)]
        #[Assert\Length(max: 2048)]
        public readonly string $url,
    ) {
    }
}

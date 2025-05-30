<?php

declare(strict_types=1);

namespace App\HttpCache;

use ApiPlatform\HttpCache\PurgerInterface;
use Override;

class TestPurger implements PurgerInterface
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    public function purge(array $iris): void
    {
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getResponseHeaders(array $iris): array
    {
        return [];
    }
}

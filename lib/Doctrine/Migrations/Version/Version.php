<?php

declare(strict_types=1);

namespace Doctrine\Migrations\Version;

class Version
{
    private $version;

    public function __construct(string $version)
    {
        $this->version = $version;
    }

    public function __toString()
    {
        return (string) $this->version;
    }
}

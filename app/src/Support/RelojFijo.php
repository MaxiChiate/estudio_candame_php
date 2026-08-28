<?php

declare(strict_types=1);

namespace EstudioCandame\Support;

use DateTimeImmutable;

final class RelojFijo implements Reloj
{
    public function __construct(private readonly DateTimeImmutable $momento)
    {
    }

    public function ahora(): DateTimeImmutable
    {
        return $this->momento;
    }
}

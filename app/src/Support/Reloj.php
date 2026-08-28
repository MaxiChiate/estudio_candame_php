<?php

declare(strict_types=1);

namespace EstudioCandame\Support;

use DateTimeImmutable;

interface Reloj
{
    public function ahora(): DateTimeImmutable;
}

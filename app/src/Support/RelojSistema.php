<?php

declare(strict_types=1);

namespace EstudioCandame\Support;

use DateTimeImmutable;

final class RelojSistema implements Reloj
{
    public function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}

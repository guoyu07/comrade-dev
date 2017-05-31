<?php

namespace App\Infra;

use Ramsey\Uuid\Uuid as RamseyUuid;
use Rhumsaa\Uuid\Uuid as RhumsaaUuid;

class Uuid
{
    /**
     * @return string
     */
    public static function generate()
    {
        if (class_exists(RamseyUuid::class)) {
            return RamseyUuid::uuid4()->toString();
        }

        return RhumsaaUuid::uuid4()->toString();
    }
}

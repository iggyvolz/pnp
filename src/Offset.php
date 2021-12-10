<?php

namespace iggyvolz\Pnp;

final class Offset
{
    public function __construct(
        public readonly int $start,
        public readonly int $length,
    )
    {
    }
}
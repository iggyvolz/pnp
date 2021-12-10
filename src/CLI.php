<?php

namespace iggyvolz\Pnp;

use Symfony\Component\Console\Application;

class CLI extends Application
{
    public function __construct()
    {
        parent::__construct("PNP");
        $this->add(new Pnp());
        $this->setDefaultCommand("run", true);
    }

    public static function go(): void
    {
        (new self())->run();
    }
}
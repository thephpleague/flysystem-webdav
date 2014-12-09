<?php

return Symfony\CS\Config\Config::create()
    ->fixers(['-phpdoc_params', '-yoda_conditions', 'ordered_use', 'short_array_syntax'])
    ->finder(Symfony\CS\Finder\DefaultFinder::create()
    ->in(__DIR__));

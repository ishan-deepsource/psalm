<?php

namespace Psalm;

class Bar
{
    public function arrayWithoutDimForReading(): void
    {
        $array = [];

        $array[] = 10;
        $array[][] = 10;
        $array[];
        var_dump($array[]);
    }
}

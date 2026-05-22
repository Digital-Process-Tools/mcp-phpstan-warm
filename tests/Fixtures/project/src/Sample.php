<?php

declare(strict_types=1);

final class Sample
{
    public function getLabel(): string
    {
        return 42; // deliberate type error: int returned where string expected
    }
}

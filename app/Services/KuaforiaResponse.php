<?php

namespace App\Services;

class KuaforiaResponse
{
    public function __construct(
        public readonly string $answerText,
        public readonly float $confidence,
        public readonly array $sources,
    ) {}
}

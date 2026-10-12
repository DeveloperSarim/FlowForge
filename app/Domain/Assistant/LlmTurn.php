<?php

namespace App\Domain\Assistant;

/** Normalised result of one model turn. */
class LlmTurn
{
    public string $text = '';

    /** @var list<array{id: string, name: string, input: array}> */
    public array $toolCalls = [];

    /** @var array{input: int, cached: int, output: int} */
    public array $usage = ['input' => 0, 'cached' => 0, 'output' => 0];

    public ?string $stop = null;
}

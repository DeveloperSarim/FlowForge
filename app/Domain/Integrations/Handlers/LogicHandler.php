<?php

namespace App\Domain\Integrations\Handlers;

use App\Domain\Integrations\ActionException;
use App\Domain\Integrations\ActionResult;
use App\Domain\Integrations\Models\Credential;
use Illuminate\Support\Str;

/**
 * If/Else branching, Filter, JSON parser and text formatter nodes.
 */
class LogicHandler
{
    public const OPERATORS = [
        'equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with',
        'greater_than', 'less_than', 'greater_or_equal', 'less_or_equal',
        'is_empty', 'is_not_empty', 'is_true', 'is_false', 'matches_regex',
    ];

    public function handle(string $handler, array $inputs, ?Credential $credential = null): ActionResult
    {
        return match ($handler) {
            'if' => $this->branch($inputs),
            'filter' => $this->filter($inputs),
            'json_parse' => $this->jsonParse($inputs),
            'text_format' => $this->textFormat($inputs),
        };
    }

    private function branch(array $inputs): ActionResult
    {
        $result = $this->evaluate($inputs['conditions'] ?? [], $inputs['combinator'] ?? 'all');

        return new ActionResult(output: ['result' => $result], handle: $result ? 'true' : 'false');
    }

    private function filter(array $inputs): ActionResult
    {
        $passed = $this->evaluate($inputs['conditions'] ?? [], $inputs['combinator'] ?? 'all');

        return new ActionResult(output: ['passed' => $passed], halt: ! $passed);
    }

    public function evaluate(array $conditions, string $combinator): bool
    {
        if ($conditions === []) {
            return true;
        }

        $results = array_map(fn (array $c) => $this->compare($c['left'] ?? null, $c['operator'] ?? 'equals', $c['right'] ?? null), $conditions);

        return $combinator === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    public function compare(mixed $left, string $operator, mixed $right): bool
    {
        $text = fn (mixed $value) => is_array($value) ? json_encode($value) : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        $numeric = is_numeric($left) && is_numeric($right);

        return match ($operator) {
            'equals' => $numeric ? $left + 0 == $right + 0 : $text($left) === $text($right),
            'not_equals' => $numeric ? $left + 0 != $right + 0 : $text($left) !== $text($right),
            'contains' => is_array($left) ? in_array($right, $left) : str_contains($text($left), $text($right)),
            'not_contains' => is_array($left) ? ! in_array($right, $left) : ! str_contains($text($left), $text($right)),
            'starts_with' => str_starts_with($text($left), $text($right)),
            'ends_with' => str_ends_with($text($left), $text($right)),
            'greater_than' => $numeric ? $left + 0 > $right + 0 : strcmp($text($left), $text($right)) > 0,
            'less_than' => $numeric ? $left + 0 < $right + 0 : strcmp($text($left), $text($right)) < 0,
            'greater_or_equal' => $numeric ? $left + 0 >= $right + 0 : strcmp($text($left), $text($right)) >= 0,
            'less_or_equal' => $numeric ? $left + 0 <= $right + 0 : strcmp($text($left), $text($right)) <= 0,
            'is_empty' => $left === null || $left === '' || $left === [],
            'is_not_empty' => ! ($left === null || $left === '' || $left === []),
            'is_true' => filter_var($left, FILTER_VALIDATE_BOOLEAN),
            'is_false' => ! filter_var($left, FILTER_VALIDATE_BOOLEAN),
            'matches_regex' => $this->regex($text($right), $text($left)),
            default => throw new ActionException("Unknown operator [$operator]."),
        };
    }

    private function regex(string $pattern, string $subject): bool
    {
        // Accept bare patterns ("^ab+c$") as well as delimited ones ("/abc/i").
        if (! preg_match('/^([\/#~]).*\1[imsxuU]*$/s', $pattern)) {
            $pattern = '/'.str_replace('/', '\/', $pattern).'/';
        }

        $result = @preg_match($pattern, $subject);
        if ($result === false) {
            throw new ActionException("Invalid regular expression [$pattern].");
        }

        return $result === 1;
    }

    private function jsonParse(array $inputs): ActionResult
    {
        $source = $inputs['source'] ?? null;
        if (is_array($source)) {
            return new ActionResult(output: $source);
        }

        $decoded = json_decode((string) $source, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ActionException('Invalid JSON: '.json_last_error_msg());
        }

        return new ActionResult(output: is_array($decoded) ? $decoded : ['value' => $decoded]);
    }

    private function textFormat(array $inputs): ActionResult
    {
        $text = (string) ($inputs['text'] ?? '');

        $formatted = match ($inputs['operation'] ?? 'none') {
            'upper' => mb_strtoupper($text),
            'lower' => mb_strtolower($text),
            'title' => Str::title($text),
            'trim' => trim($text),
            'slug' => Str::slug($text),
            'strip_html' => trim(html_entity_decode(strip_tags($text))),
            'truncate' => Str::limit($text, max(1, (int) ($inputs['length'] ?? 100))),
            'url_encode' => rawurlencode($text),
            'base64_encode' => base64_encode($text),
            'base64_decode' => (string) base64_decode($text, true),
            'replace' => str_replace((string) ($inputs['search'] ?? ''), (string) ($inputs['replace'] ?? ''), $text),
            default => $text,
        };

        return new ActionResult(output: ['text' => $formatted, 'length' => mb_strlen($formatted)]);
    }
}

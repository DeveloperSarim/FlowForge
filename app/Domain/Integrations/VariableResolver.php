<?php

namespace App\Domain\Integrations;

use Illuminate\Support\Str;

/**
 * Resolves {{ path.to.value }} references against a context array.
 *
 * - A string that is exactly one placeholder returns the raw value (arrays/ints stay typed).
 * - Mixed text interpolates; arrays are JSON-encoded, null becomes "".
 * - Paths use dot notation; items[0] and * wildcards (data_get) are supported.
 */
class VariableResolver
{
    private const TOKEN = '/\{\{\s*([^{}]+?)\s*\}\}/';

    private const EXACT = '/^\s*\{\{\s*([^{}]+?)\s*\}\}\s*$/';

    /**
     * @param  callable(string): string|null  $encode  applied to each interpolated fragment (e.g. rawurlencode for URL paths)
     */
    public function resolve(mixed $value, array $context, ?callable $encode = null): mixed
    {
        if (is_array($value)) {
            $resolved = [];
            foreach ($value as $key => $item) {
                $resolved[$key] = $this->resolve($item, $context, $encode);
            }

            return $resolved;
        }

        if (! is_string($value) || ! str_contains($value, '{{')) {
            return $value;
        }

        if (preg_match(self::EXACT, $value, $match) && ! str_starts_with(ltrim($value), '{{{')) {
            return $this->lookup($match[1], $context);
        }

        // {{{ path }}} interpolates without the encoder (e.g. a nested folder path "a/b/c" inside a URL).
        $value = preg_replace_callback('/\{\{\{\s*([^{}]+?)\s*\}\}\}/', fn (array $m) => $this->stringify($this->lookup($m[1], $context)), $value);

        return preg_replace_callback(self::TOKEN, function (array $match) use ($context, $encode) {
            $text = $this->stringify($this->lookup($match[1], $context));

            return $encode ? $encode($text) : $text;
        }, $value);
    }

    public function lookup(string $path, array $context): mixed
    {
        $path = preg_replace('/\[\s*["\']?([^\]"\']+)["\']?\s*\]/', '.$1', trim($path));
        if ($path === 'empty_object') {
            return new \stdClass; // serialises as {} where an API needs an empty JSON object
        }
        if ($path === 'uuid') {
            return (string) Str::uuid(); // fresh per use: idempotency keys, Matrix txn ids
        }

        return data_get($context, ltrim($path, '.'));
    }

    public function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value), is_object($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }
}

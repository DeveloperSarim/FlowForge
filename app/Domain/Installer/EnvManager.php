<?php

namespace App\Domain\Installer;

use InvalidArgumentException;
use RuntimeException;

/**
 * Updates keys in the .env file in place, writing atomically.
 */
class EnvManager
{
    public function __construct(private ?string $path = null)
    {
        $this->path ??= base_path('.env');
    }

    /**
     * @param  array<string, scalar|null>  $values
     */
    public function set(array $values): void
    {
        $env = is_file($this->path) ? (string) file_get_contents($this->path) : '';

        foreach ($values as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                throw new InvalidArgumentException("Invalid .env key [$key].");
            }

            $line = $key.'='.$this->format($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            $env = preg_match($pattern, $env)
                ? preg_replace_callback($pattern, fn () => $line, $env)
                : rtrim($env).PHP_EOL.$line.PHP_EOL;
        }

        $tmp = $this->path.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (file_put_contents($tmp, $env, LOCK_EX) === false || ! rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new RuntimeException("Unable to write {$this->path}. Check file permissions.");
        }
    }

    private function format(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = (string) $value;

        if (preg_match('/[\r\n]/', $value)) {
            throw new InvalidArgumentException('.env values cannot contain line breaks.');
        }
        if (preg_match('/^[A-Za-z0-9_.\-:\/@+]+$/', $value)) {
            return $value;
        }
        // Single quotes are literal in phpdotenv (no ${VAR} expansion, no escapes).
        if (! str_contains($value, "'")) {
            return "'{$value}'";
        }
        if (str_contains($value, '${')) {
            throw new InvalidArgumentException('.env values cannot contain both a single quote and "${".');
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}

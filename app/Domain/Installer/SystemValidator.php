<?php

namespace App\Domain\Installer;

class SystemValidator
{
    public const MIN_PHP = '8.2.0';

    public const EXTENSIONS = ['pdo_mysql', 'curl', 'mbstring', 'openssl', 'fileinfo', 'gd', 'zip', 'tokenizer', 'ctype', 'xml'];

    /**
     * @return array<int, array{group: string, label: string, ok: bool, detail: string}>
     */
    public function checks(): array
    {
        $checks = [[
            'group' => 'PHP',
            'label' => 'PHP '.self::MIN_PHP.' or newer',
            'ok' => version_compare(PHP_VERSION, self::MIN_PHP, '>='),
            'detail' => PHP_VERSION,
        ]];

        foreach (self::EXTENSIONS as $extension) {
            $loaded = extension_loaded($extension);
            $checks[] = [
                'group' => 'Extensions',
                'label' => $extension,
                'ok' => $loaded,
                'detail' => $loaded ? 'Loaded' : 'Missing — enable it in your hosting PHP selector',
            ];
        }

        $paths = [
            'storage' => storage_path(),
            'storage/framework' => storage_path('framework'),
            'storage/logs' => storage_path('logs'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
            '.env' => is_file(base_path('.env')) ? base_path('.env') : base_path(),
        ];

        foreach ($paths as $label => $path) {
            $writable = is_writable($path);
            $checks[] = [
                'group' => 'Permissions',
                'label' => $label,
                'ok' => $writable,
                'detail' => $writable ? 'Writable' : 'Not writable — chmod 775 (or 664 for .env)',
            ];
        }

        $built = is_file(public_path('build/manifest.json'));
        $checks[] = [
            'group' => 'Application',
            'label' => 'Compiled frontend assets',
            'ok' => $built,
            'detail' => $built ? 'public/build present' : 'Run "npm run build" before uploading',
        ];

        return $checks;
    }

    public function passes(): bool
    {
        return collect($this->checks())->every('ok');
    }

    /**
     * Best guess at the CLI PHP binary (web SAPIs report php-fpm / lsphp in PHP_BINARY).
     */
    public function phpCliBinary(): string
    {
        $binary = PHP_BINARY;
        $candidates = [
            preg_replace('#/sbin/php-fpm[\d.]*$#', '/bin/php', $binary),
            preg_replace('#/lsphp$#', '/php', $binary),
            preg_replace('#php-fpm[\d.]*$#', 'php', $binary),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== $binary && @is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return str_contains(basename($binary), 'fpm') || str_contains(basename($binary), 'lsphp') || $binary === ''
            ? '/usr/local/bin/php'
            : $binary;
    }

    public function cronCommand(): string
    {
        return '* * * * * '.$this->phpCliBinary().' '.base_path('artisan').' schedule:run >> /dev/null 2>&1';
    }
}

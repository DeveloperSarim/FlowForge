<?php

namespace App\Domain\System;

use Illuminate\Http\Request;

/** Shared validation for the SMTP form (installer + settings). */
class SettingsValidation
{
    /**
     * @return array{0: array, 1: ?string} [smtp settings, notification email]
     */
    public static function mail(Request $request, ?array $existing = null, bool $requirePassword = false): array
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', 'in:tls,ssl,none'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => [$requirePassword ? 'required' : 'nullable', 'string', 'max:255'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'notify_email' => ['nullable', 'email', 'max:255'],
        ]);

        $mail = [
            'host' => trim($data['host']),
            'port' => (int) $data['port'],
            'encryption' => $data['encryption'],
            'username' => $data['username'] ?? null,
            // Blank password on an edit keeps the stored one (it is never sent to the browser).
            'password' => ($data['password'] ?? '') !== '' ? $data['password'] : ($existing['password'] ?? null),
            'from_address' => $data['from_address'],
            'from_name' => $data['from_name'] ?? 'FlowForge',
        ];

        return [$mail, $data['notify_email'] ?? null];
    }
}

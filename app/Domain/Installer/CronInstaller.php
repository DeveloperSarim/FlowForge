<?php

namespace App\Domain\Installer;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * "Set it up for me": adds FlowForge's line to the hosting account's crontab when the host lets PHP run
 * the crontab command. Other entries are kept. Many shared hosts block this; callers then show the
 * panel guide. Either way the heartbeat check is what proves cron actually runs.
 */
class CronInstaller
{
    public function __construct(private SystemValidator $validator) {}

    /** @return array{ok: bool, message: string} */
    public function install(): array
    {
        $marker = base_path('artisan').' schedule:run';

        try {
            $current = Process::timeout(15)->run('crontab -l');
            // "no crontab for <user>" exits 1 with that message: an empty table, not a failure.
            $existing = $current->successful() ? $current->output() : '';
            if (! $current->successful() && ! str_contains(strtolower($current->errorOutput().$current->output()), 'no crontab')) {
                return $this->unavailable($current->errorOutput() ?: $current->output());
            }
            if (str_contains($existing, $marker)) {
                return ['ok' => true, 'message' => 'The cron job is already set up. Waiting for its next run…'];
            }

            $table = ltrim(rtrim($existing)."\n".$this->validator->cronCommand()."\n");
            $write = Process::timeout(15)->input($table)->run('crontab -');
            if (! $write->successful()) {
                return $this->unavailable($write->errorOutput() ?: $write->output());
            }

            // Some hosts accept the write but don't keep it; read it back to be sure.
            if (! str_contains(Process::timeout(15)->run('crontab -l')->output(), $marker)) {
                return $this->unavailable('the host did not keep the new entry');
            }
        } catch (Throwable $e) {
            return $this->unavailable($e->getMessage()); // proc_open disabled, no crontab binary…
        }

        return ['ok' => true, 'message' => 'Cron job added. It starts at the top of the next minute.'];
    }

    private function unavailable(string $reason): array
    {
        $reason = trim(strtok(trim($reason), "\n") ?: '');

        return ['ok' => false, 'message' => "This host doesn't let FlowForge add cron jobs itself".($reason !== '' ? " ({$reason})" : '').'. Add it in your hosting panel with the steps below; it takes a minute.'];
    }
}

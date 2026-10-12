<?php

namespace App\Domain\System;

use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowExecution;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails the admin(s) through the system SMTP settings when something needs attention:
 * a production run failed, a polling trigger keeps failing, or cron stopped for a while.
 * Alerts are throttled per workflow so a broken workflow can't flood the inbox.
 */
class Notifier
{
    public const MAILER = 'flowforge';

    public function __construct(private SystemSettings $settings) {}

    /** Point the "flowforge" mailer at the given (or saved) SMTP settings. */
    public function configure(?array $mail = null): bool
    {
        $mail ??= $this->settings->mail();
        if (! $mail) {
            return false;
        }

        config([
            'mail.mailers.'.self::MAILER => [
                'transport' => 'smtp',
                'scheme' => ($mail['encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
                'host' => $mail['host'],
                'port' => (int) ($mail['port'] ?? 587),
                'username' => $mail['username'] ?? null,
                'password' => $mail['password'] ?? null,
                'timeout' => 15,
            ],
            'mail.from' => ['address' => $mail['from_address'], 'name' => ($mail['from_name'] ?? '') ?: 'FlowForge'],
        ]);
        app('mail.manager')->purge(self::MAILER);

        return true;
    }

    /** @return string|null error message, or null when the email was accepted by the server */
    public function sendTest(array $mail, string $to): ?string
    {
        try {
            $this->configure($mail);
            Mail::mailer(self::MAILER)->to($to)->send(new SystemMail(
                'FlowForge email is working',
                "This is a test from FlowForge.\n\nYou'll get an email here when a workflow fails or the scheduler stops running.",
            ));

            return null;
        } catch (Throwable $e) {
            return strtok($e->getMessage(), "\n") ?: class_basename($e);
        }
    }

    public function workflowFailed(WorkflowExecution $execution): void
    {
        $prefs = $this->settings->notifications();
        if (! $prefs['on_failure'] || $execution->mode === 'test') {
            return;
        }

        $this->throttled("workflow:{$execution->workflow_id}", $prefs, function (int $suppressed) use ($execution) {
            return new WorkflowFailedMail($execution->loadMissing('workflow'), $suppressed);
        });
    }

    public function pollFailed(Workflow $workflow, string $error): void
    {
        $prefs = $this->settings->notifications();
        if (! $prefs['on_failure']) {
            return;
        }

        $this->throttled("poll:{$workflow->id}", $prefs, fn (int $suppressed) => new SystemMail(
            "Trigger check failed: {$workflow->name}",
            "FlowForge couldn't check \"{$workflow->name}\" for new items:\n\n> {$error}\n\nNew items won't start runs until this is fixed. Open the trigger step to see the details."
                .($suppressed ? "\n\n({$suppressed} more failures since the last email.)" : ''),
            url("/workflows/{$workflow->id}"),
            'Open workflow',
        ));
    }

    /**
     * Called once a minute by the scheduler. If the previous tick is long ago, cron was down:
     * schedules and polls in that window were skipped, so tell the admin.
     */
    public function heartbeat(?CarbonInterface $now = null): void
    {
        $now ??= now();
        $previous = Cache::get('flowforge:heartbeat');
        Cache::forever('flowforge:heartbeat', $now->toIso8601String());

        if (! $previous || ! $this->settings->notifications()['on_scheduler_stall']) {
            return;
        }

        $gap = (int) Carbon::parse($previous)->diffInMinutes($now);
        if ($gap >= 10) {
            $this->send(new SystemMail(
                "The scheduler was not running for {$gap} minutes",
                "FlowForge's cron job didn't run between {$previous} and {$now->toIso8601String()}. Scheduled workflows and polling checks in that window were skipped, and queued webhook runs waited.\n\nIt is running again now. If this keeps happening, check the cron entry in your hosting panel:\n\n`* * * * * php ".base_path('artisan').' schedule:run`',
                url('/'),
                'Open FlowForge',
            ));
        }
    }

    private function throttled(string $key, array $prefs, callable $build): void
    {
        $cacheKey = "flowforge:notified:{$key}";
        if (! Cache::add($cacheKey, 1, now()->addMinutes(max(1, (int) $prefs['throttle_minutes'])))) {
            Cache::increment("{$cacheKey}:suppressed");

            return;
        }

        $this->send($build((int) Cache::pull("{$cacheKey}:suppressed", 0)));
    }

    private function send(Mailable $mail): void
    {
        $recipients = $this->recipients();
        if ($recipients === [] || ! $this->configure()) {
            return;
        }

        try {
            Mail::mailer(self::MAILER)->to($recipients)->send($mail);
        } catch (Throwable $e) {
            report($e); // never let a mail problem break a run
        }
    }

    /** Configured recipients, else every admin account. */
    private function recipients(): array
    {
        $list = array_values(array_filter((array) $this->settings->notifications()['recipients'], fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL)));

        return $list ?: rescue(fn () => User::query()->pluck('email')->all(), [], false);
    }
}

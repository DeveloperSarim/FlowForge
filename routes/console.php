<?php

use App\Domain\Engine\PollerService;
use App\Domain\Installer\EnsureInstalled;
use App\Domain\Integrations\IntegrationAuditor;
use App\Domain\Integrations\ManifestLoader;
use App\Domain\License\DemoMode;
use App\Domain\License\License;
use App\Domain\System\Notifier;
use App\Domain\System\SchemaUpgrader;
use App\Domain\Workflows\Models\WorkflowExecution;
use App\Domain\Workflows\TemplateLibrary;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

/*
| The whole engine runs off one cPanel cron line:  * * * * * php /path/to/artisan schedule:run
| Order matters: triggers enqueue first, then the worker drains the database queue and exits.
*/

// Heartbeat + "cron was down for N minutes" alert email.
Schedule::call(function () {
    if (EnsureInstalled::isInstalled()) {
        SchemaUpgrader::ensureCurrent(); // cron completes updates even with no visitors
    }
    app(Notifier::class)->heartbeat();
})
    ->everyMinute()->name('flowforge:heartbeat');

// Daily license check with the server (revocations, refreshed support dates).
Schedule::call(fn () => app(License::class)->refresh())->daily()->name('flowforge:license');

Schedule::call(fn () => app(PollerService::class)->dispatchDue())
    ->everyMinute()->name('flowforge:triggers')->withoutOverlapping(5);

// --max-time keeps each worker inside its minute; withoutOverlapping covers a long-running job.
Schedule::command('queue:work database --stop-when-empty --sleep=1 --max-time=50 --tries=1 --timeout=600')
    ->everyMinute()->withoutOverlapping(15);

Schedule::command('model:prune', ['--model' => [WorkflowExecution::class]])->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();

Artisan::command('flowforge:integrations {--refresh : Rebuild the manifest cache} {--summary : Counts per category instead of the full table}', function (ManifestLoader $loader) {
    if ($this->option('refresh')) {
        $loader->flush();
    }

    $catalog = collect($loader->catalog());

    if ($this->option('summary')) {
        $this->info($catalog->count().' integrations, '.$catalog->sum(fn ($m) => count($m['actions'])).' actions, '.$catalog->sum(fn ($m) => count($m['triggers'])).' triggers.');
        $catalog->countBy('category')->sortDesc()->each(fn ($count, $category) => $this->line(str_pad($count, 5, ' ', STR_PAD_LEFT)."  $category"));
    } else {
        $this->table(['Key', 'Name', 'Category', 'Auth', 'Actions', 'Triggers'], $catalog->map(fn ($m) => [
            $m['key'], $m['name'], $m['category'], implode(', ', $m['auth']) ?: 'none', count($m['actions']), count($m['triggers']),
        ]));
    }

    foreach ($loader->errors() as $file => $errors) {
        $this->error("$file: ".implode(' ', $errors));
    }

    return $loader->errors() === [] ? 0 : 1;
})->purpose('List integration manifests and report invalid ones');

Artisan::command('flowforge:audit {only? : An integration key prefix or a category name} {--live : Also call keyless GET actions on the real APIs} {--warnings : Show warnings too}', function (IntegrationAuditor $auditor, TemplateLibrary $templates) {
    $report = $auditor->audit($this->argument('only'), (bool) $this->option('live'));
    $templateIssues = $this->argument('only') ? [] : $auditor->auditTemplates($templates);

    $issues = collect($report['issues']);
    $shown = $issues->filter(fn ($i) => $i['level'] === 'error' || $this->option('warnings') || ($this->option('live') && $i['level'] === 'info'));
    foreach ($shown as $issue) {
        $line = str_pad($issue['app'], 26).str_pad($issue['where'], 32).$issue['message'];
        match ($issue['level']) {
            'error' => $this->error($line), 'warning' => $this->warn($line), default => $this->line($line)
        };
    }
    foreach ($templateIssues as $issue) {
        $this->error(str_pad('template '.$issue['template'], 58).$issue['message']);
    }

    $errors = $issues->where('level', 'error')->count() + count($templateIssues);
    $this->newLine();
    $this->info("Audited {$report['apps']} apps and {$report['operations']} HTTP operations (dry run against a fake HTTP client).");
    $this->line("Errors: {$errors}   Warnings: ".$issues->where('level', 'warning')->count());

    return $errors === 0 ? 0 : 1;
})->purpose('Dry-run every integration action and check manifests + templates for problems');

Artisan::command('flowforge:reset-password {email : The account to reset}', function (string $email) {
    $user = User::query()->where('email', $email)->first();
    if (! $user) {
        $this->error("No account with the email $email.");

        return 1;
    }
    $password = (string) $this->secret('New password (min. 10 characters)');
    if (mb_strlen($password) < 10 || $password !== $this->secret('Repeat it')) {
        $this->error('Passwords must match and be at least 10 characters.');

        return 1;
    }
    $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
    $this->info("Password changed for $email.");
})->purpose('Set a new password when email-based reset is not available');

// Live demo (only when the license is the author's demo license).
Schedule::call(function () {
    $demo = app(DemoMode::class);
    if ($demo->active()) {
        $demo->reset();
    }
})->hourly()->name('flowforge:demo-reset');

Artisan::command('flowforge:demo-reset', function (DemoMode $demo) {
    if (! $demo->active()) {
        $this->error('This copy is not running a demo license.');

        return 1;
    }
    $demo->reset();
    $this->info('Demo reset to its starting point.');
})->purpose('Restore the live demo to its saved starting point');

Artisan::command('flowforge:demo-snapshot', function (DemoMode $demo) {
    $this->info('Saved '.$demo->snapshot().' workflows as the demo starting point.');
})->purpose('Save the current workflows as the live demo\'s starting point');

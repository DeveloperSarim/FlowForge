<x-mail::message>
# {{ $workflow?->name ?? 'A workflow' }} failed

Run #{{ $execution->id }} ({{ $execution->mode }}) failed {{ $execution->finished_at?->diffForHumans() ?? 'just now' }}.

@if ($step)
**Step:** {{ $step['label'] ?? $step['node_id'] }}@if (!empty($step['http_status'])) (HTTP {{ $step['http_status'] }})@endif

<x-mail::panel>
{{ \Illuminate\Support\Str::limit($step['error'] ?? $execution->error, 600) }}
</x-mail::panel>
@else
<x-mail::panel>
{{ \Illuminate\Support\Str::limit($execution->error ?? 'Unknown error', 600) }}
</x-mail::panel>
@endif

<x-mail::button :url="$url">
See what happened
</x-mail::button>

@if ($suppressed > 0)
{{ $suppressed }} more {{ \Illuminate\Support\Str::plural('failure', $suppressed) }} of this workflow happened since the last email.
@endif

You get at most one email per workflow per alert window. Change this under Settings → Email & alerts.
</x-mail::message>

<x-mail::message>
# {{ $heading }}

{{ $body }}

@if ($url)
<x-mail::button :url="$url">
{{ $button }}
</x-mail::button>
@endif

FlowForge
</x-mail::message>

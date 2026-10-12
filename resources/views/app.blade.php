<!DOCTYPE html>
@php($theme = auth()->user()?->theme ?? 'light')
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $theme }}" @class(['dark' => $theme === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <script>
            // Resolve "system" before first paint so the page never flashes the wrong theme.
            (function () {
                var t = document.documentElement.dataset.theme;
                if (t === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches) document.documentElement.classList.add('dark');
            })();
        </script>
        <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='9' fill='%23EE5A24'/%3E%3Cpath d='M9 22V10h10M9 16h7' stroke='white' stroke-width='3.2' stroke-linecap='round' stroke-linejoin='round' fill='none'/%3E%3Ccircle cx='23' cy='21' r='3' fill='white'/%3E%3C/svg%3E">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400..700&family=Geist+Mono:wght@400;500&display=swap" rel="stylesheet">
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
        @inertiaHead
    </head>
    <body class="bg-bg text-fg font-sans antialiased">
        @inertia
    </body>
</html>

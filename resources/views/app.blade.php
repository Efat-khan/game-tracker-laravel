<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>CafeTrack</title>

    {{-- Light is the default. Applied before first paint so a dark-mode user
         never sees a white flash on load. --}}
    <script>
        try {
            if (localStorage.getItem('cafetrack.theme') === 'dark') {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {}
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
</head>
<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100 antialiased">
    <div id="cafetrack"></div>
</body>
</html>

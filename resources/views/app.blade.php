<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#060a14">
    <title>CafeTrack</title>

    {{-- Dark is the default. Applied before first paint so nobody gets a white
         flash on the way into a dim room. --}}
    <script>
        try {
            if (localStorage.getItem('cafetrack.theme') !== 'light') {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {
            document.documentElement.classList.add('dark');
        }
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
</head>
<body class="bg-slate-50 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    <div id="cafetrack"></div>
</body>
</html>

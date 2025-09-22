<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Laravel to Shopify Integration</title>
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/import-products.js'])
</head>

<body class="antialiased">
    <div class="min-h-screen bg-gray-100">
        @include('layouts.navigation')

        <!-- Page Content -->
        <main>
            <div class="py-6">
                @yield('content')
            </div>
        </main>
    </div>
</body>
</html>
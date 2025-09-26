@php
$settings = \App\Models\Setting::first();
@endphp

@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold text-gray-800">
            Application Settings
        </h2>
        <x-nav-link
            :href="route('import.logs')"
            :active="request()->routeIs('import.logs')"
            class="inline-flex items-center px-4 py-2 text-sm font-semibold text-black hover:text-blue-700 transition">
            {{ __('View Full Logs') }}
        </x-nav-link>
    </div>


    {{-- Flash Messages --}}
    @if (session('success'))
    <div class="bg-green-100 text-green-800 px-4 py-3 rounded mb-4">
        {{ session('success') }}
    </div>
    @endif
    @if (session('error'))
    <div class="bg-red-100 text-red-800 px-4 py-3 rounded mb-4">
        {{ session('error') }}
    </div>
    @endif

    {{-- Card 1: Client & Email --}}
    <div class="bg-white shadow rounded-lg p-6 space-y-6">
        <h3 class="text-lg font-semibold text-gray-800">Client & Email</h3>

        {{-- Create Client --}}
        <form method="POST" action="{{ route('settings.createClient') }}" class="flex items-center gap-4">
            @csrf
            <div class="flex-1">
                <label class="block text-sm font-medium text-gray-700">Email</label>
                <input type="email"
                    name="email"
                    value="{{ old('email', $settings->email ?? '') }}"
                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 
                    @if($settings->api_key) bg-gray-100 cursor-not-allowed @endif"
                    @if($settings->api_key) disabled @endif
                required>
                <p class="mt-1 text-xs text-gray-500">Please add the email address before creating the client.</p>
            </div>

            <div class="relative inline-block group">

                <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-bold py-2 px-4 rounded mt-1"
                    @if($settings->api_key) disabled @endif>
                    Create Client
                </button>
                <!-- Tooltip -->
                <div class="absolute left-1/2 -translate-x-1/2 mt-2 w-64 px-3 py-2 text-sm text-white bg-gray-800 rounded shadow-lg opacity-0 group-hover:opacity-100 transition pointer-events-none z-10">
                    This will validate the email and the associated database, as well as construct a client that will be used to import the products.
                </div>
            </div>
        </form>

        {{-- Delete Client --}}
        <form method="POST" action="{{ route('settings.deleteClient') }}">
            @csrf
            <button type="submit"
                class="w-full bg-red-600 hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-bold py-2 px-4 rounded"
                @if(!$settings->api_key) disabled @endif>
                Delete Client
            </button>
            <p class="mt-1 text-xs text-gray-500">This will remove the client and all the associated data like APIKey Token.</p>
        </form>
    </div>



    {{-- Show these cards ONLY if client is created --}}
    @if($settings->api_key)
    {{-- Card 2: Product Settings --}}
    <div class="bg-white shadow rounded-lg p-6 space-y-4">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Product Settings</h3>

        <form method="POST" action="{{ route('settings.update') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium text-gray-700">Product Limit</label>
                <input type="number"
                    name="product_limit"
                    value="{{ old('product_limit', $settings->product_limit ?? 10) }}"
                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Product Skip</label>
                <input type="number"
                    name="product_skip"
                    value="{{ old('product_skip', $settings->product_skip ?? 0) }}"
                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <button type="submit"
                class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded">
                Save Settings
            </button>
        </form>
    </div>

    {{-- Card 3: Import Products --}}
    <div class="bg-white shadow rounded-lg p-6 space-y-4">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Import Products</h3>
        <button id="import-products-btn"
            class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded"
            data-url="{{ route('import.products') }}">
            Import Products
        </button>

        <!-- Progress and Logs -->
        <div id="progress-wrapper" class="mt-6 space-y-4">
            <div id="progress-container" class="bg-white border rounded p-4 shadow hidden">
                <div id="progress-text" class="text-gray-800 font-medium mb-2">Preparing to import...</div>
                <div class="w-full h-6 bg-gray-200 rounded-full overflow-hidden">
                    <div id="progress-bar"
                        class="h-6 bg-green-500 text-white text-sm font-medium text-center leading-6 transition-all duration-300 ease-in-out"
                        style="width: 0%">0%</div>
                </div>
            </div>

            <div id="import-logs" class="bg-white border rounded p-4 shadow hidden">
                <h3 class="text-lg font-semibold text-gray-900 mb-3">📋 Import Logs</h3>
                <ul id="log-list" class="space-y-2 max-h-80 overflow-y-auto pr-2">
                    <!-- logs injected here -->
                </ul>
            </div>
        </div>
    </div>
    @endif

</div>
@endsection
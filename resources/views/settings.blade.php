@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <h2 class="text-2xl font-bold mb-6 text-gray-800">🛠 Shopify Integration Settings</h2>
    {{-- Messages --}}
    @if (session('success'))
    <div class="bg-green-100 text-green-800 px-4 py-3 rounded mb-4">{{ session('success') }}</div>
    @endif

    @if (session('error'))
    <div class="bg-red-100 text-red-800 px-4 py-3 rounded mb-4">{{ session('error') }}</div>
    @endif
    {{-- Settings Form --}}
    <form method="POST" action="{{ route('settings.update') }}" class="bg-white shadow p-6 rounded-lg space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium text-gray-700">Email</label>
            <input type="email" name="email" value="{{ old('email', $settings->email ?? '') }}"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" required>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">Product Limit</label>
            <input type="number" name="product_limit" value="{{ old('product_limit', $settings->product_limit ?? 10) }}"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">Product Skip</label>
            <input type="number" name="product_skip" value="{{ old('product_skip', $settings->product_skip ?? 0) }}"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
        </div>

        <button type="submit"
            class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded">
            💾 Save Settings
        </button>
    </form>
    {{-- Action Buttons --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-6">
        <form method="POST" action="{{ route('settings.createClient') }}">
            @csrf
            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded">
                ➕ Create Client
            </button>
        </form>
        <form method="POST" action="{{ route('settings.deleteClient') }}">
            @csrf
            <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded">
                ❌ Delete Client
            </button>
        </form>
        <button id="import-products-btn" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded"
            data-url="{{ route('import.products') }}">
            📦 Import Products
        </button>
    </div>
    <!-- Progress and Logs Container -->
    <div class="w-full mt-6 space-y-4" id="progress-wrapper">
        <!-- Progress Section -->
        <div id="progress-container" class="bg-white border rounded p-4 shadow hidden">
            <div id="progress-text" class="text-gray-800 font-medium mb-2">Preparing to import...</div>
            <div class="w-full h-6 bg-gray-200 rounded-full overflow-hidden">
                <div id="progress-bar" class="h-6 bg-green-500 text-white text-sm font-medium text-center leading-6 transition-all duration-300 ease-in-out" style="width: 0%">0%</div>
            </div>
        </div>
        <!-- Logs Section -->
        <div id="import-logs" class="bg-white border rounded p-4 shadow hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-3">📋 Import Logs</h3>
            <ul id="log-list" class="space-y-2 max-h-80 overflow-y-auto pr-2">
                <!-- logs injected here -->
            </ul>
        </div>
    </div>
</div>
@endsection
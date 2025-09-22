<!-- <x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    {{ __("You're logged in!") }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout> -->


@extends('layouts.app')

@section('content')
<div class="py-6">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Welcome Section -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
            <div class="p-6 bg-white border-b border-gray-200">
                <h1 class="text-2xl font-semibold text-gray-800">Welcome to Your Dashboard</h1>
                <p class="mt-2 text-gray-600">Here's an overview of your system</p>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
            <!-- Total Products -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="px-4 py-5 sm:p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-indigo-500 rounded-md p-3">
                            <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dt class="text-sm font-medium text-gray-500 truncate">Total Products</dt>
                            <dd class="flex items-baseline">
                                <div class="text-2xl font-semibold text-gray-900">1,024</div>
                            </dd>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Orders -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="px-4 py-5 sm:p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-green-500 rounded-md p-3">
                            <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dt class="text-sm font-medium text-gray-500 truncate">Recent Orders</dt>
                            <dd class="flex items-baseline">
                                <div class="text-2xl font-semibold text-gray-900">42</div>
                            </dd>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Total Revenue -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="px-4 py-5 sm:p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-blue-500 rounded-md p-3">
                            <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dt class="text-sm font-medium text-gray-500 truncate">Total Revenue</dt>
                            <dd class="flex items-baseline">
                                <div class="text-2xl font-semibold text-gray-900">$12,345</div>
                            </dd>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Customers -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="px-4 py-5 sm:p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-yellow-500 rounded-md p-3">
                            <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dt class="text-sm font-medium text-gray-500 truncate">Customers</dt>
                            <dd class="flex items-baseline">
                                <div class="text-2xl font-semibold text-gray-900">256</div>
                            </dd>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity & Quick Actions -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Recent Activity -->
            <div class="lg:col-span-2">
                <div class="bg-white shadow overflow-hidden sm:rounded-lg">
                    <div class="px-4 py-5 sm:px-6 border-b border-gray-200">
                        <h3 class="text-lg leading-6 font-medium text-gray-900">Recent Activity</h3>
                    </div>
                    <div class="bg-white overflow-y-auto" style="max-height: 400px;">
                        <ul class="divide-y divide-gray-200">
                            <li class="px-6 py-4">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <span class="inline-flex items-center justify-center h-10 w-10 rounded-full bg-gray-500">
                                            <span class="text-xs font-medium leading-none text-white">A</span>
                                        </span>
                                    </div>
                                    <div class="ml-4">
                                        <p class="text-sm font-medium text-gray-900">New product added</p>
                                        <p class="text-sm text-gray-500">2 hours ago</p>
                                    </div>
                                </div>
                            </li>
                            <li class="px-6 py-4">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <span class="inline-flex items-center justify-center h-10 w-10 rounded-full bg-gray-500">
                                            <span class="text-xs font-medium leading-none text-white">S</span>
                                        </span>
                                    </div>
                                    <div class="ml-4">
                                        <p class="text-sm font-medium text-gray-900">Order #1005 completed</p>
                                        <p class="text-sm text-gray-500">5 hours ago</p>
                                    </div>
                                </div>
                            </li>
                            <li class="px-6 py-4">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <span class="inline-flex items-center justify-center h-10 w-10 rounded-full bg-gray-500">
                                            <span class="text-xs font-medium leading-none text-white">M</span>
                                        </span>
                                    </div>
                                    <div class="ml-4">
                                        <p class="text-sm font-medium text-gray-900">System updated to v2.3</p>
                                        <p class="text-sm text-gray-500">1 day ago</p>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div>
                <div class="bg-white shadow overflow-hidden sm:rounded-lg">
                    <div class="px-4 py-5 sm:px-6 border-b border-gray-200">
                        <h3 class="text-lg leading-6 font-medium text-gray-900">Quick Actions</h3>
                    </div>
                    <div class="px-4 py-5 sm:p-6">
                        <div class="grid grid-cols-1 gap-4">
                            <a href="#" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                Add New Product
                            </a>
                            <a href="#" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                                View Orders
                            </a>
                            <a href="#" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                Store Settings
                            </a>
                            <a href="#" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500">
                                View Reports
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
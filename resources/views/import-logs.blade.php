@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
        <div class="p-6 bg-white border-b border-gray-200">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-2xl font-bold">Import Logs</h2>
                <a href="{{ route('settings', ['shop' => session('shop'), 'host' => session('host')]) }}"
                    class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-gray-600 bg-gray-100 rounded hover:bg-gray-200 transition">
                    ← Home
                </a>
            </div>

            <div class="flex flex-col md:flex-row gap-6">
                <!-- Log Files List -->
                <div class="w-full md:w-1/4">
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <h3 class="text-lg font-semibold mb-3">Available Logs</h3>
                        <ul class="space-y-1 max-h-96 overflow-y-auto">
                            @forelse($logFiles as $logFile)
                            <li>
                                <a href="{{ route('import.logs', ['log' => $logFile]) }}"
                                    class="block px-3 py-2 rounded {{ $selectedLog === $logFile ? 'bg-blue-100 text-blue-800' : 'hover:bg-gray-100' }}">
                                    {{ \Carbon\Carbon::createFromFormat('Y-m-d_H-i-s', str_replace(['import_cron_log_','import_log_', '.log'], '', $logFile))->format('M d, Y H:i') }}
                                </a>
                            </li>
                            @empty
                            <li class="text-gray-500 px-3 py-2">No log files found</li>
                            @endforelse
                        </ul>
                    </div>
                </div>

                <!-- Log Content -->
                <div class="w-full md:w-3/4">
                    @if($selectedLog)
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <div class="flex justify-between items-center mb-3">
                            <h3 class="text-lg font-semibold">
                                Import Log: {{ \Carbon\Carbon::createFromFormat('Y-m-d_H-i-s', str_replace(['import_log_', '.log'], '', $selectedLog))->format('M d, Y H:i') }}
                            </h3>
                            <span class="text-sm text-gray-500">
                                {{ count($logEntries) }} entries
                            </span>
                        </div>

                        <div class="bg-white p-4 rounded border border-gray-200 max-h-[600px] overflow-y-auto">
                            @forelse($logEntries as $entry)
                            <div class="mb-2 pb-2 {{ !$loop->last ? 'border-b border-gray-100' : '' }}">
                                <div class="flex items-start gap-2">
                                    <div class="text-xs text-gray-500 mt-1 w-24 shrink-0">
                                        {{ $entry['timestamp'] }}
                                    </div>
                                    <div class="grow">
                                        <div class="text-sm font-mono px-2 py-1 rounded 
                                                    {{ $entry['type'] === 'error' ? 'bg-red-50 text-red-800' : 
                                                       ($entry['type'] === 'success' ? 'bg-green-50 text-green-800' : 
                                                       ($entry['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-gray-50 text-gray-800')) }}">
                                            {!! str_replace(
                                            ['❌', '✅', '⚠️'],
                                            ['<span class="mr-1">❌</span>', '<span class="mr-1">✅</span>', '<span class="mr-1">⚠️</span>'],
                                            $entry['message']
                                            ) !!}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            @empty
                            <p class="text-gray-500">No entries in this log file</p>
                            @endforelse
                        </div>
                    </div>
                    @else
                    <div class="bg-gray-50 p-8 rounded-lg text-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <h3 class="mt-2 text-lg font-medium text-gray-700">No log selected</h3>
                        <p class="mt-1 text-gray-500">Choose a log file from the left to view its contents</p>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
         // Run daily import at midnight (for testing, you can use everyMinute())
        // $schedule->command('import:daily')
        //          ->dailyAt('00:00') // Midnight
        //          ->timezone('America/New_York') // Adjust to your timezone
        //          ->description('Test daily product import')
        //          ->appendOutputTo(storage_path('logs/scheduler-output.log'));

        // For testing, you can use this to run every minute:
         $schedule->command('import:daily')->everyMinute();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}

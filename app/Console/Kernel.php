<?php

namespace App\Console;

use App\Console\Commands\SendMemoMessages;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Models\User;
use Carbon\Carbon;
class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule)
    {
        $users = User::whereNotNull('telegram_chat_id')->get();
        foreach ($users as $user) {
            $memoTime = Carbon::createFromFormat('H:i:s', $user->memo_time);
            $hourAndMinute = $memoTime->format('H:i');
            $schedule->command('send:memo-messages')->dailyAt($hourAndMinute);
        }
    }
    
    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }


}

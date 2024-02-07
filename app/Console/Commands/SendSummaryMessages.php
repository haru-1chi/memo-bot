<?php

namespace App\Console\Commands;

use App\Services\TelegramBot;
use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\User;

class SendSummaryMessages extends Command
{
    protected $telegramBot;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:summary-messages';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send memo messages to users at their specified memo times';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(TelegramBot $telegramBot)
    {
        parent::__construct();
        $this->telegramBot = $telegramBot;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        info('called when summary_time');
        $currentTime = Carbon::now();
        if ($currentTime->isWeekday()) {
            $users = User::whereNotNull('telegram_chat_id')->get();
            foreach ($users as $user) {
                $summaryTime = Carbon::createFromFormat('H:i:s', $user->summary_time)->format('H:i');
                $currentTimeFormatted = $currentTime->format('H:i');

                if ($currentTimeFormatted === $summaryTime) {
                    $text = "สรุปงานประจำวัน\n";
                    $this->sendMessageToUser($user->telegram_chat_id, $text);
                }
            }
        }

        return 0;
    }

    /**
     * Send message to user using Telegram Bot service.
     *
     * @param int $chatId
     * @param string $message
     * @return void
     */
    private function sendMessageToUser($chatId, $message)
    {
        $this->telegramBot->sendMessage($message, $chatId);
    }
}

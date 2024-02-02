<?php

namespace App\Console\Commands;
use Illuminate\Support\Facades\Http;
use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\User;
class SendMemoMessages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:memo-messages';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send memo messages to users at their specified memo times';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $currentTime = Carbon::now();
        
        if ($currentTime->isWeekday()) {
            $users = User::whereNotNull('memo_time')->get();
            
            foreach ($users as $user) {
                if ($currentTime->format('H:i') === $user->memo_time) {
                    $this->sendMessageToUser($user->telegram_chat_id, "It's memo time!");
                }
            }
        }
    }

    private function sendMessageToUser($chatId, $message)
    {
        $response = Http::post('TELEGRAM_API_URL', [
            'chat_id' => $chatId,
            'text' => $message,
        ]);
    }
}

<?php

use Carbon\Carbon;
use App\Models\Memo;

if (!function_exists('processAction')) {
    function processAction($text, $chat_id, $reply_to_message, $cacheKey, $confirmationText, $successMessage, $cancelMessage, $failureMessage, $memoType)
    {
        $text_reply = '';

        if ($text === $confirmationText) {
            $data = cache()->get("chat_id_{$chat_id}_{$cacheKey}");
            $currentTime = Carbon::now()->toDateString();

            switch ($memoType) {
                case 'Memo':
                    $model = Memo::where('user_id', $chat_id);
                    break;

                default:
                    return;
            }

            if ($data && $model->whereDate('memo_date', $currentTime)->exists()) {
                $model->where('user_id', $chat_id)->where('memo_date', $currentTime)->update($data);
                $text_reply = $successMessage;
            } elseif ($data) {
                $model->create(['user_id' => $chat_id] + $data + ['memo_date' => $currentTime]);
                $text_reply = $successMessage;
            } else {
                $text_reply = $failureMessage;
            }

            app('telegram_bot')->sendMessage($text_reply, $chat_id, $reply_to_message);
        } elseif ($text === '/cancel') {
            app('telegram_bot')->sendMessage($cancelMessage, $chat_id, $reply_to_message);
        } else {
            app('telegram_bot')->sendMessage("กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ", $chat_id, $reply_to_message);
        }

        cache()->forget("chat_id_{$chat_id}_start{$cacheKey}");
        cache()->forget("chat_id_{$chat_id}_{$cacheKey}");
    }
}
<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\TemplateProcessor;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use App\Models\User;
use App\Models\Memo;
use Illuminate\Http\Request;
use App\Services\TelegramBot;
use function App\Helpers\processAction;

class TelegramController extends Controller
{
    protected $telegram_bot_service;

    public function __construct(TelegramBot $telegram_bot_service)
    {
        $this->telegram_bot_service = $telegram_bot_service;
    }
    public function inbound(Request $request)
    {
        Log::info($request->all());
        $chat_id = $request->message['from']['id'] ?? null;
        $reply_to_message = $request->message['message_id'] ?? null;
        Log::info("chat_id: {$chat_id}");
        Log::info("reply_to_message: {$reply_to_message}");

        if ($request->message['text'] === '/cancel') {
            Cache::flush();
            // cache()->forget("chat_id_{$chat_id}_start_set_info");
            // cache()->forget("chat_id_{$chat_id}_start_edit_info");
            app('telegram_bot')->sendMessage("ยกเลิกคำสั่งปัจจุบันเรียบร้อยแล้ว", $chat_id, $reply_to_message);
            return;
        }

        if ($request->message['text'] === '/start' || $request->message['text'] === '/help') {
            $chat_id = $request->message['from']['id'];

            $text = "หวัดดีจ้า! เรา MemoActivityBot ใหม่! 📝\n";
            $text .= "เรามีหลายฟังก์ชั่นที่คุณสามารถใช้งานได้:\n\n";
            $text .= "1. ข้อมูลส่วนตัว\n";
            $text .= "   /setinfo - ตั้งค่าข้อมูลส่วนตัว\n";
            $text .= "   /editinfo - แก้ไขข้อมูลส่วนตัว\n";
            $text .= "   /getinfo - เรียกดูข้อมูลส่วนตัว\n\n";
            $text .= "2. การแจ้งเตือนเพื่อจดบันทึกงานประจำวัน\n";
            $text .= "   /setreminder - ตั้งค่าเวลาแจ้งเตือน\n";
            $text .= "   /editreminder - แก้ไขเวลาแจ้งเตือน\n";
            $text .= "   /getreminder - เรียกดูเวลาแจ้งเตือน\n\n";
            $text .= "3. จดบันทึกงานประจำวัน\n";
            $text .= "   /memo - เริ่มจดบันทึกงานประจำวัน\n";
            $text .= "   /addmemo - เพิ่มบันทึกงานประจำวัน\n";
            $text .= "   /editmemo - แก้ไขบันทึกงานประจำวัน\n";
            $text .= "   /getmemo - เรียกดูบันทึกงานประจำวัน\n\n";
            $text .= "   /notetoday - เพิ่มหมายเหตุกรณีเป็นวันหยุด หรือวันลา\n\n";
            $text .= "   หากต้องการล้างบันทึก/หมายเหตุประจำวัน สามารถ\n";
            $text .= "   /resetmemo - ล้างบันทึกงานประจำวัน\n";
            $text .= "   /resetnotetoday - ล้างหมายเหตุประจำวัน\n\n";

            $text .= "   /generateDoc - สร้างเอกสารสรุปงานประจำสัปดาห์\n";

            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

            return response()->json($result, 200);
        }

        //setinfo
        if ($request->message['text'] === '/setinfo') {
            return $this->setInfoForm($chat_id, $reply_to_message);
        }

        if (cache()->has("chat_id_{$chat_id}_start_set_info")) {
            $step = cache()->get("chat_id_{$chat_id}_start_set_info");
            if ($step === 'waiting_for_command') {
                return $this->showSetInfoForm($request, $chat_id, $reply_to_message);
            } elseif ($step === 'confirm') {
                return $this->handleConfirmation(
                    $request,
                    $chat_id,
                    $reply_to_message,
                    'chat_id_' . $chat_id,
                    'บันทึกข้อมูลเรียบร้อยแล้ว',
                    'ยกเลิกการ /setinfo',
                    function () use ($chat_id) {
                        $user_info = cache()->get("chat_id_{$chat_id}_user_info");
                        if ($user_info) {
                            $this->saveUserInfo($user_info, $chat_id);
                        }
                        cache()->forget("chat_id_{$chat_id}_user_info");
                        cache()->forget("chat_id_{$chat_id}_start_set_info");
                    }
                );
            }
        }

        //editinfo
        if ($request->message['text'] === '/editinfo') {
            return $this->selectEditInfo($chat_id, $reply_to_message);
        }

        if (cache()->has("chat_id_{$chat_id}_start_edit_info")) {
            return $this->updateEditInfo($request, $chat_id, $reply_to_message);
        }

        //getinfo
        if ($request->message['text'] === '/getinfo') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                $text = "ข้อมูลส่วนตัวของคุณ:\n";
                $text .= "1. ชื่อ-นามสกุล: {$user_info['name']}\n";
                $text .= "2. รหัสนิสิต: {$user_info['student_id']}\n";
                $text .= "3. เบอร์โทรศัพท์: {$user_info['phone_number']}\n";
                $text .= "4. สาขาวิชา: {$user_info['branch']}\n";
                $text .= "5. สถานประกอบการ: {$user_info['company']}\n";
                $text .= "หากต้องการแก้ไขข้อมูลส่วนตัว สามารถ /editinfo";
                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                return response()->json($result, 200);
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัว";
                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                return response()->json($result, 200);
            }
        }

        //setreminder
        if ($request->message['text'] === '/setreminder') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                return $this->setReminder($request);
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัว\nก่อนทำการแจ้งเตือนใดๆ";
                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_set_reminder")) {
            $step = cache()->get("chat_id_{$chat_id}_set_reminder");
            $select = cache()->get("chat_id_{$chat_id}_select_type");
            if ($step === 'waiting_for_command') {
                $message = $request->message['text'];
                if ($message === '/formemo') {
                    $text = "ต้องการให้แจ้งเตือนจดบันทึกงานประจำวันกี่โมง?\n";
                    $text .= "กรุณาตอบในรูปแบบนาฬิกา 24 ชั่วโมง\n";
                    $text .= "ยกตัวอย่าง <10:00>\n";
                    cache()->put("chat_id_{$chat_id}_set_reminder", 'waiting_for_time', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_type", '/formemo', now()->addMinutes(60));
                    $reply_to_message = $request->message['message_id'] ?? null;
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                    return response()->json($result, 200);
                }
                if ($message === '/forsummary') {
                    $text = "ต้องการให้แจ้งเตือนสรุปงานประจำวันกี่โมง?\n";
                    $text .= "กรุณาตอบในรูปแบบนาฬิกา 24 ชั่วโมง\n";
                    $text .= "ยกตัวอย่าง <10:00>\n";
                    cache()->put("chat_id_{$chat_id}_set_reminder", 'waiting_for_time', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_type", '/forsummary', now()->addMinutes(60));
                    $reply_to_message = $request->message['message_id'] ?? null;
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                    return response()->json($result, 200);
                }
            } elseif ($step === 'waiting_for_time') {

                if ($select === '/formemo') {
                    $time = $request->message['text'];

                    $text = "ให้แจ้งเตือนเริ่มจดบันทึกงานประจำวันในเวลา\n";
                    $text .= "{$time} น. ใช่ไหมคะ?\n";
                    $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                    $result = app('telegram_bot')->sendMessage($text, $chat_id);
                    cache()->put("chat_id_{$chat_id}_set_reminder", ['type' => '/formemo', 'time' => $time], now()->addMinutes(60));
                    cache()->forget("chat_id_{$chat_id}_select_type");
                    return response()->json($result, 200);
                }
                if ($select === '/forsummary') {
                    $time = $request->message['text'];

                    $text = "ให้แจ้งเตือนสรุปงานประจำวันในเวลา\n";
                    $text .= "{$time} น. ใช่ไหมคะ?\n";
                    $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                    $result = app('telegram_bot')->sendMessage($text, $chat_id);
                    cache()->put("chat_id_{$chat_id}_set_reminder", ['type' => '/forsummary', 'time' => $time], now()->addMinutes(60));
                    cache()->forget("chat_id_{$chat_id}_select_type");
                    return response()->json($result, 200);
                }
            }
            return $this->handleConfirmation(
                $request,
                $chat_id,
                $reply_to_message,
                'chat_id_' . $chat_id . '_setreminder',
                "ตั้งค่าเวลาแจ้งเตือนเริ่มจดบันทึกงานประจำวันเรียบร้อยแล้ว!",
                "ยกเลิกการ /setreminder",
                function () use ($chat_id) {
                    $set_reminder_time = cache()->get("chat_id_{$chat_id}_set_reminder");
                    if ($set_reminder_time) {
                        switch ($set_reminder_time['type']) {
                            case '/formemo':
                                User::where('telegram_chat_id', $chat_id)->update([
                                    'memo_time' => $set_reminder_time['time'],
                                ]);
                                break;
                            case '/forsummary':
                                User::where('telegram_chat_id', $chat_id)->update([
                                    'summary_time' => $set_reminder_time['time'],
                                ]);
                                break;
                            default:
                                break;
                        }
                        cache()->forget("chat_id_{$chat_id}_set_reminder");
                    }
                }
            );
        }

        //editreminder
        if ($request->message['text'] === '/editreminder') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                return $this->editReminder($request);
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัว\nก่อนทำการแจ้งเตือนใดๆ";
                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_edit_reminder")) {
            $step = cache()->get("chat_id_{$chat_id}_edit_reminder");
            $select = cache()->get("chat_id_{$chat_id}_select_type_edit");
            if ($step === 'waiting_for_command') {
                $message = $request->message['text'];
                if ($message === '1') {
                    $text = "ต้องการแก้ไขเวลาแจ้งเตือนจดบันทึกงานประจำวันกี่โมง?\n";
                    $text .= "กรุณาตอบในรูปแบบนาฬิกา 24 ชั่วโมง\n";
                    $text .= "ยกตัวอย่าง <10:00>\n";
                    cache()->put("chat_id_{$chat_id}_edit_reminder", 'waiting_for_time', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_type_edit", '/formemo', now()->addMinutes(60));
                    $reply_to_message = $request->message['message_id'] ?? null;
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                    return response()->json($result, 200);
                }
                if ($message === '2') {
                    $text = "ต้องการแก้ไขเวลาสรุปงานประจำวันกี่โมง?\n";
                    $text .= "กรุณาตอบในรูปแบบนาฬิกา 24 ชั่วโมง\n";
                    $text .= "ยกตัวอย่าง <10:00>\n";
                    cache()->put("chat_id_{$chat_id}_edit_reminder", 'waiting_for_time', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_type_edit", '/forsummary', now()->addMinutes(60));
                    $reply_to_message = $request->message['message_id'] ?? null;
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                    return response()->json($result, 200);
                }
            } elseif ($step === 'waiting_for_time') {

                if ($select === '/formemo') {
                    $time = $request->message['text'];

                    $text = "ให้แจ้งเตือนเริ่มจดบันทึกงานประจำวันในเวลา\n";
                    $text .= "{$time} น. ใช่ไหมคะ?\n";
                    $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                    $result = app('telegram_bot')->sendMessage($text, $chat_id);
                    cache()->put("chat_id_{$chat_id}_edit_reminder", ['type' => '/formemo', 'time' => $time], now()->addMinutes(60));
                    cache()->forget("chat_id_{$chat_id}_select_type_edit");
                    return response()->json($result, 200);
                }
                if ($select === '/forsummary') {
                    $time = $request->message['text'];

                    $text = "ให้แจ้งเตือนสรุปงานประจำวันในเวลา\n";
                    $text .= "{$time} น. ใช่ไหมคะ?\n";
                    $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                    $result = app('telegram_bot')->sendMessage($text, $chat_id);
                    cache()->put("chat_id_{$chat_id}_edit_reminder", ['type' => '/forsummary', 'time' => $time], now()->addMinutes(60));
                    cache()->forget("chat_id_{$chat_id}_select_type_edit");
                    return response()->json($result, 200);
                }
            }
            return $this->handleEditReminderConfirmation($request);
        }

        //getreminder
        if ($request->message['text'] === '/getreminder') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                if (!empty($user_info['memo_time'] && $user_info['summary_time'])) {
                    $memo_time = Carbon::createFromFormat('H:i:s', $user_info['memo_time'])->format('H:i');
                    $summary_time = Carbon::createFromFormat('H:i:s', $user_info['summary_time'])->format('H:i');
                    $text = "แจ้งเตือนการจดบันทึกประจำวันเวลา: {$memo_time} น.\n";
                    $text .= "แจ้งเตือนสรุปงานประจำวันเวลา: {$summary_time} น.\n";
                    $text .= "หากต้องการแก้ไข สามารถ /editreminder";
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                    return response()->json($result, 200);
                } elseif (!empty($user_info['memo_time']) && empty($user_info['summary_time'])) {
                    $memo_time = Carbon::createFromFormat('H:i:s', $user_info['memo_time'])->format('H:i');
                    $text = "แจ้งเตือนการจดบันทึกประจำวันเวลา: {$memo_time} น.\n";
                    $text .= "คุณยังไม่ได้สรุปงานประจำวัน!\n";
                    $text .= "กรุณา /setreminder เพื่อตั้งค่าเวลาสรุปงานประจำวัน";
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                    return response()->json($result, 200);
                } elseif (empty($user_info['memo_time']) && !empty($user_info['summary_time'])) {
                    $summary_time = Carbon::createFromFormat('H:i:s', $user_info['summary_time'])->format('H:i');
                    $text = "คุณยังไม่ได้ตั้งค่าเวลาแจ้งเตือนจดบันทึกประจำวัน!\n";
                    $text .= "แจ้งเตือนสรุปงานประจำวันเวลา: {$summary_time} น.\n";
                    $text .= "กรุณา /setreminder เพื่อตั้งค่าเวลาจดบันทึกประจำวัน";
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                    return response()->json($result, 200);
                } else {
                    $text = "คุณยังไม่ได้ตั้งค่าเวลาแจ้งเตือนใดๆ!\n";
                    $text .= "กรุณา /setreminder เพื่อตั้งค่าเวลาแจ้งเตือน";
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                    return response()->json($result, 200);
                }
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัว\nก่อนทำการแจ้งเตือนใดๆ";
                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                return response()->json($result, 200);
            }
        }

        //memo
        if ($request->message['text'] === '/memo') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                return $this->memoDairy($request);
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัวก่อนทำการจดบันทึกใดๆ";
                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_start_memo_dairy")) {
            $step = cache()->get("chat_id_{$chat_id}_start_memo_dairy");
            if ($step === 'waiting_for_command') {
                $memo_message = $request->message['text'];
                if ($memo_message === '/end') {
                    $current_memo = cache()->get("chat_id_{$chat_id}_memo_daily"); //case null
                    if ($current_memo !== null) {
                        $formatted_memo = [];
                        foreach ($current_memo as $key => $memo) {
                            $formatted_memo[] = ($key + 1) . ". " . $memo;
                        }
                        $text = "งานที่บันทึกในตอนนี้:\n" . implode("\n", $formatted_memo);
                        $text .= "\nถูกต้องมั้ยคะ? (กรุณาตอบ yes หรือ /cancel)\n";
                        app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                        cache()->put("chat_id_{$chat_id}_start_memo_dairy", 'waiting_for_time', now()->addMinutes(60));
                    } else {
                        $text = "\nกรุณาเพิ่มบันทึกประจำวันใหม่อีกครั้ง\nเมื่อจดบันทึกครบแล้ว ให้พิมพ์ /end เพื่อจบการบันทึก";
                        app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                        cache()->put("chat_id_{$chat_id}_start_memo_dairy", 'waiting_for_command', now()->addMinutes(60));
                    }
                } else {
                    $memo_messages = cache()->get("chat_id_{$chat_id}_memo_daily", []);
                    $memo_messages[] = $memo_message;
                    cache()->put("chat_id_{$chat_id}_memo_daily", $memo_messages, now()->addMinutes(60));
                }
            } elseif ($step === 'waiting_for_time') {
                $confirmation_text = 'yes';
                $text_reply = '';
                $text = $request->message['text'];
                if ($text === $confirmation_text) {
                    $current_memo = cache()->get("chat_id_{$chat_id}_memo_daily");
                    $current_time = Carbon::now()->toDateString();
                    if ($current_memo && Memo::where('user_id', $chat_id)->whereDate('memo_date', $current_time)->exists()) {
                        $formatted_memo = implode(', ', $current_memo);
                        Memo::where('user_id', $chat_id)->where('memo_date', $current_time)->update(['memo' => $formatted_memo]);
                        $text_reply = "บันทึกงานประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                    } elseif ($current_memo) {
                        $formatted_memo = implode(', ', $current_memo);
                        Memo::create(['user_id' => $chat_id, 'memo' => $formatted_memo, 'memo_date' => $current_time]);

                        $text_reply = "บันทึกงานประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                    } else {
                        $text_reply = "ไม่มีงานประจำวันที่จะบันทึกในขณะนี้ค่ะ!";
                    }

                    app('telegram_bot')->sendMessage($text_reply, $chat_id, $reply_to_message);
                } elseif ($text === '/cancel') {
                    app('telegram_bot')->sendMessage("ยกเลิกการ /memo", $chat_id, $reply_to_message);
                } else {
                    app('telegram_bot')->sendMessage("กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ", $chat_id, $reply_to_message);
                }
                cache()->forget("chat_id_{$chat_id}_start_memo_dairy");
                cache()->forget("chat_id_{$chat_id}_memo_daily");
            }
        }
//getmemo
        if ($request->message['text'] === '/getmemo') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {

                $user_memo = $this->getUserMemo($chat_id);
                if (!$user_memo || (!$user_memo['memo'] && !$user_memo['note_today'])) {

                    $text = "คุณยังไม่ได้จดบันทึกงานประจำวัน!\n";
                    $text .= "กรุณา /memo เพื่อเริ่มจดบันทึกประจำวัน";
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                    return response()->json($result, 200);
                } elseif ($user_memo['memo']) {

                    $memo_array = explode(', ', $user_memo['memo']);
                    $formatted_memo = [];
                    foreach ($memo_array as $key => $memo) {
                        $formatted_memo[] = ($key + 1) . ". " . $memo;
                    }
                    $text = "งานที่บันทึกในตอนนี้:\n" . implode("\n", $formatted_memo);
                    if ($user_memo['note_today']) {
                        $text .= "\n\nหมายเหตุประจำวัน:\n{$user_memo['note_today']}";
                    }
                    $text .= "\n\nหรือคุณต้องการ\n";
                    $text .= "   /addmemo - เพิ่มบันทึกงานประจำวัน\n";
                    $text .= "   /editmemo - แก้ไขบันทึกงานประจำวัน\n";
                    $text .= "   /getmemo - เรียกดูบันทึกงานประจำวัน\n\n";
                    $text .= "   /notetoday - เพิ่มหมายเหตุกรณีเป็นวันหยุด หรือวันลา\n\n";
                    $text .= "   หากต้องการล้างบันทึก/หมายเหตุประจำวัน สามารถ\n";
                    $text .= "   /resetmemo - ล้างบันทึกงานประจำวัน\n";
                    $text .= "   /resetnotetoday - ล้างหมายเหตุประจำวัน\n\n";
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                    return response()->json($result, 200);
                } elseif ($user_memo['note_today'] && empty($user_memo['memo'])) {
                    $text = "หมายเหตุประจำวัน:\n{$user_memo['note_today']}";
                    $text .= "\n\nหรือคุณต้องการ\n";
                    $text .= "   /memo - เริ่มจดบันทึกงานประจำวัน\n";
                    $text .= "   /addmemo - เพิ่มบันทึกงานประจำวัน\n";
                    $text .= "   /editmemo - แก้ไขบันทึกงานประจำวัน\n";
                    $text .= "   /getmemo - เรียกดูบันทึกงานประจำวัน\n\n";
                    $text .= "   /notetoday - เพิ่มหมายเหตุกรณีเป็นวันหยุด หรือวันลา\n\n";
                    $text .= "   หากต้องการล้างบันทึก/หมายเหตุประจำวัน สามารถ\n";
                    $text .= "   /resetmemo - ล้างบันทึกงานประจำวัน\n";
                    $text .= "   /resetnotetoday - ล้างหมายเหตุประจำวัน\n\n";
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                    return response()->json($result, 200);
                }
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัวก่อนทำการจดบันทึกใดๆ";
                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                return response()->json($result, 200);
            }
        }
//addmemo
        if ($request->message['text'] === '/addmemo') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                return $this->addMemoDairy($request);
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัวก่อนทำการจดบันทึกใดๆ";
                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_start_add_memo_dairy")) {
            $step = cache()->get("chat_id_{$chat_id}_start_add_memo_dairy");
            if ($step === 'waiting_for_command') {
                $memo_message = $request->message['text'];
                $user_memo = $this->getUserMemo($chat_id);
                $memo_array = explode(', ', $user_memo['memo']);
                if ($memo_message === '/end') {
                    $current_memo = cache()->get("chat_id_{$chat_id}_add_memo_daily"); //case null
                    if ($current_memo !== null) {
                        $formatted_memo = [];
                        foreach ($current_memo as $key => $memo) {
                            $formatted_memo[] = ($key + 1) . ". " . $memo;
                        }
                        $text = "งานที่บันทึกในตอนนี้:\n" . implode("\n", $formatted_memo);
                        $text .= "\nถูกต้องมั้ยคะ? (กรุณาตอบ yes หรือ /cancel)\n";
                        app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                        cache()->put("chat_id_{$chat_id}_start_add_memo_dairy", 'waiting_for_time', now()->addMinutes(60));
                    } else {
                        $text = "\nกรุณาเพิ่มบันทึกประจำวันใหม่อีกครั้ง\nเมื่อจดบันทึกครบแล้ว ให้พิมพ์ /end เพื่อจบการบันทึก";
                        app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                        cache()->put("chat_id_{$chat_id}_start_add_memo_dairy", 'waiting_for_command', now()->addMinutes(60));
                    }
                } else {
                    $memo_array = cache()->get("chat_id_{$chat_id}_add_memo_daily", $memo_array);
                    $memo_array[] = $memo_message;
                    cache()->put("chat_id_{$chat_id}_add_memo_daily", $memo_array, now()->addMinutes(60));
                }
            } elseif ($step === 'waiting_for_time') {
                $confirmation_text = 'yes';
                $text_reply = '';
                $text = $request->message['text'];
                if ($text === $confirmation_text) {
                    $current_memo = cache()->get("chat_id_{$chat_id}_add_memo_daily");

                    if (!empty($current_memo)) {
                        $formatted_memo = implode(', ', $current_memo);
                        $current_date = Carbon::now()->toDateString();
                        Memo::where('user_id', $chat_id)->where('memo_date', $current_date)->update(['memo' => $formatted_memo,]);
                        $text_reply = "บันทึกงานประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                    } else {
                        $text_reply = "ไม่มีงานประจำวันที่จะบันทึกในขณะนี้ค่ะ!";
                    }

                    app('telegram_bot')->sendMessage($text_reply, $chat_id, $reply_to_message);
                } elseif ($text === '/cancel') {
                    app('telegram_bot')->sendMessage("ยกเลิกการ /addmemo", $chat_id, $reply_to_message);
                } else {
                    app('telegram_bot')->sendMessage("กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ", $chat_id, $reply_to_message);
                }
                cache()->forget("chat_id_{$chat_id}_start_add_memo_dairy");
                cache()->forget("chat_id_{$chat_id}_add_memo_daily");
            }
        }
        //editmemo
        if ($request->message['text'] === '/editmemo') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                return $this->editMemoDairy($request);
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัวก่อนทำการจดบันทึกใดๆ";
                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_start_edit_memo_dairy")) {
            $step = cache()->get("chat_id_{$chat_id}_start_edit_memo_dairy");
            $select = cache()->get("chat_id_{$chat_id}_select_choice_edit_memo");
            $user_memo = $this->getUserMemo($chat_id);
            $memo_messages = explode(', ', $user_memo['memo']);

            if ($step === 'waiting_for_command') {
                $selected_index = $request->message['text'];
                if ($selected_index >= 1 && $selected_index <= count($memo_messages)) {
                    $text = "สามารถพิมพ์ข้อความเพื่อแก้ไขงานประจำวันได้เลยค่ะ\n";
                    $text .= "(สามารถแก้ไขได้เพียงข้อที่เลือก)\n";
                    $text .= "'Create function CRUD'\n";
                    cache()->put("chat_id_{$chat_id}_start_edit_memo_dairy", 'updated', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_choice_edit_memo", $selected_index, now()->addMinutes(60));
                    $reply_to_message = $request->message['message_id'] ?? null;
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                    return response()->json($result, 200);
                }
            } elseif ($step === 'updated') {
                $select = cache()->get("chat_id_{$chat_id}_select_choice_edit_memo");
                $memo_messages[$select - 1] = $request->message['text'];
                cache()->put("chat_id_{$chat_id}_edit_memo_dairy", $memo_messages, now()->addMinutes(60));
                $current_memo = cache()->get("chat_id_{$chat_id}_edit_memo_dairy");
                $formatted_memo = [];
                foreach ($current_memo as $key => $memo) {
                    $formatted_memo[] = ($key + 1) . ". " . $memo;
                }
                $text = "งานที่บันทึกในตอนนี้:\n" . implode("\n", $formatted_memo);
                $text .= "\nถูกต้องมั้ยคะ? (กรุณาตอบ yes หรือ /cancel)\n";
                app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                cache()->put("chat_id_{$chat_id}_start_edit_memo_dairy", 'waiting_for_time', now()->addMinutes(60));
            } elseif ($step === 'waiting_for_time') {
                $confirmation_text = 'yes';
                $text_reply = '';
                $text = $request->message['text'];
                if ($text === $confirmation_text) {
                    $current_memo = cache()->get("chat_id_{$chat_id}_edit_memo_dairy");

                    if (!empty($current_memo)) {
                        $formatted_memo = implode(', ', $current_memo);
                        $current_date = Carbon::now()->toDateString();
                        Memo::where('user_id', $chat_id)->where('memo_date', $current_date)->update(['memo' => $formatted_memo,]);
                        $text_reply = "บันทึกงานประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                    } else {
                        $text_reply = "ไม่มีงานประจำวันที่จะบันทึกในขณะนี้ค่ะ!";
                    }

                    app('telegram_bot')->sendMessage($text_reply, $chat_id, $reply_to_message);
                } elseif ($text === '/cancel') {
                    app('telegram_bot')->sendMessage("ยกเลิกการ /editmemo", $chat_id, $reply_to_message);
                } else {
                    app('telegram_bot')->sendMessage("กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ", $chat_id, $reply_to_message);
                }
                cache()->forget("chat_id_{$chat_id}_edit_memo_dairy");
                cache()->forget("chat_id_{$chat_id}_start_edit_memo_dairy");
                cache()->forget("chat_id_{$chat_id}_select_choice_edit_memo");
            }
        }
//resetmemo
        if ($request->message['text'] === '/resetmemo') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                $user_memo = $this->getUserMemo($chat_id);
                if (!$user_memo || !$user_memo['memo'] || (!$user_memo['memo'] && !$user_memo['note_today'])) {
                    $text = "คุณยังไม่ได้จดบันทึกงานประจำวัน!\n";
                    $text .= "กรุณา /memo เพื่อเริ่มจดบันทึกประจำวัน";
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                    return response()->json($result, 200);
                } else {
                    $memo_array = explode(', ', $user_memo['memo']);
                    $formatted_memo = [];
                    foreach ($memo_array as $key => $memo) {
                        $formatted_memo[] = ($key + 1) . ". " . $memo;
                    }
                    $text = "งานที่บันทึกในตอนนี้:\n" . implode("\n", $formatted_memo);
                    $text .= "\nคุณต้องการล้างบันทึกประจำวันเพื่อเริ่มจดบันทึกใหม่หรือไม่?";
                    $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                    cache()->put("chat_id_{$chat_id}_start_reset_memo_dairy", true, now()->addMinutes(60));
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                    return response()->json($result, 200);
                }
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัวก่อนทำการจดบันทึกใดๆ";
                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_start_reset_memo_dairy")) {
            $confirmation_text = 'yes';
            $text_reply = '';
            $text = $request->message['text'];
            $user_memo = $this->getUserMemo($chat_id);
            if ($text === $confirmation_text) {
                $user_memo->memo = null;
                $user_memo->save();
                $text_reply = "ล้างบันทึกงานประจำวันเรียบร้อยแล้ว!\n";
                $text_reply .= "สามารถ /memo เพื่อเริ่มจดบันทึกประจำวันใหม่อีกครั้ง";
                app('telegram_bot')->sendMessage($text_reply, $chat_id, $reply_to_message);
            } elseif ($text === '/cancel') {
                app('telegram_bot')->sendMessage("ยกเลิกการ /resetmemo", $chat_id, $reply_to_message);
            } else {
                app('telegram_bot')->sendMessage("กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ", $chat_id, $reply_to_message);
            }
            cache()->forget("chat_id_{$chat_id}_start_reset_memo_dairy");
        }
//resetnotetoday
        if ($request->message['text'] === '/resetnotetoday') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                $user_memo = $this->getUserMemo($chat_id);//check ว่า ถ้าไม่เคยบันทึกเลยในวันนี้
                if ($user_memo['note_today']) {
                    $text = "หมายเหตุประจำวันตอนนี้:\n{$user_memo['note_today']}";
                    $text .= "\nคุณต้องการล้างหมายเหตุประจำวันเพื่อเริ่มจดบันทึกใหม่หรือไม่?";
                    $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                    cache()->put("chat_id_{$chat_id}_start_reset_notetoday", true, now()->addMinutes(60));
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                    return response()->json($result, 200);
                } elseif (!$user_memo['note_today']) {
                    $text = "คุณยังไม่ได้เพิ่มหมายเหตุประจำวัน!\n";
                    $text .= "กรุณา /notetoday เพิ่มหมายเหตุประจำวัน";
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                    return response()->json($result, 200);
                }
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัวก่อนทำการจดบันทึกใดๆ";
                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_start_reset_notetoday")) {
            $confirmation_text = 'yes';
            $text_reply = '';
            $text = $request->message['text'];
            $user_memo = $this->getUserMemo($chat_id);
            if ($text === $confirmation_text) {
                $user_memo->note_today = null;
                $user_memo->save();
                $text_reply = "ล้างหมายเหตุประจำวันเรียบร้อยแล้ว!\n";
                $text_reply .= "สามารถ /notetoday เพื่อเริ่มจดบันทึกประจำวันใหม่อีกครั้ง";
                app('telegram_bot')->sendMessage($text_reply, $chat_id, $reply_to_message);
            } elseif ($text === '/cancel') {
                app('telegram_bot')->sendMessage("ยกเลิกการ /resetnotetoday", $chat_id, $reply_to_message);
            } else {
                app('telegram_bot')->sendMessage("กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ", $chat_id, $reply_to_message);
            }
            cache()->forget("chat_id_{$chat_id}_start_reset_notetoday");
        }

        //notetoday
        if ($request->message['text'] === '/notetoday') {
            $user_info = $this->getUserInfo($chat_id);
            if ($user_info) {
                $user_memo = $this->getUserMemo($chat_id);
                if (!$user_memo || !$user_memo['note_today']) {
                    $text = "สามารถพิมพ์ข้อความใดๆเพื่อเพิ่มหมายเหตุได้เลยค่ะ\n";
                    $text .= "ยกตัวอย่าง ‘วันหยุดปีใหม่’\n";
                    cache()->put("chat_id_{$chat_id}_start_notetoday", 'waiting_for_command', now()->addMinutes(60));
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                    return response()->json($result, 200);
                } else {
                    $text = "คุณเริ่มจดหมายเหตุประจำวันไปแล้ว!\n\n";
                    $text .= "หรือคุณต้องการ\n";
                    $text .= "   /resetmemo - ล้างบันทึกงานประจำวัน\n";
                    $text .= "   /resetnotetoday - ล้างหมายเหตุประจำวัน\n\n";
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                    return response()->json($result, 200);
                }
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัวก่อนทำการจดบันทึกใดๆ";
                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_start_notetoday")) {
            $step = cache()->get("chat_id_{$chat_id}_start_notetoday");
            if ($step === 'waiting_for_command') {
                $note_today = $request->message['text'];

                $text = "หมายเหตุของวันนี้:\n";
                $text .= "{$note_today}\nถูกต้องมั้ยคะ?";
                $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                cache()->put("chat_id_{$chat_id}_start_notetoday", 'confirm', now()->addMinutes(60));
                cache()->put("chat_id_{$chat_id}_notetoday", $note_today, now()->addMinutes(60));
                $reply_to_message = $request->message['message_id'] ?? null;
                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

            } elseif ($step === 'confirm') {
                $confirmation_text = 'yes';
                $text_reply = '';
                $text = $request->message['text'];
                if ($text === $confirmation_text) {
                    $current_notetoday = cache()->get("chat_id_{$chat_id}_notetoday");
                    $current_time = Carbon::now()->toDateString();

                    if ($current_notetoday && Memo::where('user_id', $chat_id)->whereDate('memo_date', $current_time)->exists()) {
                        Memo::where('user_id', $chat_id)->where('memo_date', $current_time)->update(['note_today' => $current_notetoday]);
                        $text_reply = "บันทึกหมายเหตุประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                    } elseif ($current_notetoday) {
                        Memo::create(['user_id' => $chat_id, 'note_today' => $current_notetoday, 'memo_date' => $current_time]);
                        $text_reply = "บันทึกหมายเหตุประจำวันของวันนี้เรียบร้อยแล้วค่ะ!";
                    } else {
                        $text_reply = "ไม่มีหมายเหตุประจำวันที่จะบันทึกในขณะนี้ค่ะ!";
                    }
                    app('telegram_bot')->sendMessage($text_reply, $chat_id, $reply_to_message);
                } elseif ($text === '/cancel') {
                    app('telegram_bot')->sendMessage("ยกเลิกการ /notetoday", $chat_id, $reply_to_message);
                } else {
                    app('telegram_bot')->sendMessage("กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ", $chat_id, $reply_to_message);
                }
                cache()->forget("chat_id_{$chat_id}_start_notetoday");
                cache()->forget("chat_id_{$chat_id}_notetoday");
            }
        }

        if ($request->message['text'] === '/generateDoc') {
            $document_path = $this->generateDocument($request);
            $result = app('telegram_bot')->sendDocument($chat_id, $document_path);
            return response()->json($result, 200);
        }
    }

    protected function handleConfirmation( //everything
        $request,
        $chat_id,
        $reply_to_message,
        $cache_key_prefix,
        $success_message,
        $cancel_message,
        $update_callback = null
    ) {
        $confirmation_text = 'yes';
        $text = $request->message['text'];

        if ($text === $confirmation_text) {
            if ($update_callback && is_callable($update_callback)) {
                $update_callback();
                app('telegram_bot')->sendMessage($success_message, $chat_id, $reply_to_message);
            } else {
                app('telegram_bot')->sendMessage("ไม่พบข้อมูล user", $chat_id, $reply_to_message);
            }
        } elseif ($text === '/cancel') {
            app('telegram_bot')->sendMessage($cancel_message, $chat_id, $reply_to_message);
            cache()->forget("{$cache_key_prefix}_user_info");
            cache()->forget("{$cache_key_prefix}_startSetInfo");
        } else {
            app('telegram_bot')->sendMessage("กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ", $chat_id, $reply_to_message);
        }
    }

    //trysetinfo
    protected function setInfoForm($chat_id, $reply_to_message)
    {
        $user_info = User::where('telegram_chat_id', $chat_id)->first();
        if ($user_info) {
            $text = "คุณได้ตั้งค่าข้อมูลส่วนตัวของคุณไปแล้ว!\n";
            $text .= "ถ้าคุณต้องการแก้ไขข้อมูลให้ใช้คำสั่ง /editinfo";
        } else {
            $text = "กรุณากรอกข้อมูลตามนี้:\n";
            $text .= "1. ชื่อ-นามสกุล\n";
            $text .= "2. รหัสนิสิต\n";
            $text .= "3. เบอร์โทรศัพท์\n";
            $text .= "4. สาขาวิชา\n";
            $text .= "5. สถานประกอบการ\n";
            $text .= "กรุณากรอกข้อมูลตามรูปแบบดังกล่าว\n";
            cache()->put("chat_id_{$chat_id}_start_set_info", 'waiting_for_command', now()->addMinutes(60));
        }
        $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
        return response()->json($result, 200);
    }

    protected function showSetInfoForm($request, $chat_id, $reply_to_message)
    {
        $user_information_lines = explode("\n", $request->message['text']);
        if (count($user_information_lines) === 5) {
            $name = trim($user_information_lines[0]);
            $student_id = trim($user_information_lines[1]);
            $phone_number = trim(preg_replace('/\D/', '', $user_information_lines[2]));
            $branch = isset($user_information_lines[3]) ? trim($user_information_lines[3]) : '';
            $company = isset($user_information_lines[4]) ? trim($user_information_lines[4]) : '';

            $text = "ข้อมูลที่คุณกรอกมีดังนี้:\n";
            $text .= "ชื่อ-นามสกุล: $name\n";
            $text .= "รหัสนิสิต: $student_id\n";
            $text .= "เบอร์โทรศัพท์: $phone_number\n";
            $text .= "สาขาวิชา: $branch\n";
            $text .= "สถานประกอบการ: $company\n";
            $text .= "ถูกต้องมั้ยคะ? (กรุณาตอบ yes หรือ /cancel)";

            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

            cache()->put("chat_id_{$chat_id}_start_set_info", 'confirm', now()->addMinutes(60));
            cache()->put("chat_id_{$chat_id}_user_info", compact('name', 'student_id', 'phone_number', 'branch', 'company'));
            return response()->json($result, 200);
        } else {
            $text = "กรุณากรอกข้อมูลให้ครบถ้วนตามรูปแบบที่กำหนด:\n";
            $text .= "ชื่อ-นามสกุล\n";
            $text .= "รหัสนิสิต\n";
            $text .= "เบอร์โทรศัพท์\n";
            $text .= "สาขาวิชา\n";
            $text .= "สถานประกอบการ";
            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
            return response()->json($result, 200);
        }
    }

    //tryeditinfo
    protected function selectEditInfo($chat_id, $reply_to_message)
    {
        $user_info = $this->getUserInfo($chat_id);
        if ($user_info) {
            $text = "ต้องการแก้ไขข้อมูลใด:\n";
            $text .= "1. ชื่อ-นามสกุล: {$user_info['name']}\n";
            $text .= "2. รหัสนิสิต: {$user_info['student_id']}\n";
            $text .= "3. เบอร์โทรศัพท์: {$user_info['phone_number']}\n";
            $text .= "4. สาขาวิชา: {$user_info['branch']}\n";
            $text .= "5. สถานประกอบการ: {$user_info['company']}\n";
            $text .= "กรุณาตอบเป็นตัวเลข(1-5)";
            cache()->put("chat_id_{$chat_id}_start_edit_info", 'waiting_for_command', now()->addMinutes(60));
        } else {
            $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
            $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัว";
        }
        $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
        return response()->json($result, 200);
    }
    protected function updateEditInfo($request, $chat_id, $reply_to_message)
    {
        $step = cache()->get("chat_id_{$chat_id}_start_edit_info");
        $select = cache()->get("chat_id_{$chat_id}_select_choice_edit");
        $user_info = $this->getUserInfo($chat_id);
        if ($step === 'waiting_for_command') {
            $selected_index = (int) $request->message['text'];
            if ($user_info && is_array($user_info->toArray()) && $selected_index >= 1 && $selected_index <= 5) {
                $column_name = [
                    1 => 'ชื่อ-นามสกุล',
                    2 => 'รหัสนิสิต',
                    3 => 'เบอร์โทรศัพท์',
                    4 => 'สาขาวิชา',
                    5 => 'สถานประกอบการ'
                ];
                $text = "กรุณากรอกข้อมูลดังกล่าวใหม่\n";
                $text .= "$selected_index. {$column_name[$selected_index]}\n";
                cache()->put("chat_id_{$chat_id}_start_edit_info", 'updated', now()->addMinutes(60));
                cache()->put("chat_id_{$chat_id}_select_choice_edit", $selected_index, now()->addMinutes(60));
                $reply_to_message = $request->message['message_id'] ?? null;
                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                return response()->json($result, 200);
            } else {
                $text = "กรุณาตอบเป็นตัวเลข(1-5)เท่านั้น";
                app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
            }
        } elseif ($step === 'updated') {
            $select = cache()->get("chat_id_{$chat_id}_select_choice_edit");
            $memo_messages = $request->message['text'];
            cache()->put("chat_id_{$chat_id}_edit_user_info", $memo_messages, now()->addMinutes(60));
            $current_memo = cache()->get("chat_id_{$chat_id}_edit_user_info");
            $column_name = [
                1 => 'ชื่อ-นามสกุล',
                2 => 'รหัสนิสิต',
                3 => 'เบอร์โทรศัพท์',
                4 => 'สาขาวิชา',
                5 => 'สถานประกอบการ'
            ];
            $text = "ข้อมูลที่แก้ไขใหม่\n";
            $text .= "{$column_name[$select]}: {$current_memo}\n";
            $text .= "ถูกต้องไหมคะ?\n(กรุณาตอบ yes หรือ /cancel)";
            app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
            cache()->put("chat_id_{$chat_id}_start_edit_info", 'waiting_for_time', now()->addMinutes(60));
        } elseif ($step === 'waiting_for_time') {
            $this->handleConfirmation(
                $request,
                $chat_id,
                $reply_to_message,
                'chat_id_' . $chat_id . '_startEdit_userinfo',
                'แก้ไขข้อมูลเรียบร้อยแล้ว',
                'ยกเลิกการ /editinfo',
                function () use ($chat_id) {
                    $user_info = cache()->get("chat_id_{$chat_id}_select_choice_edit");
                    if ($user_info) {
                        $column_name = [
                            1 => 'name',
                            2 => 'student_id',
                            3 => 'phone_number',
                            4 => 'branch',
                            5 => 'company'
                        ];
                        $text_update = cache()->get("chat_id_{$chat_id}_edit_user_info");
                        User::where('telegram_chat_id', $chat_id)->update([
                            $column_name[$user_info] => $text_update
                        ]);
                        cache()->forget("chat_id_{$chat_id}_edit_user_info");
                    }
                    cache()->forget("chat_id_{$chat_id}_start_edit_info");
                    cache()->forget("chat_id_{$chat_id}_select_choice_edit");
                }
            );
        }
    }
    public function generateDocument(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $user_info = $this->getUserInfo($chat_id);
        $directory = 'word-send';
        if (!file_exists(public_path($directory))) {
            mkdir(public_path($directory), 0777, true);
        }
        $template_processor = new TemplateProcessor('word-template/user.docx');
        $memo_dates = Memo::where('user_id', $chat_id)
            ->pluck('memo_date')
            ->unique();
        $current_week_number = $memo_dates->map(function ($date) {
            return Carbon::parse($date)->weekOfYear;
        })->unique()->count();
        $latest_week_memos = Memo::where('user_id', $chat_id)
            ->whereBetween('memo_date', [
                Carbon::now()->startOfWeek()->format('Y-m-d'),
                Carbon::now()->endOfWeek()->format('Y-m-d')
            ])
            ->orderBy('memo_date')
            ->get();
        foreach ($latest_week_memos as $memo) {
            $weekday_index = Carbon::parse($memo->memo_date)->dayOfWeekIso;
            $template_processor->setValue("number_of_week", $current_week_number);
            $template_processor->setValue("memo_date_$weekday_index", $memo->memo_date);
            for ($i = 0; $i < 5; $i++) {
                $template_processor->setValue("memo[$i]_$weekday_index", $this->getMemo($memo->memo, $i));
            }
            $template_processor->setValue("note_today_$weekday_index", $memo->note_today);
        }

        for ($i = 1; $i <= 7; $i++) {
            if (!isset($latest_week_memos[$i])) {
                $template_processor->setValue("memo_date_$i", '');
                for ($j = 0; $j < 5; $j++) {
                    $template_processor->setValue("memo[$j]_$i", '……………………………………………………………………………………');
                }
                $template_processor->setValue("note_today_$i", '');
            }
        }

        $file_name = $user_info['student_id'] . '_week' . $current_week_number . '_memo.docx';
        $file_path = public_path($directory . DIRECTORY_SEPARATOR . $file_name);
        $template_processor->saveAs($file_path);
        return $file_path;
    }

    private function getMemo($memo, $index)
    {
        if ($memo) {
            $memo_array = explode(',', $memo);
            return isset($memo_array[$index]) ? trim($memo_array[$index]) : '……………………………………………………………………………………';
        } else {
            return '……………………………………………………………………………………';
        }
    }
    public function editMemoDairy(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $reply_to_message = $request->message['message_id'] ?? null;
        $user_memo = $this->getUserMemo($chat_id);
        if (!$user_memo || !$user_memo['memo'] || (!$user_memo['memo'] && !$user_memo['note_today'])) {
            $text = "คุณยังไม่ได้จดบันทึกงานประจำวัน!\n";
            $text .= "กรุณา /memo เพื่อเริ่มจดบันทึกประจำวัน";
            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
            return response()->json($result, 200);
        } elseif ($user_memo['memo']) {
            $current_memo = explode(', ', $user_memo['memo']);
            $formatted_memo = [];
            foreach ($current_memo as $key => $memo) {
                $formatted_memo[] = ($key + 1) . ". " . $memo;
            }
            $text = "กรุณาเลือกบันทึกที่ต้องการแก้ไข:\n" . implode("\n", $formatted_memo);
            $text .= "\nกรุณาตอบเพียงตัวเลขเดียวเท่านั้น ";
            cache()->put("chat_id_{$chat_id}_start_edit_memo_dairy", 'waiting_for_command', now()->addMinutes(60));
            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
            return response()->json($result, 200);
        }
    }
    public function addMemoDairy(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $reply_to_message = $request->message['message_id'] ?? null;
        $user_memo = $this->getUserMemo($chat_id);
        if (!$user_memo || !$user_memo['memo'] || (!$user_memo['memo'] && !$user_memo['note_today'])) {
            $text = "คุณยังไม่ได้จดบันทึกงานประจำวัน!\n";
            $text .= "กรุณา /memo เพื่อเริ่มจดบันทึกประจำวัน";
            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
            return response()->json($result, 200);
        } elseif ($user_memo['memo']) {
            $text = "สามารถพิมพ์ข้อความใดๆเพื่อเพิ่มบันทึกงานประจำวันได้เลยค่ะ\n";
            $text .= "ยกตัวอย่าง 'Create function CRUD'\n";
            $text .= "เมื่อจดบันทึกครบแล้ว ให้พิมพ์ /end เพื่อจบการบันทึก\n";
            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
            cache()->put("chat_id_{$chat_id}_start_add_memo_dairy", 'waiting_for_command', now()->addMinutes(60));
            return response()->json($result, 200);
        }
    }
    public function memoDairy(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $reply_to_message = $request->message['message_id'] ?? null;
        $user_memo = $this->getUserMemo($chat_id);
        if (!$user_memo || !$user_memo['memo']) {
            $text = "สามารถพิมพ์ข้อความใดๆเพื่อจดบันทึกงานประจำวันได้เลยค่ะ\n";
            $text .= "ยกตัวอย่าง 'Create function CRUD'\n";
            $text .= "เมื่อจดบันทึกครบแล้ว ให้พิมพ์ /end เพื่อจบการบันทึก\n";
            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
            cache()->put("chat_id_{$chat_id}_start_memo_dairy", 'waiting_for_command', now()->addMinutes(60));
            cache()->put("chat_id_{$chat_id}_memo_daily", [], now()->addMinutes(60));
            return response()->json($result, 200);
        } else {
            $text = "คุณเริ่มจดบันทึกประจำวันไปแล้ว!\n\n";
            $text .= "หรือคุณต้องการ\n";
            $text .= "   /addmemo - เพิ่มบันทึกงานประจำวัน\n";
            $text .= "   /editmemo - แก้ไขบันทึกงานประจำวัน\n";
            $text .= "   /getmemo - เรียกดูบันทึกงานประจำวัน\n";
            $text .= "   /notetoday - เพิ่มหมายเหตุกรณีเป็นวันหยุด หรือวันลา\n\n";
            $text .= "   หากต้องการล้างบันทึก/หมายเหตุประจำวัน สามารถ\n";
            $text .= "   /resetmemo - ล้างบันทึกงานประจำวัน\n";
            $text .= "   /resetnotetoday - ล้างหมายเหตุประจำวัน\n\n";
            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
            return response()->json($result, 200);
        }
    }

    //reminder
    public function setReminder(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $reply_to_message = $request->message['message_id'] ?? null;
        $user_info = $this->getUserInfo($chat_id);
        if ($user_info['memo_time'] && $user_info['summary_time']) {
            $text = "คุณตั้งค่าเวลาแจ้งเตือนจดบันทึกงานประจำวัน\n";
            $text .= "และตั้งค่าเวลาสรุปงานประจำวัน เรียบร้อยแล้ว!\n";
            $text .= "หากต้องการแก้ไข สามารถ /editreminder";
            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

            return response()->json($result, 200);
        } else if ($user_info['memo_time'] && !$user_info['summary_time']) {
            $text = "คุณตั้งค่าเวลาแจ้งเตือนจดบันทึกงานประจำวัน เรียบร้อยแล้ว!\n";
            $text .= "กรุณา /forsummary เพื่อตั้งค่าแจ้งเตือนสรุปงานประจำวัน\n";
            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
            cache()->put("chat_id_{$chat_id}_set_reminder", 'waiting_for_command', now()->addMinutes(60));

            return response()->json($result, 200);
        } else if (!$user_info['memo_time'] && $user_info['summary_time']) {
            $text = "คุณตั้งค่าเวลาแจ้งเตือนจดบันทึกงานประจำวัน เรียบร้อยแล้ว!\n";
            $text .= "กรุณา /formemo เพื่อตั้งค่าแจ้งเตือนสรุปงานประจำวัน\n";
            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
            cache()->put("chat_id_{$chat_id}_set_reminder", 'waiting_for_command', now()->addMinutes(60));

            return response()->json($result, 200);
        } else {
            $text = "กรุณาเลือกประเภทการแจ้งเตือนเพื่อตั้งค่าเวลา:\n";
            $text .= "1. /formemo - แจ้งเตือนจดบันทึกงานประจำวัน\n";
            $text .= "2. /forsummary - แจ้งเตือนสรุปงานประจำวัน\n";
            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

            cache()->put("chat_id_{$chat_id}_set_reminder", 'waiting_for_command', now()->addMinutes(60));

            return response()->json($result, 200);
        }

    }

    public function editReminder(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $reply_to_message = $request->message['message_id'] ?? null;
        $text = "กรุณาเลือกประเภทการแจ้งเตือนเพื่อแก้ไขเวลา:\n";
        $text .= "1. แก้ไขเวลาแจ้งเตือนจดบันทึกงานประจำวัน\n";
        $text .= "2. แก้ไขเวลาแจ้งเตือนสรุปงานประจำวัน\n";
        $text .= "กรุณาตอบเป็นตัวเลข(1-2)\n";
        $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

        cache()->put("chat_id_{$chat_id}_edit_reminder", 'waiting_for_command', now()->addMinutes(60));

        return response()->json($result, 200);
    }

    //setinfo
    public function saveUserInfo(array $user_info, $chat_id)
    {
        User::create([
            'name' => $user_info['name'],
            'student_id' => $user_info['student_id'],
            'phone_number' => $user_info['phone_number'],
            'branch' => $user_info['branch'],
            'company' => $user_info['company'],
            'telegram_chat_id' => $chat_id
        ]);
    }

    //editinfo

    public function getUserInfo($telegram_chat_id)
    {
        $user_info = User::where('telegram_chat_id', $telegram_chat_id)->first();
        return $user_info;
    }

    //setreminder
    private function handleReminderConfirmation(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $reply_to_message = $request->message['message_id'] ?? null;
        $text = strtolower(trim($request->input('message.text')));
        $confirmation_text = 'yes';
        $text_reply = '';
        if ($text === $confirmation_text) {
            $set_reminder_time = cache()->get("chat_id_{$chat_id}_set_reminder");
            if ($set_reminder_time) {
                switch ($set_reminder_time['type']) {
                    case '/formemo':
                        User::where('telegram_chat_id', $chat_id)->update([
                            'memo_time' => $set_reminder_time['time'],
                        ]);
                        $text_reply = "ตั้งค่าเวลาแจ้งเตือนเริ่มจดบันทึกงานประจำวันเรียบร้อยแล้ว!";
                        break;
                    case '/forsummary':
                        User::where('telegram_chat_id', $chat_id)->update([
                            'summary_time' => $set_reminder_time['time'],
                        ]);
                        $text_reply = "ตั้งค่าเวลาแจ้งเตือนสรุปงานประจำวันเรียบร้อยแล้ว!";
                        break;
                    default:
                        break;
                }
                app('telegram_bot')->sendMessage($text_reply, $chat_id, $reply_to_message);
                cache()->forget("chat_id_{$chat_id}_set_reminder");
            } else {
                app('telegram_bot')->sendMessage("ไม่พบข้อมูล user", $chat_id, $reply_to_message);
            }
        } elseif ($text === '/cancel') {
            app('telegram_bot')->sendMessage("ยกเลิกการ /setreminder", $chat_id, $reply_to_message);
            cache()->forget("chat_id_{$chat_id}_set_reminder");
        } else {
            app('telegram_bot')->sendMessage("กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ", $chat_id, $reply_to_message);
        }
    }

    private function handleEditReminderConfirmation(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $reply_to_message = $request->message['message_id'] ?? null;
        $text = strtolower(trim($request->input('message.text')));
        $confirmation_text = 'yes';
        $text_reply = '';
        if ($text === $confirmation_text) {
            $set_reminder_time = cache()->get("chat_id_{$chat_id}_edit_reminder");
            if ($set_reminder_time) {
                switch ($set_reminder_time['type']) {
                    case '/formemo':
                        User::where('telegram_chat_id', $chat_id)->update([
                            'memo_time' => $set_reminder_time['time'],
                        ]);
                        $text_reply = "แก้ไขเวลาแจ้งเตือนเริ่มจดบันทึกงานประจำวันเรียบร้อยแล้ว!";
                        break;
                    case '/forsummary':
                        User::where('telegram_chat_id', $chat_id)->update([
                            'summary_time' => $set_reminder_time['time'],
                        ]);
                        $text_reply = "แก้ไขเวลาแจ้งเตือนสรุปงานประจำวันเรียบร้อยแล้ว!";
                        break;
                    default:
                        break;
                }
                app('telegram_bot')->sendMessage($text_reply, $chat_id, $reply_to_message);
                cache()->forget("chat_id_{$chat_id}_edit_reminder");
            } else {
                app('telegram_bot')->sendMessage("ไม่พบข้อมูล user", $chat_id, $reply_to_message);
            }
        } elseif ($text === '/cancel') {
            app('telegram_bot')->sendMessage("ยกเลิกการ /editreminder", $chat_id, $reply_to_message);
            cache()->forget("chat_id_{$chat_id}_edit_reminder");
        } else {
            app('telegram_bot')->sendMessage("กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ", $chat_id, $reply_to_message);
        }
    }

    //memo
    public function getUserMemo($telegram_chat_id)
    {
        $current_date = Carbon::now()->toDateString();
        $user_memo = Memo::where('user_id', $telegram_chat_id)->where('memo_date', $current_date)->first();
        return $user_memo;
    }
}



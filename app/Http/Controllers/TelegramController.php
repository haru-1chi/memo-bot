<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use App\Models\Memo;
use Illuminate\Http\Request;
use App\Services\TelegramBot;

class TelegramController extends Controller
{
    protected $telegramBotService;

    public function __construct(TelegramBot $telegramBotService)
    {
        $this->telegramBotService = $telegramBotService;
    }
    public function inbound(Request $request)
    {
        \Log::info($request->all());
        $chat_id = $request->message['from']['id'];
        $reply_to_message = $request->message['message_id'];
        \Log::info("chat_id: {$chat_id}");
        \Log::info("reply_to_message: {$reply_to_message}");

        if ($request->message['text'] === '/start' || cache()->has("chat_id_{$chat_id}")) {
            $chat_id = $request->message['from']['id'];

            $text = "หวัดดีจ้า! เรา MemoActivityBot ใหม่! 📝\n";
            $text .= "เรามีหลายฟังก์ชั่นที่คุณสามารถใช้งานได้:\n\n";
            $text .= "1. /setinfo - ตั้งค่าข้อมูลส่วนตัว\n";
            $text .= "2. /setreminder - ตั้งค่าการแจ้งเตือนประจำวัน\n";
            $text .= "3. /weeklysummary - สรุปงานประจำสัปดาห์\n";
            $text .= "4. /generateDoc - สร้างเอกสารสรุปงานประจำสัปดาห์\n";

            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

            return response()->json($result, 200);
        }
        //info
        if (strpos($request->message['text'], '/setinfo') !== false) {
            $userInfo = User::where('telegram_chat_id', $chat_id)->first();
            if ($userInfo) {
                $text = "คุณได้ตั้งค่าข้อมูลส่วนตัวของคุณไปแล้ว!\n";
                $text .= "ถ้าคุณต้องการแก้ไขข้อมูลให้ใช้คำสั่ง /editinfo";

                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                return response()->json($result, 200);
            }

            $text = "กรุณากรอกข้อมูลตามนี้:\n";
            $text .= "1. ชื่อ-นามสกุล\n";
            $text .= "2. รหัสนิสิต\n";
            $text .= "3. เบอร์โทรศัพท์\n";
            $text .= "4. สาขาวิชา\n";
            $text .= "5. สถานประกอบการ\n";
            $text .= "โปรดส่งข้อมูลในรูปแบบต่อไปนี้:\n";
            $text .= "/setinfo <ชื่อ-นามสกุล> <รหัสนิสิต> <เบอร์โทร> <สาขา> <สถานประกอบการ>";

            cache()->put("chat_id_{$chat_id}_user_info", true, now()->addMinutes(60));

            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

            return response()->json($result, 200);
        }

        if (cache()->has("chat_id_{$chat_id}_user_info")) {
            return $this->confirmUserInfo($request);
        }

        if ($request->message['text'] === '/editinfo') {
            $userInfo = $this->getUserInfo($chat_id);
            if ($userInfo) {
                $text = "ต้องการแก้ไขข้อมูลใด:\n";
                $text .= "1. ชื่อ-นามสกุล: {$userInfo['name']}\n";
                $text .= "2. รหัสนิสิต: {$userInfo['student_id']}\n";
                $text .= "3. เบอร์โทรศัพท์: {$userInfo['phone_number']}\n";
                $text .= "4. สาขาวิชา: {$userInfo['branch']}\n";
                $text .= "5. สถานประกอบการ: {$userInfo['company']}\n";
                $text .= "กรุณาตอบเป็นตัวเลข(1-5)";
                cache()->put("chat_id_{$chat_id}_edit_user_info", true, now()->addMinutes(10));
                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                return response()->json($result, 200);
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัว";
                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                return response()->json($result, 200);
            }
        }

        if (cache()->has("chat_id_{$chat_id}_edit_user_info")) {
            return $this->confirmEditUserEditInfo($request);
        }

        if ($request->message['text'] === '/getinfo') {
            $userInfo = $this->getUserInfo($chat_id);
            if ($userInfo) {
                $text = "ข้อมูลส่วนตัวของคุณ:\n";
                $text .= "1. ชื่อ-นามสกุล: {$userInfo['name']}\n";
                $text .= "2. รหัสนิสิต: {$userInfo['student_id']}\n";
                $text .= "3. เบอร์โทรศัพท์: {$userInfo['phone_number']}\n";
                $text .= "4. สาขาวิชา: {$userInfo['branch']}\n";
                $text .= "5. สถานประกอบการ: {$userInfo['company']}\n";
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
        //reminder
        if ($request->message['text'] === '/setreminder') {
            return $this->setReminder($request);
        }

        if (cache()->has("chat_id_{$chat_id}_setreminder")) {
            $step = cache()->get("chat_id_{$chat_id}_setreminder");
            $select = cache()->get("chat_id_{$chat_id}_select_type");
            if ($step === 'waiting_for_command') {
                $message = $request->message['text'];
                if ($message === '/formemo') {
                    $text = "ต้องการให้แจ้งเตือนจดบันทึกงานประจำวันกี่โมง?\n";
                    $text .= "กรุณาตอบในรูปแบบนาฬิกา 24 ชั่วโมง\n";
                    $text .= "ยกตัวอย่าง <10:00>\n";
                    cache()->put("chat_id_{$chat_id}_setreminder", 'waiting_for_time', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_type", '/formemo', now()->addMinutes(60));
                    $reply_to_message = $request->message['message_id'];
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                    return response()->json($result, 200);
                }
                if ($message === '/forsummary') {
                    $text = "ต้องการให้แจ้งเตือนสรุปงานประจำวันกี่โมง?\n";
                    $text .= "กรุณาตอบในรูปแบบนาฬิกา 24 ชั่วโมง\n";
                    $text .= "ยกตัวอย่าง <10:00>\n";
                    cache()->put("chat_id_{$chat_id}_setreminder", 'waiting_for_time', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_type", '/forsummary', now()->addMinutes(60));
                    $reply_to_message = $request->message['message_id'];
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
                    cache()->put("chat_id_{$chat_id}_setreminder", ['type' => '/formemo', 'time' => $time], now()->addMinutes(60));
                    cache()->forget("chat_id_{$chat_id}_select_type");
                    return response()->json($result, 200);
                }
                if ($select === '/forsummary') {
                    $time = $request->message['text'];

                    $text = "ให้แจ้งเตือนสรุปงานประจำวันในเวลา\n";
                    $text .= "{$time} น. ใช่ไหมคะ?\n";
                    $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                    $result = app('telegram_bot')->sendMessage($text, $chat_id);
                    cache()->put("chat_id_{$chat_id}_setreminder", ['type' => '/forsummary', 'time' => $time], now()->addMinutes(60));
                    cache()->forget("chat_id_{$chat_id}_select_type");
                    return response()->json($result, 200);
                }
            }
            return $this->handleReminderConfirmation($request);
        }

        if ($request->message['text'] === '/editreminder') {
            return $this->editReminder($request);
        }

        if (cache()->has("chat_id_{$chat_id}_editreminder")) {
            $step = cache()->get("chat_id_{$chat_id}_editreminder");
            $select = cache()->get("chat_id_{$chat_id}_select_type_edit");
            if ($step === 'waiting_for_command') {
                $message = $request->message['text'];
                if ($message === '1') {
                    $text = "ต้องการแก้ไขเวลาแจ้งเตือนจดบันทึกงานประจำวันกี่โมง?\n";
                    $text .= "กรุณาตอบในรูปแบบนาฬิกา 24 ชั่วโมง\n";
                    $text .= "ยกตัวอย่าง <10:00>\n";
                    cache()->put("chat_id_{$chat_id}_editreminder", 'waiting_for_time', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_type_edit", '/formemo', now()->addMinutes(60));
                    $reply_to_message = $request->message['message_id'];
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                    return response()->json($result, 200);
                }
                if ($message === '2') {
                    $text = "ต้องการแก้ไขเวลาสรุปงานประจำวันกี่โมง?\n";
                    $text .= "กรุณาตอบในรูปแบบนาฬิกา 24 ชั่วโมง\n";
                    $text .= "ยกตัวอย่าง <10:00>\n";
                    cache()->put("chat_id_{$chat_id}_editreminder", 'waiting_for_time', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_type_edit", '/forsummary', now()->addMinutes(60));
                    $reply_to_message = $request->message['message_id'];
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
                    cache()->put("chat_id_{$chat_id}_editreminder", ['type' => '/formemo', 'time' => $time], now()->addMinutes(60));
                    cache()->forget("chat_id_{$chat_id}_select_type_edit");
                    return response()->json($result, 200);
                }
                if ($select === '/forsummary') {
                    $time = $request->message['text'];

                    $text = "ให้แจ้งเตือนสรุปงานประจำวันในเวลา\n";
                    $text .= "{$time} น. ใช่ไหมคะ?\n";
                    $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
                    $result = app('telegram_bot')->sendMessage($text, $chat_id);
                    cache()->put("chat_id_{$chat_id}_editreminder", ['type' => '/forsummary', 'time' => $time], now()->addMinutes(60));
                    cache()->forget("chat_id_{$chat_id}_select_type_edit");
                    return response()->json($result, 200);
                }
            }
            return $this->handleEditReminderConfirmation($request);
        }

        if ($request->message['text'] === '/getreminder') {
            $userInfo = $this->getReminder($chat_id);
            $memoTime = Carbon::createFromFormat('H:i:s', $userInfo['memo_time'])->format('H:i');
            $summaryTime = Carbon::createFromFormat('H:i:s', $userInfo['summary_time'])->format('H:i');
            if (!empty($userInfo)) {
                $text = "แจ้งเตือนการจดบันทึกประจำวันเวลา: {$memoTime} น.\n";
                $text .= "แจ้งเตือนสรุปงานประจำวันเวลา: {$summaryTime} น.\n";
                $text .= "หากต้องการแก้ไข สามารถ /editreminder";
                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                return response()->json($result, 200);
            } else {
                $text = "คุณยังไม่ได้ตั้งค่าข้อมูลส่วนตัว!\n";
                $text .= "กรุณา /setinfo เพื่อตั้งค่าข้อมูลส่วนตัว";
                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                return response()->json($result, 200);
            }
        }
        //memo
        if ($request->message['text'] === '/memo') {
            return $this->memoDairy($request);
        }

        if (cache()->has("chat_id_{$chat_id}_startMemoDairy")) {
            $step = cache()->get("chat_id_{$chat_id}_startMemoDairy");
            if ($step === 'waiting_for_command') {
                $memoMessage = $request->message['text'];
                if ($memoMessage === '/end') {
                    $currentMemo = cache()->get("chat_id_{$chat_id}_memoDaily");
                    $formattedMemo = [];
                    foreach ($currentMemo as $key => $memo) {
                        $formattedMemo[] = ($key + 1) . ". " . $memo;
                    }
                    $text = "งานที่บันทึกในตอนนี้:\n" . implode("\n", $formattedMemo);
                    $text .= "\nถูกต้องมั้ยคะ? (yes/no)\n";
                    app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                    cache()->put("chat_id_{$chat_id}_startMemoDairy", 'waiting_for_time', now()->addMinutes(60));
                } else {
                    $memoMessages = cache()->get("chat_id_{$chat_id}_memoDaily", []);
                    $memoMessages[] = $memoMessage;
                    cache()->put("chat_id_{$chat_id}_memoDaily", $memoMessages, now()->addMinutes(60));
                }
            } elseif ($step === 'waiting_for_time') {
                $confirmationText = 'yes';
                $text_reply = '';
                $text = $request->message['text'];
                if ($text === $confirmationText) {
                    $currentMemo = cache()->get("chat_id_{$chat_id}_memoDaily");
                    $currentTime = Carbon::now();
                    if (!empty($currentMemo)) {
                        $formattedMemo = implode(', ', $currentMemo);
                        Memo::create(['user_id' => $chat_id, 'memo' => $formattedMemo, 'memo_date' => $currentTime]);
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
                cache()->forget("chat_id_{$chat_id}_startMemoDairy");
                cache()->forget("chat_id_{$chat_id}_memoDaily");
            }
        }

        if ($request->message['text'] === '/getmemo') {
            $userMemo = $this->getUserMemo($chat_id);
            if ($userMemo) {
                $memoArray = explode(', ', $userMemo['memo']);
                $formattedMemo = [];
                foreach ($memoArray as $key => $memo) {
                    $formattedMemo[] = ($key + 1) . ". " . $memo;
                }
                $text = "งานที่บันทึกในตอนนี้:\n" . implode("\n", $formattedMemo);
                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                return response()->json($result, 200);
            } else {
                $text = "คุณยังไม่ได้จดบันทึกงานประจำวัน!\n";
                $text .= "กรุณา /memo เพื่อเริ่มจดบันทึกประจำวัน";
                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                return response()->json($result, 200);
            }
        }

        if ($request->message['text'] === '/addmemo') {
            return $this->addMemoDairy($request);
        }

        if (cache()->has("chat_id_{$chat_id}_startAddMemoDairy")) {
            $step = cache()->get("chat_id_{$chat_id}_startAddMemoDairy");
            if ($step === 'waiting_for_command') {
                $memoMessage = $request->message['text'];
                $userMemo = $this->getUserMemo($chat_id);
                $memoArray = explode(', ', $userMemo['memo']);
                if ($memoMessage === '/end') {
                    $currentMemo = cache()->get("chat_id_{$chat_id}_addMemoDaily");
                    $formattedMemo = [];
                    foreach ($currentMemo as $key => $memo) {
                        $formattedMemo[] = ($key + 1) . ". " . $memo;
                    }
                    $text = "งานที่บันทึกในตอนนี้:\n" . implode("\n", $formattedMemo);
                    $text .= "\nถูกต้องมั้ยคะ? (yes/no)\n";
                    app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                    cache()->put("chat_id_{$chat_id}_startAddMemoDairy", 'waiting_for_time', now()->addMinutes(60));
                } else {
                    $memoArray = cache()->get("chat_id_{$chat_id}_addMemoDaily", $memoArray);
                    $memoArray[] = $memoMessage;
                    cache()->put("chat_id_{$chat_id}_addMemoDaily", $memoArray, now()->addMinutes(60));
                }
            } elseif ($step === 'waiting_for_time') {
                $confirmationText = 'yes';
                $text_reply = '';
                $text = $request->message['text'];
                if ($text === $confirmationText) {
                    $currentMemo = cache()->get("chat_id_{$chat_id}_addMemoDaily");

                    if (!empty($currentMemo)) {
                        $formattedMemo = implode(', ', $currentMemo);
                        Memo::where('user_id', $chat_id)->update(['memo' => $formattedMemo,]);
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
                cache()->forget("chat_id_{$chat_id}_startAddMemoDairy");
                cache()->forget("chat_id_{$chat_id}_addMemoDaily");
            }
        }

        if ($request->message['text'] === '/editmemo') {
            return $this->editMemoDairy($request);
        }

        if (cache()->has("chat_id_{$chat_id}_editMemoDairy")) {
            $step = cache()->get("chat_id_{$chat_id}_editMemoDairy");
            $select = cache()->get("chat_id_{$chat_id}_select_choice_edit");
            $userMemo = $this->getUserMemo($chat_id);
            $memoMessages = explode(', ', $userMemo['memo']);

            if ($step === 'waiting_for_command') {
                $selectedIndex = $request->message['text'];
                if ($selectedIndex >= 1 && $selectedIndex <= count($memoMessages)) {
                    $text = "สามารถพิมพ์ข้อความเพื่อแก้ไขงานประจำวันได้เลยค่ะ\n";
                    $text .= "(สามารถแก้ไขได้เพียงข้อที่เลือก)\n";
                    $text .= "'Create function CRUD'\n";
                    cache()->put("chat_id_{$chat_id}_editMemoDairy", 'updated', now()->addMinutes(60));
                    cache()->put("chat_id_{$chat_id}_select_choice_edit", $selectedIndex, now()->addMinutes(60));
                    $reply_to_message = $request->message['message_id'];
                    $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                    return response()->json($result, 200);
                }
            } elseif ($step === 'updated') {
                $select = cache()->get("chat_id_{$chat_id}_select_choice_edit");
                $memoMessages[$select - 1] = $request->message['text'];
                cache()->put("chat_id_{$chat_id}_memoDaily", $memoMessages, now()->addMinutes(60));
                $currentMemo = cache()->get("chat_id_{$chat_id}_memoDaily");
                $formattedMemo = [];
                foreach ($currentMemo as $key => $memo) {
                    $formattedMemo[] = ($key + 1) . ". " . $memo;
                }
                $text = "งานที่บันทึกในตอนนี้:\n" . implode("\n", $formattedMemo);
                $text .= "\nถูกต้องมั้ยคะ? (yes/no)\n";
                app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
                cache()->put("chat_id_{$chat_id}_editMemoDairy", 'waiting_for_time', now()->addMinutes(60));
            } elseif ($step === 'waiting_for_time') {
                $confirmationText = 'yes';
                $text_reply = '';
                $text = $request->message['text'];
                if ($text === $confirmationText) {
                    $currentMemo = cache()->get("chat_id_{$chat_id}_memoDaily");

                    if (!empty($currentMemo)) {
                        $formattedMemo = implode(', ', $currentMemo);
                        Memo::where('user_id', $chat_id)->update(['memo' => $formattedMemo,]);
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
                cache()->forget("chat_id_{$chat_id}_memoDaily");
                cache()->forget("chat_id_{$chat_id}_editMemoDairy");
                cache()->forget("chat_id_{$chat_id}_select_choice_edit");
            }
        }

        if ($request->message['text'] === '/resetmemo') {
            return $this->resetMemoDairy($request);
        }

        // if ($request->message['text'] === '/notetoday') {
        //     $text = "สามารถพิมพ์ข้อความใดๆเพื่อเพิ่มหมายเหตุได้เลยค่ะ\n";
        //     $text .= "ยกตัวอย่าง ‘วันหยุดปีใหม่’\n";
        //     $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
        //     cache()->put("chat_id_{$chat_id}_startNoteMemoDairy", true, now()->addMinutes(60));
        //     return $this->noteMemoDairy($request);
        // }

        if ($request->message['text'] === '/notetoday') {
            $text = "สามารถพิมพ์ข้อความใดๆเพื่อเพิ่มหมายเหตุได้เลยค่ะ\n";
            $text .= "ยกตัวอย่าง ‘วันหยุดปีใหม่’\n";
            cache()->put("chat_id_{$chat_id}_startNoteMemoDairy", true, now()->addMinutes(60));
            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
            return response()->json($result, 200);
        }

        if (cache()->has("chat_id_{$chat_id}_startNoteMemoDairy")) {
            return $this->confirmNoteMemo($request);
        }

        // if (cache()->has("chat_id_{$chat_id}_startNoteMemoDairy")) {
        //     $step = cache()->get("chat_id_{$chat_id}_startNoteMemoDairy");
        //     if ($step === 'waiting_for_command') {
        //         $memoMessage = $request->message['text'];
        //         cache()->put("chat_id_{$chat_id}_noteMemoDaily", $memoMessage, now()->addMinutes(60));
        //         cache()->put("chat_id_{$chat_id}_startNoteMemoDairy", 'waiting_for_time', now()->addMinutes(60));
        //     } elseif ($step === 'waiting_for_time') {
        //         $memoMessage = cache()->get("chat_id_{$chat_id}_noteMemoDaily");
        //         $reply_to_message = $request->message['message_id'];
        //         $text = "หมายเหตุของวันนี้:\n";
        //         $text .= "{$memoMessage}\nถูกต้องมั้ยคะ?";  
        //         $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
        //         cache()->forget("chat_id_{$chat_id}_startMemoDairy");
        //         app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
        //         return $this->handleNoteMemoConfirmation($request);
        //     }

        // }
    }
    public function confirmNoteMemo(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $reply_to_message = $request->message['message_id'] ?? null;
        if (cache()->has("chat_id_{$chat_id}_startNoteMemoDairy")) {
            $noteMemo = $request->message['text'];
            $text = "หมายเหตุของวันนี้:\n";
            $text .= "{$noteMemo}\nถูกต้องมั้ยคะ?";
            $text .= "(กรุณาตอบ yes หรือ /cancel)\n";
            app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
            cache()->put("chat_id_{$chat_id}_noteMemoDairy", $noteMemo, now()->addMinutes(10));
            cache()->forget("chat_id_{$chat_id}_startNoteMemoDairy");
        }
        if (cache()->has("chat_id_{$chat_id}_noteMemoDairy")) {
            return $this->handleNoteMemoConfirmation($request);
        }

    }


    public function editMemoDairy(Request $request)
    {
        $chat_id = $request->message['from']['id'];
        $reply_to_message = $request->message['message_id'];
        $userMemo = $this->getUserMemo($chat_id);
        if ($userMemo) {
            $currentMemo = explode(', ', $userMemo['memo']);
            $formattedMemo = [];
            foreach ($currentMemo as $key => $memo) {
                $formattedMemo[] = ($key + 1) . ". " . $memo;
            }
            $text = "กรุณาเลือกบันทึกที่ต้องการแก้ไข:\n" . implode("\n", $formattedMemo);
            $text .= "\nกรุณาตอบเพียงตัวเลขเดียวเท่านั้น ";
            cache()->put("chat_id_{$chat_id}_editMemoDairy", 'waiting_for_command', now()->addMinutes(60));
            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
            return response()->json($result, 200);
        } else {
            $text = "คุณยังไม่ได้จดบันทึกงานประจำวัน!\n";
            $text .= "กรุณา /memo เพื่อเริ่มจดบันทึกประจำวัน";
            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

            return response()->json($result, 200);
        }
    }
    public function addMemoDairy(Request $request)
    {
        $chat_id = $request->message['from']['id'];
        $reply_to_message = $request->message['message_id'];
        $text = "สามารถพิมพ์ข้อความใดๆเพื่อเพิ่มบันทึกงานประจำวันได้เลยค่ะ\n";
        $text .= "ยกตัวอย่าง 'Create function CRUD'\n";
        $text .= "เมื่อจดบันทึกครบแล้ว ให้พิมพ์ /end เพื่อจบการบันทึก\n";
        $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
        cache()->put("chat_id_{$chat_id}_startAddMemoDairy", 'waiting_for_command', now()->addMinutes(60));
        return response()->json($result, 200);

    }
    public function memoDairy(Request $request)
    {
        $chat_id = $request->message['from']['id'];
        $reply_to_message = $request->message['message_id'];
        $text = "สามารถพิมพ์ข้อความใดๆเพื่อจดบันทึกงานประจำวันได้เลยค่ะ\n";
        $text .= "ยกตัวอย่าง 'Create function CRUD'\n";
        $text .= "เมื่อจดบันทึกครบแล้ว ให้พิมพ์ /end เพื่อจบการบันทึก\n";
        $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
        cache()->put("chat_id_{$chat_id}_startMemoDairy", 'waiting_for_command', now()->addMinutes(60));
        cache()->put("chat_id_{$chat_id}_memoDaily", [], now()->addMinutes(60));
        return response()->json($result, 200);
    }



    public function resetMemoDairy(Request $request)
    {
        $chat_id = $request->message['from']['id'];
        $reply_to_message = $request->message['message_id'];
        $userMemo = $this->getUserMemo($chat_id);
        if ($userMemo) {
            $userMemo->memo = null;
        } else {
            $text = "คุณยังไม่ได้จดบันทึกงานประจำวัน!\n";
            $text .= "กรุณา /memo เพื่อเริ่มจดบันทึกประจำวัน";
            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
            return response()->json($result, 200);
        }
        cache()->forget("chat_id_{$chat_id}_memoDaily");
        $text = "ล้างบันทึกงานประจำวันเรียบร้อยแล้ว!\n";
        $text .= "สามารถพิมพ์ข้อความใดๆเพื่อจดบันทึกงานประจำวันได้เลยค่ะ\n";
        $text .= "ยกตัวอย่าง 'Create function CRUD'\n";
        $text .= "เมื่อจดบันทึกครบแล้ว ให้พิมพ์ /end เพื่อจบการบันทึก\n";
        $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
        cache()->put("chat_id_{$chat_id}_startMemoDairy", 'waiting_for_command', now()->addMinutes(60));
        cache()->put("chat_id_{$chat_id}_memoDaily", [], now()->addMinutes(60));
        return response()->json($result, 200);
    }
    //reminder
    public function setReminder(Request $request)
    {
        $chat_id = $request->message['from']['id'];
        $reply_to_message = $request->message['message_id'];
        $userInfo = $this->getReminder($chat_id);
        if ($userInfo['memo_time'] && $userInfo['summary_time']) {
            $text = "คุณตั้งค่าเวลาแจ้งเตือนจดบันทึกงานประจำวัน\n";
            $text .= "และตั้งค่าเวลาสรุปงานประจำวัน เรียบร้อยแล้ว!\n";
            $text .= "หากต้องการแก้ไข สามารถ /editreminder";
            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

            return response()->json($result, 200);
        } else if ($userInfo['memo_time'] && !$userInfo['summary_time']) {
            $text = "คุณตั้งค่าเวลาแจ้งเตือนจดบันทึกงานประจำวัน เรียบร้อยแล้ว!\n";
            $text .= "กรุณา /forsummary เพื่อตั้งค่าแจ้งเตือนสรุปงานประจำวัน\n";
            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
            cache()->put("chat_id_{$chat_id}_setreminder", 'waiting_for_command', now()->addMinutes(60));

            return response()->json($result, 200);
        } else if (!$userInfo['memo_time'] && $userInfo['summary_time']) {
            $text = "คุณตั้งค่าเวลาแจ้งเตือนจดบันทึกงานประจำวัน เรียบร้อยแล้ว!\n";
            $text .= "กรุณา /formemo เพื่อตั้งค่าแจ้งเตือนสรุปงานประจำวัน\n";
            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);
            cache()->put("chat_id_{$chat_id}_setreminder", 'waiting_for_command', now()->addMinutes(60));

            return response()->json($result, 200);
        } else {
            $text = "กรุณาเลือกประเภทการแจ้งเตือนเพื่อตั้งค่าเวลา:\n";
            $text .= "1. /formemo - แจ้งเตือนจดบันทึกงานประจำวัน\n";
            $text .= "2. /forsummary - แจ้งเตือนสรุปงานประจำวัน\n";
            $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

            cache()->put("chat_id_{$chat_id}_setreminder", 'waiting_for_command', now()->addMinutes(60));

            return response()->json($result, 200);
        }

    }

    public function editReminder(Request $request)
    {
        $chat_id = $request->message['from']['id'];
        $reply_to_message = $request->message['message_id'];
        $text = "กรุณาเลือกประเภทการแจ้งเตือนเพื่อแก้ไขเวลา:\n";
        $text .= "1. แก้ไขเวลาแจ้งเตือนจดบันทึกงานประจำวัน\n";
        $text .= "2. แก้ไขเวลาแจ้งเตือนสรุปงานประจำวัน\n";
        $text .= "กรุณาตอบเป็นตัวเลข(1-2)\n";
        $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

        cache()->put("chat_id_{$chat_id}_editreminder", 'waiting_for_command', now()->addMinutes(60));

        return response()->json($result, 200);
    }

    //setinfo
    public function confirmUserInfo(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $reply_to_message = $request->message['message_id'] ?? null;

        if (cache()->has("chat_id_{$chat_id}_user_info")) {
            $userInformationLines = explode("\n", $request->message['text']);

            if (count($userInformationLines) >= 5) {
                $name = trim($userInformationLines[0]);
                $student_id = trim($userInformationLines[1]);
                $phone_number = trim(preg_replace('/\D/', '', $userInformationLines[2])); // Remove non-numeric characters
                $branch = isset($userInformationLines[3]) ? trim($userInformationLines[3]) : '';
                $company = isset($userInformationLines[4]) ? trim($userInformationLines[4]) : '';

                $text = "ข้อมูลที่คุณกรอกมีดังนี้:\n";
                $text .= "ชื่อ-นามสกุล: $name\n";
                $text .= "รหัสนิสิต: $student_id\n";
                $text .= "เบอร์โทรศัพท์: $phone_number\n";
                $text .= "สาขาวิชา: $branch\n";
                $text .= "สถานประกอบการ: $company\n";
                $text .= "ถูกต้องมั้ยคะ? (yes/no)";

                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                cache()->put("chat_id_{$chat_id}_user_info", compact('name', 'student_id', 'phone_number', 'branch', 'company'), now()->addMinutes(10));

                return response()->json($result, 200);
            }

            if (cache()->has("chat_id_{$chat_id}_user_info")) {
                \Log::info('Calling confirmUserInfo function.');
                return $this->handleConfirmation($request);
            }
        }

        return response()->json(['message' => 'User information not found.'], 404);
    }

    private function handleConfirmation(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $reply_to_message = $request->message['message_id'] ?? null;
        $text = strtolower(trim($request->input('message.text')));

        $confirmationText = 'yes';

        if ($text === $confirmationText) {
            $userInformation = cache()->get("chat_id_{$chat_id}_user_info");
            if ($userInformation) {
                $this->handleYes($userInformation, $chat_id);
                app('telegram_bot')->sendMessage("บันทึกข้อมูลเรียบร้อยแล้ว", $chat_id, $reply_to_message);
                cache()->forget("chat_id_{$chat_id}_user_info");
            } else {
                app('telegram_bot')->sendMessage("ไม่พบข้อมูล user", $chat_id, $reply_to_message);
            }
        } elseif ($text === '/cancel') {
            app('telegram_bot')->sendMessage("ยกเลิกการ /setinfo", $chat_id, $reply_to_message);
            cache()->forget("chat_id_{$chat_id}_user_info");
        } else {
            app('telegram_bot')->sendMessage("กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ", $chat_id, $reply_to_message);
        }
    }
    public function handleYes(array $userInformation, $chat_id)
    {
        User::create([
            'name' => $userInformation['name'],
            'student_id' => $userInformation['student_id'],
            'phone_number' => $userInformation['phone_number'],
            'branch' => $userInformation['branch'],
            'company' => $userInformation['company'],
            'telegram_chat_id' => $chat_id
        ]);
    }

    //editinfo

    public function getUserInfo($telegram_chat_id)
    {
        $userInfo = User::where('telegram_chat_id', $telegram_chat_id)->first();
        return $userInfo;
    }

    public function confirmEditUserEditInfo(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $reply_to_message = $request->message['message_id'] ?? null;

        if (cache()->has("chat_id_{$chat_id}_edit_user_info")) {
            $userInformationLines = explode("\n", $request->message['text']);
            if (count($userInformationLines) >= 2) {
                $number = trim($userInformationLines[0]);
                $textUpdate = trim($userInformationLines[1]);

                $text = "ข้อมูลที่คุณกรอกมีดังนี้:\n";
                $text .= "$number\n";
                $text .= "ข้อมูลใหม่: $textUpdate\n";
                $text .= "ถูกต้องมั้ยคะ? (yes/no)";

                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                cache()->put("chat_id_{$chat_id}_edit_user_info", compact('number', 'textUpdate'), now()->addMinutes(10));

                return response()->json($result, 200);
            }

            if (cache()->has("chat_id_{$chat_id}_edit_user_info")) {
                \Log::info('Calling confirmUserInfo function.');
                return $this->handleEditConfirmation($request);
            }
        }

        return response()->json(['message' => 'User information not found.'], 404);
    }

    private function handleEditConfirmation(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $reply_to_message = $request->message['message_id'] ?? null;
        $text = strtolower(trim($request->input('message.text')));
        $confirmationText = 'yes';

        if ($text === $confirmationText) {
            $userInformation = cache()->get("chat_id_{$chat_id}_edit_user_info");
            if ($userInformation) {

                switch ($userInformation['number']) {
                    case '1':
                        User::where('telegram_chat_id', $chat_id)->update([
                            'name' => $userInformation['textUpdate'],
                        ]);
                        break;
                    case '2':
                        User::where('telegram_chat_id', $chat_id)->update([
                            'student_id' => $userInformation['textUpdate'],
                        ]);
                        break;
                    case '3':
                        User::where('telegram_chat_id', $chat_id)->update([
                            'phone_number' => $userInformation['textUpdate'],
                        ]);
                        break;
                    case '4':
                        User::where('telegram_chat_id', $chat_id)->update([
                            'branch' => $userInformation['textUpdate'],
                        ]);
                        break;
                    case '5':
                        User::where('telegram_chat_id', $chat_id)->update([
                            'company' => $userInformation['textUpdate'],
                        ]);
                        break;
                    default:
                        break;
                }

                app('telegram_bot')->sendMessage("บันทึกข้อมูลเรียบร้อยแล้ว", $chat_id, $reply_to_message);
                cache()->forget("chat_id_{$chat_id}_edit_user_info");
            } else {
                app('telegram_bot')->sendMessage("ไม่พบข้อมูล user", $chat_id, $reply_to_message);
            }
        } elseif ($text === '/cancel') {
            app('telegram_bot')->sendMessage("ยกเลิกการ /editinfo", $chat_id, $reply_to_message);
            cache()->forget("chat_id_{$chat_id}_edit_user_info");
        } else {
            app('telegram_bot')->sendMessage("กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ", $chat_id, $reply_to_message);
        }
    }
    //setreminder
    private function handleReminderConfirmation(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $reply_to_message = $request->message['message_id'] ?? null;
        $text = strtolower(trim($request->input('message.text')));
        $confirmationText = 'yes';
        $text_reply = '';
        if ($text === $confirmationText) {
            $setReminderTime = cache()->get("chat_id_{$chat_id}_setreminder");
            if ($setReminderTime) {
                switch ($setReminderTime['type']) {
                    case '/formemo':
                        User::where('telegram_chat_id', $chat_id)->update([
                            'memo_time' => $setReminderTime['time'],
                        ]);
                        $text_reply = "ตั้งค่าเวลาแจ้งเตือนเริ่มจดบันทึกงานประจำวันเรียบร้อยแล้ว!";
                        break;
                    case '/forsummary':
                        User::where('telegram_chat_id', $chat_id)->update([
                            'summary_time' => $setReminderTime['time'],
                        ]);
                        $text_reply = "ตั้งค่าเวลาแจ้งเตือนสรุปงานประจำวันเรียบร้อยแล้ว!";
                        break;
                    default:
                        break;
                }
                app('telegram_bot')->sendMessage($text_reply, $chat_id, $reply_to_message);
                cache()->forget("chat_id_{$chat_id}_setreminder");
            } else {
                app('telegram_bot')->sendMessage("ไม่พบข้อมูล user", $chat_id, $reply_to_message);
            }
        } elseif ($text === '/cancel') {
            app('telegram_bot')->sendMessage("ยกเลิกการ /setreminder", $chat_id, $reply_to_message);
            cache()->forget("chat_id_{$chat_id}_setreminder");
        } else {
            app('telegram_bot')->sendMessage("กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ", $chat_id, $reply_to_message);
        }
    }

    private function handleEditReminderConfirmation(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $reply_to_message = $request->message['message_id'] ?? null;
        $text = strtolower(trim($request->input('message.text')));
        $confirmationText = 'yes';
        $text_reply = '';
        if ($text === $confirmationText) {
            $setReminderTime = cache()->get("chat_id_{$chat_id}_editreminder");
            if ($setReminderTime) {

                switch ($setReminderTime['type']) {
                    case '/formemo':
                        User::where('telegram_chat_id', $chat_id)->update([
                            'memo_time' => $setReminderTime['time'],
                        ]);
                        $text_reply = "แก้ไขเวลาแจ้งเตือนเริ่มจดบันทึกงานประจำวันเรียบร้อยแล้ว!";
                        break;
                    case '/forsummary':
                        User::where('telegram_chat_id', $chat_id)->update([
                            'summary_time' => $setReminderTime['time'],
                        ]);
                        $text_reply = "แก้ไขเวลาแจ้งเตือนสรุปงานประจำวันเรียบร้อยแล้ว!";
                        break;
                    default:
                        break;
                }
                app('telegram_bot')->sendMessage($text_reply, $chat_id, $reply_to_message);
                cache()->forget("chat_id_{$chat_id}_editreminder");
            } else {
                app('telegram_bot')->sendMessage("ไม่พบข้อมูล user", $chat_id, $reply_to_message);
            }
        } elseif ($text === '/cancel') {
            app('telegram_bot')->sendMessage("ยกเลิกการ /setreminder", $chat_id, $reply_to_message);
            cache()->forget("chat_id_{$chat_id}_editreminder");
        } else {
            app('telegram_bot')->sendMessage("กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ", $chat_id, $reply_to_message);
        }
    }
    public function getReminder($telegram_chat_id)
    {
        $userReminder = User::where('telegram_chat_id', $telegram_chat_id)->first();
        return $userReminder;
    }

    //memo
    private function handleNoteMemoConfirmation(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $reply_to_message = $request->message['message_id'] ?? null;
        $text = strtolower(trim($request->input('message.text')));
        $confirmationText = 'yes';
        $text_reply = '';
        if ($text === $confirmationText) {
            $noteMemoToday = cache()->get("chat_id_{$chat_id}_noteMemoDairy");
            // User::where('telegram_chat_id', $chat_id)->update(['memo_time' => $setReminderTime['time']]); //แก้ table ตรงนี้
            $text_reply = "บันทึกหมายเหตุของวันนี้เรียบร้อยแล้วค่ะ!";
            $text_reply .= "{$noteMemoToday}";
            app('telegram_bot')->sendMessage($text_reply, $chat_id, $reply_to_message);
            cache()->forget("chat_id_{$chat_id}_noteMemoDairy");
        } elseif ($text === '/cancel') {
            app('telegram_bot')->sendMessage("ยกเลิกการ /notetoday", $chat_id, $reply_to_message);
            cache()->forget("chat_id_{$chat_id}_noteMemoDairy");
        } else {
            app('telegram_bot')->sendMessage("กรุณาตอบด้วย 'yes' หรือ '/cancel' เท่านั้นค่ะ", $chat_id, $reply_to_message);
        }
    }
    public function getUserMemo($telegram_chat_id)
    {
        $userMemo = Memo::where('user_id', $telegram_chat_id)->first();
        return $userMemo;
    }
}

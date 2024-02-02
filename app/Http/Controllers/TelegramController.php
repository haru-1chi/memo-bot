<?php

namespace App\Http\Controllers;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
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
        // \Log::info(print_r($request->all(), true));

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
            $chat_id = $request->message['from']['id'] ?? null;

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
            $userInfo = User::where('telegram_chat_id', $chat_id)->first();
            $userInfo = $this->getUserInfo($chat_id);
            if (!empty($userInfo)) {
                $text = "ต้องการแก้ไขข้อมูลใด:\n";
                $text .= "1. ชื่อ-นามสกุล: {$userInfo['name']}\n";
                $text .= "2. รหัสนิสิต: {$userInfo['student_id']}\n";
                $text .= "3. เบอร์โทรศัพท์: {$userInfo['phone_number']}\n";
                $text .= "4. สาขาวิชา: {$userInfo['faculty']}\n";
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
            if (!empty($userInfo)) {
                $text = "ข้อมูลส่วนตัวของคุณ:\n";
                $text .= "1. ชื่อ-นามสกุล: {$userInfo['name']}\n";
                $text .= "2. รหัสนิสิต: {$userInfo['student_id']}\n";
                $text .= "3. เบอร์โทรศัพท์: {$userInfo['phone_number']}\n";
                $text .= "4. สาขาวิชา: {$userInfo['faculty']}\n";
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
                // User sent a new message, process it
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
    }

    public function setReminder(Request $request)
    {
        $chat_id = $request->message['from']['id'];
        $reply_to_message = $request->message['message_id'];
        $text = "กรุณาเลือกประเภทการแจ้งเตือนเพื่อตั้งค่าเวลา:\n";
        $text .= "1. /formemo - แจ้งเตือนจดบันทึกงานประจำวัน\n";
        $text .= "2. /forsummary - แจ้งเตือนสรุปงานประจำวัน\n";
        $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

        // Cache the current step in the conversation
        cache()->put("chat_id_{$chat_id}_setreminder", 'waiting_for_command', now()->addMinutes(60));

        return response()->json($result, 200);
    }


    //setinfo
    public function confirmUserInfo(Request $request)
    {
        $chat_id = $request->message['from']['id'] ?? null;
        $reply_to_message = $request->message['message_id'] ?? null;

        if (cache()->has("chat_id_{$chat_id}_user_info")) {
            $userInformationLines = explode("\n", $request->message['text']); //explode as array

            if (count($userInformationLines) >= 5) {
                $name = trim($userInformationLines[0]);
                $student_id = trim($userInformationLines[1]);
                $phone_number = trim(preg_replace('/\D/', '', $userInformationLines[2])); // Remove non-numeric characters
                $faculty = isset($userInformationLines[3]) ? trim($userInformationLines[3]) : '';
                $company = isset($userInformationLines[4]) ? trim($userInformationLines[4]) : '';

                $text = "ข้อมูลที่คุณกรอกมีดังนี้:\n";
                $text .= "ชื่อ-นามสกุล: $name\n";
                $text .= "รหัสนิสิต: $student_id\n";
                $text .= "เบอร์โทรศัพท์: $phone_number\n";
                $text .= "สาขาวิชา: $faculty\n";
                $text .= "สถานประกอบการ: $company\n";
                $text .= "ถูกต้องมั้ยคะ? (yes/no)";

                $result = app('telegram_bot')->sendMessage($text, $chat_id, $reply_to_message);

                cache()->put("chat_id_{$chat_id}_user_info", compact('name', 'student_id', 'phone_number', 'faculty', 'company'), now()->addMinutes(10));

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
            'faculty' => $userInformation['faculty'],
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
                            'faculty' => $userInformation['textUpdate'],
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

}

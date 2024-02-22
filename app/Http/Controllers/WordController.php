<?php

// namespace App\Http\Controllers;
// use PhpOffice\PhpWord\TemplateProcessor;
// use App\Models\User;
// use Illuminate\Http\Request;

// class WordController extends Controller
// {
//     public function index()
//     {
//         $users = User::all();
//         return view(view: 'user.index');
//     }

//     public function wordExport($id)
//     {
//         $users = User::findOrFail($id);
//         $templateProcessor = new TemplateProcessor(documentTemplate: 'word-templete/user.doc');
//         $templateProcessor->setValue(search:'id', $users->id);
//         $templateProcessor->setValue(search:'name', $users->name);
//         $fileName = $users->name;
//         $templateProcessor->saveAs(fileName: $fileName.'.docx');
//     return response()->download(file: $fileName.'.docx')->deleteFileAfterSend(shouldDelete:true);
//     }
// }
namespace App\Http\Controllers;

use PhpOffice\PhpWord\TemplateProcessor;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\Memo;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use Carbon\Carbon;

class WordController extends Controller
{


    public function testRender()
    {
        $weekNumber = 1;
        $memos = Memo::all();
        return view('word-summary', compact('weekNumber', 'memos'));
    }

    public function downloadDocx()
    {
        $documentPath = $this->generateDocument();
        return response()->download($documentPath, 'memo_week.docx');
    }


    public function generateDocument(Request $request)
{
    $chat_id = $request->message['from']['id'] ?? null;
    $userInfo = $this->getUserInfo($chat_id);
    $directory = 'word-send';

    if (!file_exists(public_path($directory))) {
        mkdir(public_path($directory), 0777, true);
    }

    $templateProcessor = new TemplateProcessor('word-template/user.docx');
    $currentWeekNumber = 0;

    $memos = Memo::where('user_id', $chat_id)->get();

    foreach ($memos as $memo) {
        $memoDate = Carbon::parse($memo->memo_date);
        $weekNumber = $memoDate->weekOfYear;

        if ($weekNumber > $currentWeekNumber) {
            $currentWeekNumber = $weekNumber;
        }

        $weekdayIndex = $memoDate->dayOfWeekIso;
        $templateProcessor->setValue("memo_date_$weekdayIndex", $memo->memo_date);
        for ($i = 0; $i < 5; $i++) {
            $templateProcessor->setValue("memo[$i]_$weekdayIndex", $this->getMemo($memo->memo, $i));
        }
        $templateProcessor->setValue("note_today_$weekdayIndex", $memo->note_today);
    }

    $fileName = $userInfo['student_id'] . '_week' . $currentWeekNumber . '_memo.docx';
    $filePath = public_path($directory . DIRECTORY_SEPARATOR . $fileName);
    $templateProcessor->saveAs($filePath);

    return $filePath;
}

    private function getMemo($memo, $index)
    {
        $memoArray = explode(',', $memo);
        return isset($memoArray[$index]) ? trim($memoArray[$index]) : '……………………………………………………………………………………';
    }

}
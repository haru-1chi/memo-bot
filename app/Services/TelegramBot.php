<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TelegramBot
{
    protected $token;
    protected $api_endpoint;
    protected $headers;

    public function __construct()
    {
        $this->token = env('TELEGRAM_BOT_TOKEN');
        $this->api_endpoint = env('TELEGRAM_API_ENDPOINT');
        $this->setHeaders();
    }

    protected function setHeaders()
    {
        $this->headers = [
            "Content-Type" => "application/json",
            "Accept" => "application/json",
        ];
    }

    public function sendMessage($text = '', $chat_id, $reply_to_message_id = null)
    {
        // Default result array
        $result = ['success' => false, 'body' => []];

        // Create params array
        $params = [
            'chat_id' => $chat_id,
            'text' => $text,
        ];

        // Add reply_to_message_id to params if it is provided
        if (!is_null($reply_to_message_id)) {
            $params['reply_to_message_id'] = $reply_to_message_id;
        }

        // Create URL -> https://api.telegram.org/bot{token}/sendMessage
        $url = "{$this->api_endpoint}/{$this->token}/sendMessage";

        // Send the request
        try {
            $response = Http::withHeaders($this->headers)->post($url, $params);
            $result = ['success' => $response->ok(), 'body' => $response->json()];

            // \Log::info('TelegramBot->sendMessage->request', ['request' => compact('url', 'params')]);
        } catch (\Throwable $th) {
            $result['error'] = $th->getMessage();
        }

        \Log::info('TelegramBot->sendMessage->result', ['result' => $result]);

        return $result;
    }

    // public function sendDocument($chat_id, $documentPath, $caption = null)
    // {
    //     // Default result array
    //     $result = ['success' => false, 'body' => []];

    //     // Create URL for sending document
    //     $url = "{$this->api_endpoint}/{$this->token}/sendDocument";

    //     // Prepare the parameters for the request
    //     $params = [
    //         'chat_id' => $chat_id,
    //         'document' => fopen($documentPath, 'r'),
    //         'caption' => $caption,
    //     ];

    //     // Send the request
    //     try {
    //         $response = Http::withHeaders($this->headers)->post($url, $params);
    //         $result = ['success' => $response->ok(), 'body' => $response->json()];
    //     } catch (\Throwable $th) {
    //         $result['error'] = $th->getMessage();
    //     }

    //     return $result;
    // }

    // $telegramBot = new TelegramBot();

    // // Call the sendDocument method with the chat ID and the path to the .docx file
// $documentPath = 'path/to/your/document.docx';
// $chatId = 'your_chat_id';
// $result = $telegramBot->sendDocument($chatId, $documentPath, 'Optional caption for the document');

}

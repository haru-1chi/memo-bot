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

    public function sendDocument($chatId, $filePath)
    {
        // Initialize cURL session
        $fileName = pathinfo($filePath, PATHINFO_BASENAME);

        $ch = curl_init();

        // Set URL for sending document
        $url = "{$this->api_endpoint}/{$this->token}/sendDocument?chat_id={$chatId}";

        // Configure cURL options
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);

        // Create CURLFile object for the document
        $finfo = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $filePath);
        $cFile = new \CURLFile($filePath, $finfo, $fileName);

        // Add the document to the POST data
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            "document" => $cFile,
            "caption" => $fileName
        ]);

        // Execute the cURL request
        $result = curl_exec($ch);

        // Close the cURL session
        curl_close($ch);
        
        // Return the result of the request
        return $result;
    }

    // public function sendDocument($chat_id, $documentPath, $caption)
    // {
    //     // Default result array
    //     $result = ['success' => false, 'body' => []];

    //     // Create URL for sending document
    //     $params = [
    //         'chat_id' => $chat_id,
    //         'document' => fopen($documentPath, 'r'),//
    //         'caption' => $caption,
    //     ];

    //     $url = "{$this->api_endpoint}/{$this->token}/sendDocument";

    //     try {
    //         $response = Http::withHeaders($this->headers)->post($url, $params);
    //         $result = ['success' => $response->ok(), 'body' => $response->json()];
    //     } catch (\Throwable $th) {
    //         $result['error'] = $th->getMessage();
    //     }
    //     \Log::info('TelegramBot->sendDocument->result', ['result' => $result]);
    //     return $result;
    // }
    // public function sendDocument($chat_id, $documentPath, $caption)
    // {
    //     // Default result array
    //     $url = "{$this->api_endpoint}/{$this->token}/sendDocument";

    //     // Prepare the parameters for the request
    //     $params = [
    //         'chat_id' => $chat_id,
    //         'document' => new \CURLFile($documentPath),
    //         'caption' => $caption,
    //     ];

    //     try {
    //         // Send the request to upload the document
    //         $response = Http::withHeaders($this->headers)->post($url, $params);
    //         $result = ['success' => $response->ok(), 'body' => $response->json()];

    //         // Extract the file_id from the response
    //         $file_id = $result['body']['result']['document']['file_id'];

    //     } catch (\Throwable $th) {
    //         // Handle exceptions
    //         $result['error'] = $th->getMessage();
    //     }
    //     return $file_id;
    // }
    // public function getFileUrl($file_ids)
    // {

    //     $file_url = '';

    //     $file_id = $file_ids;

    //     // set url -> https://api.telegram.org/bot<Your-Bot-token>/getFile?file_id=<Your-file-id>
    //     $url = "{$this->api_endpoint}/{$this->token}/getFile?file_id={$file_id}";

    //     // Send the request
    //     try {

    //         $response = Http::withHeaders($this->headers)->get($url);
    //         $result = ['success' => $response->ok(), 'body' => $response->json()];

    //         $file_path = $result['body']['result']['file_path'];

    //         // https://api.telegram.org/file/bot<Your-Bot-token>/<Your-file-path>
    //         $file_url = "{$this->api_endpoint}/file/{$this->token}/{$file_path}";

    //     } catch (\Throwable $th) {

    //         $result['error'] = $th->getMessage();
    //     }

    //     \Log::info('TelegramBot->getFileUrl->result', ['result' => $result]);


    //     return $file_url;
    // }


}



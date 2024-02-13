<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WordController;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test-render', [WordController::class, 'testRender']);
Route::get('/downloadDocx', [WordController::class, 'downloadDocx']);

Route::get('/doc-generate',function(){
    $headers = array(
        'Content-type'=>'text/html',
        'Content-Disposition'=>'attatchement;Filename=mydoc.docx'
    );
    return \Response::make(view('word-summary'), 200,$headers);
});
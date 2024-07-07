<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use FFMpeg;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class YouTubeController extends Controller
{
    public function convert(Request $request)
    {
        $url = $request->input('url');
        $apiKey = env('YOUTUBE_API_KEY');
        
        // YouTubeの動画IDを抽出する
        preg_match('/[\\?\\&]v=([^\\?\\&]+)/', $url, $matches);
        $videoId = $matches[1] ?? null;
        
        if (!$videoId) {
            return redirect()->back()->with('error', 'Invalid YouTube URL');
        }
        
        // YouTube APIを使用して動画情報を取得する
        $response = Http::get('https://www.googleapis.com/youtube/v3/videos', [
            'id' => $videoId,
            'part' => 'snippet',
            'key' => $apiKey
        ]);
        
        if ($response->failed()) {
            Log::error('Failed to fetch video information', ['response' => $response->json()]);
            return redirect()->back()->with('error', 'Failed to fetch video information');
        }
        
        $videoInfo = $response->json();
        $thumbnailUrl = $videoInfo['items'][0]['snippet']['thumbnails']['high']['url'] ?? null;
        $title = $videoInfo['items'][0]['snippet']['title'] ?? 'Unknown Title';
        
        if (!$thumbnailUrl) {
            return redirect()->back()->with('error', 'Thumbnail not found');
        }

        // セッションにデータを保存
        Session::flash('thumbnail', $thumbnailUrl);
        Session::flash('title', $title);
        Session::flash('url', $url);
        
        return redirect()->back();
    }

    public function convertToMp3(Request $request)
    {
        set_time_limit(600); // 最大実行時間を300秒に設定
        
        $videoUrl = $request->input('url');
        Log::info('Received video URL: ' . $videoUrl);
    
        // YouTube APIを使用してサムネイルとタイトルを取得
        $videoId = explode('v=', $videoUrl)[1];
        $apiKey = env('YOUTUBE_API_KEY');
        $apiUrl = "https://www.googleapis.com/youtube/v3/videos?id={$videoId}&key={$apiKey}&part=snippet";
    
        $client = new \GuzzleHttp\Client();
        $response = $client->get($apiUrl);
        $videoData = json_decode($response->getBody(), true);
    
        if (!isset($videoData['items'][0])) {
            return response()->json(['error' => 'Failed to fetch video details'], 500);
        }
    
        $title = $videoData['items'][0]['snippet']['title'];
        $thumbnail = $videoData['items'][0]['snippet']['thumbnails']['high']['url'];
    
        // タイトルをファイル名として使用（無効な文字を削除）
        $sanitizedTitle = preg_replace('/[^\p{L}\p{N}ぁ-んァ-ヶー一-龠、"_\-()]/u', '_', $title);
    
        $outputFile = storage_path("app/public/temp/{$sanitizedTitle}.mp4");
        Log::info('Output file path: ' . $outputFile);
    
        // yt-dlp コマンドを構成
        $command = base_path('venv/Scripts/yt-dlp.exe') . ' -o ' . $outputFile . ' ' . $videoUrl;
        Log::info('Running yt-dlp process', ['command' => $command]);
    
        // コマンドを実行し、出力をキャプチャ
        exec($command . ' 2>&1', $output, $return_var);
    
        // 出力とエラー出力をログに記録
        Log::info('yt-dlp process output', ['output' => implode("\n", $output)]);
        if ($return_var !== 0) {
            Log::error('yt-dlp process error', ['error_output' => implode("\n", $output)]);
            return response()->json(['error' => 'Video download failed: ' . implode("\n", $output)], 500);
        }
    
        // ffmpegを使用してMP3に変換
        $mp3File = storage_path("app/public/temp/{$sanitizedTitle}.mp3");
        $ffmpegCommand = 'C:\ffmpeg\bin\ffmpeg.exe -i ' . $outputFile . ' ' . $mp3File;
        Log::info('Running ffmpeg process', ['command' => $ffmpegCommand]);
    
        // コマンドを実行し、出力をキャプチャ
        exec($ffmpegCommand . ' 2>&1', $ffmpegOutput, $ffmpegReturnVar);
    
        // 出力とエラー出力をログに記録
        Log::info('ffmpeg process output', ['output' => implode("\n", $ffmpegOutput)]);
        if ($ffmpegReturnVar !== 0) {
            Log::error('ffmpeg process error', ['error_output' => implode("\n", $ffmpegOutput)]);
            return response()->json(['error' => 'MP3 conversion failed: ' . implode("\n", $ffmpegOutput)], 500);
        }
    
        // 相対パスを使用してMP3ファイルを保存
        $mp3FilePath = "temp/{$sanitizedTitle}.mp3";
    
        return redirect()->back()->with([
            'mp3_file' => $mp3FilePath,
            'title' => $title,
            'thumbnail' => $thumbnail,
            'url' => $videoUrl, // splitボタンに必要な情報を追加
        ]);
    }

    public function splitMp3(Request $request)
    {
        $mp3FilePath = storage_path('app/public/' . $request->input('mp3_file'));
        $outputDir = storage_path('app/public/temp/split');
        $shazamApiKey = env('SHAZAM_API_KEY');
        
        Log::info('MP3 file path: ' . $mp3FilePath);
        Log::info('Output directory: ' . $outputDir);
        Log::info('SHAZAM API key: ' . $shazamApiKey);
        
        $pythonPath = 'C:\Users\k-kousaka\AppData\Local\Programs\Python\Python312\python.exe';  // ここに確認したPythonのパスを設定します
        
        $command = [
            $pythonPath,
            base_path('python_scripts/split_mp3.py'),
            $mp3FilePath,
            $outputDir,
            $shazamApiKey
        ];
        
        Log::info('Running split_mp3.py process', ['command' => implode(' ', $command)]);
        
        // プロセスを実行し、出力とエラー出力をキャプチャ
        $process = new Process($command);
        $process->run();
        
        // 出力とエラー出力をログに記録
        Log::info('split_mp3.py process output', ['output' => $process->getOutput()]);
        Log::error('split_mp3.py process error', ['error_output' => $process->getErrorOutput()]);
        
        if (!$process->isSuccessful()) {
            return response()->json(['error' => 'Failed to split the MP3 file. See logs for details.'], 500);
        }
        
        // 出力ディレクトリ内のファイルを取得
        $splitFiles = array_diff(scandir($outputDir), ['.', '..']);
        
        // ファイルパスをレスポンスに含める
        $filePaths = [];
        foreach ($splitFiles as $file) {
            $filePaths[] = asset('storage/temp/split/' . $file);
        }
        
        return response()->json(['message' => 'MP3 file has been successfully split.', 'files' => $filePaths]);
    }
}
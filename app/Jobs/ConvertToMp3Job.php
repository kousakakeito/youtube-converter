<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use FFMpeg;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\File;
use Exception;

class ConvertToMp3Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $videoUrl;
    public $tries = 5; 
    public $timeout = 3600; // タイムアウトを600秒に設定

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($videoUrl)
    {
        $this->videoUrl = $videoUrl;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $videoUrl = $this->videoUrl;

            Log::info('Starting MP3 conversion for URL: ' . $this->videoUrl);
            
            // 進捗状況の初期化
            Cache::put('progress2', 0);
            Cache::put('job_status', 'running');
    
            set_time_limit(600);
    
            $tempDir = storage_path('app/public/temp');
            $splitDir = storage_path('app/public/temp/split');
    
            $this->convertToMp3ProcessStep1();
    
            $files = File::files($tempDir);
            foreach ($files as $file) {
                if (in_array($file->getExtension(), ['mp3', 'mp4'])) {
                    File::delete($file->getPathname());
                }
            }

            $this->convertToMp3ProcessStep2();
    
            $splitFiles = File::exists($splitDir) ? File::files($splitDir) : [];
            foreach ($splitFiles as $splitFile) {
                if (in_array($splitFile->getExtension(), ['mp3', 'mp4'])) {
                    File::delete($splitFile->getPathname());
                }
            }

            $this->convertToMp3ProcessStep3();
    
            Log::info('Received video URL: ' . $videoUrl);
            
            preg_match('/[\\?\\&]v=([^\\?\\&]+)/', $videoUrl, $matches);
            $videoId = $matches[1] ?? null;

            $this->convertToMp3ProcessStep4();
            
            if (!$videoId) {
                Log::error('Invalid YouTube URL', ['url' => $videoUrl]);
                throw new Exception('Invalid YouTube URL');
            }
    
            $apiKey = config('services.youtube.key');
            $apiUrl = "https://www.googleapis.com/youtube/v3/videos?id={$videoId}&key={$apiKey}&part=snippet";

            $this->convertToMp3ProcessStep5();
    
            $client = new Client();
            $response = $client->get($apiUrl);
            $videoData = json_decode($response->getBody(), true);

            $this->convertToMp3ProcessStep6();
            
            if (!isset($videoData['items'][0])) {
                Log::error('Failed to fetch video details');
                throw new Exception('Failed to fetch video details');
            }
            
            $title = $videoData['items'][0]['snippet']['title'];
            $thumbnail = $videoData['items'][0]['snippet']['thumbnails']['high']['url'];

            $this->convertToMp3ProcessStep7();
            
            $sanitizedTitle = mb_convert_encoding(preg_replace('/[^\p{L}\p{N}ぁ-んァ-ヶー一-龠、。"_\-()]/u', '_', $title), 'UTF-8');
            $outputFile = storage_path("app/public/temp/{$sanitizedTitle}.mp4");
            Log::info('Output file path: ' . $outputFile);

            $this->convertToMp3ProcessStep8();
            
            $command = sprintf(
                '%s -o %s --encoding utf-8 %s',
                escapeshellarg(base_path('venv/Scripts/yt-dlp.exe')),
                escapeshellarg($outputFile),
                escapeshellarg($videoUrl)
            );
            Log::info('Running yt-dlp process', ['command' => $command]);

            $this->convertToMp3ProcessStep9();
            
            exec($command . ' 2>&1', $output, $return_var);
            
            
            $this->convertToMp3ProcessStep10();
            
            Log::info('yt-dlp process output', ['output' => mb_convert_encoding(implode("\n", $output), 'UTF-8')]);
            if ($return_var !== 0) {
                Log::error('yt-dlp process error', ['error_output' => mb_convert_encoding(implode("\n", $output), 'UTF-8')]);
                throw new Exception('yt-dlp process error: ' . implode("\n", $output));
            }

            $this->convertToMp3ProcessStep11();
            
            $mp3File = storage_path("app/public/temp/{$sanitizedTitle}.mp3");
            $ffmpegCommand = sprintf(
                'C:\\ffmpeg\\bin\\ffmpeg.exe -i %s %s',
                escapeshellarg($outputFile),
                escapeshellarg($mp3File)
            );
            Log::info('Running ffmpeg process', ['command' => $ffmpegCommand]);

            $this->convertToMp3ProcessStep12();
            
            exec($ffmpegCommand . ' 2>&1', $ffmpegOutput, $ffmpegReturnVar);
            
            
            $this->convertToMp3ProcessStep13();
            
            Log::info('ffmpeg process output', ['output' => mb_convert_encoding(implode("\n", $ffmpegOutput), 'UTF-8')]);
            if ($ffmpegReturnVar !== 0) {
                Log::error('ffmpeg process error', ['error_output' => mb_convert_encoding(implode("\n", $ffmpegOutput), 'UTF-8')]);
                throw new Exception('ffmpeg process error: ' . implode("\n", $ffmpegOutput));
            }

            $this->convertToMp3ProcessStep14();
            
            $mp3FilePath = "temp/{$sanitizedTitle}.mp3";
            
            $this->convertToMp3ProcessStep15();
            
            $data = [
                'mp3_file' => mb_convert_encoding($mp3FilePath, 'UTF-8'),
                'title' => mb_convert_encoding($title, 'UTF-8'),
                'thumbnail' => $thumbnail,
                'url' => $videoUrl,
            ];

            // セッションに保存
            Cache::put('mp3_convert_data', $data, 300);
            Cache::put('job_status', 'completed');
        } catch (Exception $e) {
            Log::error('ConvertToMp3Job failed', [
                'url' => $videoUrl,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
    
            Cache::put('job_status', 'failed');
            // 必要に応じてジョブを再試行
            $this->fail($e);
        }
    }
    
    protected function convertToMp3ProcessStep1()
    {
        
        for ($i = 0; $i <= 6; $i++) {
            // 進捗状況を更新
            Cache::put('progress2', $i);
        }
    }

    protected function convertToMp3ProcessStep2()
    {
        
        for ($i = 7; $i <= 12; $i++) {
            // 進捗状況を更新
            Cache::put('progress2', $i);
        }
    }

    protected function convertToMp3ProcessStep3()
    {
        
        for ($i = 13; $i <= 18; $i++) {
            // 進捗状況を更新
            Cache::put('progress2', $i);
        }
    }

    protected function convertToMp3ProcessStep4()
    {
        
        for ($i = 19; $i <= 24; $i++) {
            // 進捗状況を更新
            Cache::put('progress2', $i);
        }
    }

    protected function convertToMp3ProcessStep5()
    {
        
        for ($i = 25; $i <= 30; $i++) {
            // 進捗状況を更新
            Cache::put('progress2', $i);
        }
    }

    protected function convertToMp3ProcessStep6()
    {
        
        for ($i = 31; $i <= 36; $i++) {
            // 進捗状況を更新
            Cache::put('progress2', $i);
        }
    }

    protected function convertToMp3ProcessStep7()
    {
        
        for ($i = 37; $i <= 42; $i++) {
            // 進捗状況を更新
            Cache::put('progress2', $i);
        }
    }

    protected function convertToMp3ProcessStep8()
    {
        
        for ($i = 43; $i <= 48; $i++) {
            // 進捗状況を更新
            Cache::put('progress2', $i);
        }
    }

    protected function convertToMp3ProcessStep9()
    {
        
        for ($i = 49; $i <= 54; $i++) {
            // 進捗状況を更新
            Cache::put('progress2', $i);
        }
    }

    protected function convertToMp3ProcessStep10()
    {
        
        for ($i = 55; $i <= 60; $i++) {
            // 進捗状況を更新
            Cache::put('progress2', $i);
        }
    }

    protected function convertToMp3ProcessStep11()
    {
        
        for ($i = 61; $i <= 66; $i++) {
            // 進捗状況を更新
            Cache::put('progress2', $i);
        }
    }

    protected function convertToMp3ProcessStep12()
    {
        
        for ($i = 67; $i <= 72; $i++) {
            // 進捗状況を更新
            Cache::put('progress2', $i);
        }
    }

    protected function convertToMp3ProcessStep13()
    {
        
        for ($i = 73; $i <= 78; $i++) {
            // 進捗状況を更新
            Cache::put('progress2', $i);
        }
    }

    protected function convertToMp3ProcessStep14()
    {
        
        for ($i = 79; $i <= 84; $i++) {
            // 進捗状況を更新
            Cache::put('progress2', $i);
        }
    }

    protected function convertToMp3ProcessStep15()
    {
        
        for ($i = 85; $i <= 100; $i++) {
            // 進捗状況を更新
            Cache::put('progress2', $i);
        }
    }


}

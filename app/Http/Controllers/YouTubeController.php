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
use GuzzleHttp\Client;
use Illuminate\Support\Facades\File;
use App\Events\ProgressUpdated;
use Illuminate\Support\Facades\Cache;
use App\Jobs\ConvertToMp3Job; 

class YouTubeController extends Controller
{
    public function sessionClear(Request $request)
    {
        // すべてのセッションデータを消去
        Session::flush();

        $tempDir = storage_path('app/public/temp');
        $splitDir = storage_path('app/public/temp/split');

        $files = File::files($tempDir);
        foreach ($files as $file) {
            if (in_array($file->getExtension(), ['mp3', 'mp4'])) {
                File::delete($file->getPathname());
            }
        }
        $splitFiles = File::exists($splitDir) ? File::files($splitDir) : [];
        foreach ($splitFiles as $splitFile) {
            if (in_array($splitFile->getExtension(), ['mp3', 'mp4'])) {
                File::delete($splitFile->getPathname());
            }
        }

        return redirect()->back();
    }
    public function convert(Request $request)
    {
        $url = $request->input('url');
        $apiKey = config('services.youtube.key');

        // 進捗状況の初期化
        Cache::put('progress1', 0);

        $this->convertProcessStep1();

        Log::info('Fetching video details', ['url' => $url, 'apiKey' => $apiKey]);
        
        preg_match('/[\\?\\&]v=([^\\?\\&]+)/', $url, $matches);
        $videoId = $matches[1] ?? null;

        $this->convertProcessStep2();

        Log::info('Extracted video ID', ['videoId' => $videoId]);
        
        if (!$videoId) {
            Log::error('Invalid YouTube URL', ['url' => $url]);
            return redirect()->back()->with('error', 'Invalid YouTube URL');
        }
        
        $response = Http::get('https://www.googleapis.com/youtube/v3/videos', [
            'id' => $videoId,
            'part' => 'snippet',
            'key' => $apiKey
        ]);

        Cache::put('progress1', 50);

        Log::info('YouTube API request', [
            'endpoint' => 'https://www.googleapis.com/youtube/v3/videos',
            'parameters' => [
                'id' => $videoId,
                'part' => 'snippet',
                'key' => $apiKey
            ],
            'response_status' => $response->status(),
            'response_body' => $response->body()
        ]);
        
        if ($response->failed()) {
            Log::error('Failed to fetch video information', ['response' => $response->json()]);
            return redirect()->back()->with('error', 'Failed to fetch video information');
        }

        $this->convertProcessStep3();

        Log::info('Successfully fetched video information', ['response' => $response->json()]);
        
        $videoInfo = $response->json();
        $thumbnailUrl = $videoInfo['items'][0]['snippet']['thumbnails']['high']['url'] ?? null;
        $title = $videoInfo['items'][0]['snippet']['title'] ?? 'Unknown Title';
        
        if (!$thumbnailUrl) {
            return redirect()->back()->with('error', 'Thumbnail not found');
        }

        $this->convertProcessStep4();

        // セッションデータを保存
        $data = [
            'title' => mb_convert_encoding($title, 'UTF-8'),
            'thumbnail' => $thumbnailUrl,
            'url' => $url,
        ];
        
        Session::put('mp3_data', $data);

        
        // リダイレクト
        return redirect()->back();
    }

    protected function convertProcessStep1()
    {
        for ($i = 0; $i <= 25; $i++) {
            // 実際の処理を行う
            Cache::put('progress1', $i);
        }
    }

    protected function convertProcessStep2()
    {
        for ($i = 26; $i <= 50; $i++) {
            // 実際の処理を行う
            Cache::put('progress1', $i);
        }
    }

    protected function convertProcessStep3()
    {
        for ($i = 51; $i <= 75; $i++) {
            // 実際の処理を行う
            Cache::put('progress1', $i);
        }
    }

    protected function convertProcessStep4()
    {
        for ($i = 76; $i <= 100; $i++) {
            // 実際の処理を行う
            Cache::put('progress1', $i);
        }
    }

    public function getProgress1()
    {
        $progress = Cache::get('progress1', 0);
        return response()->json(['progress' => $progress]);
    }

    public function convertToMp3(Request $request)
    {
        $videoUrl = $request->input('url');

        Log::info('Dispatching ConvertToMp3Job for URL: ' . $videoUrl);

        if (!$videoUrl) {
            return response()->json(['status' => 'URL is required'], 400);
        }

        ConvertToMp3Job::dispatch($videoUrl)->onQueue('mp3_conversion');

        return response()->json(['status' => 'Job dispatched, check progress']);
    }

    public function getProgress2()
    {
        $progress = Cache::get('progress2', 0);
        return response()->json(['progress' => $progress]);
    }

    public function getJobStatus()
    {
        $status = Cache::get('job_status', 'unknown');
        if ($status === 'completed') {
            // キャッシュからデータを取得してセッションに保存
            $data = Cache::get('mp3_convert_data');
            Session::put('mp3_convert_data', $data);
        }
        return response()->json(['status' => $status]);
    }


    public function splitMp3(Request $request)
    {
        set_time_limit(3600);

        $mp3FilePath = storage_path('app/public/' . $request->input('mp3_file'));
        $outputDir = storage_path('app/public/temp/split');
        $acoustIdApiKey = config('services.acoust.key');
        $requestId = uniqid(); // リクエストごとに一意のIDを生成
        broadcast(new ProgressUpdated(10, $requestId)); // 進行状況を10%としてブロードキャスト
        Log::info('Progress 10%', ['requestId' => $requestId]);

        if (!file_exists($mp3FilePath)) {
            Log::error('MP3 file does not exist at path: ' . $mp3FilePath);
            return response()->json(['error' => 'MP3 file does not exist.'], 500);
        }

        if (!file_exists($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $cleanOutputDir = str_replace(' ', '_', $outputDir);

        try {
            Log::info('Starting splitMp3BySilence', ['mp3FilePath' => $mp3FilePath, 'outputDir' => $cleanOutputDir]);
            $this->splitMp3BySilence($mp3FilePath, $cleanOutputDir, 1.0);
            Log::info('Completed splitMp3BySilence');
        } catch (\Exception $e) {
            Log::error('Error during splitting MP3 file by silence', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to split the MP3 file. See logs for details.'], 500);
        }

        broadcast(new ProgressUpdated(50, $requestId)); // 進行状況を50%としてブロードキャスト
        Log::info('Progress 50%', ['requestId' => $requestId]);

        $splitFiles = array_diff(scandir($cleanOutputDir), ['.', '..']);
        $recognizedSegments = [];

        foreach ($splitFiles as $splitFile) {
            Log::info('Processing split file', ['splitFile' => $splitFile]);
            $splitFilePath = $cleanOutputDir . '/' . $splitFile;

            if (!$this->isPlayable($splitFilePath)) {
                Log::warning('File is not playable, deleting', ['file' => $splitFilePath]);
                unlink($splitFilePath);
                continue;
            }

            try {
                $fingerprintData = $this->generateFingerprint($splitFilePath);
                $duration = (int)$fingerprintData['duration'];
                $fingerprint = $fingerprintData['fingerprint'];

                $client = new Client();
                $response = $client->post("https://api.acoustid.org/v2/lookup", [
                    'form_params' => [
                        'client' => $acoustIdApiKey,
                        'meta' => 'recordings',
                        'duration' => $duration,
                        'fingerprint' => $fingerprint,
                    ],
                    'timeout' => 600,
                ]);

                if ($response->getStatusCode() !== 200) {
                    throw new \Exception('AcoustID API request failed: ' . $response->getBody());
                }

                $responseData = json_decode($response->getBody(), true);

                if (empty($responseData['results'])) {
                    Log::warning('No results returned from AcoustID API.');
                    $newFilePath = $cleanOutputDir . '/不明な曲.mp3';
                    $this->renameFile($splitFilePath, $newFilePath);
                    $recognizedSegments[] = [
                        'file_path' => 'temp/split/不明な曲.mp3',
                        'title' => '不明な曲'
                    ];
                    continue;
                }

                $recognized = false;
                foreach ($responseData['results'] as $result) {
                    if (isset($result['recordings']) && !empty($result['recordings'])) {
                        $recording = $result['recordings'][0];
                        if (!isset($recording['title'])) {
                            Log::warning('Recording does not have a title', ['recording' => $recording]);
                            continue;
                        }
                        $newFileName = preg_replace('/[^\p{L}\p{N}ぁ-んァ-ヶー一-龠、。"_\-()]/u', '_', $recording['title']) . '.mp3';
                        $newFilePath = $cleanOutputDir . '/' . $newFileName;
                        $this->renameFile($splitFilePath, $newFilePath);
                        $recognizedSegments[] = [
                            'file_path' => 'temp/split/' . $newFileName,
                            'title' => $recording['title']
                        ];
                        $recognized = true;
                        break;
                    }
                }

                if (!$recognized) {
                    $this->renameFile($splitFilePath, $cleanOutputDir . '/不明な曲.mp3');
                }
            } catch (\Exception $e) {
                Log::error('AcoustID API error', ['message' => $e->getMessage()]);
                $this->renameFile($splitFilePath, $cleanOutputDir . '/不明な曲.mp3');
            }
        }

        broadcast(new ProgressUpdated(100, $requestId)); // 進行状況を100%としてブロードキャスト
        Log::info('Progress 100%', ['requestId' => $requestId]);

        Log::info('Recognized segments', ['segments' => $recognizedSegments]);
        return redirect()->back()->with('split_files', $recognizedSegments);
    }

    
    private function isPlayable($filePath)
    {
        $ffprobePath = 'C:\\ffmpeg\\bin\\ffprobe.exe';
        $ffprobeCommand = sprintf(
            '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
            escapeshellarg($ffprobePath),
            escapeshellarg($filePath)
        );
    
        exec($ffprobeCommand, $ffprobeOutput, $ffprobeReturnVar);
    
        if ($ffprobeReturnVar !== 0 || empty($ffprobeOutput)) {
            return false;
        }
    
        return true;
    }
    
    private function renameFile($oldPath, $newPath)
    {
        if (file_exists($newPath)) {
            $newPath = str_replace('.mp3', '_' . uniqid() . '.mp3', $newPath);
        }
        rename($oldPath, $newPath);
    }
    
    
    
    public function splitMp3BySilence($mp3FilePath, $outputDir, $silenceDuration = 1.0)
    {
        $ffmpegPath = 'C:\\ffmpeg\\bin\\ffmpeg.exe';
        $ffprobePath = 'C:\\ffmpeg\\bin\\ffprobe.exe';
        $cleanOutputDir = str_replace(' ', '_', $outputDir);
        $cleanOutputFileNameTemplate = $cleanOutputDir . '/output_%03d.mp3';
        
        Log::info('Clean output directory: ' . $cleanOutputDir);
        Log::info('Clean output file name template: ' . $cleanOutputFileNameTemplate);
        
        // Correctly escape the file path
        $escapedMp3FilePath = escapeshellarg($mp3FilePath);
        
        // Build the FFmpeg command as a string
        $ffmpegCommand = "{$ffmpegPath} -i {$escapedMp3FilePath} -af silencedetect=noise=-50dB:d=2.5 -f null  - 2>&1";
        
        Log::info('Preparing to execute FFmpeg command for silence detection', ['command' => $ffmpegCommand]);
        
        // Execute the FFmpeg command using exec()
        exec($ffmpegCommand, $output, $returnVar);
        
        if ($returnVar !== 0) {
            Log::error('Failed to detect silence in the MP3 file.', ['return_var' => $returnVar]);
            throw new \Exception('Failed to detect silence in the MP3 file. See logs for details.');
        }
        
        Log::info('FFmpeg command output', ['output' => implode("\n", $output)]);
        
        // Analyze the output to get silence timestamps
        $segments = $this->parseSilenceTimestamps($output);
        Log::info('Parsed segments', ['segments' => $segments]);
        
        // Get the total duration of the MP3 file using FFprobe
        $ffprobeCommand = "{$ffprobePath} -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 {$escapedMp3FilePath}";
        exec($ffprobeCommand, $ffprobeOutput, $ffprobeReturnVar);
        $totalDuration = (float)implode("", $ffprobeOutput);
    
        // Process each segment based on silence detection
        foreach ($segments as $index => $segment) {
            Log::info('Processing segment', ['index' => $index, 'start' => $segment['start'], 'end' => $segment['end']]);
            
            $startTime = (float)$segment['start'];
            $endTime = $segment['end'] === 'Infinity' ? $totalDuration : (float)$segment['end'];
            $duration = $endTime - $startTime;
            
            $outputFile = sprintf($cleanOutputFileNameTemplate, $index);
            $escapedOutputFile = escapeshellarg($outputFile);
            
            // Execute FFmpeg command to extract each segment
            $ffmpegSegmentCommand = "{$ffmpegPath} -i {$escapedMp3FilePath} -ss {$startTime} -t {$duration} -c copy {$escapedOutputFile}";
            exec($ffmpegSegmentCommand, $segmentOutput, $segmentReturnVar);
            
            if ($segmentReturnVar !== 0) {
                Log::error('FFmpeg segment error', ['error_output' => implode("\n", $segmentOutput), 'return_var' => $segmentReturnVar]);
                throw new \Exception('Failed to split the MP3 file into segments. See logs for details.');
            }
        }
    }
    
    
    
    
    
    
    private function parseSilenceTimestamps($output)
    {
        $segments = [];
        $lastEnd = 0;
    
        foreach ($output as $line) {
            if (preg_match('/silence_start: (\d+(\.\d+)?)/', $line, $startMatches)) {
                $start = (float)$startMatches[1];
                $segments[] = ['start' => $lastEnd, 'end' => $start];
            }
    
            if (preg_match('/silence_end: (\d+(\.\d+)?)/', $line, $endMatches)) {
                $lastEnd = (float)$endMatches[1];
            }
        }
    
        // 最後のセグメントを追加
        $segments[] = ['start' => $lastEnd, 'end' => 'Infinity'];
    
        return $segments;
    }
    
    
    
    
    
    
    
    
    
    
    
    
    private function generateFingerprint($audioFilePath)
    {
        $fpcalcPath = 'C:\tools\fpcalc\fpcalc.exe';
        $output = [];
        $return_var = 0;
        $command = $fpcalcPath . ' -json ' . escapeshellarg($audioFilePath);
        Log::info('Running fpcalc command', ['command' => $command]);
        exec($command . ' 2>&1', $output, $return_var);
        
        Log::info('fpcalc output', ['output' => $output]);
        Log::info('fpcalc return_var', ['return_var' => $return_var]);
        
        if ($return_var !== 0) {
            Log::error('Failed to generate fingerprint.', [
                'command' => $command,
                'output' => $output,
                'return_var' => $return_var,
            ]);
            throw new \Exception('Failed to generate fingerprint. Error output: ' . implode("\n", $output));
        }
    
        $fingerprintData = json_decode(implode("\n", $output), true);
        Log::info('Generated fingerprint data', ['data' => $fingerprintData]);
        return $fingerprintData;
    }
    
    private function mergeSegments($segments)
    {
        Log::info('Merging segments', ['segments' => $segments]);
        $uniqueSegments = [];
        $segmentMap = [];
    
        foreach ($segments as $segment) {
            $key = md5($segment['title']);
            if (isset($segmentMap[$key])) {
                $segmentMap[$key]['files'][] = $segment['file'];
            } else {
                $segmentMap[$key] = [
                    'title' => $segment['title'],
                    'files' => [$segment['file']]
                ];
            }
        }
    
        foreach ($segmentMap as $segment) {
            if (count($segment['files']) > 1) {
                $mergedFileName = $this->mergeFiles($segment['files'], $segment['title']);
                $uniqueSegments[] = [
                    'file' => $mergedFileName,
                    'title' => $segment['title']
                ];
            } else {
                $uniqueSegments[] = [
                    'file' => $segment['files'][0],
                    'title' => $segment['title']
                ];
            }
        }
    
        Log::info('Merged segments', ['uniqueSegments' => $uniqueSegments]);
        return $uniqueSegments;
    }
    
    private function mergeFiles($files, $title)
    {
        $mergedFilePath = storage_path('app/public/temp/split/' . str_replace(' ', '_', $title) . '.mp3');
        Log::info('Merging files into', ['mergedFilePath' => $mergedFilePath]);
    
        $validFiles = [];
        foreach ($files as $file) {
            $ffprobeCommand = 'ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($file) . ' 2>&1';
            exec($ffprobeCommand, $ffprobeOutput, $ffprobeReturnVar);
    
            if ($ffprobeReturnVar === 0 && !empty($ffprobeOutput)) {
                $validFiles[] = $file;
            } else {
                Log::info('Skipping invalid file', ['file' => $file, 'error_output' => implode("\n", $ffprobeOutput)]);
            }
        }
    
        $tempFileList = tempnam(sys_get_temp_dir(), 'ffmpeg_file_list');
        $fileListContent = "";
        foreach ($validFiles as $file) {
            $fileListContent .= "file '" . $file . "'\n";
        }
        file_put_contents($tempFileList, $fileListContent);
    
        Log::info('Temporary file list content', ['content' => $fileListContent]);
    
        $command = "ffmpeg -f concat -safe 0 -i $tempFileList -c copy $mergedFilePath";
        Log::info('Running ffmpeg merge command', ['command' => $command]);
    
        $process = new Process([$command]);
        $process->setTimeout(600);
    
        try {
            $process->mustRun();
            Log::info('ffmpeg merge output', ['output' => $process->getOutput()]);
        } catch (ProcessFailedException $exception) {
            Log::error('ffmpeg merge failed', ['error' => $exception->getMessage()]);
            unlink($tempFileList);
            return response()->json(['error' => 'Failed to merge files.'], 500);
        }
    
        unlink($tempFileList);
    
        return asset('storage/temp/split/' . basename($mergedFilePath));
    }
}

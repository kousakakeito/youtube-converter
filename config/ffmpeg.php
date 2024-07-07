<?php

return [
    'ffmpeg' => [
        'binaries' => env('FFMPEG_BINARIES', 'C:\ffmpeg\bin\ffmpeg.exe'),
        'threads'  => 12,
    ],

    'ffprobe' => [
        'binaries' => env('FFPROBE_BINARIES', 'C:\ffmpeg\bin\ffprobe.exe'),
    ],

    'timeout' => 3600,

    'enable_logging' => false,

    'set_command_and_error_output_on_exception' => false,
];

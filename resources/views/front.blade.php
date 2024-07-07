<!DOCTYPE html>
<html>
<head>
    <title>YouTube Converter</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f9f9f9;
            color: #333;
            text-align: center;
            padding: 50px;
        }

        h1 {
            color: #3498db;
        }

        form {
            margin-bottom: 30px;
        }

        label {
            display: block;
            margin-bottom: 10px;
            font-size: 1.2em;
        }

        input[type="text"] {
            width: 300px;
            padding: 10px;
            border: 2px solid #ccc;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        button {
            background-color: #3498db;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            font-size: 1em;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        button:hover {
            background-color: #2980b9;
        }

        .loader {
            border: 4px solid #f3f3f3; /* Light grey */
            border-top: 4px solid #3498db; /* Blue */
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 2s linear infinite;
            display: none; /* Initially hidden */
            margin: 10px auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .thumbnail img {
            max-width: 100%;
            border-radius: 5px;
        }

        .thumbnail p {
            font-size: 1.2em;
            color: #3498db;
        }

        .download {
            background-color: #2ecc71;
            color: #fff;
            padding: 10px 20px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 1em;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .download:hover {
            background-color: #27ae60;
        }

        .audio-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }

        audio {
            flex-shrink: 0;
        }

        .split {
            background-color: #e74c3c;
            color: #fff;
            padding: 10px 20px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 1em;
            cursor: pointer;
            transition: background-color 0.3s;
            margin-left: 10px;
        }

        .split:hover {
            background-color: #c0392b;
        }
    </style>
</head>
<body>
    <h1>YouTube Converter</h1>
    <form action="{{ route('convert') }}" method="POST" id="youtube-form" onsubmit="showLoader()">
        @csrf
        <label for="url">YouTube URL:</label>
        <input type="text" id="url" name="url" required>
        <button type="submit">動画を取得</button>
        <div class="loader" id="loader"></div> <!-- ローディングアニメーション -->
    </form>
    
    @if(session('thumbnail'))
        <div id="thumbnail" class="thumbnail" style="margin-top: 20px;">
            <img src="{{ session('thumbnail') }}" alt="Thumbnail">
            <p>{{ session('title') }}</p>
            <form action="{{ route('convert-to-mp3') }}" method="POST" onsubmit="showLoader()" style="display: inline;">
                @csrf
                <input type="hidden" name="url" value="{{ session('url') }}">
                <button type="submit">MP3に変換</button>
            </form>
            <form action="{{ route('split-mp3') }}" method="POST" onsubmit="showLoader()" style="display: inline;">
                @csrf
                <input type="hidden" name="mp3_file" value="{{ session('mp3_file') }}">
                <button type="submit" class="split">曲毎に分割</button>
            </form>
        </div>
    @endif

    @if(session('mp3_file'))
        <div id="mp3-file" class="audio-container">
            <a href="{{ asset('storage/' . session('mp3_file')) }}" download class="download">Download</a>
            <audio controls>
                <source src="{{ asset('storage/' . session('mp3_file')) }}" type="audio/mpeg">
                Your browser does not support the audio element.
            </audio>
        </div>
    @endif

    @if(session('split_files'))
        <div id="split-files" style="margin-top: 20px;">
            <h2>分割されたファイル:</h2>
            @foreach(session('split_files') as $file)
                <div>
                    <p>{{ $file['title'] }}</p>
                    <audio controls>
                        <source src="{{ asset('storage/' . $file['file_path']) }}" type="audio/mpeg">
                        Your browser does not support the audio element.
                    </audio>
                </div>
            @endforeach
        </div>
    @endif

    <script>
        function showLoader() {
            document.getElementById('loader').style.display = 'block';
        }

        function playAudio(url) {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const audioElement = new Audio(url);
            const track = audioContext.createMediaElementSource(audioElement);
            track.connect(audioContext.destination);
            audioElement.play();
        }
    </script>
</body>
</html>

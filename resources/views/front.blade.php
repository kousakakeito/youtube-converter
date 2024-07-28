<!DOCTYPE html>
<html>
<head>
    <title>YouTube Converter</title>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body>
    <h1>YouTube Converter</h1>
    <form action="{{ route('convert') }}" method="POST" id="youtube-form" onsubmit="startConvert(event)">
        @csrf
        <label for="url">YouTube URL:</label>
        <input type="text" id="url" name="url" required>
        <button type="submit">動画を取得</button>
    </form>
    <form action="{{ route('session-clear') }}" method="POST">
        @csrf
        <button type="submit">セッションクリア</button>
    </form>

    <div id="progress-bar" style="diplay:none;">
        <div id="progress" style="display:none;"></div>
    </div>
    
    @if(session('mp3_data.thumbnail'))
        <div id="thumbnail" class="thumbnail" style="margin-top: 20px;">
            <img src="{{ session('mp3_data.thumbnail') }}" alt="Thumbnail">
            <p>{{ session('mp3_data.title') }}</p>
            <div class="loader"></div>
            @if(!session('mp3_convert_data.mp3_file'))
                <form action="{{ route('convert-to-mp3') }}" method="POST" id="convert-mp3-form" onsubmit="startMp3Convert(event)">
                    @csrf
                    <input type="hidden" name="url" value="{{ session('mp3_data.url') }}">
                    <button type="submit">MP3に変換</button>
                    <p style="display:none;font-weight:bold;color:red;">※ボタンの準備中です、10秒程お待ちください。</p>
                </form>
                <script>
                    document.getElementById('convert-mp3-form').style.pointerEvents = 'none';
                    document.querySelector('#convert-mp3-form button').style.backgroundColor = '#051c2b';
                    document.querySelector('#convert-mp3-form p').style.display="block";
                    setTimeout(function() {
                        document.querySelector('#convert-mp3-form button').style.backgroundColor = '';
                        document.getElementById('convert-mp3-form').style.pointerEvents = 'auto';
                        document.querySelector('#convert-mp3-form p').style.display="none";
                    }, 10000); 
                </script>
            @endif
            @if(session('mp3_convert_data.mp3_file'))
                @if(!session('split_files'))
                    <form action="{{ route('split-mp3') }}" method="POST" id="split-mp3-form" onsubmit="startSplitMp3(event)">
                        @csrf
                        <input type="hidden" name="mp3_file" value="{{ session('mp3_convert_data.mp3_file') }}">
                        <button type="submit" class="split" id="split-button">曲毎に分割</button>
                    </form>
                @endif
            @endif
        </div>
        <script>document.getElementById('youtube-form').style.display="none";</script>
    @else
        <p>※"MP3に変換"ボタンをクリック後、再度"MP3に変換"ボタンが表示される、もしくは変換後ファイル時間が0秒の場合は"セッションクリア"ボタンをクリックして再度実行してください※</p>
    @endif

    @if(session('mp3_convert_data.mp3_file'))
        @if(!session('split_files'))
            <div id="mp3-file" class="audio-container">
                <a href="{{ asset('storage/' . session('mp3_convert_data.mp3_file')) }}" download class="download">Download</a>
                <audio controls>
                    <source src="{{ asset('storage/' . session('mp3_convert_data.mp3_file')) }}" type="audio/mpeg">
                    Your browser does not support the audio element.
                </audio>
            </div>
        @endif
    @endif

    @if(session('split_files'))
        <div id="split-files" style="margin-top: 20px;">
            <h2>分割されたファイル:</h2>
            @foreach(session('split_files') as $file)
                <div class="file-card">
                    <p>{{ $file['title'] }}</p>
                    <div class="audio-container">
                        <audio controls>
                            <source src="{{ asset('storage/' . $file['file_path']) }}" type="audio/mpeg">
                            Your browser does not support the audio element.
                        </audio>
                        <a href="{{ asset('storage/' . $file['file_path']) }}" download class="download">Download</a>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <script>
        
        function startSplitMp3(event){
            document.querySelector("#split-mp3-form").style.display = "none";
            document.querySelector(".audio-container").style.display = "none";
            document.querySelector(".loader").style.display = "block";
        }
        
        function startMp3Convert(event) {
            event.preventDefault();
            document.querySelector(".loader").style.display = "block";
            document.getElementById('convert-mp3-form').style.display = 'none';
            let interval = setInterval(function() {
                document.querySelector("#progress-bar").style.display = "block";
                document.querySelector("#progress").style.display = "block";
                $.ajax({
                    url: '/get-progress2',
                    method: 'GET',
                    success: function(response) {
                        $('#progress').css('width', response.progress + '%');
                        if (response.progress >= 100) {
                            clearInterval(interval);
                            checkJobStatus();
                        }
                    },
                    error: function(response) {
                        clearInterval(interval);
                        console.error('進捗状況の取得に失敗しました。');
                    }
                });
            }, 1000); // 1秒ごとに進捗状況を取得

            $.ajax({
                url: '{{ route("convert-to-mp3") }}',
                method: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    url: '{{ session("mp3_data.url") }}'
                },
                success: function(response) {
                    // Job dispatched, now start progress checking
                    console.log('Job dispatched');
                },
                error: function(response) {
                    console.error('ジョブのディスパッチに失敗しました。');
                }
            });
        }

        function checkJobStatus() {
            $.ajax({
                url: '/job-status',
                method: 'GET',
                success: function(response) {
                    if (response.status === 'completed') {
                        setTimeout(function() {
                            window.location.reload(); // ジョブ完了後にページをリロード
                        }, 4000); 
                    } else if (response.status === 'failed') {
                        console.error('ジョブのステータスが失敗しました。');
                    } else {
                        setTimeout(checkJobStatus, 1000); // 1秒後に再度確認
                    }
                },
                error: function(response) {
                    console.error('ジョブのステータス確認に失敗しました。');
                }
            });
        }

        function playAudio(url) {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const audioElement = new Audio(url);
            const track = audioContext.createMediaElementSource(audioElement);
            track.connect(audioContext.destination);
            audioElement.play();
        }


        function startConvert(event) {
            event.preventDefault();
            document.getElementById('youtube-form').style.display="none";
            document.querySelector("#progress-bar").style.display = "block";
            document.querySelector("#progress").style.display = "block";
            let interval = setInterval(function() {
                $.ajax({
                    url: '/get-progress1',
                    method: 'GET',
                    success: function(response) {
                        $('#progress').css('width', response.progress + '%');
                        if (response.progress >= 100) {
                            setTimeout(function() {
                                clearInterval(interval);
                                document.querySelector("#progress-bar").style.display = "none";
                                document.querySelector("#progress").style.display = "none";
                            }, 2000); 
                        }
                    },
                    error: function(response) {
                        clearInterval(interval);
                        console.error('進捗状況の取得に失敗しました。');
                    }
                });
            }, 1000); // 1秒ごとに進捗状況を取得
            setTimeout(function() {
                document.getElementById('youtube-form').submit();
            }, 1000); 
            
        }
    </script>
    @vite(['resources/sass/app.scss', 'resources/js/app.js'])
</body>
</html>

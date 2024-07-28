import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'pusher',
    key: '996d9f18e4a6170dae2f',
    cluster: 'ap3',
    forceTLS: true,
    logToConsole: true
});

console.log('Echo initialized', window.Echo); // Echoの初期化ログ

const requestId = window.requestId;

if (requestId) {
    console.log('Request ID from session:', requestId); // デバッグ用
    window.Echo.connector.pusher.connection.bind('state_change', (states) => {
        console.log(states);
    });
    window.Echo.channel('progress-channel').listen('ProgressUpdated', (e) => { // ここでドットを追加
        console.log('Event received:', e); // イベントの内容をログ出力
        console.log('Event requestId:', e.requestId); // イベントのrequestIdをログ出力
        console.log('Expected requestId:', requestId); // 期待されるrequestIdをログ出力
        if (e.requestId === requestId) {
            console.log('Updating progress bar:', e.progress); // 進行状況の更新をログ出力
            const progressBar = document.getElementById('progress-bar');
            const progressPercent = document.getElementById('progress-text');
            progressBar.style.width = e.progress + '%';
            progressPercent.innerText = e.progress + '%';
        }
    });
} else {
    console.log('No requestId found in window');
}

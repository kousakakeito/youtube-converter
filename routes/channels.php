<?php
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('progress', function () {
    return true;
});

Broadcast::channel('progress-channel', function () {
    return true; // Or your own authorization logic
});

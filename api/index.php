<?php

$runtimeStorage = '/tmp/laravel-storage';

if (($_SERVER['REQUEST_URI'] ?? '') === '/__probe') {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'php' => PHP_VERSION,
        'cwd' => getcwd(),
        'entry' => __FILE__,
        'vendor' => is_file(__DIR__.'/../vendor/autoload.php'),
        'storage_writable' => is_writable('/tmp'),
    ]);
    return;
}

if (($_SERVER['REQUEST_URI'] ?? '') === '/__laravel_probe') {
    header('Content-Type: application/json');
    try {
        require __DIR__.'/../vendor/autoload.php';
        $app = require __DIR__.'/../bootstrap/app.php';
        echo json_encode([
            'ok' => true,
            'app' => $app::class,
            'storage_path' => $app->storagePath(),
            'cache_store' => env('CACHE_STORE'),
            'session_driver' => env('SESSION_DRIVER'),
            'log_channel' => env('LOG_CHANNEL'),
        ]);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);
    }
    return;
}

foreach ([
    $runtimeStorage.'/app',
    $runtimeStorage.'/app/travel_data',
    $runtimeStorage.'/framework/cache/data',
    $runtimeStorage.'/framework/sessions',
    $runtimeStorage.'/framework/testing',
    $runtimeStorage.'/framework/views',
    $runtimeStorage.'/logs',
] as $directory) {
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
}

$sourceData = __DIR__.'/../storage/app/travel_data';
$targetData = $runtimeStorage.'/app/travel_data';
if (is_dir($sourceData)) {
    foreach (glob($sourceData.'/*') ?: [] as $sourceFile) {
        if (is_file($sourceFile)) {
            copy($sourceFile, $targetData.'/'.basename($sourceFile));
        }
    }
}

$_ENV['LARAVEL_STORAGE_PATH'] = $runtimeStorage;
$_SERVER['LARAVEL_STORAGE_PATH'] = $runtimeStorage;

try {
    require __DIR__.'/../public/index.php';
} catch (Throwable $exception) {
    error_log($exception);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $exception::class.': '.$exception->getMessage();
}

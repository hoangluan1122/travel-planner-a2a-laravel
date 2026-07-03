<?php

$runtimeStorage = '/tmp/laravel-storage';

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

require __DIR__.'/../public/index.php';

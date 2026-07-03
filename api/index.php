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

if (($_SERVER['REQUEST_URI'] ?? '') === '/__dispatch_probe') {
    header('Content-Type: application/json');
    try {
        require __DIR__.'/../vendor/autoload.php';
        $app = require __DIR__.'/../bootstrap/app.php';
        $app['config']->set('app.debug', true);
        $request = Illuminate\Http\Request::create('/health', 'GET', [], [], [], [
            'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? 'localhost',
            'HTTPS' => 'on',
        ]);
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        $body = (string) $response->getContent();
        if ($response->getStatusCode() >= 500 && preg_match('/<title>(.*?)<\/title>/s', $body, $match)) {
            $body = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5));
        } else {
            $body = substr($body, 0, 1000);
        }
        echo json_encode([
            'ok' => $response->getStatusCode() < 500,
            'status' => $response->getStatusCode(),
            'headers' => $response->headers->all(),
            'body' => $body,
        ]);
        $kernel->terminate($request, $response);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => array_slice(explode("\n", $exception->getTraceAsString()), 0, 8),
        ]);
    }
    return;
}

if (($_SERVER['REQUEST_URI'] ?? '') === '/__controller_probe') {
    http_response_code(200);
    header('Content-Type: application/json');
    try {
        require __DIR__.'/../vendor/autoload.php';
        $app = require __DIR__.'/../bootstrap/app.php';
        $controller = $app->make(App\Http\Controllers\TravelPlannerController::class);
        $response = $controller->health();
        echo json_encode([
            'ok' => true,
            'controller' => $controller::class,
            'status' => $response->getStatusCode(),
            'body' => $response->getData(true),
        ]);
    } catch (Throwable $exception) {
        echo json_encode([
            'ok' => false,
            'error' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => array_slice(explode("\n", $exception->getTraceAsString()), 0, 8),
        ]);
    }
    return;
}

if (($_SERVER['REQUEST_URI'] ?? '') === '/__binding_probe') {
    http_response_code(200);
    header('Content-Type: application/json');
    try {
        require __DIR__.'/../vendor/autoload.php';
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->register(Illuminate\Filesystem\FilesystemServiceProvider::class);
        $app->register(Illuminate\Translation\TranslationServiceProvider::class);
        $app->register(Illuminate\View\ViewServiceProvider::class);
        echo json_encode([
            'ok' => true,
            'bound_view' => $app->bound('view'),
            'bound_response' => $app->bound(Illuminate\Contracts\Routing\ResponseFactory::class),
            'view_class' => $app->bound('view') ? $app->make('view')::class : null,
            'response_class' => $app->bound(Illuminate\Contracts\Routing\ResponseFactory::class) ? $app->make(Illuminate\Contracts\Routing\ResponseFactory::class)::class : null,
        ]);
    } catch (Throwable $exception) {
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
    require __DIR__.'/../vendor/autoload.php';

    /** @var Illuminate\Foundation\Application $app */
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->register(Illuminate\Filesystem\FilesystemServiceProvider::class);
    $app->register(Illuminate\Translation\TranslationServiceProvider::class);
    $app->register(Illuminate\View\ViewServiceProvider::class);
    $app->handleRequest(Illuminate\Http\Request::capture());
} catch (Throwable $exception) {
    error_log($exception);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $exception::class.': '.$exception->getMessage();
}

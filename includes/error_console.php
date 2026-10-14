<?php

function console_error_script(array $payload): string
{
    return '<script>console.error("[Sistema Interclasse]", ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ');</script>';
}

function normalize_error_file(string $file): string
{
    $root = dirname(__DIR__);
    $normalizedFile = str_replace('\\', '/', $file);
    $normalizedRoot = str_replace('\\', '/', $root);

    if (strpos($normalizedFile, $normalizedRoot) === 0) {
        return ltrim(substr($normalizedFile, strlen($normalizedRoot)), '/');
    }

    return $file;
}

function php_error_type(int $severity): string
{
    return [
        E_ERROR => 'E_ERROR',
        E_WARNING => 'E_WARNING',
        E_PARSE => 'E_PARSE',
        E_NOTICE => 'E_NOTICE',
        E_CORE_ERROR => 'E_CORE_ERROR',
        E_CORE_WARNING => 'E_CORE_WARNING',
        E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING => 'E_COMPILE_WARNING',
        E_USER_ERROR => 'E_USER_ERROR',
        E_USER_WARNING => 'E_USER_WARNING',
        E_USER_NOTICE => 'E_USER_NOTICE',
        E_STRICT => 'E_STRICT',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED => 'E_DEPRECATED',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED',
    ][$severity] ?? 'E_UNKNOWN';
}

function emit_php_console_error(string $type, string $message, string $file, int $line): void
{
    echo console_error_script([
        'type' => $type,
        'message' => $message,
        'file' => normalize_error_file($file),
        'line' => $line,
        'url' => $_SERVER['REQUEST_URI'] ?? null,
    ]);
}

function register_browser_console_error_handlers(): void
{
    set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        emit_php_console_error(php_error_type($severity), $message, $file, $line);
        return false;
    });

    set_exception_handler(function (Throwable $exception): void {
        http_response_code(500);
        emit_php_console_error(get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine());
        echo '<div class="alert alert-danger">Erro interno no sistema. Abra o console do navegador para ver o tipo e o local do erro.</div>';
    });

    register_shutdown_function(function (): void {
        $error = error_get_last();
        if (!$error) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array((int) $error['type'], $fatalTypes, true)) {
            return;
        }

        emit_php_console_error(
            php_error_type((int) $error['type']),
            (string) $error['message'],
            (string) $error['file'],
            (int) $error['line']
        );
    });
}

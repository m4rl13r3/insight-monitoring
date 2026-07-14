<?php

declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

function insight_python_binary(): string
{
    $configured = trim((string)(getenv('PYTHON_BIN') ?: ''));
    if ($configured !== '') {
        return $configured;
    }
    foreach (['python3', '/usr/local/bin/python3', '/usr/bin/python3'] as $candidate) {
        if ($candidate === 'python3' || is_executable($candidate)) {
            return $candidate;
        }
    }
    return 'python3';
}

function insight_python_path(string $projectRoot): string
{
    $configured = trim((string)(getenv('PYTHONPATH') ?: ''));
    if ($configured !== '') {
        return $configured;
    }
    $local = $projectRoot . '/monitoring/.pydeps';
    return is_dir($local) ? $local : '';
}

function insight_python_engine(array $arguments, string $input = '', int $timeoutSeconds = 30): array
{
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'status_code' => 503, 'message' => 'Python execution is unavailable.'];
    }
    $projectRoot = dirname(__DIR__);
    $command = [
        insight_python_binary(),
        $projectRoot . '/monitoring/python_monitoring/cli.py',
        '--root',
        $projectRoot . '/monitoring',
        ...array_map(static fn(mixed $value): string => (string)$value, $arguments),
    ];
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $pythonPath = insight_python_path($projectRoot);
    if ($pythonPath !== '') {
        $environment['PYTHONPATH'] = $pythonPath;
    }
    $process = @proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $projectRoot,
        $environment
    );
    if (!is_resource($process)) {
        return ['ok' => false, 'status_code' => 503, 'message' => 'Unable to start the Python engine.'];
    }
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + max(1, $timeoutSeconds);
    $timedOut = false;
    while (true) {
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!($status['running'] ?? false)) {
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }
        usleep(20000);
    }
    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($timedOut) {
        return ['ok' => false, 'status_code' => 504, 'message' => 'Python engine timeout.'];
    }
    $lines = preg_split('/\R+/', trim($stdout)) ?: [];
    $decoded = null;
    foreach (array_reverse($lines) as $line) {
        $candidate = json_decode(trim((string)$line), true);
        if (is_array($candidate)) {
            $decoded = $candidate;
            break;
        }
    }
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'status_code' => 502,
            'message' => trim($stderr) !== '' ? trim($stderr) : 'Invalid Python engine response.',
            'exit_code' => $exitCode,
        ];
    }
    $decoded['exit_code'] = $exitCode;
    return $decoded;
}

<?php

if (!function_exists('python_bridge_log')) {
    function python_bridge_log(string $message, array $context = []): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . trim($message);
        if (!empty($context)) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                $line .= ' ' . $json;
            }
        }
        @error_log($line . PHP_EOL, 3, __DIR__ . '/logs/python_bridge.log');
    }
}

if (!function_exists('run_monitoring_python')) {
    function resolve_python_bin(): string
    {
        $envBin = getenv('PYTHON_BIN');
        if (is_string($envBin) && trim($envBin) !== '') {
            return trim($envBin);
        }

        $candidates = [
            'python3.12',
            'python3.11',
            'python3.10',
            'python3',
            '/opt/alt/python312/bin/python3',
            '/opt/alt/python311/bin/python3',
            '/opt/alt/python310/bin/python3',
            '/usr/local/bin/python3.12',
            '/usr/local/bin/python3.11',
            '/usr/local/bin/python3.10',
            '/usr/bin/python3.12',
            '/usr/bin/python3.11',
            '/usr/bin/python3.10',
            '/usr/local/bin/python3',
            '/usr/bin/python3',
        ];

        foreach ($candidates as $candidate) {
            $cmd = escapeshellarg($candidate)
                . ' -c '
                . escapeshellarg('import sys; print(f"{sys.version_info[0]}.{sys.version_info[1]}")')
                . ' 2>/dev/null';
            $out = [];
            $code = 0;
            @exec($cmd, $out, $code);
            if ($code !== 0 || empty($out[0])) {
                continue;
            }

            $version = trim((string)$out[0]);
            if (preg_match('/^(\d+)\.(\d+)$/', $version, $m)) {
                $major = (int)$m[1];
                $minor = (int)$m[2];
                if ($major > 3 || ($major === 3 && $minor >= 10)) {
                    return $candidate;
                }
            }
        }

        return '/usr/bin/python3';
    }

    function resolve_python_path(): string
    {
        $envPath = getenv('PYTHONPATH');
        if (is_string($envPath) && trim($envPath) !== '') {
            return trim($envPath);
        }
        $default = __DIR__ . '/.pydeps';
        if (is_dir($default)) {
            return $default;
        }
        return '';
    }

    function run_monitoring_python(array $args): array
    {
        $python = resolve_python_bin();
        $pythonPath = resolve_python_path();

        $script = __DIR__ . '/python_monitoring/cli.py';
        if (!is_file($script)) {
            return [
                'ok' => false,
                'status_code' => 500,
                'message' => 'Python monitoring script not found.',
            ];
        }

        $timeoutRaw = getenv('MONITORING_PYTHON_TIMEOUT_SEC');
        $timeoutSec = is_numeric((string)$timeoutRaw) ? (int)$timeoutRaw : 55;

        $envParts = [];
        if ($pythonPath !== '') {
            $envParts[] = 'PYTHONPATH=' . escapeshellarg($pythonPath);
        }
        $envParts[] = 'PYTHON_BIN=' . escapeshellarg($python);

        $parts = [escapeshellarg($python), escapeshellarg($script), '--root', escapeshellarg(__DIR__)];
        foreach ($args as $arg) {
            $parts[] = escapeshellarg((string)$arg);
        }

        $command = '';
        if (!empty($envParts)) {
            $command .= implode(' ', $envParts) . ' ';
        }
        if ($timeoutSec > 0 && is_file('/usr/bin/timeout')) {
            $command .= escapeshellarg('/usr/bin/timeout') . ' ' . $timeoutSec . ' ';
        }
        $command .= implode(' ', $parts) . ' 2>&1';
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        $raw = trim(implode("\n", $output));
        $decoded = null;
        if ($raw !== '') {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                $decoded = $parsed;
            } else {
                // Some hosts prepend warnings before the JSON result.
                $lines = preg_split('/\R+/', $raw) ?: [];
                for ($i = count($lines) - 1; $i >= 0; $i--) {
                    $candidate = trim((string)$lines[$i]);
                    if ($candidate === '') {
                        continue;
                    }
                    $parsedLine = json_decode($candidate, true);
                    if (is_array($parsedLine)) {
                        $decoded = $parsedLine;
                        break;
                    }
                }
            }
        }

        if (!is_array($decoded)) {
            python_bridge_log('Python response is not valid JSON.', [
                'command' => $command,
                'exit_code' => $exitCode,
                'raw' => $raw,
            ]);
            return [
                'ok' => false,
                'status_code' => 500,
                'message' => ($exitCode === 124 ? 'Python monitoring timeout.' : 'Invalid Python response.'),
                'raw' => $raw,
                'exit_code' => $exitCode,
            ];
        }

        if (!array_key_exists('ok', $decoded)) {
            $decoded['ok'] = ($exitCode === 0);
        }

        $decoded['exit_code'] = $exitCode;
        if (!array_key_exists('raw', $decoded)) {
            $decoded['raw'] = $raw;
        }
        return $decoded;
    }
}

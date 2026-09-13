<?php
/**
 * PixelHop - AI HD Upscale Runner
 * Bridges PHP with python/upscale_engine.py using the same exec/timeout
 * pattern as AiService::executeCommand, without modifying AiService itself.
 *
 * Fail-closed behaviour: empty stdout, invalid JSON, or a GNU timeout exit
 * (124) all produce a non-success result. Every argument is escaped.
 */

class UpscaleRunner
{
    /** Must match AiService::PYTHON_BIN so production keeps one venv path. */
    private const PYTHON_BIN = '/var/www/pichost/python/venv/bin/python3';

    /**
     * Deterministic HOME for the spawned Python process.
     *
     * PHP-FPM's www-data may inherit an empty or wrong HOME (e.g.
     * /home/carawin when invoked via CLI). Python's setdefault('HOME')
     * only fires when the variable is *absent*, not when it is wrong.
     * Prefixing the command with an explicit HOME= ensures the child
     * process always resolves ~/.cache/… to the directory where
     * scripts/download_models.sh placed the ONNX weights.
     */
    private const PROCESS_HOME = '/var/www';

    /**
     * Absolute path to the Real-ESRGAN ONNX model.
     *
     * Must match the default in scripts/download_models.sh and the
     * fallback in python/upscale_engine.py resolve_model_path().
     */
    private const REALESRGAN_MODEL = '/var/www/.cache/realesrgan/RealESRGAN_x2plus.onnx';

    private string $pythonDir;

    public function __construct()
    {
        $this->pythonDir = __DIR__ . '/../python';
    }

    /**
     * Run the Real-ESRGAN upscale engine.
     *
     * @param string $inputPath  Source image path
     * @param string $outputPath Destination image path
     * @param int    $scale      2 or 4
     * @param int    $timeout    GNU timeout in seconds
     * @return array Parsed JSON payload from the engine; never null on failure
     */
    public function run(string $inputPath, string $outputPath, int $scale, int $timeout): array
    {
        $scriptPath = $this->pythonDir . '/upscale_engine.py';

        if (!file_exists($scriptPath)) {
            return [
                'success' => false,
                'error' => 'Upscale engine not available',
                'code' => 500,
            ];
        }

        if (!file_exists($inputPath)) {
            return [
                'success' => false,
                'error' => 'Image file not found',
                'code' => 400,
            ];
        }

        if ($scale !== 2 && $scale !== 4) {
            $scale = 2;
        }

        $timeout = max(1, $timeout);
        $command = sprintf(
            'HOME=%s REALESRGAN_MODEL=%s timeout %ds %s %s %s %s %d 2>/dev/null',
            escapeshellarg(self::PROCESS_HOME),
            escapeshellarg(self::REALESRGAN_MODEL),
            $timeout,
            escapeshellarg(self::PYTHON_BIN),
            escapeshellarg($scriptPath),
            escapeshellarg($inputPath),
            escapeshellarg($outputPath),
            $scale
        );

        return $this->executeCommand($command, 'AI HD Upscale', $timeout);
    }

    /**
     * Execute a command and parse its JSON stdout.
     */
    private function executeCommand(string $command, string $operation, int $timeout): array
    {
        $startTime = microtime(true);

        $outputLines = [];
        $exitCode = 0;
        exec($command, $outputLines, $exitCode);
        $output = implode("\n", $outputLines);

        $duration = round((microtime(true) - $startTime) * 1000);

        // GNU timeout exits with 124 when the command timed out.
        if ($exitCode === 124) {
            return [
                'success' => false,
                'error' => $operation . ' timed out after ' . $timeout . ' seconds',
                'code' => 504,
                'duration_ms' => $duration,
            ];
        }

        // Engine prints JSON even on failure (then exits 1). Fail closed when
        // there is nothing to parse.
        if (trim($output) === '') {
            return [
                'success' => false,
                'error' => $operation . ' failed to execute',
                'code' => 500,
                'duration_ms' => $duration,
            ];
        }

        $result = json_decode(trim($output), true);

        if ($result === null) {
            error_log("UpscaleRunner: Invalid JSON output from {$operation}: " . substr($output, 0, 500));
            return [
                'success' => false,
                'error' => $operation . ' returned invalid response',
                'code' => 500,
                'duration_ms' => $duration,
                'raw_output' => substr($output, 0, 200),
            ];
        }

        $result['duration_ms'] = $duration;

        return $result;
    }
}

<?php
/**
 * PixelHop - AI Service Wrapper
 * Bridges PHP with Python AI tools (PaddleOCR, rembg)
 *
 * Features:
 * - Process timeout protection (30s default)
 * - System load checking (prevents CPU overload)
 * - Error handling and logging
 */

class AiService
{
    private const DEFAULT_TIMEOUT = 30;
    private const MAX_LOAD_AVERAGE = 3.0;
    private const PYTHON_BIN = '/var/www/pichost/python/venv/bin/python3';

    private string $pythonDir;
    private int $timeout;

    public function __construct(int $timeout = self::DEFAULT_TIMEOUT)
    {
        $this->pythonDir = __DIR__ . '/../python';
        $this->timeout = $timeout;
    }

    /**
     * Check if system load is too high
     * Returns true if server is available, false if busy
     */
    public function checkSystemLoad(): bool
    {
        $load = sys_getloadavg();

        if ($load === false) {

            return true;
        }


        return $load[0] < self::MAX_LOAD_AVERAGE;
    }

    /**
     * Get current system load info
     */
    public function getLoadInfo(): array
    {
        $load = sys_getloadavg();

        return [
            'load_1min' => $load[0] ?? 0,
            'load_5min' => $load[1] ?? 0,
            'load_15min' => $load[2] ?? 0,
            'threshold' => self::MAX_LOAD_AVERAGE,
            'available' => $this->checkSystemLoad(),
        ];
    }

    /**
     * Perform OCR on an image using PaddleOCR
     *
     * @param string $filePath Path to the image file
     * @param string $language Language code (default: en)
     * @return array Result with success status and data
     */
    public function performOcr(string $filePath, string $language = 'en'): array
    {

        if (!$this->checkSystemLoad()) {
            return [
                'success' => false,
                'error' => 'Server busy, try again later',
                'code' => 503,
            ];
        }


        if (!file_exists($filePath)) {
            return [
                'success' => false,
                'error' => 'Image file not found',
                'code' => 400,
            ];
        }

        $scriptPath = $this->pythonDir . '/ocr_engine.py';

        if (!file_exists($scriptPath)) {
            return [
                'success' => false,
                'error' => 'OCR engine not available',
                'code' => 500,
            ];
        }


        $command = sprintf(
            'timeout %ds %s %s %s %s 2>/dev/null',
            $this->timeout,
            escapeshellarg(self::PYTHON_BIN),
            escapeshellarg($scriptPath),
            escapeshellarg($filePath),
            escapeshellarg($language)
        );

        return $this->executeCommand($command, 'OCR');
    }

    /**
     * Remove background from an image using rembg
     *
     * @param string $inputPath Path to the input image
     * @param string|null $outputPath Output path (auto-generated if null)
     * @param string $model Model to use (u2net, u2netp, etc.)
     * @return array Result with success status and data
     */
    public function removeBackground(
        string $inputPath,
        ?string $outputPath = null,
        string $model = 'u2net'
    ): array {

        if (!$this->checkSystemLoad()) {
            return [
                'success' => false,
                'error' => 'Server busy, try again later',
                'code' => 503,
            ];
        }


        if (!file_exists($inputPath)) {
            return [
                'success' => false,
                'error' => 'Image file not found',
                'code' => 400,
            ];
        }

        $scriptPath = $this->pythonDir . '/rembg_engine.py';

        if (!file_exists($scriptPath)) {
            return [
                'success' => false,
                'error' => 'Background remover not available',
                'code' => 500,
            ];
        }


        if ($outputPath === null) {
            $pathInfo = pathinfo($inputPath);
            $outputPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_nobg.png';
        }


        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }


        $validModels = ['u2net', 'u2netp', 'u2net_human_seg', 'u2net_cloth_seg', 'silueta', 'isnet-general-use'];
        if (!in_array($model, $validModels)) {
            $model = 'u2net';
        }


        $rembgTimeout = max($this->timeout, 60);
        $command = sprintf(
            'timeout %ds %s %s %s %s %s 2>/dev/null',
            $rembgTimeout,
            escapeshellarg(self::PYTHON_BIN),
            escapeshellarg($scriptPath),
            escapeshellarg($inputPath),
            escapeshellarg($outputPath),
            escapeshellarg($model)
        );

        return $this->executeCommand($command, 'Background removal');
    }

    /**
     * Execute a command and parse JSON output
     */
    private function executeCommand(string $command, string $operation): array
    {
        $startTime = microtime(true);


        $output = shell_exec($command);

        $duration = round((microtime(true) - $startTime) * 1000);

        if ($output === null) {

            $exitCode = 0;
            exec('echo $?', $_, $exitCode);

            if ($exitCode === 124) {
                return [
                    'success' => false,
                    'error' => $operation . ' timed out after ' . $this->timeout . ' seconds',
                    'code' => 504,
                    'duration_ms' => $duration,
                ];
            }

            return [
                'success' => false,
                'error' => $operation . ' failed to execute',
                'code' => 500,
                'duration_ms' => $duration,
            ];
        }


        $result = json_decode(trim($output), true);

        if ($result === null) {
            error_log("AI Service: Invalid JSON output from $operation: " . substr($output, 0, 500));
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

    /**
     * Check if Python and required modules are available
     */
    public function checkDependencies(): array
    {
        $checks = [
            'python' => false,
            'python_version' => null,
            'paddleocr' => false,
            'rembg' => false,
        ];


        $pythonVersion = trim(shell_exec(self::PYTHON_BIN . ' --version 2>&1') ?? '');
        if (strpos($pythonVersion, 'Python') !== false) {
            $checks['python'] = true;
            $checks['python_version'] = $pythonVersion;
        }


        $paddleCheck = shell_exec(self::PYTHON_BIN . ' -c "import paddleocr; print(\'ok\')" 2>/dev/null');
        $checks['paddleocr'] = trim($paddleCheck ?? '') === 'ok';


        $rembgCheck = shell_exec(self::PYTHON_BIN . ' -c "import rembg; print(\'ok\')" 2>/dev/null');
        $checks['rembg'] = trim($rembgCheck ?? '') === 'ok';

        return $checks;
    }

    /**
     * Get available OCR languages
     */
    public static function getOcrLanguages(): array
    {
        return [
            'en' => 'English',
            'ch' => 'Chinese (Simplified)',
            'chinese_cht' => 'Chinese (Traditional)',
            'japan' => 'Japanese',
            'korean' => 'Korean',
            'fr' => 'French',
            'german' => 'German',
            'es' => 'Spanish',
            'pt' => 'Portuguese',
            'it' => 'Italian',
            'ru' => 'Russian',
            'ar' => 'Arabic',
            'th' => 'Thai',
            'vi' => 'Vietnamese',
            'id' => 'Indonesian',
        ];
    }

    /**
     * Get available background removal models
     */
    public static function getRembgModels(): array
    {
        return [
            'u2net' => 'U2-Net (Best quality, slower)',
            'u2netp' => 'U2-Net Portable (Fast, good quality)',
            'u2net_human_seg' => 'U2-Net Human (Optimized for people)',
            'silueta' => 'Silueta (Fast, general purpose)',
            'isnet-general-use' => 'IS-Net (High quality)',
        ];
    }
}

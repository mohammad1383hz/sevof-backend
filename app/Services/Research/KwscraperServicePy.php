<?php

namespace App\Services\Research;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class KwscraperServicePy
{
    protected string $pythonBin;
    protected string $projectPath;
    protected string $lang;
    protected string $country;
    protected int $timeout;

    public function __construct()
    {
        $this->pythonBin   = config('kwscraper.python_bin');
        $this->projectPath = config('kwscraper.project_path');
        $this->lang        = config('kwscraper.lang');
        $this->country     = config('kwscraper.country');
        $this->timeout     = config('kwscraper.timeout');
    }

    /**
     * دریافت پیشنهادات Autocomplete برای یک کلمه از طریق اسکریپت پایتون.
     *
     * موفقیت = پروسه با کد خروجی 0 تمام شده و فایل خروجی JSON معتبر تولید شده،
     * حتی اگر لیست کلمات خالی باشد (یعنی گوگل واقعاً چیزی برنگردانده).
     *
     * شکست = پروسه fail شده، exception پرتاب شده، یا فایل خروجی/JSON نامعتبر بوده.
     * در این حالت RuntimeException پرتاب می‌شود تا caller بتواند تصمیم بگیرد
     * (مثلاً fallback به یک سرویس دیگر).
     *
     * @return array<int, string>
     *
     * @throws RuntimeException
     */
    public function suggest(string $query, array $options = []): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $outFile = tempnam(sys_get_temp_dir(), 'kws_') . '.json';

        $command = [
            $this->pythonBin, '-m', 'kwscraper',
            $query,
            '--format', 'json',
            '-o', $outFile,
            '--lang', $options['lang'] ?? $this->lang,
            '--country', $options['country'] ?? $this->country,
            '--sleep', (string) ($options['sleep'] ?? 0.2),
        ];

        if (! empty($options['alpha'])) {
            $command[] = '--alpha';
        }
        if (! empty($options['limit'])) {
            $command[] = '--limit';
            $command[] = (string) $options['limit'];
        }

        try {
            $process = new Process($command, $this->projectPath);
            $process->setTimeout($this->timeout);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException(
                    "Kwscraper process failed (exit={$process->getExitCode()}): " . $process->getErrorOutput()
                );
            }

            if (! file_exists($outFile)) {
                throw new RuntimeException('Kwscraper did not produce an output file.');
            }

            $raw = file_get_contents($outFile);
            $json = json_decode($raw, true);

            if (! is_array($json) || ! array_key_exists('results', $json)) {
                throw new RuntimeException('Kwscraper output JSON is malformed or missing "results" key.');
            }

            return array_values(array_unique(array_column($json['results'], 'keyword')));
        } catch (RuntimeException $e) {
            Log::error('[Kwscraper] ' . $e->getMessage(), ['query' => $query]);
            throw $e;
        } catch (Throwable $e) {
            Log::error('[Kwscraper] Unexpected exception: ' . $e->getMessage(), ['query' => $query]);
            throw new RuntimeException('Kwscraper unexpected failure: ' . $e->getMessage(), 0, $e);
        } finally {
            if (file_exists($outFile)) {
                @unlink($outFile);
            }
        }
    }

    /**
     * دریافت پیشنهادات برای چند کلمه در یک اجرای پایتون.
     *
     * @param array<int, string> $queries
     * @return array<string, array<int, string>>
     *
     * @throws RuntimeException
     */
    public function suggestBatch(array $queries, array $options = []): array
    {
        $queries = array_values(array_filter(array_map('trim', $queries)));
        if (empty($queries)) {
            return [];
        }

        $seedsFile = tempnam(sys_get_temp_dir(), 'kws_seeds_') . '.txt';
        $outFile   = tempnam(sys_get_temp_dir(), 'kws_out_') . '.json';
        file_put_contents($seedsFile, implode(PHP_EOL, $queries));

        $command = [
            $this->pythonBin, '-m', 'kwscraper',
            '--file', $seedsFile,
            '--format', 'json',
            '-o', $outFile,
            '--group-by-seed',
            '--lang', $options['lang'] ?? $this->lang,
            '--country', $options['country'] ?? $this->country,
            '--sleep', (string) ($options['sleep'] ?? 0.2),
        ];

        if (! empty($options['alpha'])) {
            $command[] = '--alpha';
        }

        try {
            $process = new Process($command, $this->projectPath);
            $process->setTimeout($this->timeout * max(1, count($queries)));
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException(
                    "Kwscraper batch process failed (exit={$process->getExitCode()}): " . $process->getErrorOutput()
                );
            }

            if (! file_exists($outFile)) {
                throw new RuntimeException('Kwscraper batch did not produce an output file.');
            }

            $json = json_decode(file_get_contents($outFile), true);

            if (! is_array($json) || ! array_key_exists('groups', $json)) {
                throw new RuntimeException('Kwscraper batch output JSON is malformed or missing "groups" key.');
            }

            return $json['groups'];
        } catch (RuntimeException $e) {
            Log::error('[Kwscraper] ' . $e->getMessage(), ['queries' => $queries]);
            throw $e;
        } catch (Throwable $e) {
            Log::error('[Kwscraper] Unexpected batch exception: ' . $e->getMessage(), ['queries' => $queries]);
            throw new RuntimeException('Kwscraper batch unexpected failure: ' . $e->getMessage(), 0, $e);
        } finally {
            @unlink($seedsFile);
            @unlink($outFile);
        }
    }
}

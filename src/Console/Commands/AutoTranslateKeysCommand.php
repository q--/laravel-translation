<?php

namespace JoeDixon\Translation\Console\Commands;

use JoeDixon\Translation\Proxy\ProxyPool;
use JoeDixon\Translation\Proxy\ProxyStore;
use JoeDixon\Translation\Proxy\ProxyValidator;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class AutoTranslateKeysCommand extends BaseCommand
{
    protected $signature = 'translation:auto-translate
        {language? : Specific language to translate (omit for all)}
        {--concurrency=10 : Number of languages to translate in parallel}
        {--no-proxy : Disable proxy pool (translate sequentially without proxies)}';

    protected $description = 'Auto translate keys using Google Translate';

    public function handle()
    {
        $language = $this->argument('language') ?: false;
        $noProxy = $this->option('no-proxy');
        $concurrency = max(1, (int) $this->option('concurrency'));

        // Single language or --no-proxy: use the simple sequential path
        if ($language || $noProxy || $concurrency <= 1) {
            return $this->handleSequential($language);
        }

        return $this->handleParallel($concurrency);
    }

    private function handleSequential($language): int
    {
        try {
            $this->translation->autoTranslate($language);
            $this->info(__('translation::translation.auto_translated'));

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function handleParallel(int $concurrency): int
    {
        $languages = $this->translation->allLanguages()->keys()->toArray();
        $sourceLanguage = $this->translation->getSourceLanguage();

        // Save missing translations for all languages first (sequential, fast)
        $scannedTranslations = $this->translation->scanForTranslations();
        foreach ($languages as $lang) {
            $this->translation->saveMissingTranslations($lang, $scannedTranslations);
        }

        // Exclude source language — nothing to translate
        $languages = array_values(array_filter($languages, fn ($l) => $l !== $sourceLanguage));

        if (empty($languages)) {
            $this->info(__('translation::translation.auto_translated'));

            return self::SUCCESS;
        }

        // Set up proxy pool
        $proxyStorePath = config('translation.auto_translate.proxy_store_path');
        $minPool = (int) config('translation.auto_translate.proxy_min_pool', 5);
        $failLimit = (int) config('translation.auto_translate.proxy_fail_limit', 3);
        $timeoutSec = (int) config('translation.auto_translate.proxy_timeout_sec', 5);

        $store = new ProxyStore($proxyStorePath ?: null);
        $validator = new ProxyValidator();
        $pool = new ProxyPool($store, $validator, $minPool, $failLimit, $timeoutSec);

        $this->info("Fetching and validating proxies...");
        $pool->ensureReady($concurrency);

        $hasProxies = $store->hasWorking();
        if (! $hasProxies) {
            $this->warn("No working proxies found; falling back to sequential translation.");

            return $this->handleSequential(false);
        }

        $this->info(sprintf("Translating %d languages with concurrency %d...", count($languages), $concurrency));

        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';
        $artisan = base_path('artisan');

        $queue = $languages;
        $running = []; // proxy => Process
        $failed = [];
        $maxRetries = 3;
        $retryCount = [];

        while (! empty($queue) || ! empty($running)) {
            // Start new workers up to concurrency limit
            while (count($running) < $concurrency && ! empty($queue)) {
                $lang = array_shift($queue);
                $proxy = $pool->acquire();

                if (! $proxy) {
                    // No proxy available — translate this one sequentially
                    $this->line("Translating {$lang} (no proxy available)...");
                    try {
                        $this->translation->translateLanguage($lang);
                        fwrite(STDOUT, __('translation::translation.auto_translated_language', ['language' => $lang]) . PHP_EOL);
                    } catch (\Throwable $e) {
                        $this->error("Failed to translate {$lang}: " . $e->getMessage());
                    }
                    continue;
                }

                $process = new Process([$phpBinary, $artisan, 'translation:translate-language', $lang, '--proxy=' . $proxy]);
                $process->setTimeout(300);
                $process->start();
                $running[$proxy] = ['process' => $process, 'language' => $lang];
            }

            if (empty($running)) {
                break;
            }

            // Poll running processes
            usleep(100_000); // 100ms

            foreach ($running as $proxy => $info) {
                $process = $info['process'];
                $lang = $info['language'];

                if (! $process->isRunning()) {
                    $exitCode = $process->getExitCode();
                    unset($running[$proxy]);

                    if ($exitCode === 0) {
                        $pool->reportSuccess($proxy);
                        fwrite(STDOUT, __('translation::translation.auto_translated_language', ['language' => $lang]) . PHP_EOL);
                    } elseif ($exitCode === 2) {
                        // Proxy failure — mark and retry with different proxy
                        $pool->reportFailure($proxy);
                        $retries = ($retryCount[$lang] ?? 0) + 1;
                        $retryCount[$lang] = $retries;

                        if ($retries < $maxRetries) {
                            $queue[] = $lang;
                        } else {
                            $this->warn("Giving up on {$lang} after {$maxRetries} proxy failures; trying sequentially...");
                            try {
                                $this->translation->translateLanguage($lang);
                                fwrite(STDOUT, __('translation::translation.auto_translated_language', ['language' => $lang]) . PHP_EOL);
                            } catch (\Throwable $e) {
                                $this->error("Failed to translate {$lang}: " . $e->getMessage());
                                $failed[] = $lang;
                            }
                        }
                    } else {
                        $this->error("Failed to translate {$lang} (exit {$exitCode}): " . $process->getErrorOutput());
                        $failed[] = $lang;
                    }
                }
            }
        }

        $this->info(__('translation::translation.auto_translated'));

        return empty($failed) ? self::SUCCESS : self::FAILURE;
    }
}

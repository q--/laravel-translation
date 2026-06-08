<?php

namespace JoeDixon\Translation\Proxy;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;

class ProxyValidator
{
    // Use the same endpoint and parameters the stichoza library uses, so that
    // validation and actual usage conditions are identical. A proxy that passes
    // this test will reliably work for translateLanguage() calls.
    private const TEST_URL = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=en&tl=es&dt=t&q=hello&ie=UTF-8&oe=UTF-8';

    /**
     * Validate a batch of proxy URLs concurrently.
     * Uses the same endpoint and headers as the stichoza Google Translate library
     * so that only proxies that actually work for translation are marked working.
     * Updates the ProxyStore with results.
     *
     * @param  string[]  $proxyUrls
     * @param  ProxyStore  $store
     * @param  int  $concurrency  Max simultaneous connections
     * @param  int  $timeoutSec  Per-proxy timeout
     * @return string[]  URLs that validated as working
     */
    public function validate(array $proxyUrls, ProxyStore $store, int $concurrency = 10, int $timeoutSec = 5): array
    {
        $working = [];
        $startTimes = [];

        $client = new Client([
            'connect_timeout' => $timeoutSec,
            'timeout'         => $timeoutSec * 2,
            // Match stichoza's default User-Agent so proxies that filter by UA behave consistently
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ],
        ]);

        $requests = function () use ($proxyUrls, &$startTimes) {
            foreach ($proxyUrls as $url) {
                $startTimes[$url] = microtime(true);
                yield $url => new Request('GET', self::TEST_URL);
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => $concurrency,
            'options' => fn ($url) => ['proxy' => $url],
            'fulfilled' => function ($response, $url) use ($store, &$working, &$startTimes) {
                $body = (string) $response->getBody();
                // A valid Google Translate response always starts with [[[
                if (str_starts_with($body, '[[[')) {
                    $ms = (int) ((microtime(true) - $startTimes[$url]) * 1000);
                    $store->markWorking($url, $ms);
                    $working[] = $url;
                } else {
                    $store->markDead($url);
                }
            },
            'rejected' => function ($reason, $url) use ($store) {
                $store->markDead($url);
            },
        ]);

        $pool->promise()->wait();

        return $working;
    }
}

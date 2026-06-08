<?php

namespace JoeDixon\Translation\Proxy;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;

class ProxyValidator
{
    // A lightweight endpoint — just need a valid translate response
    private const TEST_URL = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=en&tl=es&dt=t&q=hello';

    /**
     * Validate a batch of proxy URLs concurrently.
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

        $client = new Client(['timeout' => $timeoutSec, 'verify' => false]);

        $requests = function () use ($proxyUrls, $client, &$startTimes) {
            foreach ($proxyUrls as $url) {
                $startTimes[$url] = microtime(true);
                yield $url => new Request('GET', self::TEST_URL, [
                    'Proxy' => $url,
                ]);
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => $concurrency,
            'fulfilled' => function ($response, $url) use ($store, &$working, &$startTimes) {
                $body = (string) $response->getBody();
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

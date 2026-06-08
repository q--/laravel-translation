<?php

namespace JoeDixon\Translation\Proxy;

use GuzzleHttp\Client;

class ProxyPool
{
    private ProxyStore $store;

    private ProxyValidator $validator;

    /** Working proxy URLs, rotated round-robin */
    private array $working = [];

    private int $roundRobinIndex = 0;

    private int $minPool;

    private int $failLimit;

    private int $timeoutSec;

    public function __construct(
        ProxyStore $store,
        ProxyValidator $validator,
        int $minPool = 5,
        int $failLimit = 3,
        int $timeoutSec = 5
    ) {
        $this->store = $store;
        $this->validator = $validator;
        $this->minPool = $minPool;
        $this->failLimit = $failLimit;
        $this->timeoutSec = $timeoutSec;
    }

    /**
     * Ensure at least $count working proxies are available.
     * Validates unknowns first, then fetches fresh ones from ProxyScrape if needed.
     */
    public function ensureReady(int $count): void
    {
        $this->working = $this->store->getByStatus('working');

        if (count($this->working) >= $count) {
            return;
        }

        // Validate unknown proxies first
        $unknowns = $this->store->getByStatus('unknown');
        if (! empty($unknowns)) {
            $newWorking = $this->validator->validate($unknowns, $this->store, 20, $this->timeoutSec);
            $this->working = array_merge($this->working, $newWorking);
        }

        if (count($this->working) >= $count) {
            return;
        }

        // Fetch fresh proxies from ProxyScrape and validate them
        $fresh = $this->fetchFromSource();
        if (! empty($fresh)) {
            $this->store->merge($fresh);
            $newWorking = $this->validator->validate($fresh, $this->store, 20, $this->timeoutSec);
            $this->working = array_merge($this->working, $newWorking);
        }
    }

    /**
     * Get the next working proxy URL (round-robin), or null if none available.
     */
    public function acquire(): ?string
    {
        if (empty($this->working)) {
            $this->working = $this->store->getByStatus('working');
        }

        if (empty($this->working)) {
            return null;
        }

        $proxy = $this->working[$this->roundRobinIndex % count($this->working)];
        $this->roundRobinIndex++;

        return $proxy;
    }

    public function reportSuccess(string $proxy): void
    {
        // Nothing to update on success (markWorking is done at validation time)
    }

    public function reportFailure(string $proxy): void
    {
        $this->store->recordFailure($proxy, $this->failLimit);

        // Remove from local working list so we stop handing it out
        $this->working = array_values(array_filter($this->working, fn ($p) => $p !== $proxy));
    }

    /**
     * Fetch proxy list from ProxyScrape free API.
     *
     * @return string[]  Array of proxy URLs like "http://1.2.3.4:8080"
     */
    public function fetchFromSource(): array
    {
        try {
            $client = new Client(['timeout' => 15]);
            $response = $client->get(
                'https://api.proxyscrape.com/v2/?request=getproxies&protocol=http&timeout=10000&country=all&ssl=all&anonymity=all'
            );
            $body = (string) $response->getBody();
            $lines = array_filter(array_map('trim', explode("\n", $body)));

            return array_map(fn ($line) => 'http://' . $line, $lines);
        } catch (\Throwable $e) {
            return [];
        }
    }
}

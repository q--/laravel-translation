<?php

namespace JoeDixon\Translation\Tests;

use JoeDixon\Translation\Proxy\ProxyStore;
use PHPUnit\Framework\TestCase;

class ProxyStoreTest extends TestCase
{
    private ProxyStore $store;

    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = sys_get_temp_dir() . '/translation_proxy_test_' . uniqid() . '.sqlite';
        $this->store = new ProxyStore($this->dbPath);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Close PDO before unlinking — on Windows, WAL mode keeps the file locked
        $this->store->close();
        if (file_exists($this->dbPath)) {
            @unlink($this->dbPath);
            @unlink($this->dbPath . '-wal');
            @unlink($this->dbPath . '-shm');
        }
    }

    /** @test */
    public function it_stores_and_retrieves_proxies()
    {
        $this->store->merge(['http://1.2.3.4:8080', 'http://5.6.7.8:3128']);

        $this->assertSame(2, $this->store->totalCount());
        $unknowns = $this->store->getByStatus('unknown');
        $this->assertContains('http://1.2.3.4:8080', $unknowns);
        $this->assertContains('http://5.6.7.8:3128', $unknowns);
    }

    /** @test */
    public function it_marks_proxies_as_working()
    {
        $this->store->merge(['http://1.2.3.4:8080']);
        $this->store->markWorking('http://1.2.3.4:8080', 123);

        $this->assertSame(1, $this->store->countByStatus('working'));
        $this->assertTrue($this->store->hasWorking());

        $working = $this->store->getByStatus('working');
        $this->assertContains('http://1.2.3.4:8080', $working);
    }

    /** @test */
    public function it_records_failures_and_marks_dead_after_threshold()
    {
        $this->store->merge(['http://1.2.3.4:8080']);

        $failLimit = 3;
        $this->store->recordFailure('http://1.2.3.4:8080', $failLimit);
        $this->assertSame(0, $this->store->countByStatus('dead'));

        $this->store->recordFailure('http://1.2.3.4:8080', $failLimit);
        $this->assertSame(0, $this->store->countByStatus('dead'));

        $this->store->recordFailure('http://1.2.3.4:8080', $failLimit);
        $this->assertSame(1, $this->store->countByStatus('dead'));
        $this->assertFalse($this->store->hasWorking());
    }

    /** @test */
    public function it_does_not_overwrite_existing_entries_on_merge()
    {
        $this->store->merge(['http://1.2.3.4:8080']);
        $this->store->markWorking('http://1.2.3.4:8080', 50);

        // Merge again — should not reset status back to unknown
        $this->store->merge(['http://1.2.3.4:8080']);

        $this->assertSame(1, $this->store->countByStatus('working'));
        $this->assertSame(0, $this->store->countByStatus('unknown'));
    }

    /** @test */
    public function it_resets_consecutive_failures_on_mark_working()
    {
        $this->store->merge(['http://1.2.3.4:8080']);
        $this->store->recordFailure('http://1.2.3.4:8080', 10);
        $this->store->recordFailure('http://1.2.3.4:8080', 10);

        $this->store->markWorking('http://1.2.3.4:8080', 100);

        // After markWorking, a new failure should start the count from 1 again
        $this->store->recordFailure('http://1.2.3.4:8080', 3);
        $this->assertSame(0, $this->store->countByStatus('dead'));
    }
}

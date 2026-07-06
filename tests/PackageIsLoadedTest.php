<?php

namespace JoeDixon\Translation\Tests;

use JoeDixon\Translation\TranslationBindingsServiceProvider;
use JoeDixon\Translation\TranslationServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PackageIsLoadedTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            TranslationServiceProvider::class,
            TranslationBindingsServiceProvider::class,
        ];
    }

    #[Test]
    public function the_translation_pacakage_is_loaded()
    {
        $this->assertArrayHasKey(TranslationServiceProvider::class, app()->getLoadedProviders());
        $this->assertArrayHasKey(TranslationBindingsServiceProvider::class, app()->getLoadedProviders());
    }
}

<?php

namespace JoeDixon\Translation\Tests;

use Illuminate\Support\Facades\Event;
use JoeDixon\Translation\Drivers\Translation;
use JoeDixon\Translation\Events\TranslationAdded;
use JoeDixon\Translation\Exceptions\LanguageExistsException;
use JoeDixon\Translation\TranslationBindingsServiceProvider;
use JoeDixon\Translation\TranslationServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Stichoza\GoogleTranslate\GoogleTranslate;

class FileDriverTest extends TestCase
{
    private $translation;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
        app()['path.lang'] = __DIR__.'/fixtures/lang';
        $this->translation = app()->make(Translation::class);
    }

    protected function getPackageProviders($app)
    {
        return [
            TranslationServiceProvider::class,
            TranslationBindingsServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('translation.driver', 'file');
    }

    #[Test]
    public function it_returns_all_languages()
    {
        $languages = $this->translation->allLanguages();

        $this->assertEquals($languages->count(), 2);
        $this->assertEquals($languages->toArray(), ['en' => 'en', 'es' => 'es']);
    }

    #[Test]
    public function it_returns_all_translations()
    {
        $translations = $this->translation->allTranslations();

        $this->assertEquals($translations->count(), 2);
        $this->assertEquals(['single' => ['single' => ['Hello' => 'Hello', "What's up" => "What's up!"]], 'group' => ['test' => ['hello' => 'Hello', 'whats_up' => "What's up!"]]], $translations->toArray()['en']);
        $this->assertArrayHasKey('en', $translations->toArray());
        $this->assertArrayHasKey('es', $translations->toArray());
    }

    #[Test]
    public function it_returns_all_translations_for_a_given_language()
    {
        $translations = $this->translation->allTranslationsFor('en');
        $this->assertEquals($translations->count(), 2);
        $this->assertEquals(['single' => ['single' => ['Hello' => 'Hello', "What's up" => "What's up!"]], 'group' => ['test' => ['hello' => 'Hello', 'whats_up' => "What's up!"]]], $translations->toArray());
        $this->assertArrayHasKey('single', $translations->toArray());
        $this->assertArrayHasKey('group', $translations->toArray());
    }

    #[Test]
    public function it_throws_an_exception_if_a_language_exists()
    {
        $this->expectException(LanguageExistsException::class);
        $this->translation->addLanguage('en');
    }

    #[Test]
    public function it_can_add_a_new_language()
    {
        $this->translation->addLanguage('fr');

        $this->assertTrue(file_exists(__DIR__.'/fixtures/lang/fr.json'));
        $this->assertTrue(file_exists(__DIR__.'/fixtures/lang/fr'));

        rmdir(__DIR__.'/fixtures/lang/fr');
        unlink(__DIR__.'/fixtures/lang/fr.json');
    }

    #[Test]
    public function it_can_add_a_new_translation_to_a_new_group()
    {
        $this->translation->addGroupTranslation('es', 'test', 'hello', 'Hola!');

        $translations = $this->translation->allTranslationsFor('es');

        $this->assertEquals(['test' => ['hello' => 'Hola!']], $translations->toArray()['group']);

        unlink(__DIR__.'/fixtures/lang/es/test.php');
    }

    #[Test]
    public function it_can_add_a_new_translation_to_an_existing_translation_group()
    {
        $this->translation->addGroupTranslation('en', 'test', 'test', 'Testing');

        $translations = $this->translation->allTranslationsFor('en');

        $this->assertEquals(['test' => ['hello' => 'Hello', 'whats_up' => 'What\'s up!', 'test' => 'Testing']], $translations->toArray()['group']);

        file_put_contents(
            app()['path.lang'].'/en/test.php',
            "<?php\n\nreturn ".var_export(['hello' => 'Hello', 'whats_up' => 'What\'s up!'], true).';'.\PHP_EOL
        );
    }

    #[Test]
    public function it_can_add_a_new_single_translation()
    {
        $this->translation->addSingleTranslation('es', 'single', 'Hello', 'Hola!');

        $translations = $this->translation->allTranslationsFor('es');

        $this->assertEquals(['single' => ['Hello' => 'Hola!']], $translations->toArray()['single']);

        unlink(__DIR__.'/fixtures/lang/es.json');
    }

    #[Test]
    public function it_can_add_a_new_single_translation_to_an_existing_language()
    {
        $this->translation->addSingleTranslation('en', 'single', 'Test', 'Testing');

        $translations = $this->translation->allTranslationsFor('en');

        $this->assertEquals(['single' => ['Hello' => 'Hello', 'What\'s up' => 'What\'s up!', 'Test' => 'Testing']], $translations->toArray()['single']);

        file_put_contents(
            app()['path.lang'].'/en.json',
            json_encode((object) ['Hello' => 'Hello', 'What\'s up' => 'What\'s up!'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    #[Test]
    public function it_can_get_a_collection_of_group_names_for_a_given_language()
    {
        $groups = $this->translation->getGroupsFor('en');

        $this->assertEquals($groups->toArray(), ['test']);
    }

    #[Test]
    public function it_can_merge_a_language_with_the_base_language()
    {
        $this->translation->addGroupTranslation('es', 'test', 'hello', 'Hola!');
        $translations = $this->translation->getSourceLanguageTranslationsWith('es');

        $this->assertEquals($translations->toArray(), [
            'group' => [
                'test' => [
                    'hello' => ['en' => 'Hello', 'es' => 'Hola!'],
                    'whats_up' => ['en' => "What's up!", 'es' => ''],
                ],
            ],
            'single' => [
                'single' => [
                    'Hello' => [
                        'en' => 'Hello',
                        'es' => '',
                    ],
                    "What's up" => [
                        'en' => "What's up!",
                        'es' => '',
                    ],
                ],
            ],
        ]);

        unlink(__DIR__.'/fixtures/lang/es/test.php');
    }

    #[Test]
    public function it_can_add_a_vendor_namespaced_translations()
    {
        $this->translation->addGroupTranslation('es', 'translation_test::test', 'hello', 'Hola!');

        $this->assertEquals($this->translation->allTranslationsFor('es')->toArray(), [
            'group' => [
                'translation_test::test' => [
                    'hello' => 'Hola!',
                ],
            ],
            'single' => [],
        ]);

        \File::deleteDirectory(__DIR__.'/fixtures/lang/vendor');
    }

    #[Test]
    public function it_can_add_a_nested_translation()
    {
        $this->translation->addGroupTranslation('en', 'test', 'test.nested', 'Nested!');

        $this->assertEquals($this->translation->getGroupTranslationsFor('en')->toArray(), [
            'test' => [
                'hello' => 'Hello',
                'test.nested' => 'Nested!',
                'whats_up' => 'What\'s up!',
            ],
        ]);

        file_put_contents(
            app()['path.lang'].'/en/test.php',
            "<?php\n\nreturn ".var_export(['hello' => 'Hello', 'whats_up' => 'What\'s up!'], true).';'.\PHP_EOL
        );
    }

    #[Test]
    public function it_can_add_nested_vendor_namespaced_translations()
    {
        $this->translation->addGroupTranslation('es', 'translation_test::test', 'nested.hello', 'Hola!');

        $this->assertEquals($this->translation->allTranslationsFor('es')->toArray(), [
            'group' => [
                'translation_test::test' => [
                    'nested.hello' => 'Hola!',
                ],
            ],
            'single' => [],
        ]);

        \File::deleteDirectory(__DIR__.'/fixtures/lang/vendor');
    }

    #[Test]
    public function it_can_merge_a_namespaced_language_with_the_base_language()
    {
        $this->translation->addGroupTranslation('en', 'translation_test::test', 'hello', 'Hello');
        $this->translation->addGroupTranslation('es', 'translation_test::test', 'hello', 'Hola!');
        $translations = $this->translation->getSourceLanguageTranslationsWith('es');

        $this->assertEquals($translations->toArray(), [
            'group' => [
                'test' => [
                    'hello' => ['en' => 'Hello', 'es' => ''],
                    'whats_up' => ['en' => "What's up!", 'es' => ''],
                ],
                'translation_test::test' => [
                    'hello' => ['en' => 'Hello', 'es' => 'Hola!'],
                ],
            ],
            'single' => [
                'single' => [
                    'Hello' => [
                        'en' => 'Hello',
                        'es' => '',
                    ],
                    "What's up" => [
                        'en' => "What's up!",
                        'es' => '',
                    ],
                ],
            ],
        ]);

        \File::deleteDirectory(__DIR__.'/fixtures/lang/vendor');
    }

    #[Test]
    public function a_list_of_languages_can_be_viewed()
    {
        $this->get(config('translation.ui_url'))
            ->assertSee('en');
    }

    #[Test]
    public function the_language_creation_page_can_be_viewed()
    {
        $this->get(config('translation.ui_url').'/create')
            ->assertSee('Add a new language');
    }

    #[Test]
    public function a_language_can_be_added()
    {
        $this->post(config('translation.ui_url'), ['locale' => 'de'])
            ->assertRedirect();

        $this->assertTrue(file_exists(__DIR__.'/fixtures/lang/de.json'));
        $this->assertTrue(file_exists(__DIR__.'/fixtures/lang/de'));

        rmdir(__DIR__.'/fixtures/lang/de');
        unlink(__DIR__.'/fixtures/lang/de.json');
    }

    #[Test]
    public function a_list_of_translations_can_be_viewed()
    {
        $this->get(config('translation.ui_url').'/en/translations')
            ->assertSee('hello')
            ->assertSee('whats_up');
    }

    #[Test]
    public function the_translation_creation_page_can_be_viewed()
    {
        $this->get(config('translation.ui_url').'/'.config('app.locale').'/translations/create')
            ->assertSee('Add a translation');
    }

    #[Test]
    public function a_new_translation_can_be_added()
    {
        $this->post(config('translation.ui_url').'/en/translations', ['key' => 'joe', 'value' => 'is cool'])
            ->assertRedirect();
        $translations = $this->translation->getSingleTranslationsFor('en');

        $this->assertEquals(['Hello' => 'Hello', 'What\'s up' => 'What\'s up!', 'joe' => 'is cool'], $translations->toArray()['single']);

        file_put_contents(
            app()['path.lang'].'/en.json',
            json_encode((object) ['Hello' => 'Hello', 'What\'s up' => 'What\'s up!'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    #[Test]
    public function a_translation_can_be_updated()
    {
        $this->post(config('translation.ui_url').'/en', ['group' => 'test', 'key' => 'hello', 'value' => 'Hello there!'])
            ->assertStatus(200);

        $translations = $this->translation->getGroupTranslationsFor('en');

        $this->assertEquals(['hello' => 'Hello there!', 'whats_up' => 'What\'s up!'], $translations->toArray()['test']);

        file_put_contents(
            app()['path.lang'].'/en/test.php',
            "<?php\n\nreturn ".var_export(['hello' => 'Hello', 'whats_up' => 'What\'s up!'], true).';'.\PHP_EOL
        );
    }

    #[Test]
    public function adding_a_translation_fires_an_event_with_the_expected_data()
    {
        Event::fake();

        $data = ['key' => 'joe', 'value' => 'is cool'];
        $this->post(config('translation.ui_url').'/en/translations', $data);

        Event::assertDispatched(TranslationAdded::class, function ($event) use ($data) {
            return $event->language === 'en' &&
                $event->group === 'single' &&
                $event->value === $data['value'] &&
                $event->key === $data['key'];
        });
        file_put_contents(
            app()['path.lang'].'/en.json',
            json_encode((object) ['Hello' => 'Hello', 'What\'s up' => 'What\'s up!'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    #[Test]
    public function updating_a_translation_fires_an_event_with_the_expected_data()
    {
        Event::fake();

        $data = ['group' => 'test', 'key' => 'hello', 'value' => 'Hello there!'];
        $this->post(config('translation.ui_url').'/en/translations', $data);

        Event::assertDispatched(TranslationAdded::class, function ($event) use ($data) {
            return $event->language === 'en' &&
                $event->group === $data['group'] &&
                $event->value === $data['value'] &&
                $event->key === $data['key'];
        });
        file_put_contents(
            app()['path.lang'].'/en/test.php',
            "<?php\n\nreturn ".var_export(['hello' => 'Hello', 'whats_up' => 'What\'s up!'], true).';'.\PHP_EOL
        );
    }

    #[Test]
    public function pipe_characters_in_google_translate_output_are_replaced_with_a_danda()
    {
        $tr = $this->createMock(GoogleTranslate::class);
        $tr->method('translate')->willReturnCallback(function ($text) {
            // Simulate Odia, where Google renders the sentence terminator danda as " |"
            return $text.' |';
        });

        $result = $this->translation->getGoogleTranslate('or', 'Hello', $tr);

        // The pipe becomes a danda attached to the preceding word — never \|,
        // which Laravel would render literally (it has no pipe escaping).
        $this->assertSame('Hello।', $result);
    }

    #[Test]
    public function pipe_characters_are_replaced_with_a_danda_for_any_target_language()
    {
        $driver = $this->translationWithWarningSpy();

        $tr = $this->createMock(GoogleTranslate::class);
        $tr->method('translate')->willReturnCallback(function ($text) {
            return $text.' |';
        });

        $result = $driver->getGoogleTranslate('fr', 'Hello', $tr);

        $this->assertSame('Hello।', $result);
    }

    #[Test]
    public function a_warning_is_emitted_when_google_introduces_a_pipe_for_a_language_other_than_odia()
    {
        $driver = $this->translationWithWarningSpy();

        $tr = $this->createMock(GoogleTranslate::class);
        $tr->method('translate')->willReturnCallback(function ($text) {
            return $text.' |';
        });

        $driver->getGoogleTranslate('fr', 'Hello', $tr);

        $this->assertCount(1, $driver->pipeWarnings);
        $this->assertSame('fr', $driver->pipeWarnings[0]['language']);
        $this->assertSame('Hello', $driver->pipeWarnings[0]['originalToken']);
    }

    #[Test]
    public function no_warning_is_emitted_when_google_introduces_a_pipe_for_odia()
    {
        $driver = $this->translationWithWarningSpy();

        $tr = $this->createMock(GoogleTranslate::class);
        $tr->method('translate')->willReturnCallback(function ($text) {
            return $text.' |';
        });

        $driver->getGoogleTranslate('or', 'Hello', $tr);

        $this->assertSame([], $driver->pipeWarnings);
    }

    #[Test]
    public function pipe_characters_in_plural_strings_become_dandas_while_variants_still_join_with_a_pipe()
    {
        $tr = $this->createMock(GoogleTranslate::class);
        $tr->method('translate')->willReturnCallback(function ($text) {
            return $text.' |';
        });

        // Each variant's Google-introduced | becomes a danda; the variants
        // themselves are still joined with a genuine pluralization pipe.
        $result = $this->translation->getGoogleTranslate('or', 'One item|Many items', $tr);

        $this->assertSame('One item।|Many items।', $result);
        $this->assertStringNotContainsString('\\|', $result);
    }

    /**
     * A File driver whose pipe warning is captured instead of written to STDERR.
     */
    private function translationWithWarningSpy()
    {
        return new class(
            app('files'),
            app()['path.lang'],
            'en',
            app(\JoeDixon\Translation\Scanner::class)
        ) extends \JoeDixon\Translation\Drivers\File {
            public $pipeWarnings = [];

            protected function warnUnexpectedPipeFromGoogle(string $language, string $originalToken, string $translatedPiece): void
            {
                $this->pipeWarnings[] = compact('language', 'originalToken', 'translatedPiece');
            }
        };
    }

    #[Test]
    public function batch_translate_combines_multiple_strings_into_one_call()
    {
        $callCount = 0;
        $tr = $this->createMock(GoogleTranslate::class);
        $tr->method('translate')->willReturnCallback(function ($text) use (&$callCount) {
            $callCount++;
            // Echo back the text (simulating a translation that preserves separators)
            return $text;
        });

        $tokens = [
            'group\0test\0hello' => 'Hello',
            'group\0test\0world' => 'World',
            'group\0test\0foo'   => 'Foo',
        ];

        $results = $this->translation->batchTranslate('es', $tokens, $tr);

        // All three should be batched into a single translate() call
        $this->assertSame(1, $callCount, 'Expected a single batch translate call');
        $this->assertArrayHasKey('group\0test\0hello', $results);
        $this->assertArrayHasKey('group\0test\0world', $results);
        $this->assertArrayHasKey('group\0test\0foo', $results);
    }

    #[Test]
    public function batch_translate_handles_strings_with_newlines_individually()
    {
        $calls = [];
        $tr = $this->createMock(GoogleTranslate::class);
        $tr->method('translate')->willReturnCallback(function ($text) use (&$calls) {
            $calls[] = $text;

            return $text;
        });

        $tokens = [
            'group\0test\0multiline' => "Line one\nLine two",
            'group\0test\0normal'    => 'Normal string',
        ];

        $results = $this->translation->batchTranslate('es', $tokens, $tr);

        // The multiline string must be translated individually (its own call), not batched
        $multilineTranslated = $results['group\0test\0multiline'] ?? null;
        $this->assertSame("Line one\nLine two", $multilineTranslated);
        $this->assertArrayHasKey('group\0test\0normal', $results);
    }

    #[Test]
    public function batch_translate_handles_pluralization_variants()
    {
        $tr = $this->createMock(GoogleTranslate::class);
        $tr->method('translate')->willReturnCallback(function ($text) {
            // Simulate translation: echo text back unchanged
            return $text;
        });

        $tokens = [
            'group\0test\0count' => 'One item|Many items',
        ];

        $results = $this->translation->batchTranslate('es', $tokens, $tr);

        // Pluralization separator must be preserved in output
        $this->assertStringContainsString('|', $results['group\0test\0count']);
    }

    #[Test]
    public function batch_translate_replaces_pipe_characters_with_a_danda_in_batch_mode()
    {
        $tr = $this->createMock(GoogleTranslate::class);
        $tr->method('translate')->willReturnCallback(function ($text) {
            // Simulate a translation that injects a bare pipe character
            return $text.' |';
        });

        $tokens = [
            'group\0test\0hello' => 'Hello',
        ];

        $results = $this->translation->batchTranslate('or', $tokens, $tr);

        $this->assertSame('Hello।', $results['group\0test\0hello']);
        $this->assertStringNotContainsString('\\|', $results['group\0test\0hello']);
    }

    #[Test]
    public function save_missing_translations_for_all_languages_scans_once(): void
    {
        $scanner = $this->createMock(\JoeDixon\Translation\Scanner::class);
        $scanner->expects($this->once())
            ->method('findTranslations')
            ->willReturn(['single' => [], 'group' => []]);

        app()->instance(\JoeDixon\Translation\Scanner::class, $scanner);
        app()->forgetInstance(Translation::class);
        $translation = app()->make(Translation::class);

        // Two languages (en + es) in fixtures — scanner must still only run once.
        $translation->saveMissingTranslations(false);
    }

    #[Test]
    public function list_missing_translation_keys_command_scans_once(): void
    {
        $scanner = $this->createMock(\JoeDixon\Translation\Scanner::class);
        $scanner->expects($this->once())
            ->method('findTranslations')
            ->willReturn(['single' => [], 'group' => []]);

        app()->instance(\JoeDixon\Translation\Scanner::class, $scanner);
        app()->forgetInstance(Translation::class);

        // Two languages (en + es) in fixtures — scanner must still only run once.
        $this->artisan('translation:list-missing-translation-keys')
            ->assertExitCode(0);
    }

    #[Test]
    public function batch_translate_falls_back_to_individual_calls_when_batch_split_fails()
    {
        $individualCallCount = 0;
        $tr = $this->createMock(GoogleTranslate::class);
        $tr->method('translate')->willReturnCallback(function ($text) use (&$individualCallCount) {
            $individualCallCount++;
            // Remove the separator URL so split produces wrong count, forcing per-item fallback
            return preg_replace('/https?:\/\/example\.com\/\d+/i', '', $text);
        });

        $tokens = [
            'group\0test\0a' => 'Apple',
            'group\0test\0b' => 'Banana',
        ];

        $results = $this->translation->batchTranslate('es', $tokens, $tr);

        // Fallback path: 1 batch attempt (which fails) + 1 call per item = 3 total
        $this->assertSame(3, $individualCallCount);
        $this->assertArrayHasKey('group\0test\0a', $results);
        $this->assertArrayHasKey('group\0test\0b', $results);
    }
}

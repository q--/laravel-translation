<?php

namespace JoeDixon\Translation\Drivers;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use JoeDixon\Translation\Events\TranslationAdded;
use Stichoza\GoogleTranslate\GoogleTranslate;

abstract class Translation
{
    /**
     * Find all of the translations in the app without translation for a given language.
     *
     * @param  string  $language
     * @param  array|null  $scannedTranslations  Pre-computed scan result to avoid redundant scanning
     * @param  \Illuminate\Support\Collection|null  $targetTranslations  Pre-loaded translations for $language
     * @return array
     */
    public function findMissingTranslations($language, ?array $scannedTranslations = null, ?\Illuminate\Support\Collection $targetTranslations = null)
    {
        return array_diff_assoc_recursive(
            $scannedTranslations ?? $this->scanner->findTranslations(),
            $targetTranslations ?? $this->allTranslationsFor($language)
        );
    }

    /**
     * Save all of the translations in the app without translation for a given language.
     *
     * @param  string  $language
     * @param  array|null  $scannedTranslations  Pre-computed scan result to avoid redundant scanning
     * @param  \Illuminate\Support\Collection|null  $targetTranslations  Pre-loaded translations for $language (only used when $language is a specific language, not false)
     * @return void
     */
    public function saveMissingTranslations($language = false, ?array $scannedTranslations = null, ?\Illuminate\Support\Collection $targetTranslations = null)
    {
        $languages = $language ? [$language => $language] : $this->allLanguages();
        // Pre-compute once so the scanner doesn't run once per language when called for all languages.
        $scannedTranslations ??= $this->scanner->findTranslations();

        foreach ($languages as $language => $name) {
            $missingTranslations = $this->findMissingTranslations($language, $scannedTranslations, $targetTranslations);

            $pendingGroup = [];
            $pendingSingle = [];

            foreach ($missingTranslations as $type => $groups) {
                foreach ($groups as $group => $translations) {
                    foreach ($translations as $key => $value) {
                        if (Str::contains($group, 'single')) {
                            $pendingSingle[$group][$key] = '';
                        } else {
                            $pendingGroup[$group][$key] = '';
                        }
                    }
                }
            }

            $this->batchAddTranslations($language, $pendingGroup, $pendingSingle);
        }
    }

    /**
     * Save all of the translations in the app without translation for a given language then
     * Translate all the tokens into it's respective language using google translate
     *
     * @param  string  $language
     * @return void
     */
    public function autoTranslate($language = false)
    {
        $languages = $language ? [$language => $language] : $this->allLanguages();

        // Pre-compute once and reuse across all languages instead of repeating per language.
        $sourceTranslations = $this->allTranslationsFor($this->sourceLanguage);
        $scannedTranslations = $this->scanner->findTranslations();

        foreach ($languages as $language => $name) {
            $targetTranslations = $this->allTranslationsFor($language);
            $this->saveMissingTranslations($language, $scannedTranslations, $targetTranslations);
            $this->translateLanguage($language, $sourceTranslations, null, $targetTranslations);
            //Inform the user of what language we just finished translating
            fwrite(STDOUT, __('translation::translation.auto_translated_language', ['language' => $language]) . PHP_EOL);
        }
    }

    /**
     * Run the scanner and return raw translations array.
     */
    public function scanForTranslations(): array
    {
        return $this->scanner->findTranslations();
    }

    public function getSourceLanguage(): string
    {
        return $this->sourceLanguage;
    }

    /**
     * Replace :placeholders in a token with temporary fake URLs safe to send through Google Translate.
     *
     * Returns [$modifiedToken, $placeholders, $tempStrings].
     */
    private function applyPlaceholders(string $language, string $token): array
    {
        preg_match_all('/:([a-zA-Z0-9_]+)/', $token, $matches);
        $placeholders = $matches[0];
        $tempStrings = [];
        foreach ($placeholders as $index => $placeholder) {
            // After experiments, fake URLs survive Google Translate best.
            // Newar (new) converts digits to Newar script, so we use letters there.
            $tempStrings[] = 'https://t.co/' . (
                $language === 'new'
                    ? mb_strtoupper(base_convert($index + 10, 10, 36))
                    : $index
            );
        }

        return [str_replace($placeholders, $tempStrings, $token), $placeholders, $tempStrings];
    }

    /**
     * Restore :placeholders from temporary fake URLs in translated text.
     * Emits a STDERR warning if placeholder count changed.
     */
    private function restorePlaceholders(string $text, array $placeholders, array $tempStrings, string $language, string $originalToken): string
    {
        $text = str_ireplace($tempStrings, $placeholders, $text);

        preg_match_all('/:([a-zA-Z0-9_]+)/', $text, $translatedMatches);
        if (count($translatedMatches[0]) !== count($placeholders)) {
            fwrite(STDERR, sprintf(
                "Warning: Placeholder count mismatch in translated text when translating %s to %s.\nOriginal text: %s\nTranslated text: %s\nExpected placeholders: %s\nActual placeholders: %s\n",
                $this->sourceLanguage,
                $language,
                $originalToken,
                $text,
                json_encode($placeholders),
                json_encode($translatedMatches[0])
            ));
        }

        return $text;
    }

    /**
     * Fix pipe characters that Google Translate introduced into a translated piece.
     *
     * Laravel has no escape sequence for pipes in translation strings:
     * MessageSelector::choose() does a plain explode('|', $line) and never
     * unescapes \|, so neither a bare nor an "escaped" pipe may ever be stored.
     * Pieces are split on | before being sent to Google, so any pipe in the
     * response was introduced by Google. The only known cause is Odia (or),
     * where Google renders the sentence terminator danda (।, U+0964) as an
     * ASCII pipe. The danda attaches directly to the preceding word, so a
     * space before the pipe is dropped along with it.
     *
     * Some scripts have terminators that merely resemble the danda (Tibetan
     * shad །, Ol Chiki ᱾), so for any target language other than Odia a
     * warning is emitted — the danda may be the wrong glyph there.
     */
    protected function replaceGoogleIntroducedPipes(string $piece, string $language, string $originalToken): string
    {
        if (! str_contains($piece, '|')) {
            return $piece;
        }

        if ($language !== 'or') {
            $this->warnUnexpectedPipeFromGoogle($language, $originalToken, $piece);
        }

        return preg_replace('/ ?\|/', '।', $piece);
    }

    /**
     * Warn that Google Translate introduced a pipe for a language not known to do so.
     */
    protected function warnUnexpectedPipeFromGoogle(string $language, string $originalToken, string $translatedPiece): void
    {
        fwrite(STDERR, sprintf(
            "Warning: Google Translate output contained a pipe character when translating %s to %s; it was replaced with a danda (।). Only Odia (or) is known to mis-render its sentence terminator as a pipe — verify the danda is the correct glyph for %s.\nOriginal text: %s\nTranslated text: %s\n",
            $this->sourceLanguage,
            $language,
            $language,
            $originalToken,
            $translatedPiece
        ));
    }

    /**
     * Translate text using Google Translate (single string, public API for backwards compat).
     *
     * @param $language
     * @param $token
     * @return string|null
     * @throws \ErrorException
     */
    public function getGoogleTranslate($language, $token, ?GoogleTranslate $tr = null)
    {
        [$modifiedToken, $placeholders, $tempStrings] = $this->applyPlaceholders($language, $token);

        $tr ??= new GoogleTranslate($language, $this->sourceLanguage);

        // In Laravel, | separates pluralization variants — translate each separately
        // so Google Translate doesn't mix them up.
        $translated = [];
        foreach (explode('|', $modifiedToken) as $translatableText) {
            $piece = $tr->translate($translatableText);
            $translated[] = $this->replaceGoogleIntroducedPipes($piece, $language, $token);
        }
        $translatedText = implode('|', $translated);

        return $this->restorePlaceholders($translatedText, $placeholders, $tempStrings, $language, $token);
    }

    /**
     * Translate a single chunk of items with one Google Translate call.
     * Falls back to individual calls if the batch split produces unexpected results.
     * Each item must have 'variants' (array of strings), 'placeholders', 'tempStrings', 'token' (original).
     * Returns the same array with 'translated' => string set on each item.
     */
    private function translateBatchChunk(array $chunk, string $language, GoogleTranslate $tr): array
    {
        // Build the combined text, using indexed URL markers to separate items/variants.
        $separatorPattern = 'https://example.com/%d';
        $parts = [];
        $indexMap = []; // maps flat index → [item index, variant index]
        $flatIndex = 0;

        foreach ($chunk as $itemIndex => $item) {
            foreach ($item['variants'] as $variantIndex => $variant) {
                $parts[] = $variant;
                $indexMap[$flatIndex] = [$itemIndex, $variantIndex];
                $flatIndex++;
            }
        }

        // Interleave with separator URLs
        $combined = '';
        foreach ($parts as $i => $part) {
            if ($i > 0) {
                $combined .= "\n\n" . sprintf($separatorPattern, $i) . "\n\n";
            }
            $combined .= $part;
        }

        $translatedCombined = $tr->translate($combined);

        // Split by the separator URLs (case-insensitive — Google Translate may change case)
        $splitParts = preg_split(
            '/\s*https?:\/\/example\.com\/\d+\s*/i',
            $translatedCombined
        );

        // If split count doesn't match, fall back to individual calls
        if (count($splitParts) !== count($parts)) {
            foreach ($chunk as &$item) {
                $translatedVariants = [];
                foreach ($item['variants'] as $variant) {
                    $piece = $tr->translate($variant);
                    $translatedVariants[] = $this->replaceGoogleIntroducedPipes($piece, $language, $item['token']);
                }
                $item['translated'] = $translatedVariants;
            }
            unset($item);

            return $chunk;
        }

        // Assign translated variants back to each item
        $resultsByItem = [];
        foreach ($splitParts as $flatIdx => $translatedPart) {
            [$itemIndex, $variantIndex] = $indexMap[$flatIdx];
            $resultsByItem[$itemIndex][$variantIndex] = $this->replaceGoogleIntroducedPipes(
                trim($translatedPart),
                $language,
                $chunk[$itemIndex]['token']
            );
        }

        foreach ($chunk as $itemIndex => &$item) {
            $item['translated'] = $resultsByItem[$itemIndex] ?? array_fill(0, count($item['variants']), '');
        }
        unset($item);

        return $chunk;
    }

    /**
     * Batch-translate multiple tokens in as few Google Translate HTTP calls as possible.
     *
     * Tokens that contain literal newlines cannot be safely batched and are translated individually.
     * Pluralization variants (| separated) are expanded into separate batch entries.
     *
     * Returns an array keyed by the same keys as $tokens with the translated strings as values.
     *
     * @param  string  $language  Target language code
     * @param  array<string, string>  $tokens  Associative array of composite-key => source string
     * @param  GoogleTranslate  $tr
     * @return array<string, string>
     */
    public function batchTranslate(string $language, array $tokens, GoogleTranslate $tr): array
    {
        $batchableItems = [];
        $individualItems = [];

        foreach ($tokens as $compositeKey => $token) {
            [$modifiedToken, $placeholders, $tempStrings] = $this->applyPlaceholders($language, $token);

            if (str_contains($modifiedToken, "\n")) {
                // Can't safely batch strings with literal newlines — translate individually
                $individualItems[$compositeKey] = [
                    'token' => $token,
                    'modifiedToken' => $modifiedToken,
                    'placeholders' => $placeholders,
                    'tempStrings' => $tempStrings,
                ];
            } else {
                $variants = explode('|', $modifiedToken);
                $batchableItems[$compositeKey] = [
                    'token' => $token,
                    'variants' => $variants,
                    'placeholders' => $placeholders,
                    'tempStrings' => $tempStrings,
                ];
            }
        }

        $results = [];

        // Translate individual (newline-containing) items one by one
        foreach ($individualItems as $compositeKey => $item) {
            $translated = [];
            foreach (explode('|', $item['modifiedToken']) as $variant) {
                $piece = $tr->translate($variant);
                $translated[] = $this->replaceGoogleIntroducedPipes($piece, $language, $item['token']);
            }
            $joined = implode('|', $translated);
            $results[$compositeKey] = $this->restorePlaceholders(
                $joined,
                $item['placeholders'],
                $item['tempStrings'],
                $language,
                $item['token']
            );
        }

        // Chunk batchable items and translate in bulk
        $chunks = $this->chunkForBatchKeyed($batchableItems);

        foreach ($chunks as $chunk) {
            $translatedChunk = $this->translateBatchChunk(array_values($chunk), $language, $tr);

            foreach (array_keys($chunk) as $pos => $compositeKey) {
                $item = $translatedChunk[$pos];
                $variantStrings = $item['translated'] ?? [];
                $joined = implode('|', $variantStrings);
                $results[$compositeKey] = $this->restorePlaceholders(
                    $joined,
                    $item['placeholders'],
                    $item['tempStrings'],
                    $language,
                    $item['token']
                );
            }
        }

        return $results;
    }

    /**
     * Like chunkForBatch but preserves the string keys of $items.
     * Returns array of chunks, each chunk being an associative array keyed by composite key.
     */
    private function chunkForBatchKeyed(array $items, int $maxChars = 4500): array
    {
        $chunks = [];
        $currentChunk = [];
        $currentLength = 0;

        foreach ($items as $compositeKey => $item) {
            $itemText = implode("\n\n", $item['variants']);
            $itemLength = strlen($itemText);

            if ($itemLength > $maxChars) {
                if (! empty($currentChunk)) {
                    $chunks[] = $currentChunk;
                    $currentChunk = [];
                    $currentLength = 0;
                }
                $chunks[] = [$compositeKey => $item];
                continue;
            }

            $separatorLength = empty($currentChunk) ? 0 : strlen("\n\nhttps://example.com/0\n\n");

            if ($currentLength + $separatorLength + $itemLength > $maxChars && ! empty($currentChunk)) {
                $chunks[] = $currentChunk;
                $currentChunk = [];
                $currentLength = 0;
            }

            $currentChunk[$compositeKey] = $item;
            $currentLength += $separatorLength + $itemLength;
        }

        if (! empty($currentChunk)) {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }

    /**
     * Loop through all the keys and get translated text from Google Translate
     *
     * @param $language
     * @param  \Illuminate\Support\Collection|null  $sourceTranslations  Pre-loaded source language translations
     * @param  GoogleTranslate|null  $tr  Optional pre-configured translator (e.g. with proxy set)
     * @param  \Illuminate\Support\Collection|null  $targetTranslations  Pre-loaded translations for $language
     */
    public function translateLanguage($language, ?\Illuminate\Support\Collection $sourceTranslations = null, ?GoogleTranslate $tr = null, ?\Illuminate\Support\Collection $targetTranslations = null)
    {
        //No need to translate e.g. English to English
        if ($language === $this->sourceLanguage) {
            return;
        }

        $translations = $this->getSourceLanguageTranslationsWith($language, $sourceTranslations, $targetTranslations);
        $tr ??= new GoogleTranslate($language, $this->sourceLanguage);

        // Collect all strings that need translation, keyed by composite key
        $tokensToTranslate = [];

        foreach ($translations as $type => $groups) {
            foreach ($groups as $group => $groupTranslations) {
                foreach ($groupTranslations as $key => $value) {
                    // Fall back to $key if source language has no value
                    $sourceValue = in_array($value[$this->sourceLanguage], ['', null]) ? $key : $value[$this->sourceLanguage];
                    $targetValue = $value[$language];

                    if (in_array($targetValue, ['', null])) {
                        // Composite key: type\0group\0key (null byte never appears in translation keys)
                        $compositeKey = "{$type}\0{$group}\0{$key}";
                        $tokensToTranslate[$compositeKey] = $sourceValue;
                    }
                }
            }
        }

        if (empty($tokensToTranslate)) {
            return;
        }

        $translated = $this->batchTranslate($language, $tokensToTranslate, $tr);

        $pendingGroup = [];
        $pendingSingle = [];

        foreach ($translated as $compositeKey => $newValue) {
            [$type, $group, $key] = explode("\0", $compositeKey, 3);
            if (Str::contains($group, 'single')) {
                $pendingSingle[$group][$key] = $newValue;
            } else {
                $pendingGroup[$group][$key] = $newValue;
            }
        }

        $this->batchAddTranslations($language, $pendingGroup, $pendingSingle);
    }

    /**
     * Save a batch of translations for a language. The default implementation calls the individual
     * add methods one by one. Drivers can override this for more efficient batch I/O.
     */
    protected function batchAddTranslations(string $language, array $pendingGroup, array $pendingSingle): void
    {
        foreach ($pendingGroup as $group => $keys) {
            foreach ($keys as $key => $value) {
                $this->addGroupTranslation($language, $group, $key, $value);
            }
        }
        foreach ($pendingSingle as $group => $keys) {
            foreach ($keys as $key => $value) {
                $this->addSingleTranslation($language, $group, $key, $value);
            }
        }
    }

    /**
     * Get all translations for a given language merged with the source language.
     *
     * @param  string  $language
     * @param  \Illuminate\Support\Collection|null  $sourceTranslations  Pre-loaded source language translations
     * @param  \Illuminate\Support\Collection|null  $targetTranslations  Pre-loaded translations for $language
     * @return Collection
     */
    public function getSourceLanguageTranslationsWith($language, ?\Illuminate\Support\Collection $sourceTranslations = null, ?\Illuminate\Support\Collection $targetTranslations = null)
    {
        $sourceTranslations = $sourceTranslations ?? $this->allTranslationsFor($this->sourceLanguage);
        $languageTranslations = $targetTranslations ?? $this->allTranslationsFor($language);

        return $sourceTranslations->map(function ($groups, $type) use ($language, $languageTranslations) {
            return $groups->map(function ($translations, $group) use ($type, $language, $languageTranslations) {
                $translations = $translations->toArray();
                array_walk($translations, function (&$value, $key) use ($type, $group, $language, $languageTranslations) {
                    $value = [
                        $this->sourceLanguage => $value,
                        $language => $languageTranslations->get($type, collect())->get($group, collect())->get($key),
                    ];
                });

                return $translations;
            });
        });
    }

    /**
     * Filter all keys and translations for a given language and string.
     *
     * @param  string  $language
     * @param  string  $filter
     * @return Collection
     */
    public function filterTranslationsFor($language, $filter)
    {
        $allTranslations = $this->getSourceLanguageTranslationsWith($language);
        if (! $filter) {
            return $allTranslations;
        }

        return $allTranslations->map(function ($groups, $type) use ($language, $filter) {
            return $groups->map(function ($keys, $group) use ($language, $filter) {
                return collect($keys)->filter(function ($translations, $key) use ($group, $language, $filter) {
                    return strs_contain([$group, $key, $translations[$language], $translations[$this->sourceLanguage]], $filter);
                });
            })->filter(function ($keys) {
                return $keys->isNotEmpty();
            });
        });
    }

    public function add(Request $request, $language, $isGroupTranslation)
    {
        $namespace = $request->has('namespace') && $request->get('namespace') ? "{$request->get('namespace')}::" : '';
        $group = $namespace.$request->get('group');
        $key = $request->get('key');
        $value = $request->get('value') ?: '';

        if ($isGroupTranslation) {
            $this->addGroupTranslation($language, $group, $key, $value);
        } else {
            $this->addSingleTranslation($language, 'single', $key, $value);
        }

        Event::dispatch(new TranslationAdded($language, $group ?: 'single', $key, $value));
    }
}

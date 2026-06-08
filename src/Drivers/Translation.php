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
     * @return array
     */
    public function findMissingTranslations($language, ?array $scannedTranslations = null)
    {
        return array_diff_assoc_recursive(
            $scannedTranslations ?? $this->scanner->findTranslations(),
            $this->allTranslationsFor($language)
        );
    }

    /**
     * Save all of the translations in the app without translation for a given language.
     *
     * @param  string  $language
     * @param  array|null  $scannedTranslations  Pre-computed scan result to avoid redundant scanning
     * @return void
     */
    public function saveMissingTranslations($language = false, ?array $scannedTranslations = null)
    {
        $languages = $language ? [$language => $language] : $this->allLanguages();

        foreach ($languages as $language => $name) {
            $missingTranslations = $this->findMissingTranslations($language, $scannedTranslations);

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
            $this->saveMissingTranslations($language, $scannedTranslations);
            $this->translateLanguage($language, $sourceTranslations);
            //Inform the user of what language we just finished translating
            fwrite(STDOUT, __('translation::translation.auto_translated_language', ['language' => $language]) . PHP_EOL);
        }
    }

    /**
     * Translate text using Google Translate
     *
     * @param $language
     * @param $token
     * @return string|null
     * @throws \ErrorException
     */
    public function getGoogleTranslate($language, $token, ?GoogleTranslate $tr = null)
    {
        $placeholderRegex = '/:([a-zA-Z0-9_]+)/';

        // Step 1: Identify placeholders
        preg_match_all($placeholderRegex, $token, $matches);
        $placeholders = $matches[0];

        // Step 2: Replace placeholders with temporary unique strings
        $modifiedToken = $token;
        $tempStrings = [];
        foreach ($placeholders as $index => $placeholder) {
            //After some experiments, I found fake URLs were most likely to be left intact by Google Translate
            $tempStrings[] = 'https://t.co/' .
                //Use letters instead of numbers for Newar, because Google Translate converts the numbers to Newar script
                ($language === 'new' ?
                    mb_strtoupper(base_convert($index+10, 10, 36))
                    :
                    //Letters break in other languages, for all other languages we'll use numbers
                    $index
                );
        }
        $modifiedToken = str_replace($placeholders, $tempStrings, $modifiedToken);

        // Step 3: Translate the modified text using Google Translate
        $tr ??= new GoogleTranslate($language, $this->sourceLanguage);
        //In Laravel, | is used to separate pluralization variants.
        //Translate each of these separately to prevent Google Translate mixing them up.
        $translated = [];
        foreach(explode('|', $modifiedToken) AS $translatableText){
            $piece = $tr->translate($translatableText);
            // Escape any pipe in the translated output so Laravel doesn't mistake
            // it for a pluralization separator (convention: \| means a literal pipe).
            $translated[] = str_replace('|', '\\|', $piece);
        }
        $translatedText = implode('|', $translated);

        // Step 4: Replace the temporary unique strings back with the original placeholders
        //Note: we're using case-insensitive replace because Google Translate sometimes uppercases the temp string
        $translatedText = str_ireplace($tempStrings, $placeholders, $translatedText);

        // Step 5: Check if the number of placeholders has stayed the same
        preg_match_all($placeholderRegex, $translatedText, $translatedMatches);
        if (count($translatedMatches[0]) !== count($placeholders)) {
            // Print a warning to stderr
            fwrite(STDERR, sprintf(
                "Warning: Placeholder count mismatch in translated text when translating %s to %s.\nOriginal text: %s\nTranslated text: %s\nExpected placeholders: %s\nActual placeholders: %s\n",
                $this->sourceLanguage,
                $language,
                $token,
                $translatedText,
                json_encode($placeholders),
                json_encode($translatedMatches[0])
            ));
        }

        return $translatedText;
    }

    /**
     * Loop through all the keys and get translated text from Google Translate
     *
     * @param $language
     * @param  \Illuminate\Support\Collection|null  $sourceTranslations  Pre-loaded source language translations
     */
    public function translateLanguage($language, ?\Illuminate\Support\Collection $sourceTranslations = null)
    {
        //No need to translate e.g. English to English
        if ($language === $this->sourceLanguage) {
            return;
        }

        $translations = $this->getSourceLanguageTranslationsWith($language, $sourceTranslations);
        $tr = new GoogleTranslate($language, $this->sourceLanguage);

        $pendingGroup = [];
        $pendingSingle = [];

        foreach ($translations as $type => $groups) {
            foreach ($groups as $group => $translations) {
                foreach ($translations as $key => $value) {
                    //Value will be empty if it's found in the app source code but not in the source language files
                    //We fall back to $key in that case
                    $sourceLanguageValue = in_array($value[$this->sourceLanguage], ["", null]) ? $key : $value[$this->sourceLanguage];
                    $targetLanguageValue = $value[$language];

                    if (in_array($targetLanguageValue, ["", null])) {
                        $new_value = $this->getGoogleTranslate($language, $sourceLanguageValue, $tr);
                        if (Str::contains($group, 'single')) {
                            $pendingSingle[$group][$key] = $new_value;
                        } else {
                            $pendingGroup[$group][$key] = $new_value;
                        }
                    }
                }
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
     * @return Collection
     */
    public function getSourceLanguageTranslationsWith($language, ?\Illuminate\Support\Collection $sourceTranslations = null)
    {
        $sourceTranslations = $sourceTranslations ?? $this->allTranslationsFor($this->sourceLanguage);
        $languageTranslations = $this->allTranslationsFor($language);

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

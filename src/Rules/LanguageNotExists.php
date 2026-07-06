<?php

namespace JoeDixon\Translation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use JoeDixon\Translation\Drivers\Translation;

class LanguageNotExists implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $translation = app()->make(Translation::class);

        if ($translation->languageExists($value)) {
            $fail(__('translation::translation.language_exists'));
        }
    }
}

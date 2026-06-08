<?php

namespace JoeDixon\Translation\Console\Commands;

use Stichoza\GoogleTranslate\GoogleTranslate;

class TranslateLanguageCommand extends BaseCommand
{
    protected $hidden = true;

    protected $signature = 'translation:translate-language
        {language : Language code to translate}
        {--proxy= : HTTP proxy URL to use for Google Translate requests}';

    protected $description = 'Translate a single language (worker command for parallel auto-translate)';

    public function handle()
    {
        $language = $this->argument('language');
        $proxy = $this->option('proxy');

        try {
            $tr = new GoogleTranslate($language, $this->translation->getSourceLanguage());

            if ($proxy) {
                $tr->setProxy($proxy);
            }

            $this->translation->translateLanguage($language, null, $tr);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $message = $e->getMessage();

            // Proxy-related errors get exit code 2 so the parent can retry with a different proxy
            if ($proxy && (
                str_contains($message, 'proxy') ||
                str_contains($message, 'curl') ||
                str_contains($message, 'connect') ||
                str_contains($message, '407')
            )) {
                $this->error("Proxy error ({$proxy}): {$message}");

                return 2;
            }

            $this->error($message);

            return self::FAILURE;
        }
    }
}

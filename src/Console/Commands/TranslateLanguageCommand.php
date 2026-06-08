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
                $tr->setOptions([
                    'proxy'           => $proxy,
                    'connect_timeout' => 5,
                    'timeout'         => 30,
                ]);
            }

            $this->translation->translateLanguage($language, null, $tr);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $message = $e->getMessage();

            // Any error when using a proxy gets exit code 2 so the parent retries with a different proxy
            if ($proxy) {
                $this->error("Proxy error ({$proxy}): {$message}");

                return 2;
            }

            $this->error($message);

            return self::FAILURE;
        }
    }
}

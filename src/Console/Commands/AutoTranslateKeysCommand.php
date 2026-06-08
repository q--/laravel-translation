<?php

namespace JoeDixon\Translation\Console\Commands;

class AutoTranslateKeysCommand extends BaseCommand
{
    protected $signature = 'translation:auto-translate {language?}';

    protected $description = 'Auto translate keys using Google Translate';

    public function handle()
    {
        $language = $this->argument('language') ?: false;

        try {
            $this->translation->autoTranslate($language);
            $this->info(__('translation::translation.auto_translated'));

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}

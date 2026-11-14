<?php

namespace App\Console\Commands;

use App\Actions\Privacy\PurgeExpiredPersonalDataAction;
use App\Models\Setting;
use Illuminate\Console\Command;

class PurgeExpiredPersonalData extends Command
{
    /**
     * @var string
     */
    protected $signature = 'snipeit:privacy-cleanup';

    /**
     * @var string
     */
    protected $description = 'Removes personal data that is past the retention periods set in the privacy settings';

    public function handle(): int
    {
        $settings = Setting::getSettings();

        if (! $settings) {
            $this->error('Snipe-IT has not been set up yet.');

            return self::FAILURE;
        }

        $result = PurgeExpiredPersonalDataAction::run($settings);

        $this->table(['', 'Count'], [
            ['Log entries with IP address / user agent removed', $result['ip_addresses_removed']],
            ['Login attempts deleted', $result['login_attempts_deleted']],
            ['Deleted users anonymized', $result['users_anonymized']],
            ['Deleted users skipped (items still assigned)', $result['users_skipped']],
        ]);

        return self::SUCCESS;
    }
}

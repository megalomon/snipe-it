<?php

namespace Tests\Feature\Privacy;

use App\Enums\ActionType;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IpAddressTruncationTest extends TestCase
{
    public function test_ip_addresses_are_stored_unchanged_by_default()
    {
        $this->assertSame('203.0.113.77', Setting::storableIpAddress('203.0.113.77'));
        $this->assertSame('2001:db8:85a3:8d3:1319:8a2e:370:7348', Setting::storableIpAddress('2001:db8:85a3:8d3:1319:8a2e:370:7348'));
    }

    public function test_ip_addresses_are_truncated_when_enabled()
    {
        $this->settings->set(['privacy_truncate_ip_addresses' => 1]);

        $this->assertSame('203.0.113.0', Setting::storableIpAddress('203.0.113.77'));
        $this->assertSame('2001:db8:85a3::', Setting::storableIpAddress('2001:db8:85a3:8d3:1319:8a2e:370:7348'));
    }

    public function test_invalid_ip_addresses_are_not_stored()
    {
        $this->assertNull(Setting::storableIpAddress(null));
        $this->assertNull(Setting::storableIpAddress('not-an-ip'));
    }

    public function test_failed_login_stores_truncated_ip_address()
    {
        Cache::flush();
        $this->settings->set(['privacy_truncate_ip_addresses' => 1]);
        User::factory()->create(['username' => 'jdoe']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])
            ->post('/login', ['username' => 'jdoe', 'password' => 'wrong-password']);

        $this->assertDatabaseHas('login_attempts', [
            'username' => 'jdoe',
            'remote_ip' => '198.51.100.0',
            'successful' => 0,
        ]);
    }

    public function test_activity_log_stores_truncated_ip_address()
    {
        $this->settings->set(['privacy_truncate_ip_addresses' => 1]);

        $this->app->instance('request', Request::create('/', 'GET', server: ['REMOTE_ADDR' => '198.51.100.23']));

        $log = new Actionlog;
        $log->item_type = Asset::class;
        $log->item_id = Asset::factory()->create()->id;
        $log->logaction(ActionType::NoteAdded);

        $this->assertSame('198.51.100.0', DB::table('action_logs')->where('id', $log->id)->value('remote_ip'));
    }
}

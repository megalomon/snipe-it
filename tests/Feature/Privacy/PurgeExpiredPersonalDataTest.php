<?php

namespace Tests\Feature\Privacy;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurgeExpiredPersonalDataTest extends TestCase
{
    private function logWithIp(\DateTimeInterface $createdAt): int
    {
        return DB::table('action_logs')->insertGetId([
            'action_type' => 'note added',
            'item_type' => Asset::class,
            'item_id' => 1,
            'remote_ip' => '203.0.113.5',
            'user_agent' => 'Firefox',
            'created_at' => $createdAt,
        ]);
    }

    public function test_nothing_is_removed_without_retention_periods()
    {
        $log = $this->logWithIp(now()->subYears(5));
        DB::table('login_attempts')->insert(['username' => 'jdoe', 'successful' => 1, 'created_at' => now()->subYears(5)]);
        $deleted = User::factory()->create(['deleted_at' => now()->subYears(5)]);

        $this->artisan('snipeit:privacy-cleanup')->assertSuccessful();

        $this->assertSame('203.0.113.5', DB::table('action_logs')->where('id', $log)->value('remote_ip'));
        $this->assertSame(1, DB::table('login_attempts')->count());
        $this->assertNull($deleted->fresh()->anonymized_at);
    }

    public function test_ip_addresses_older_than_the_retention_period_are_removed()
    {
        $this->settings->set(['privacy_ip_retention_days' => 30]);
        $old = $this->logWithIp(now()->subDays(31));
        $recent = $this->logWithIp(now()->subDays(29));

        $this->artisan('snipeit:privacy-cleanup')->assertSuccessful();

        $row = DB::table('action_logs')->find($old);
        $this->assertNull($row->remote_ip);
        $this->assertNull($row->user_agent);
        $this->assertSame('note added', $row->action_type);
        $this->assertSame('203.0.113.5', DB::table('action_logs')->where('id', $recent)->value('remote_ip'));
    }

    public function test_login_attempts_older_than_the_retention_period_are_deleted()
    {
        $this->settings->set(['privacy_login_attempts_retention_days' => 7]);
        DB::table('login_attempts')->insert([
            ['username' => 'old', 'successful' => 1, 'created_at' => now()->subDays(8)],
            ['username' => 'recent', 'successful' => 1, 'created_at' => now()->subDays(6)],
        ]);

        $this->artisan('snipeit:privacy-cleanup')->assertSuccessful();

        $this->assertSame(['recent'], DB::table('login_attempts')->pluck('username')->all());
    }

    public function test_long_deleted_users_are_anonymized()
    {
        $this->settings->set(['privacy_anonymize_deleted_users_days' => 90]);
        $longGone = User::factory()->create(['username' => 'long.gone', 'deleted_at' => now()->subDays(91)]);
        $recentlyGone = User::factory()->create(['username' => 'recently.gone', 'deleted_at' => now()->subDays(10)]);
        $active = User::factory()->create(['username' => 'still.here']);

        $this->artisan('snipeit:privacy-cleanup')->assertSuccessful();

        $this->assertSame('anonymized-'.$longGone->id, DB::table('users')->where('id', $longGone->id)->value('username'));
        $this->assertSame('recently.gone', DB::table('users')->where('id', $recentlyGone->id)->value('username'));
        $this->assertSame('still.here', DB::table('users')->where('id', $active->id)->value('username'));
    }

    public function test_deleted_users_still_holding_items_are_skipped()
    {
        $this->settings->set(['privacy_anonymize_deleted_users_days' => 90]);
        $user = User::factory()->create(['username' => 'holds.laptop']);
        Asset::factory()->assignedToUser($user)->create();
        DB::table('users')->where('id', $user->id)->update(['deleted_at' => now()->subDays(91)]);

        $this->artisan('snipeit:privacy-cleanup')->assertSuccessful();

        $this->assertSame('holds.laptop', DB::table('users')->where('id', $user->id)->value('username'));
    }

    public function test_cleanup_is_scheduled_daily()
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('snipeit:privacy-cleanup')
            ->assertSuccessful();
    }
}

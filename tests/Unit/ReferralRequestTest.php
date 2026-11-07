<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\ReferralRequestController;
use App\Models\ReferralRequest;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReferralRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->assertSame('sqlite', DB::connection()->getDriverName());

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('phone')->nullable();
        });
        Schema::create('referral_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('phone');
            $t->json('channels');
            $t->string('audience_size')->nullable();
            $t->text('description');
            $t->string('status')->default('pending');
            $t->text('admin_note')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });
        DB::table('users')->insert(['id' => 1, 'phone' => '09120000000']);
    }

    private function submit(array $data): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/api/v1/referral-request', 'POST', $data);
        $request->setUserResolver(fn () => User::find(1));

        return (new ReferralRequestController)->store($request);
    }

    private function valid(array $overrides = []): array
    {
        return $overrides + [
            'phone' => '+1 (415) 555-0123',
            'channels' => ['instagram', 'telegram'],
            'audience_size' => '1k_10k',
            'description' => 'پیج اینستاگرام آموزش سئو دارم و در کانال تلگرامم ابزارها را معرفی می‌کنم.',
        ];
    }

    public function test_phone_is_normalized_to_international_format(): void
    {
        $this->assertSame('+14155550123', ReferralRequest::normalizePhone('+1 (415) 555-0123'));
        $this->assertSame('+971501234567', ReferralRequest::normalizePhone('00971 50 123 4567'));
        $this->assertSame('+971501234567', ReferralRequest::normalizePhone('+۹۷۱۵۰۱۲۳۴۵۶۷'));
        $this->assertNull(ReferralRequest::normalizePhone('0912 123 4567'), 'no country code');
        $this->assertNull(ReferralRequest::normalizePhone('+12'));
    }

    public function test_request_is_saved_once_and_duplicate_is_refused(): void
    {
        $first = $this->submit($this->valid());
        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame('+14155550123', ReferralRequest::first()->phone);
        $this->assertSame(ReferralRequest::STATUS_PENDING, ReferralRequest::first()->status);

        $second = $this->submit($this->valid());
        $this->assertSame(409, $second->getStatusCode());
        $this->assertSame(1, ReferralRequest::count());

        // بعد از رد شدن، کاربر می‌تواند دوباره درخواست بدهد
        ReferralRequest::first()->update(['status' => ReferralRequest::STATUS_REJECTED]);
        $this->assertSame(201, $this->submit($this->valid())->getStatusCode());
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->assertSame(422, $this->submit($this->valid(['phone' => '123']))->getStatusCode());

        $this->expectException(ValidationException::class);
        $this->submit($this->valid(['channels' => ['tiktok-ads']]));
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * اشتراک ۱، ۳ و ۶ ماهه.
 *  - plans: درصد تخفیف دوره‌ی ۳ و ۶ ماهه (نسبت به قیمت خام × تعداد ماه)
 *  - payments / subscriptions: مدت خریداری‌شده (period_months)
 *  - subscriptions.credit_limit: کریدیت کل دوره (credit پلن × ماه)؛ null یعنی همان credit پلن (ردیف‌های قدیمی)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedTinyInteger('quarterly_discount_percentage')->default(0)->after('discount_percentage');
            $table->unsignedTinyInteger('semiannual_discount_percentage')->default(0)->after('quarterly_discount_percentage');
        });

        // پلن‌های پولی فعلی: ۳ ماهه ۱۰٪ و ۶ ماهه ۲۰٪ بیشتر از تخفیف ماهانه
        DB::table('plans')->where('is_free', false)->get(['id', 'discount_percentage'])->each(function ($plan) {
            $monthly = (int) ($plan->discount_percentage ?? 0);

            DB::table('plans')->where('id', $plan->id)->update([
                'quarterly_discount_percentage' => min($monthly + 10, 90),
                'semiannual_discount_percentage' => min($monthly + 20, 90),
            ]);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedTinyInteger('period_months')->default(1)->after('plan_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedTinyInteger('period_months')->default(1)->after('plan_id');
            $table->decimal('credit_limit', 12, 4)->nullable()->after('period_months');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['period_months', 'credit_limit']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('period_months');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['quarterly_discount_percentage', 'semiannual_discount_percentage']);
        });
    }
};

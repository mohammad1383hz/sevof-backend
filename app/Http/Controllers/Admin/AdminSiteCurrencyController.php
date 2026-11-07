<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Products\SiteProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** واحد قیمت محصولات یک سایت (ریال/تومان) از پنل ادمین؛ همه‌ی محصولات فعلی همان لحظه اصلاح می‌شوند. */
class AdminSiteCurrencyController extends Controller
{
    public function update(Request $request, Site $site, SiteProductService $products): RedirectResponse
    {
        $validated = $request->validate(['currency' => ['required', 'in:IRR,IRT']]);

        $site->update(['product_currency' => $validated['currency']]);
        $products->applySiteCurrency($site);

        $label = $validated['currency'] === 'IRR' ? 'IRR' : 'تومان';

        return back()->with('success', "Product currency for {$site->domain} set to {$label}.");
    }
}

<?php

namespace App\Modules\Commerce\Marketplace\Ebay\Http\Controllers;

use App\Modules\Commerce\Marketplace\Ebay\EbayOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EbayOAuthCallbackController
{
    public function __invoke(Request $request, EbayOAuthService $oauth): RedirectResponse
    {
        $companyId = $request->user()?->company_id;

        if ($companyId === null) {
            abort(403);
        }

        if (! hash_equals((string) session('marketplace.ebay.oauth_state'), (string) $request->query('state'))) {
            abort(403);
        }

        $code = (string) $request->query('code');
        if ($code === '') {
            return redirect()
                ->route('commerce.marketplace.ebay.index')
                ->with('error', __('eBay did not return an authorization code.'));
        }

        $oauth->exchangeCode($companyId, $code);
        session()->forget('marketplace.ebay.oauth_state');
        $returnRoute = (string) session()->pull('marketplace.ebay.oauth_return_route', 'commerce.marketplace.ebay.index');

        return redirect()
            ->route($returnRoute)
            ->with('success', __('eBay OAuth connection saved.'));
    }
}

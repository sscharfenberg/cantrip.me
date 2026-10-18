<?php

namespace App\Http\Controllers;

use App\Services\BannedRestrictedService;
use Inertia\Inertia;
use Inertia\Response;

class BannedRestrictedController extends Controller
{
    /**
     * Display the banned & restricted lists, one tab per format.
     *
     * Public: the lists are Scryfall data. Which tab opens is decided
     * client-side from the URL hash (`/b+r#legacy`), so the server always
     * ships every format.
     */
    public function show(): Response
    {
        return Inertia::render('Guest/BannedRestricted', BannedRestrictedService::forPage());
    }
}

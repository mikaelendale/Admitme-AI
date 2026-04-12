<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class LegalController extends Controller
{
    public function privacy(): Response
    {
        return Inertia::render('legal/privacy', [
            'version' => config('legal.documents_version'),
        ]);
    }

    public function terms(): Response
    {
        return Inertia::render('legal/terms', [
            'version' => config('legal.documents_version'),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Domain\Housing\HomeService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HousingController extends Controller
{
    public function index(Request $request): Response
    {
        $home = HomeService::home((int) $request->user()->family_id);

        return Inertia::render('Housing', [
            'home' => HomeService::present($home),
        ]);
    }
}

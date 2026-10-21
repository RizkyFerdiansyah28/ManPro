<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        return redirect()->route($request->user()?->homeRoute() ?? 'login');
    }

    public function show(Request $request): Response
    {
        return Inertia::render('Dashboard', ['roleLabel' => match ($request->user()->role) {
            'admin' => 'Admin', 'perawat' => 'Perawat', 'keluarga' => 'Keluarga',
        }]);
    }
}

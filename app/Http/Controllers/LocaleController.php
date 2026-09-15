<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'in:'.implode(',', User::SUPPORTED_LOCALES)],
        ]);

        $request->user()->forceFill(['locale' => $validated['locale']])->save();

        return back();
    }
}

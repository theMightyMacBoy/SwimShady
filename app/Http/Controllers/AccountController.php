<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AccountController extends Controller
{
    public function edit(Request $request)
    {
        return view('account', ['user' => $request->user()]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        if ($user->username === 'demo') {
            return back()->with('error', 'The demo account password cannot be changed.');
        }

        $current = (string) $request->input('current', '');
        $new = (string) $request->input('new', '');
        $confirm = (string) $request->input('confirm', '');

        if (! Hash::check($current, $user->password)) {
            return back()->with('error', 'Current password is incorrect.');
        }
        if (strlen($new) < 8) {
            return back()->with('error', 'New password must be at least 8 characters.');
        }
        if ($new !== $confirm) {
            return back()->with('error', 'New passwords do not match.');
        }

        $user->password = $new;
        $user->save();

        return back()->with('status', 'Password updated.');
    }
}

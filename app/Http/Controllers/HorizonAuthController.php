<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HorizonAuthController extends Controller
{
    public function showLoginForm()
    {
        return view('horizon-login');
    }

public function login(Request $request)
{
    $request->validate([
        'username' => 'required',
        'password' => 'required',
    ]);

    if (
        $request->username == 'mohammad' &&
        $request->password == '09394889350'
    ) {
        session(['horizon_auth' => true]);
        return redirect('/horizon');
    }

    return back()->withErrors(['username' => 'Incorrect username or password']);
}

    public function logout(Request $request)
    {
        $request->session()->forget('horizon_auth');
        return redirect('/horizon/login');
    }
}

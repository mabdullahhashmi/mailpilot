<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DashboardPageController extends Controller
{
    public function index()
    {
        return view('dashboard.index');
    }

    public function campaigns()
    {
        return view('dashboard.campaigns');
    }

    public function profiles()
    {
        return view('dashboard.profiles');
    }

    public function senders()
    {
        return view('dashboard.senders');
    }

    public function seeds()
    {
        return view('dashboard.seeds');
    }

    public function domains()
    {
        return view('dashboard.domains');
    }

    public function logs()
    {
        return view('dashboard.logs');
    }
}

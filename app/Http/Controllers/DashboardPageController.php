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

    public function settings()
    {
        return view('dashboard.settings');
    }

    public function templates()
    {
        return view('dashboard.templates');
    }

    public function campaignDetail(int $id)
    {
        return view('dashboard.campaign-detail');
    }

    public function senderHealth()
    {
        return view('dashboard.sender-health');
    }

    public function dnsHealth()
    {
        return view('dashboard.dns-health');
    }

    public function systemHealth()
    {
        return view('dashboard.system-health');
    }

    public function progressReport()
    {
        return view('dashboard.progress-report');
    }

    public function deliverability()
    {
        return view('dashboard.deliverability');
    }

    public function diagnostics()
    {
        return view('dashboard.diagnostics');
    }
}

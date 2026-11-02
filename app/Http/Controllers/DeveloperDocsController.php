<?php

namespace App\Http\Controllers;

use App\Enums\ReportStatus;
use Illuminate\Contracts\View\View;

class DeveloperDocsController extends Controller
{
    /**
     * Public documentation for the department integration API. It holds no
     * secrets, so departments' developers can read it without a panel account.
     */
    public function index(): View
    {
        return view('developers.index', [
            'baseUrl' => url('/api/integrations/v1'),
            'specUrl' => asset('openapi/integrations-v1.json'),
            'statuses' => ReportStatus::cases(),
        ]);
    }
}

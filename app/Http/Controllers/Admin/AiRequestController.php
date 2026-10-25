<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use Inertia\Inertia;
use Inertia\Response;

class AiRequestController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/AiRequests', [
            'requests' => AiRequest::query()
                ->orderByDesc('id')
                ->limit(100)
                ->get([
                    'id', 'family_id', 'user_id', 'purpose', 'model', 'prompt_tokens', 'completion_tokens',
                    'cost_usd', 'ok', 'response_payload', 'response_status', 'finish_reason', 'error_message', 'created_at',
                ]),
        ]);
    }
}

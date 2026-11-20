<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappRequest;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The only public WhatsApp endpoint: counts a menu click (topic + page path). It returns nothing and stores no personal data. */
class WhatsAppClickController extends Controller
{
    public function __invoke(Request $request, WhatsAppService $whatsapp): Response
    {
        $data = $request->validate([
            'topic' => ['required', 'string', Rule::in(WhatsappRequest::TOPICS)],
            'page_path' => ['required', 'string', 'max:300'],
        ]);
        $path = $whatsapp->cleanPath($data['page_path']);
        if ($path === null) {
            throw ValidationException::withMessages(['page_path' => ['Ruta no válida.']]);
        }
        $whatsapp->recordClick($data['topic'], $path);

        return response()->noContent();
    }
}

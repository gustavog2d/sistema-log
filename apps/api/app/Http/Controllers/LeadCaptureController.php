<?php

namespace App\Http\Controllers;

use App\Leads\LeadService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;

/** Captura pública de leads da landing (PLT-011). A resposta é sempre 202: não revela repetição nem isca. */
class LeadCaptureController extends Controller
{
    public function __invoke(Request $request, LeadService $leads): Response
    {
        // Campo isca: robô que o preenche recebe sucesso e nada é gravado (a regra está em LeadService::submit).
        if ($request->filled('company_site')) {
            return response()->noContent(202);
        }

        $optional = ['sometimes', 'nullable', 'string'];
        $text = 'regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/';
        $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100', $text],
            // Só ASCII e sem aspas, parênteses nem sinais de HTML: o endereço aparece em telas e e-mails da equipe.
            'email' => ['required', 'string', 'email:rfc,strict', 'max:255', 'regex:/^[A-Za-z0-9.!#$%&*+\/=?^_`{|}~@-]+$/'],
            'phone' => [...$optional, 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'niche' => [...$optional, Rule::in(Config::array('tenancy.niches'))],
            'message' => [...$optional, 'max:500', $text],
            'utm_source' => [...$optional, 'max:100', $text], 'utm_medium' => [...$optional, 'max:100', $text], 'utm_campaign' => [...$optional, 'max:100', $text],
            'utm_term' => [...$optional, 'max:100', $text], 'utm_content' => [...$optional, 'max:100', $text],
            // Endereço de origem e caminho da página: a tela da equipe os mostra como texto, mas só formatos de endereço entram.
            'referrer' => [...$optional, 'max:255', 'url:http,https'], 'landing_path' => [...$optional, 'max:255', 'regex:/^\/[^\s<>"\']*$/'],
            'accept_privacy' => ['required', 'accepted'],
            'captcha_token' => ['required', 'string', 'max:4000'],
        ]);

        /** @var array<string, mixed> $data */
        $data = $request->only(LeadService::PUBLIC_FIELDS);
        $leads->submit($data, $request->string('captcha_token')->toString(), null, $request->ip());

        return response()->noContent(202);
    }
}

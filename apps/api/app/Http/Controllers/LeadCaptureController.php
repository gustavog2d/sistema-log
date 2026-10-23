<?php

namespace App\Http\Controllers;

use App\Leads\CaptchaVerifier;
use App\Leads\LeadService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Captura pública de leads da landing (PLT-011). A resposta é sempre 202: não revela repetição nem isca. */
class LeadCaptureController extends Controller
{
    public function __invoke(Request $request, LeadService $leads, CaptchaVerifier $captcha): Response
    {
        // Campo isca: robô que o preenche recebe sucesso e nada é gravado.
        if ($request->filled('website')) {
            return response()->noContent(202);
        }

        $optional = ['sometimes', 'nullable', 'string'];
        $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'regex:/^[\x21-\x7E]+$/'],
            'phone' => [...$optional, 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'niche' => [...$optional, Rule::in(Config::array('tenancy.niches'))],
            'message' => [...$optional, 'max:500'],
            'utm_source' => [...$optional, 'max:100'], 'utm_medium' => [...$optional, 'max:100'], 'utm_campaign' => [...$optional, 'max:100'],
            'utm_term' => [...$optional, 'max:100'], 'utm_content' => [...$optional, 'max:100'],
            'referrer' => [...$optional, 'max:255'], 'landing_path' => [...$optional, 'max:255'],
            'accept_privacy' => ['required', 'accepted'],
            'captcha_token' => ['required', 'string', 'max:4000'],
        ]);

        if (! $captcha->verify($request->string('captcha_token')->toString(), $request->ip())) {
            throw ValidationException::withMessages(['captcha_token' => 'Não foi possível confirmar que você é uma pessoa. Tente de novo.']);
        }

        /** @var array<string, mixed> $data */
        $data = $request->only(LeadService::PUBLIC_FIELDS);
        $leads->capture($data, $request->ip());

        return response()->noContent(202);
    }
}

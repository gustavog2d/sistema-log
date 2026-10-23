<?php

namespace App\Http\Controllers;

use App\Signup\SignupData;
use App\Signup\SignupService;
use App\Signup\SlugAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;

class SignupController extends Controller
{
    public function slugAvailability(Request $request, SlugAvailability $availability): JsonResponse
    {
        $request->validate(['slug' => ['required', 'string', 'max:64']]);

        return response()->json($availability->check($request->string('slug')->toString()));
    }

    public function store(Request $request, SignupService $signup): JsonResponse
    {
        $request->validate([
            'business_name' => ['required', 'string', 'min:2', 'max:100'],
            'slug' => ['required', 'string', 'max:64'],
            'niche' => ['required', 'string', Rule::in(Config::array('tenancy.niches'))],
            'owner_name' => ['required', 'string', 'min:2', 'max:100'],
            // Só ASCII: letras de outros alfabetos poderiam imitar e-mails de outras contas.
            'owner_email' => ['required', 'string', 'email:rfc,strict', 'max:255', 'regex:/^[\x21-\x7E]+$/'],
            // O bcrypt só considera os primeiros 72 bytes: acima disso a senha perderia força sem aviso.
            'password' => ['required', 'string', 'min:'.Config::integer('platform.min_password_length'), function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('A senha pode ter até 72 bytes.');
                }
            }],
            'plan' => ['required', 'string', Rule::exists('plans', 'key')->where('status', 'active')],
            'cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            // Obrigatório quando o preço final é maior que zero (a regra está no serviço: um cupom de 100% dispensa).
            'payment_method_token' => ['nullable', 'string', 'max:200'],
            'accept_terms' => ['required', 'accepted'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);

        return response()->json($signup->register(SignupData::fromRequest($request)), 201);
    }
}

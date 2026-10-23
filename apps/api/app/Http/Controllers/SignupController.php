<?php

namespace App\Http\Controllers;

use App\Leads\CaptchaVerifier;
use App\Signup\SignupData;
use App\Signup\SignupService;
use App\Signup\SlugAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SignupController extends Controller
{
    public function slugAvailability(Request $request, SlugAvailability $availability): JsonResponse
    {
        $request->validate(['slug' => ['required', 'string', 'max:64']]);

        return response()->json($availability->check($request->string('slug')->toString()));
    }

    public function store(Request $request, SignupService $signup, CaptchaVerifier $captcha): JsonResponse
    {
        $request->validate([
            'business_name' => ['required', 'string', 'min:2', 'max:100', self::plainText()],
            'slug' => ['required', 'string', 'max:64'],
            'niche' => ['required', 'string', Rule::in(Config::array('tenancy.niches'))],
            'owner_name' => ['required', 'string', 'min:2', 'max:100', self::plainText()],
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
            // Anti-robô: o cadastro cria negócio e manda e-mail para o endereço informado.
            'captcha_token' => [Config::boolean('leads.signup_captcha') ? 'required' : 'nullable', 'string', 'max:4000'],
        ]);

        if (Config::boolean('leads.signup_captcha') && ! $captcha->verify($request->string('captcha_token')->toString(), $request->ip(), 'signup')) {
            throw ValidationException::withMessages(['captcha_token' => 'Não foi possível confirmar que você é uma pessoa. Tente de novo.']);
        }

        return response()->json($signup->register(SignupData::fromRequest($request)), 201);
    }

    /** Nomes vão em e-mails e telas: sem caracteres de controle, endereços de internet nem de e-mail. */
    private static function plainText(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && (preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || preg_match('~https?:|www\.|://|@~i', $value) === 1)) {
                $fail('Use só o nome, sem endereços nem caracteres especiais de controle.');
            }
        };
    }
}

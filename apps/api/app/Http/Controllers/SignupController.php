<?php

namespace App\Http\Controllers;

use App\Models\User;
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

    public function store(Request $request, SignupService $signup, SlugAvailability $availability): JsonResponse
    {
        $request->validate([
            'business_name' => ['required', 'string', 'min:2', 'max:100'],
            'slug' => ['required', 'string', 'max:64'],
            'niche' => ['required', 'string', Rule::in(Config::array('tenancy.niches'))],
            'owner_name' => ['required', 'string', 'min:2', 'max:100'],
            'owner_email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:'.Config::integer('platform.min_password_length'), 'max:1024'],
            'plan' => ['required', 'string', Rule::exists('plans', 'key')->where('status', 'active')],
            'cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'payment_method_token' => ['required', 'string', 'max:200'],
            'accept_terms' => ['required', 'accepted'],
        ]);

        $data = SignupData::fromRequest($request);

        $slug = $availability->check($data->slug);
        if (! $slug['available']) {
            throw ValidationException::withMessages(['slug' => 'Este endereço não está disponível ('.($slug['reason'] ?? '').').']);
        }

        if (User::query()->where('email', $data->ownerEmail)->exists()) {
            throw ValidationException::withMessages(['owner_email' => 'Este e-mail já está cadastrado.']);
        }

        return response()->json($signup->register($data), 201);
    }
}

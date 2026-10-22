<?php

namespace App\Http\Controllers\TenantAuth;

use App\Http\Controllers\Controller;
use App\TenantAuth\AccessTokens;
use App\TenantAuth\AccountIsLocked;
use App\TenantAuth\AuthenticatedPerson;
use App\TenantAuth\TenantAuthenticator;
use App\TenantAuth\TenantTwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use stdClass;

class TwoFactorController extends Controller
{
    public function setup(Request $request, TenantTwoFactor $twoFactor): JsonResponse
    {
        $person = $this->person($request);
        abort_if($person->twoFactorEnabled, 403, 'O 2FA já está ativo.');

        return response()->json($twoFactor->setup(DB::connection('pgsql_auth'), $person->id, $person->email));
    }

    public function confirm(Request $request, TenantTwoFactor $twoFactor, TenantAuthenticator $authenticator, AccessTokens $tokens): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:64']]);
        $person = $this->person($request);
        abort_if($person->twoFactorEnabled, 403, 'O 2FA já está ativo.');
        $db = DB::connection('pgsql_auth');

        $codes = $twoFactor->confirm($db, $person->id, $request->string('code')->toString());

        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'O código não confere.']);
        }

        // Quem entrou só para configurar o 2FA precisa entrar de novo, agora com o código.
        if ($person->tokenKind === AccessTokens::SETUP) {
            $tokens->revoke($person->tokenId);
        }

        return response()->json(['recovery_codes' => $codes]);
    }

    public function disable(Request $request, TenantTwoFactor $twoFactor, TenantAuthenticator $authenticator): Response
    {
        $request->validate(['current_password' => ['required', 'string', 'max:1024']]);
        $person = $this->person($request);
        $db = DB::connection('pgsql_auth');
        $user = $db->table('users')->where('id', $person->id)->first();

        if (! $user instanceof stdClass) {
            throw ValidationException::withMessages(['current_password' => 'A senha atual está incorreta.']);
        }

        if ($authenticator->isLocked($user)) {
            throw new AccountIsLocked;
        }

        if (! is_string($user->password) || ! Hash::check($request->string('current_password')->toString(), $user->password)) {
            $authenticator->registerFailure($db, $user);

            throw ValidationException::withMessages(['current_password' => 'A senha atual está incorreta.']);
        }

        $twoFactor->disable($db, $person->id);

        return response()->noContent();
    }

    private function person(Request $request): AuthenticatedPerson
    {
        $person = $request->attributes->get('tenant_person');
        abort_unless($person instanceof AuthenticatedPerson, 401);

        return $person;
    }
}

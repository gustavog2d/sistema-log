<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalho `Idempotency-Key` obrigatório: repetir o mesmo pedido devolve a mesma resposta sem
 * refazer nada. A chave com corpo diferente é recusada. Só respostas de sucesso ficam guardadas;
 * uma falha libera a chave para uma nova tentativa.
 */
class IdempotentRequest
{
    public function handle(Request $request, Closure $next, string $scope = 'default'): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '' || mb_strlen($key) > 255) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'Informe o cabeçalho Idempotency-Key (até 255 caracteres).']);
        }

        $hash = hash('sha256', (string) json_encode($request->json()->all()));
        $db = DB::connection('pgsql_platform');
        $where = ['scope' => $scope, 'key' => $key];

        try {
            $db->table('idempotency_keys')->insert($where + ['request_hash' => $hash]);
        } catch (UniqueConstraintViolationException) {
            return $this->replay($db->table('idempotency_keys')->where($where)->first(), $hash);
        }

        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (\Throwable $e) {
            $db->table('idempotency_keys')->where($where)->delete();

            throw $e;
        }

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300 && $response instanceof JsonResponse) {
            $db->table('idempotency_keys')->where($where)->update([
                'response_status' => $response->getStatusCode(),
                'response_body' => json_encode($response->getData(true)),
            ]);
        } else {
            $db->table('idempotency_keys')->where($where)->delete();
        }

        return $response;
    }

    private function replay(?\stdClass $stored, string $hash): Response
    {
        if ($stored === null) {
            return Problem::response(409, 'Conflict', 'Tente novamente.');
        }

        /** @var \stdClass&object{request_hash: string, response_body: string|null, response_status: int|null} $stored */
        if ($stored->request_hash !== $hash) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'Esta chave já foi usada com outro conteúdo.']);
        }

        if ($stored->response_body === null) {
            return Problem::response(409, 'Conflict', 'Pedido em andamento.');
        }

        return response()->json(json_decode((string) $stored->response_body, true), (int) ($stored->response_status ?? 200))
            ->header('Idempotent-Replayed', 'true');
    }
}

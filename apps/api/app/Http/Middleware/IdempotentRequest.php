<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use App\Tenancy\TenantContext;
use App\TenantAuth\AuthenticatedPerson;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
    /** Depois deste prazo, uma reserva sem resposta é considerada abandonada. */
    public const STALE_AFTER_SECONDS = 300;

    public function handle(Request $request, Closure $next, string $scope = 'default'): Response
    {
        // O hash é calculado sobre o corpo JSON; outros formatos (formulário, query string) seriam
        // todos iguais a "vazio" e poderiam reaproveitar a resposta de outro pedido.
        if (! $request->isJson()) {
            return Problem::response(415, 'Unsupported Media Type', 'Envie o corpo como application/json.');
        }

        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '' || mb_strlen($key) > 255) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'Informe o cabeçalho Idempotency-Key (até 255 caracteres).']);
        }

        // O token do anti-robô é novo a cada tentativa: fora da comparação, para repetir o mesmo pedido continuar valendo.
        $hash = hash('sha256', (string) json_encode($this->normalized(Arr::except($request->json()->all(), ['captcha_token']))));
        $db = DB::connection('pgsql_platform');
        // A chave vale só para quem a criou (mesmo IP): adivinhar a chave de outra pessoa não devolve a resposta dela.
        $where = ['scope' => $scope.':'.substr(hash('sha256', $request->ip().$this->owner($request)), 0, 16), 'key' => $key];

        // Reserva a chave. Um pedido que morreu no meio (sem resposta gravada) libera a chave após o prazo.
        $db->table('idempotency_keys')->where($where)->whereNull('response_body')
            ->where('created_at', '<', now()->subSeconds(self::STALE_AFTER_SECONDS))->delete();

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

    /** Quem é dono da chave: o negócio e a pessoa quando há login; nas rotas públicas, só o endereço. */
    private function owner(Request $request): string
    {
        $person = $request->attributes->get('tenant_person');

        return $person instanceof AuthenticatedPerson ? '|'.app(TenantContext::class)->id().'|'.$person->id : '';
    }

    /**
     * A ordem das chaves do JSON não muda o resultado.
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function normalized(array $value): array
    {
        ksort($value);

        return array_map(fn ($item) => is_array($item) ? $this->normalized($item) : $item, $value);
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

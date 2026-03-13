<?php

namespace RiseTechApps\Monitoring\Repository;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RiseTechApps\Monitoring\Entry\EntryType;
use RiseTechApps\Monitoring\Jobs\SendMonitoringPayloadJob;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;
use Throwable;

/**
 * CORREÇÕES APLICADAS:
 *
 * 1. REMOVIDOS OS CHAMADOS A Log::* INTERNOS
 *    A versão anterior chamava Log::critical() em todos os catch blocks.
 *    Isso disparava MessageLogged → ExceptionWatcher → Monitoring::record() →
 *    flushBuffer() → sendHttp() → exception → Log::critical() → loop infinito.
 *    Fix: erros internos são escritos com file_put_contents() direto.
 *
 * 2. EXCEÇÃO NÃO É MAIS RE-LANÇADA EM sendHttp()
 *    O re-throw causava o job falhar, o que disparava JobFailed → JobWatcher
 *    → Monitoring::recordJob() — processamento desnecessário de um evento que
 *    o próprio pacote gerou. Agora o erro é registrado em arquivo e o job
 *    termina silenciosamente sem propagar a exceção.
 *
 * 3. RETRY DESATIVADO POR PADRÃO
 *    Retries em caso de falha HTTP adicionam latência e possíveis duplicatas.
 *    O comportamento padrão agora é 0 retries (configurável via config).
 */
class MonitoringRepositoryHttp implements MonitoringRepositoryInterface
{
    protected string $url;
    protected string $token;
    protected int    $timeout;
    protected int    $retryTimes;
    protected int    $retrySleep;
    protected bool   $forceSync;
    protected array  $config = [];
    protected bool   $isJob  = false;

    public static string $HOST     = 'https://monitoring.app.br/api/v1/store/monitoring';
    public static string $HOST_API = 'https://monitoring.app.br/api/v1/monitoring';

    public function __construct(array|string $config, bool $forceSync = false)
    {
        $this->forceSync = $forceSync;

        if (is_array($config)) {
            $this->config      = $config;
            $this->token       = $config['token'] ?? '';
            $this->url         = self::$HOST;
            $this->timeout     = (int) ($config['timeout'] ?? 10);
            $retry             = $config['retry'] ?? [];
            $this->retryTimes  = max(0, (int) ($retry['times'] ?? 0));
            $this->retrySleep  = max(0, (int) ($retry['sleep'] ?? 0));
        } else {
            $this->token      = $config;
            $this->url        = self::$HOST;
            $this->timeout    = 10;
            $this->retryTimes = 0;
            $this->retrySleep = 0;
        }
    }

    public function setIsJob(): void
    {
        $this->isJob = true;
    }

    /**
     * Cria/envia as entradas.
     *
     * - Dentro de um job: envia HTTP diretamente (evita recursão de dispatch).
     * - Fora de job: despacha um job assíncrono e retorna imediatamente,
     *   sem bloquear o request do usuário.
     */
    public function create(array $data): void
    {
        if ($this->isJob) {
            $this->sendHttp($data);
            return;
        }

        SendMonitoringPayloadJob::dispatch($data, $this->config ?: $this->toArrayConfig());
    }

    /**
     * Envia o payload via HTTP.
     *
     * CORRIGIDO: não usa Log::* — usa file_put_contents() para registrar erros,
     * pois Log::* dispara MessageLogged → loop de recursão.
     * CORRIGIDO: não re-lança a exceção para evitar disparar JobFailed.
     */
    private function sendHttp(array $data): void
    {
        try {
            $response = $this->request()->post($this->url, $data);

            if (in_array($response->status(), [200, 202], true)) {
                return;
            }

            $this->writeError(sprintf(
                'HTTP %d — body: %s',
                $response->status(),
                substr($response->body(), 0, 500)
            ));

        } catch (Throwable $e) {
            // NÃO re-lança: evita JobFailed → JobWatcher → recursão.
            // NÃO usa Log::*: evita MessageLogged → ExceptionWatcher → recursão.
            $this->writeError($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    // ─── Query methods ────────────────────────────────────────────────────────

    public function getAllEvents(): Collection
    {
        try {
            $response = $this->request()->get(self::$HOST_API);
            if ($response->successful()) {
                return collect($response->json());
            }
        } catch (Throwable $e) {
            $this->writeError('getAllEvents: ' . $e->getMessage());
        }
        return collect();
    }

    public function getEventById(string $id): Collection
    {
        try {
            $response = $this->request()->get(self::$HOST_API . '/' . $id);
            if ($response->successful()) {
                return collect($response->json());
            }
        } catch (Throwable $e) {
            $this->writeError('getEventById: ' . $e->getMessage());
        }
        return collect();
    }

    public function getEventsByTypes(string $type): Collection
    {
        try {
            $response = $this->request()->get(self::$HOST_API . '/type/' . $type);
            if ($response->successful()) {
                return collect($response->json());
            }
        } catch (Throwable $e) {
            $this->writeError('getEventsByTypes: ' . $e->getMessage());
        }
        return collect();
    }

    public function getEventsByTags(): Collection
    {
        return collect(EntryType::getTypes());
    }

    public function getByBatch(string $id): Collection
    {
        try {
            $response = $this->request()->get(self::$HOST_API . '/batch/' . $id);
            if ($response->successful()) {
                return collect($response->json());
            }
        } catch (Throwable $e) {
            $this->writeError('getByBatch: ' . $e->getMessage());
        }
        return collect();
    }

    public function getLast24Hours(): Collection  { return $this->getPeriod('24h'); }
    public function getLast7Days(): Collection    { return $this->getPeriod('7d'); }
    public function getLast15Days(): Collection   { return $this->getPeriod('15d'); }
    public function getLast30Days(): Collection   { return $this->getPeriod('30d'); }
    public function getLast60Days(): Collection   { return $this->getPeriod('60d'); }
    public function getLast90Days(): Collection   { return $this->getPeriod('90d'); }

    private function getPeriod(string $period): Collection
    {
        try {
            $response = $this->request()->get(self::$HOST_API . '/period/' . $period);
            if ($response->successful()) {
                return collect($response->json());
            }
        } catch (Throwable $e) {
            $this->writeError("getPeriod({$period}): " . $e->getMessage());
        }
        return collect();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    protected function request(): PendingRequest
    {
        return Http::withHeaders([
            'x-api-key' => $this->token,
        ])->timeout(max(1, $this->timeout));
    }

    protected function toArrayConfig(): array
    {
        return [
            'token'   => $this->token,
            'timeout' => $this->timeout,
            'retry'   => [
                'times' => $this->retryTimes,
                'sleep' => $this->retrySleep,
            ],
        ];
    }

    /**
     * Registra erros internos sem passar pelo Laravel Log facade.
     */
    private function writeError(string $message): void
    {
        try {
            $line = sprintf("[%s] MonitoringRepositoryHttp ERROR: %s\n", date('Y-m-d H:i:s'), $message);
            file_put_contents(
                storage_path('logs/monitoring-internal.log'),
                $line,
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable) {
            // Silencia — sem canal mais seguro disponível.
        }
    }
}

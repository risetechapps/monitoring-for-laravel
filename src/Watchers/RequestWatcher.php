<?php

namespace RiseTechApps\Monitoring\Watchers;

use Exception;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Monitoring;
use RiseTechApps\Monitoring\Services\PerformanceMonitoringService;
use RiseTechApps\RiseTools\Features\Device\Device;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class RequestWatcher extends Watcher
{
    /**
     * Registra um ouvinte para o evento RequestHandled.
     *
     * Este método configura um ouvinte para o evento RequestHandled, que é acionado quando uma solicitação HTTP é processada.
     *
     * @param mixed $app A instância do aplicativo que fornece o container de eventos.
     * @return void
     */
    public function register($app): void
    {
        $app['events']->listen(RequestHandled::class, $this->recordRequest(...));
    }

    /**
     * Registra as informações da solicitação HTTP processada.
     *
     * Este método coleta detalhes sobre a solicitação HTTP e a resposta, e grava essas informações no sistema de monitoramento.
     *
     * @param RequestHandled $handled O evento que contém os detalhes da solicitação e resposta.
     * @return void
     * @throws Exception
     */
    public function recordRequest(RequestHandled $handled): void
    {
        try {

            if (!Monitoring::isEnabled()) return;

            if ($this->shouldIgnoreHttpMethod($handled)) {
                return;
            }

            if ($this->shouldIgnoreStatusCode($handled)) {
                return;
            }

            if ($this->shouldIgnorePaths($handled)) {
                return;
            }

            $startTime = defined('LARAVEL_START') ? LARAVEL_START : $handled->request->server('REQUEST_TIME_FLOAT');

            $entry = IncomingEntry::make([
                'ip_address' => Device::getClientPublicIp(),
                'uri' => str_replace($handled->request->root(), '', $handled->request->fullUrl()) ?: '/',
                'method' => $handled->request->method(),
                'controller_action' => optional($handled->request->route())->getActionName(),
                'middleware' => array_values(optional($handled->request->route())->gatherMiddleware() ?? []),
                'headers' => $this->headers($handled->request->headers->all()),
                'payload' => $this->payload($this->input($handled->request)),
                'session' => $this->payload($this->sessionVariables($handled->request)),
                'response_status' => $handled->response->getStatusCode(),
                'response' => $this->response($handled->response),
                'duration' => $startTime ? floor((microtime(true) - $startTime) * 1000) : null,
                'memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
            ]);

            Monitoring::recordRequest($entry);

            // Registra métricas de performance
            if (config('monitoring.performance.track_memory_peaks', true)) {
                $performanceService = app(PerformanceMonitoringService::class);
                $performanceService->recordRequestMetrics([
                    'duration' => $entry->content['duration'] ?? 0,
                    'response_status' => $entry->content['response_status'] ?? 200,
                    'memory' => $entry->content['memory'] ?? 0,
                ]);
            }
        } catch (\Exception $exception) {
            loggly()->to('file')->performedOn(self::class)->exception($exception)->level('error')->log($exception->getMessage());
        }
    }

    /**
     * Verifica se o método HTTP da solicitação deve ser ignorado.
     *
     * @param RequestHandled $event O evento que contém os detalhes da solicitação.
     * @return bool Retorna true se o método HTTP deve ser ignorado; caso contrário, false.
     */
    protected function shouldIgnoreHttpMethod($event): bool
    {
        return in_array(
            strtolower($event->request->method()),
            collect($this->options['ignore_http_methods'] ?? [])->map(fn($method) => strtolower((string) $method))->all()
        );
    }

    /**
     * Verifica se o código de status da resposta deve ser ignorado.
     *
     * @param RequestHandled $event O evento que contém os detalhes da resposta.
     * @return bool Retorna true se o código de status deve ser ignorado; caso contrário, false.
     */
    protected function shouldIgnoreStatusCode($event): bool
    {
        return in_array(
            $event->response->getStatusCode(),
            $this->options['ignore_status_codes'] ?? []
        );
    }

    /**
     * Verifica se o path da url deve ser ignorado.
     *
     * Os padrões são glob (`telescope*`), não substring.
     *
     * A versão anterior usava Str::contains: o padrão `up`, presente na
     * configuração padrão para excluir o health check do Laravel, casava com
     * qualquer rota que contivesse "up" — `/api/upload`, `/signup`, `/support`,
     * `/groups` — e essas rotas deixavam de ser monitoradas em silêncio.
     *
     * @param RequestHandled $event O evento que contém os detalhes da requisição.
     */
    private function shouldIgnorePaths($event): bool
    {
        $patterns = $this->options['ignore_paths'] ?? [];

        if (empty($patterns)) {
            return false;
        }

        return $event->request->is(...$patterns);
    }

    /**
     * Formata os cabeçalhos da solicitação.
     *
     * @param array $headers Os cabeçalhos da solicitação.
     * @return array Os cabeçalhos formatados.
     */
    protected function headers($headers)
    {
        $headers = collect($headers)
            ->map(fn($header) => implode(', ', $header))
            ->all();

        return $this->hideParameters($headers, ['authorization', 'php-auth-pw']);
    }

    /**
     * Formata o payload da solicitação.
     *
     * @param array $payload O payload da solicitação.
     * @return array O payload formatado.
     */
    protected function payload($payload)
    {
        return $this->hideParameters($payload, ['password', 'password_confirmation']);
    }

    /**
     * Obtém os detalhes dos arquivos enviados na solicitação.
     *
     * @param Request $request A solicitação HTTP.
     * @return array Os detalhes dos arquivos enviados.
     */
    private function input(Request $request): array
    {
        $files = $request->files->all();

        array_walk_recursive($files, function (&$file) {
            $file = [
                'name' => $file->getClientOriginalName(),
                'size' => $file->isFile() ? ($file->getSize() / 1000) . 'KB' : '0',
            ];
        });

        return array_replace_recursive($request->all(), $files);
    }

    /**
     * Obtém as variáveis de sessão da solicitação.
     *
     * @param Request $request A solicitação HTTP.
     * @return array As variáveis de sessão.
     */
    private function sessionVariables(Request $request): array
    {
        return $request->hasSession() ? $request->session()->all() : [];
    }

    /**
     * Formata o conteúdo da resposta.
     *
     * @param Response $response A resposta HTTP.
     * @return array|string O conteúdo formatado da resposta.
     */
    protected function response(Response $response): array|string
    {
        // Respostas que já se resumem a um rótulo são resolvidas antes de olhar
        // o corpo — não há motivo para medir ou decodificar o que será descartado.
        if ($response instanceof RedirectResponse) {
            return 'Redirected to ' . $response->getTargetUrl();
        }

        if ($response instanceof IlluminateResponse && $response->getOriginalContent() instanceof View) {
            return ['Instance to View'];
        }

        $content = $response->getContent();

        if (is_string($content)) {
            if ($content === '') {
                return 'Empty Response';
            }

            // O limite de tamanho é checado ANTES de qualquer json_decode.
            // Decodificar primeiro materializa a resposta inteira como array PHP
            // (várias vezes o tamanho da string) só para descartá-la em seguida —
            // um pico de memória proporcional à maior resposta da aplicação.
            if (!$this->contentWithinLimits($content)) {
                return 'Purged By Monitoring';
            }

            $decoded = json_decode($content, true);

            if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
                return $this->hideParameters($decoded, $this->hiddenResponseParameters());
            }

            unset($decoded);

            if (Str::startsWith(strtolower($response->headers->get('Content-Type') ?? ''), 'text/plain')) {
                return $content;
            }
        }

        return 'HTML Response';
    }

    /**
     * Verifica se o conteúdo da resposta está dentro dos limites permitidos.
     *
     * @param string $content O conteúdo da resposta.
     * @return bool Retorna true se o conteúdo estiver dentro dos limites; caso contrário, false.
     */
    public function contentWithinLimits($content): bool
    {
        $limit = $this->options['size_limit'] ?? 64;

        return intdiv(mb_strlen($content), 1000) <= $limit;
    }

    /**
     * Oculta parâmetros específicos dos dados fornecidos.
     *
     * @param array $data Os dados que contêm parâmetros a serem ocultados.
     * @param array $hidden Os parâmetros a serem ocultados.
     * @return array Os dados com parâmetros ocultados.
     */
    protected function hideParameters($data, $hidden)
    {
        foreach ($hidden as $parameter) {
            if (Arr::get($data, $parameter)) {
                Arr::set($data, $parameter, '********');
            }
        }

        return $data;
    }

    /**
     * Parâmetros a redigir do corpo da resposta antes de gravar no monitoring.
     *
     * Antes a resposta era gravada CRUA (lista vazia): um corpo com token/secret
     * (ex.: resposta de login com access_token) ficava exposto na tabela de
     * monitoramento. A lista vem de config('monitoring.hidden_response_parameters')
     * + do registro estático Monitoring::$hiddenResponseParameters, e suporta
     * dot-notation (ex.: 'data.token', 'data.access_token'). Vazio = comportamento
     * anterior (nada redigido) — retrocompatível.
     */
    protected function hiddenResponseParameters(): array
    {
        return array_values(array_unique(array_merge(
            (array) config('monitoring.hidden_response_parameters', []),
            Monitoring::$hiddenResponseParameters,
        )));
    }

}

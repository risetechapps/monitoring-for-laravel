<?php

namespace RiseTechApps\Monitoring\Watchers;

use Illuminate\Auth\Access\Events\GateEvaluated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Monitoring;
use RiseTechApps\Monitoring\Services\FormatModel;
use Illuminate\Auth\Access\Response;

class GateWatcher extends Watcher
{
    /** Frames de backtrace inspecionados para localizar o chamador */
    private const int MAX_TRACE_FRAMES = 20;

    /**
     * Registra o ouvinte de eventos para a avaliação de permissões.
     *
     * Este método configura um ouvinte para o evento `GateEvaluated`, que
     * será tratado pelo método `handleGateEvaluated`.
     *
     * @param mixed $app A instância do aplicativo que fornece o container de eventos.
     * @return void
     */
    public function register($app): void
    {
        $app['events']->listen(GateEvaluated::class, $this->handleGateEvaluated(...));
    }

    /**
     * Manipula o evento de avaliação de permissões.
     *
     * Este método chama o método `recordGateCheck` para registrar as informações
     * da avaliação de permissões.
     *
     * @param GateEvaluated $event O evento de avaliação de permissões.
     * @return void
     */
    public function handleGateEvaluated(GateEvaluated $event): void
    {
        $this->recordGateCheck($event->user, $event->ability, $event->result, $event->arguments);
    }

    /**
     * Registra a verificação de permissões.
     *
     * Este método cria uma entrada de monitoramento com base nos detalhes da
     * verificação de permissões e a registra no sistema de monitoramento.
     *
     * @param mixed $user O usuário para quem a permissão foi avaliada.
     * @param string $ability A habilidade ou permissão que foi avaliada.
     * @param mixed $result O resultado da avaliação (geralmente uma instância de `Response`).
     * @param array $arguments Os argumentos passados para a verificação de permissões.
     * @return mixed O resultado da verificação de permissões.
     * @throws \Exception Se ocorrer um erro ao criar ou gravar a entrada de monitoramento.
     */
    public function recordGateCheck($user, $ability, $result, $arguments)
    {
        try {

            if (Monitoring::isEnabled()) {

                if ($this->shouldIgnore($ability)) {
                    return $result;
                }

                $caller = $this->getCallerFromStackTrace([0, 1]);

                $entry = IncomingEntry::make([
                    'ability' => $ability,
                    'result' => $this->gateResult($result),
                    'arguments' => $this->formatArguments($arguments),
                    'file' => $caller['file'] ?? null,
                    'line' => $caller['line'] ?? null,
                ]);

                Monitoring::recordGate($entry);
            }

            return $result;
        } catch (\Exception $exception) {
            loggly()->to('file')->performedOn(self::class)->exception($exception)->level('error')->log($exception->getMessage());
        }
    }

    /**
     * Determina se a habilidade deve ser ignorada.
     *
     * Verifica se a habilidade está na lista de habilidades a serem ignoradas.
     *
     * @param string $ability A habilidade que está sendo verificada.
     * @return bool Retorna verdadeiro se a habilidade deve ser ignorada, falso caso contrário.
     */
    private function shouldIgnore($ability): bool
    {
        return Str::is($this->options['ignore_abilities'] ?? [], $ability);
    }

    /**
     * Formata o resultado da avaliação de permissões.
     *
     * Converte o resultado da avaliação em uma string 'allowed' ou 'denied'.
     *
     * @param mixed $result O resultado da avaliação (geralmente uma instância de `Response`).
     * @return string O resultado formatado como 'allowed' ou 'denied'.
     */
    private function gateResult($result): string
    {
        if ($result instanceof Response) {
            return $result->allowed() ? 'allowed' : 'denied';
        }

        return $result ? 'allowed' : 'denied';
    }

    /**
     * Formata os argumentos passados para a verificação de permissões.
     *
     * Se algum dos argumentos for uma instância de `Model`, ele é formatado
     * usando o serviço `FormatModel`.
     *
     * @param array $arguments Os argumentos passados para a verificação de permissões.
     * @return array Os argumentos formatados.
     */
    private function formatArguments($arguments): array
    {
        return collect($arguments)->map(fn($argument) => $argument instanceof Model ? FormatModel::given($argument) : $argument)->toArray();
    }

    /**
     * Obtém o arquivo e a linha de onde a verificação foi chamada a partir da pilha de rastreamento.
     *
     * @param array $forgetLines O número de linhas a serem ignoradas no início da pilha de rastreamento.
     * @return array|null O arquivo e a linha onde a verificação foi chamada ou null se não encontrado.
     */
    protected function getCallerFromStackTrace($forgetLines = 0)
    {
        // O backtrace é limitado. Antes vinha sem limite: capturava a pilha
        // inteira a cada checagem de permissão, e uma request com policies faz
        // dezenas delas. MAX_TRACE_FRAMES cobre a distância até o código de
        // aplicação com folga.
        $trace = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::MAX_TRACE_FRAMES))
            ->forget($forgetLines);

        // Resolvido uma vez, não por frame — base_path() percorre o container.
        $ignoredPath = base_path('vendor' . DIRECTORY_SEPARATOR . $this->ignoredVendorPath());

        return $trace->first(function ($frame) use ($ignoredPath) {
            if (!isset($frame['file'])) {
                return false;
            }

            return !Str::contains($frame['file'], $ignoredPath);
        });
    }

    /**
     * Obtém o caminho do pacote a ser ignorado na pilha de rastreamento.
     */
    protected function ignoredVendorPath(): string
    {
        // 'laravel' é o padrão: pula os frames do framework para chegar ao
        // código da aplicação. A versão anterior lia options['ignore_packages']
        // sem checar existência e emitia "Undefined array key" em toda checagem
        // de permissão, já que a chave não existe na configuração padrão.
        return $this->options['ignore_packages'] ?? 'laravel';
    }
}

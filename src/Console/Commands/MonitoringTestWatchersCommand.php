<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Console\Commands;

use Illuminate\Bus\Queueable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RiseTechApps\Monitoring\Entry\EntryType;
use RiseTechApps\Monitoring\Monitoring;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Comando Artisan para testar todos os Watchers do Monitoring.
 *
 * Executa eventos de teste para verificar se todos os watchers
 * estão capturando e registrando corretamente.
 *
 * Uso:
 *   php artisan monitoring:test-watchers
 *   php artisan monitoring:test-watchers --verbose
 *   php artisan monitoring:test-watchers --wait=2
 */
class MonitoringTestWatchersCommand extends Command
{
    protected $signature = 'monitoring:test-watchers
                            {--details : Mostra detalhes de cada teste}
                            {--wait=1 : Aguarda N segundos entre testes para buffer}
                            {--no-cleanup : Não limpa os registros de teste}';

    protected $description = 'Testa todos os watchers do monitoring disparando eventos de exemplo';

    private array $testResults = [];
    private array $testBatchIds = [];

    public function __construct(
        private readonly MonitoringRepositoryInterface $repository
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->newLine();
        $this->line("<fg=cyan>┌─────────────────────────────────────────────────┐</>");
        $this->line("<fg=cyan>│     Monitoring — Teste de Watchers              │</>");
        $this->line("<fg=cyan>└─────────────────────────────────────────────────┘</>");
        $this->newLine();

        if (!Monitoring::isEnabled()) {
            $this->error('❌ Monitoramento está DESABILITADO.');
            $this->line('   Habilitado em config/monitoring.php ou MONITORING_ENABLED=true');
            return self::FAILURE;
        }

        $this->info('✓ Monitoramento habilitado');
        $this->newLine();

        $wait = (int) $this->option('wait');

        // Executa testes
        $tests = [
            ['name' => 'Exception Watcher', 'method' => 'testExceptionWatcher'],
            ['name' => 'Query Watcher', 'method' => 'testQueryWatcher'],
            ['name' => 'Cache Watcher', 'method' => 'testCacheWatcher'],
            ['name' => 'Log Watcher', 'method' => 'testLogWatcher'],
            ['name' => 'Event Watcher', 'method' => 'testEventWatcher'],
            ['name' => 'Gate Watcher', 'method' => 'testGateWatcher'],
            ['name' => 'Mail Watcher', 'method' => 'testMailWatcher'],
            ['name' => 'Notification Watcher', 'method' => 'testNotificationWatcher'],
            ['name' => 'HTTP Client Watcher', 'method' => 'testHttpClientWatcher'],
        ];

        foreach ($tests as $test) {
            $this->testWatcher($test['name'], $test['method'], $wait);
        }

        // Flush final para garantir todos os eventos foram processados
        $this->info('Flush do buffer...');
        Monitoring::flushAll();
        sleep($wait);

        // Verifica resultados
        $this->newLine();
        $this->verifyResults();

        // Mostra resumo
        $this->showSummary();

        // Cleanup
        if (!$this->option('no-cleanup')) {
            $this->cleanup();
        }

        return $this->getExitCode();
    }

    private function testWatcher(string $name, string $method, int $wait): void
    {
        $this->info("Testando: {$name}");

        try {
            $batchIdBefore = $this->getLastBatchId();

            $this->{$method}();

            // Aguarda processamento
            sleep($wait);
            Monitoring::flushAll();
            sleep($wait);

            // Verifica se registrou
            $batchIdAfter = $this->getLastBatchId();

            $this->testResults[$name] = [
                'status' => 'success',
                'batch_id_before' => $batchIdBefore,
                'batch_id_after' => $batchIdAfter,
                'message' => 'Evento disparado com sucesso',
            ];

            if ($batchIdAfter !== $batchIdBefore) {
                $this->testBatchIds[$name] = $batchIdAfter;
            }

            $this->line("  <fg=green>✓</> Evento disparado");

        } catch (\Throwable $e) {
            $this->testResults[$name] = [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
            $this->line("  <fg=red>✗</> Erro: {$e->getMessage()}");
        }

        if ($this->option('details')) {
            $this->line("");
        }
    }

    private function testExceptionWatcher(): void
    {
        try {
            throw new \RuntimeException('Test exception from monitoring:test-watchers');
        } catch (\Throwable $e) {
            Log::error('Test exception', ['exception' => $e]);
        }
    }

    private function testQueryWatcher(): void
    {
        // Executa uma query simples (força a execução usando raw para evitar binding issues)
        DB::table('monitoring')->whereRaw("created_at < ?", [now()])->exists();
    }

    private function testCacheWatcher(): void
    {
        $key = 'monitoring:test:' . uniqid();

        // Write
        Cache::put($key, 'test-value', 60);

        // Hit
        Cache::get($key);

        // Miss
        Cache::get($key . ':non-existent');

        // Delete
        Cache::forget($key);
    }

    private function testLogWatcher(): void
    {
        // Usa loggly() que é capturado pelo monitoring
        logglyInfo()
            ->withTags(['source' => 'test-watchers-command'])
            ->withContext(['test_id' => uniqid()])
            ->log('Test log message from monitoring:test-watchers');
    }

    private function testEventWatcher(): void
    {
        Event::dispatch(new \Illuminate\Foundation\Events\LocaleUpdated('en'));
    }

    private function testGateWatcher(): void
    {
        Gate::define('test-monitoring-gate', fn ($user) => true);
        Gate::check('test-monitoring-gate');
    }

    private function testMailWatcher(): void
    {
        // Cria um mailable de teste
        $mailable = new class extends Mailable {
            public function envelope(): Envelope
            {
                return new Envelope(
                    subject: 'Test Email from Monitoring',
                );
            }

            public function content(): Content
            {
                return new Content(
                    htmlString: '<p>This is a test email</p>',
                );
            }
        };

        // Não envia realmente, só simula
        Mail::fake();
        Mail::send($mailable);
    }

    private function testNotificationWatcher(): void
    {
        $notification = new class extends Notification {
            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): MailMessage
            {
                return (new MailMessage)
                    ->line('Test notification from monitoring');
            }
        };

        \Illuminate\Support\Facades\Notification::fake();
        \Illuminate\Support\Facades\Notification::route('mail', 'test@example.com')
            ->notify($notification);
    }

    private function testHttpClientWatcher(): void
    {
        Http::fake([
            'test.example.com/*' => Http::response(['test' => true], 200),
        ]);

        Http::get('https://test.example.com/api/test');
    }

    private function getLastBatchId(): ?string
    {
        try {
            $last = DB::table('monitoring')
                ->orderByDesc('created_at')
                ->value('batch_id');

            return $last;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function verifyResults(): void
    {
        $this->line('<fg=yellow>Verificando registros no banco...</>');
        $this->newLine();

        // Aguarda um pouco para garantir que flush foi concluído
        sleep(2);

        foreach ($this->testResults as $name => &$result) {
            if ($result['status'] !== 'success') {
                continue;
            }

            // Verifica se registrou no banco
            $count = $this->countTestRecords($name);

            if ($count > 0) {
                $result['recorded'] = true;
                $result['records_count'] = $count;
                $this->line("  <fg=green>✓</> {$name}: {$count} registro(s) encontrado(s)");
            } else {
                $result['recorded'] = false;
                $this->line("  <fg=yellow>⚠</> {$name}: Nenhum registro encontrado (pode usar driver single)");
            }
        }

        $this->newLine();
    }

    private function countTestRecords(string $testName): int
    {
        try {
            $driver = DB::connection()->getDriverName();
            $isPgsql = $driver === 'pgsql';

            // Para PostgreSQL, converte JSON para texto com ::text
            // Para MySQL, usa LIKE direto
            $contentLike = fn($pattern) => $isPgsql
                ? "content::text LIKE '%{$pattern}%'"
                : "content LIKE '%{$pattern}%'";

            return match ($testName) {
                'Exception Watcher' => DB::table('monitoring')
                    ->where('type', EntryType::EXCEPTION)
                    ->whereRaw($contentLike('test exception from monitoring:test-watchers'))
                    ->where('created_at', '>=', now()->subMinute())
                    ->count(),

                'Query Watcher' => DB::table('monitoring')
                    ->where('type', EntryType::QUERY)
                    ->where('created_at', '>=', now()->subMinute())
                    ->count(),

                'Cache Watcher' => DB::table('monitoring')
                    ->where('type', EntryType::CACHE)
                    ->where('created_at', '>=', now()->subMinute())
                    ->whereRaw($isPgsql
                        ? "content->>'key' LIKE 'monitoring:test:%'"
                        : "JSON_UNQUOTE(JSON_EXTRACT(content, '$.key')) LIKE 'monitoring:test:%'")
                    ->count(),

                'Log Watcher' => DB::table('monitoring')
                    ->where('type', EntryType::LOG)
                    ->where('created_at', '>=', now()->subMinute())
                    ->whereRaw($isPgsql
                        ? "content->>'message' LIKE '%Test log message from monitoring:test-watchers%'"
                        : "JSON_UNQUOTE(JSON_EXTRACT(content, '$.message')) LIKE '%Test log message from monitoring:test-watchers%'")
                    ->count(),

                'Event Watcher' => DB::table('monitoring')
                    ->where('type', EntryType::EVENT)
                    ->where('created_at', '>=', now()->subMinute())
                    ->count(),

                'Gate Watcher' => DB::table('monitoring')
                    ->where('type', EntryType::GATE)
                    ->where('created_at', '>=', now()->subMinute())
                    ->whereRaw($contentLike('test-monitoring-gate'))
                    ->count(),

                'Mail Watcher' => DB::table('monitoring')
                    ->where('type', EntryType::MAIL)
                    ->where('created_at', '>=', now()->subMinute())
                    ->count(),

                'Notification Watcher' => DB::table('monitoring')
                    ->where('type', EntryType::NOTIFICATION)
                    ->where('created_at', '>=', now()->subMinute())
                    ->count(),

                'HTTP Client Watcher' => DB::table('monitoring')
                    ->where('type', EntryType::CLIENT_REQUEST)
                    ->where('created_at', '>=', now()->subMinute())
                    ->whereRaw($contentLike('test.example.com'))
                    ->count(),

                default => 0,
            };
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function showSummary(): void
    {
        $this->line('<fg=cyan>─────────────────────────────────────────────────</>');
        $this->line('<fg=cyan>RESUMO DOS TESTES</>');
        $this->line('<fg=cyan>─────────────────────────────────────────────────</>');
        $this->newLine();

        $total = count($this->testResults);
        $success = count(array_filter($this->testResults, fn($r) => $r['status'] === 'success'));
        $recorded = count(array_filter($this->testResults, fn($r) => $r['recorded'] ?? false));

        // Tabela de resultados
        $rows = [];
        foreach ($this->testResults as $name => $result) {
            $status = match ($result['status']) {
                'success' => $result['recorded'] ?? false ? '<fg=green>✓ OK</>' : '<fg=yellow>⚠ Evento OK</>',
                'error' => '<fg=red>✗ ERRO</>',
                default => '<fg=gray>? DESCONHECIDO</>',
            };

            $records = $result['records_count'] ?? ($result['recorded'] ?? false ? 'Sim' : 'Não');

            $rows[] = [$name, $status, $records];
        }

        $this->table(
            ['Watcher', 'Status', 'Registros'],
            $rows
        );

        $this->newLine();
        $this->line("Total: {$total} | Eventos disparados: {$success} | Registros no DB: {$recorded}");
        $this->newLine();

        // Driver atual
        $driver = config('monitoring.driver', 'unknown');
        $this->line("Driver atual: <fg=yellow>{$driver}</>");

        if ($driver === 'single') {
            $this->line('<fg=yellow>Nota: Driver single (arquivo) não permite verificação de registros no banco.</>');
        }

        $this->newLine();
    }

    private function cleanup(): void
    {
        $this->info('Limpando registros de teste...');

        try {
            $driver = DB::connection()->getDriverName();

            if ($driver === 'pgsql') {
                // PostgreSQL: converte JSON para texto usando ::text antes do LIKE
                DB::table('monitoring')
                    ->where('created_at', '>=', now()->subHour())
                    ->where(function ($query) {
                        $query->whereRaw("content::text LIKE '%monitoring:test-watchers%'")
                            ->orWhereRaw("content::text LIKE '%monitoring:test:%'")
                            ->orWhereRaw("content::text LIKE '%test-monitoring-gate%'")
                            ->orWhereRaw("content::text LIKE '%test.example.com%'")
                            ->orWhereRaw("tags::text LIKE '%monitoring:test%'")
                            ->orWhereRaw("content->>'operation' = 'write' AND content->>'key' LIKE 'monitoring:test:%'")
                            ->orWhereRaw("content->>'message' LIKE '%Test log message from monitoring:test-watchers%'")
                        ;})
                    ->delete();
            } else {
                // MySQL/MariaDB
                DB::table('monitoring')
                    ->where('created_at', '>=', now()->subHour())
                    ->where(function ($query) {
                        $query->whereRaw("content LIKE '%monitoring:test-watchers%'")
                            ->orWhereRaw("content LIKE '%monitoring:test:%'")
                            ->orWhereRaw("content LIKE '%test-monitoring-gate%'")
                            ->orWhereRaw("content LIKE '%test.example.com%'")
                            ->orWhereRaw("tags LIKE '%monitoring:test%'")
                            ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(content, '$.source')) = 'test-watchers-command'");
                    })
                    ->delete();
            }

            $this->line('  <fg=green>✓</> Registros de teste removidos');
        } catch (\Throwable $e) {
            $this->line("  <fg=yellow>⚠</> Não foi possível limpar registros: {$e->getMessage()}");
        }

        $this->newLine();
    }

    private function getExitCode(): int
    {
        $errors = count(array_filter($this->testResults, fn($r) => $r['status'] === 'error'));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}

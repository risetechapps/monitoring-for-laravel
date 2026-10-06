<?php

namespace RiseTechApps\Monitoring\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;
use RiseTechApps\Monitoring\Tests\TestCase;

/**
 * Listagem paginada do painel (GET /monitoring): total para a tabela paginar,
 * filtros combinados e entrada do request tratada como hostil.
 */
class PaginateEventsTest extends TestCase
{
    private function repository(): MonitoringRepositoryInterface
    {
        return app(MonitoringRepositoryInterface::class);
    }

    private function row(string $type, array $content = [], array $tags = [], ?string $createdAt = null, ?string $resolvedAt = null): string
    {
        $id = (string) Str::orderedUuid();

        DB::connection($this->monitoringConnection())->table('monitoring')->insert([
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'batch_id' => (string) Str::uuid(),
            'type' => $type,
            'content' => json_encode($content),
            'tags' => json_encode($tags),
            'resolved_at' => $resolvedAt,
            'created_at' => $createdAt ?? now()->toDateTimeString(),
        ]);

        return $id;
    }

    public function test_returns_the_page_with_the_total(): void
    {
        foreach (range(1, 7) as $i) {
            $this->row('log', ['message' => "linha {$i}"], [], now()->subMinutes($i)->toDateTimeString());
        }

        $page = $this->repository()->paginateEvents(['per_page' => 3, 'page' => 2]);

        $this->assertSame(7, $page['recordsTotal']);
        $this->assertSame(3, $page['totalPages']);
        $this->assertSame(2, $page['current_page']);
        $this->assertCount(3, $page['data']);
        // Mais recentes primeiro: a página 2 começa no 4º.
        $this->assertSame('linha 4', $page['data'][0]['content']['message']);
    }

    public function test_combines_type_unresolved_and_search(): void
    {
        $this->row('exception', ['message' => 'Falha no pagamento']);
        $this->row('exception', ['message' => 'Falha no pagamento'], [], null, now()->toDateTimeString());
        $this->row('exception', ['message' => 'Outro erro']);
        $this->row('log', ['message' => 'Falha no pagamento']);

        $page = $this->repository()->paginateEvents([
            'type' => 'exception',
            'unresolved' => true,
            'search' => 'pagamento',
        ]);

        $this->assertSame(1, $page['recordsTotal']);
        $this->assertSame('Falha no pagamento', $page['data'][0]['content']['message']);
        $this->assertFalse($page['data'][0]['is_resolved']);
    }

    public function test_search_treats_like_wildcards_as_text(): void
    {
        $this->row('log', ['message' => '100% concluído']);
        $this->row('log', ['message' => '100 concluído']);

        $page = $this->repository()->paginateEvents(['search' => '100%']);

        $this->assertSame(1, $page['recordsTotal']);
    }

    public function test_search_without_from_stays_in_the_default_window(): void
    {
        $this->row('log', ['message' => 'antigo'], [], now()->subDays(40)->toDateTimeString());
        $this->row('log', ['message' => 'antigo recente']);

        $this->assertSame(1, $this->repository()->paginateEvents(['search' => 'antigo'])['recordsTotal']);

        // Com `from` explícito, a janela é a do usuário.
        $from = now()->subDays(60)->format('Y-m-d');
        $this->assertSame(2, $this->repository()->paginateEvents(['search' => 'antigo', 'from' => $from])['recordsTotal']);
    }

    public function test_date_range_and_invalid_dates(): void
    {
        $this->row('log', [], [], now()->subDays(10)->toDateTimeString());
        $this->row('log', [], [], now()->subDays(2)->toDateTimeString());

        $from = now()->subDays(3)->format('Y-m-d');
        $this->assertSame(1, $this->repository()->paginateEvents(['from' => $from])['recordsTotal']);

        $to = now()->subDays(5)->format('Y-m-d');
        $this->assertSame(1, $this->repository()->paginateEvents(['to' => $to])['recordsTotal']);

        // Data malformada é ignorada, não vira erro de SQL.
        $this->assertSame(2, $this->repository()->paginateEvents(['from' => "2026-01-01' OR 1=1 --"])['recordsTotal']);
    }

    public function test_sort_column_is_whitelisted_and_per_page_is_capped(): void
    {
        foreach (range(1, 3) as $i) {
            $this->row('log');
        }

        // Coluna fora da lista cai em created_at (sem erro de SQL).
        $page = $this->repository()->paginateEvents(['sort' => 'content; DROP TABLE monitoring', 'order' => 'sideways']);
        $this->assertSame(3, $page['recordsTotal']);

        $this->assertSame(200, $this->repository()->paginateEvents(['per_page' => 100000])['perPage']);
        $this->assertSame(1, $this->repository()->paginateEvents(['per_page' => -5])['perPage']);
    }

    public function test_index_route_answers_in_the_table_format(): void
    {
        \RiseTechApps\Monitoring\Monitoring::routes(['middleware' => ['api']]);

        if (!\Illuminate\Routing\ResponseFactory::hasMacro('jsonSuccess')) {
            \Illuminate\Routing\ResponseFactory::macro('jsonSuccess', fn ($data = null) => response()->json(['success' => true, 'data' => $data]));
            \Illuminate\Routing\ResponseFactory::macro('jsonGone', fn ($message = null) => response()->json(['success' => false, 'message' => $message], 410));
        }

        $this->row('request', ['uri' => '/a']);
        $this->row('exception', ['message' => 'x']);

        $this->getJson('/monitoring?pagesize=1&page=1&sort_column=created_at&sort_direction=desc&type=request')
            ->assertOk()
            ->assertJsonPath('data.recordsTotal', 1)
            ->assertJsonPath('data.data.0.type', 'request');
    }

    public function test_show_and_type_routes_answer_with_the_typed_response_macro(): void
    {
        // O jsonSuccess do risetools só aceita array|JsonResource|null: uma
        // Collection crua dava TypeError (500) — show/type/search/tags/user.
        $this->assertTrue(\Illuminate\Routing\ResponseFactory::hasMacro('jsonSuccess'));

        \RiseTechApps\Monitoring\Monitoring::routes(['middleware' => ['api']]);

        $id = $this->row('exception', ['message' => 'x']);

        $this->getJson("/monitoring/{$id}")->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson('/monitoring/type/exception')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/monitoring/search?q=x')->assertOk();
    }
}

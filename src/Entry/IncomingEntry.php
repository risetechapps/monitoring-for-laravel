<?php

namespace RiseTechApps\Monitoring\Entry;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Str;
use RiseTechApps\Monitoring\Services\BatchIdService;
use RiseTechApps\RiseTools\Features\Device\Device;

class IncomingEntry
{
    public string $uuid;
    public string $batchId;
    public string $type;
    public array $content = [];
    public mixed $tags = [];
    public Carbon|CarbonInterface $recordedAt;

    /** Dados do usuário — apenas primitivos, nunca o modelo Eloquent */
    private array $userData = [];

    /**
     * Cache de device do request em curso — evita múltiplas chamadas HTTP quando
     * várias entradas são gravadas no mesmo request.
     *
     * É estático, então precisa ser limpo entre requests via resetDeviceCache().
     * Sem isso, em Octane/FrankenPHP (onde o processo sobrevive ao request) o
     * device do primeiro usuário atendido pelo worker seria atribuído a todos
     * os seguintes.
     */
    private static ?array $deviceCache = null;

    /** Dados de device capturados no construtor */
    private array $deviceInfo = [];

    public function __construct(array $content, ?string $uuid = null)
    {
        $this->uuid = $uuid ?: self::generateUuid();
        $this->recordedAt = now();

        if (array_key_exists('tags', $content)) {
            $this->tags = $content['tags'];
            unset($content['tags']);
        }

        $this->content = array_merge($content, ['hostname' => gethostname()]);

        // O batch é resolvido AQUI, não em toArray().
        //
        // A entrada fica no buffer até o flush, e quem a produziu pode ter
        // encerrado seu batch nesse meio-tempo (JobWatcher chama forceDelete()
        // logo após registrar). Resolver na serialização faria a entrada receber
        // um batch novo e perder a correlação com o resto do job.
        //
        // getBatchId() cria um sob demanda e reaproveita o existente, então a
        // primeira entrada do request define o batch e as seguintes o herdam.
        $this->batchId = app(BatchIdService::class)->getBatchId();

        // Captura device uma vez por request (ver resetDeviceCache)
        if (self::$deviceCache === null) {
            try {
                self::$deviceCache = Device::info() ?? [];
            } catch (\Throwable) {
                self::$deviceCache = [];
            }
        }

        $this->deviceInfo = self::$deviceCache;
    }

    public static function make(...$arguments): static
    {
        return new static(...$arguments);
    }

    /**
     * Limpa o cache de device. Chamado ao fim de cada request para que o
     * próximo não herde o device do anterior em processos persistentes.
     */
    public static function resetDeviceCache(): void
    {
        self::$deviceCache = null;
    }

    public function batchId(string $batchId): static
    {
        $this->batchId = $batchId;
        return $this;
    }

    public function type(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    /** Armazena apenas dados primitivos do usuário — nunca o modelo Eloquent completo */
    public function user(User $user): static
    {
        $this->userData = [
            'id'    => $user->getAuthIdentifier(),
            'name'  => $user->name ?? null,
            'email' => $user->email ?? null,
        ];
        $this->tags(['user_id' => $user->getKey()]);
        return $this;
    }

    public function tags(array $tags): static
    {
        $this->tags = array_unique(array_merge((array) $this->tags, $tags));
        return $this;
    }

    protected static function generateUuid(): string
    {
        if (class_exists(\Symfony\Component\Uid\Uuid::class) && method_exists(\Symfony\Component\Uid\Uuid::class, 'v7')) {
            return \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        }

        if (method_exists(Str::class, 'orderedUuid')) {
            return (string) Str::orderedUuid();
        }

        return (string) Str::uuid();
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'uuid'       => $this->uuid,
            'batch_id'   => $this->batchId,
            'type'       => $this->type,
            'content'    => $this->encodeContent($this->content),
            'tags'       => $this->tags,
            'user'       => !empty($this->userData) ? $this->userData : null,
            'created_at' => $this->recordedAt->toDateTimeString(),
            'device'     => $this->deviceInfo,
        ];
    }

    private function encodeContent(array $content): string
    {
        // Trunca o response body se for maior que 32KB
        if (isset($content['response']) && is_array($content['response'])) {
            $encoded = json_encode($content['response'], JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($encoded && strlen($encoded) > 32768) {
                $content['response'] = ['_purged' => 'Response too large (' . round(strlen($encoded) / 1024, 1) . 'KB)'];
            }
        }

        // Mantém apenas headers essenciais
        if (isset($content['headers']) && is_array($content['headers'])) {
            $content['headers'] = array_intersect_key($content['headers'], array_flip([
                'content-type', 'accept', 'user-agent', 'host', 'x-tenant', 'origin',
            ]));
        }

        // Remove session — raramente útil e pode ser grande
        unset($content['session']);

        $json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($json === false || strlen($json) > 102400) { // 100KB máximo
            return json_encode(['_purged' => 'Content too large to serialize']);
        }

        return $json;
    }
}

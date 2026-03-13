<?php

namespace RiseTechApps\Monitoring\Entry;

use Carbon\Carbon;
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
    public Carbon $recordedAt;

    private mixed $batchIdService;

    /** Dados do usuário — apenas primitivos, nunca o modelo Eloquent */
    private array $userData = [];

    /** Cache estático de device por processo — evita múltiplas chamadas HTTP por request */
    private static ?array $deviceCache = null;

    /** Dados de device capturados no construtor */
    private array $deviceInfo = [];

    public function __construct(array $content, string $uuid = null)
    {
        $this->uuid = $uuid ?: self::generateUuid();
        $this->recordedAt = now();

        if (array_key_exists('tags', $content)) {
            $this->tags = $content['tags'];
            unset($content['tags']);
        }

        $this->content = array_merge($content, ['hostname' => gethostname()]);

        $this->batchIdService = app(BatchIdService::class);
        $this->batchIdService->setBatchId((string) Str::orderedUuid());

        // Captura device UMA vez por processo via cache estático
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

    public function toArray(): array
    {
        return [
            'uuid'       => $this->uuid,
            'batch_id'   => $this->batchIdService->getBatchId(),
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

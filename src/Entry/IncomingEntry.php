<?php

namespace RiseTechApps\Monitoring\Entry;


use Carbon\Carbon;
use Illuminate\Support\Str;
use RiseTechApps\Monitoring\Services\BatchIdService;
use RiseTechApps\RiseTools\Features\Device\Device;

class IncomingEntry
{
    /** Identificador único
     * @type  string
     **/
    public $uuid;

    /**   Identificador único para registro em lotes, toda operação vai ter o mesmo uuid
     * @type  string
     **/
    public $batchId;

    /** Tipo de evento
     * @var EntryType $type
     * */
    public $type;

    /** Usuário Conectado
     * @var \App\Models\User $user
     * */
    public $user;

    /**Array contendo o contexto
     * @var array
     */
    public $content = [];

    /**Array contendo as tags passadas
     * @var array
     */
    public $tags = [];

    /** Paraâmetro contendo dat e hora
     * @var Carbon
     */
    public $recordedAt;

    /** Service contendo o gerador unico de lotes
     * @var BatchIdService
     */
    private $batchIdService;


    public function __construct(array $content, string $uuid = null)
    {
        $this->uuid = $uuid ?: self::generateUuid();

        $this->recordedAt = now();

        if(array_key_exists('tags', $content)){
            $this->tags = $content['tags'];
            unset($content['tags']);
        }

        $this->content = array_merge($content, ['hostname' => gethostname()]);

        $this->batchIdService = app(BatchIdService::class);
        $this->batchIdService->setBatchId((string)Str::orderedUuid());
    }

    public static function make(...$arguments): static
    {
        return new static(...$arguments);
    }

    /** Função para setar o batch_id
     * @params string $batchId
     */
    public function batchId(string $batchId): static
    {
        $this->batchId = $batchId;

        return $this;
    }

    /** Função para setar o tipo de registro
     * @params string $type
     */
    public function type(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    /** Função para coletar dados do usuario atual
     * @params mixed $user
     */
    public function user($user): static
    {
        $this->user = $user;

        $this->content = array_merge($this->content, [
            'user' => [
                'id' => $user->getAuthIdentifier(),
                'name' => $user->name ?? null,
                'email' => $user->email ?? null,
            ],
        ]);

        $this->tags(['Auth' =>  $user->getAuthIdentifier()]);

        return $this;
    }

    /** Função para setar tags
     * @params array $tags
     */
    public function tags(array $tags): static
    {
        $this->tags = array_unique(array_merge($this->tags, $tags));

        return $this;
    }

    protected static function generateUuid(): string
    {
        // Tenta usar UUIDv7 (se disponível)
        if (class_exists(\Symfony\Component\Uid\Uuid::class) && method_exists(\Symfony\Component\Uid\Uuid::class, 'v7')) {
            return \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        }

        // Fallback: UUID ordenado (tipo v1-like)
        if (method_exists(Str::class, 'orderedUuid')) {
            return (string) Str::orderedUuid();
        }

        // Último fallback: UUID v4 puro
        return (string) Str::uuid();
    }

    /** Função para retornar array de todos os dados
     * @params array $tags
     *@return array
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'batch_id' => $this->batchIdService->getBatchId(),
            'type' => $this->type,
            'content' => json_encode($this->content),
            'tags' => json_encode($this->tags),
            'created_at' => $this->recordedAt->toDateTimeString(),
            'device' => Device::info()
        ];
    }
}

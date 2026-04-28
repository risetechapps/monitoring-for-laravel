# Exemplos de Notificações Customizadas

Esta pasta contém exemplos de como estender o sistema de alertas do Monitoring para Laravel com notificações customizadas.

---

## Arquivos Disponíveis

### 1. `TelegramAlertHandler.php`

Exemplo completo de um **Handler Customizado** para enviar alertas via Telegram.

**Recursos:**
- Mensagens formatadas com HTML para Telegram
- Suporte a todos os tipos de alerta (exception, request, job, query)
- Escape automático de caracteres especiais
- Informações detalhadas incluindo stack trace, URL, usuário
- Tratamento de erros e logging

**Como usar:**

1. Copie o arquivo para `app/Monitoring/Handlers/TelegramAlertHandler.php`
2. Crie o ServiceProvider `app/Providers/MonitoringServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Monitoring\Handlers\TelegramAlertHandler;
use Illuminate\Support\ServiceProvider;
use RiseTechApps\Monitoring\Services\Alerts\AlertService;

class MonitoringServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        AlertService::registerHandler('telegram', new TelegramAlertHandler());
    }
}
```

3. Adicione ao `config/app.php`:

```php
'providers' => [
    // ...
    App\Providers\MonitoringServiceProvider::class,
],
```

4. Configure no `.env`:

```env
TELEGRAM_BOT_TOKEN=123456789:ABCdefGHIjklMNOpqrSTUvwxyz
TELEGRAM_CHAT_ID=-1001234567890
```

---

### 2. `AlertEventListener.php`

Exemplo de **Event Listeners** para o evento `AlertTriggered`.

**Recursos:**
- Integração com PagerDuty (incident management)
- Logging em arquivo customizado
- Webhook genérico para serviços externos
- Filtragem por ambiente (só alerta em produção)
- Determinação automática de severidade

**Como usar:**

1. Crie o listener desejado em `app/Listeners/`
2. Registre no `EventServiceProvider`:

```php
protected $listen = [
    \RiseTechApps\Monitoring\Events\AlertTriggered::class => [
        \App\Listeners\SendPagerDutyNotification::class,
        \App\Listeners\LogAlertToCustomFile::class,
    ],
];
```

---

## Diferença entre Handlers e Event Listeners

| Aspecto | Handler | Event Listener |
|---------|---------|----------------|
| **Interface** | `AlertHandlerInterface` | Nenhuma (classe simples) |
| **Configuração** | Via `config/monitoring.php` custom_handlers | Via código ou configuração do Laravel |
| **Registro** | `AlertService::registerHandler()` | `EventServiceProvider` |
| **Controle de fluxo** | Retorna `bool` para indicar sucesso | Pode chamar `$event->markAsHandled()` |
| **Melhor para** | Canais de notificação reutilizáveis | Lógica de negócio específica |
| **Execução** | Após eventos, antes das notificações padrão | Antes de todos os handlers |

### Quando usar cada um?

**Use Handlers quando:**
- Quer adicionar um novo canal de notificação (Telegram, Teams, WhatsApp)
- Precisa de uma solução reutilizável em múltiplos projetos
- Quer que a configuração fique centralizada no arquivo de config

**Use Event Listeners quando:**
- Precisa executar lógica de negócio específica (ex: abrir ticket no Jira)
- Quer filtrar/modificar comportamento antes dos handlers
- Precisa integrar com sistemas externos específicos do projeto

---

## Ideias para Extensões

### Canais de Notificação

- **Microsoft Teams** - Via Microsoft Graph API ou Webhooks
- **Slack (Blocks API)** - Mensagens ricas com botões e ações
- **WhatsApp Business API** - Alertas via WhatsApp
- **SMS (Twilio)** - Para alertas críticos
- **Push Notification (OneSignal)** - Notificações push
- **Discord (Embeds)** - Mensagens ricas com embeds

### Integrações com Ferramentas

- **Jira** - Criar tickets automaticamente
- **GitHub Issues** - Criar issues para exceções
- **Sentry** - Enviar exceções para o Sentry
- **Datadog** - Métricas customizadas
- **New Relic** - Eventos customizados
- **OpsGenie** - Gerenciamento de incidentes

### Lógicas de Negócio

- **Rate Limiting por Exception** - Só alerta se a mesma exceção ocorrer N vezes
- **Agregação de Alertas** - Espera 5 minutos e agrupa alertas similares
- **Smart Grouping** - Agrupa exceções por stack trace similar
- **Alertas Inteligentes** - Só alerta se não houver um deploy recente
- **Escalonamento** - Alerta time A, se não resolver em 30min alerta time B

---

## Testando seus Handlers

```php
// Teste manual
$entry = \RiseTechApps\Monitoring\Entry\IncomingEntry::make([
    'class' => 'Exception',
    'message' => 'Test',
]);

$handler = new \App\Monitoring\Handlers\TelegramAlertHandler();
$handler->send('exception', $entry, [
    'bot_token' => 'your-token',
    'chat_id' => 'your-chat-id',
]);
```

---

## Contribuindo

Se você criar um handler útil, considere:
1. Publicá-lo como um pacote Composer
2. Compartilhar na documentação do projeto
3. Adicionar testes automatizados

# Laravel Monitoring

## 📌 Sobre o Projeto
O **Laravel Monitoring** é um package para Laravel que monitora toda atividade e registra no servidor.

## ✨ Funcionalidades
- 🔄 **Command** monitora todos os comandos
- 🔄 **Event** monitora todos os enventos
- 🔄 **Exception** monitora todas as exceções lançadas
- 🔄 **Gate** monitora todos os eventos gate
- 🔄 **JobWatcher** monitora todos os jobs
- 🔄 **Notification** monitora todas as notificações
- 🔄 **Queue** monitora todas as filas
- 🔄 **Request** captura todos os requests e responses
- 🔄 **Schedule** monitora todos os eventos programados

---

## 🚀 Instalação

### 1️⃣ Requisitos
Antes de instalar, certifique-se de que seu projeto atenda aos seguintes requisitos:
- PHP >= 8.0
- Laravel >= 10
- Composer instalado

### 2️⃣ Instalação do Package
Execute o seguinte comando no terminal:
```bash
composer require risetechapps/monitoring-for-laravel
```

### 4️⃣ Rodar Migrations
```bash
php artisan migrate
```

### ⚙️ Configuração

Após publicar o arquivo de configuração (`php artisan vendor:publish --tag=monitoring-config`), ajuste as opções em `config/monitoring.php`:

- **`enabled` / `MONITORING_ENABLED`** &mdash; liga ou desliga completamente o pacote.
- **`environments.only` / `MONITORING_ENV_ONLY`** &mdash; lista (separada por vírgula) de ambientes em que o monitoramento deve rodar. Deixe vazio para permitir em todos.
- **`environments.except` / `MONITORING_ENV_EXCEPT`** &mdash; ambientes que devem ser ignorados mesmo que estejam em `only`.
- **`buffer_size` / `MONITORING_BUFFER_SIZE`** &mdash; número de entradas acumuladas antes de persistir em lote.
- **`watchers`** &mdash; habilite/desabilite watchers individualmente e ajuste opções como métodos HTTP ignorados.
- **`drivers`** &mdash; configure a conexão SQL ou o endpoint HTTP, inclusive timeout, tentativas de retry e, para o driver HTTP, o envio assíncrono via fila.
- **`drivers.http.queue`** &mdash; defina `MONITORING_HTTP_QUEUE=true` para delegar as tentativas ao queue worker e use as variáveis `MONITORING_HTTP_QUEUE_CONNECTION`, `MONITORING_HTTP_QUEUE_NAME` e `MONITORING_HTTP_QUEUE_DELAY` para personalizar conexão, fila e atraso inicial.
- **`response_macros` / `MONITORING_RESPONSE_MACROS`** &mdash; desative se não quiser registrar os helpers `jsonSuccess`, `jsonError`, etc.

---

## 🛠 Contribuição
Sinta-se à vontade para contribuir! Basta seguir estes passos:
1. Faça um fork do repositório
2. Crie uma branch (`feature/nova-funcionalidade`)
3. Faça um commit das suas alterações
4. Envie um Pull Request

---

## 📜 Licença
Este projeto é licenciado sob os termos da **GNU General Public License v3.0** — veja o arquivo [LICENSE](./LICENSE) para detalhes.

---

💡 **Desenvolvido por [Rise Tech](https://risetech.com.br)**


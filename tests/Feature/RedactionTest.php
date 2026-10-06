<?php

namespace RiseTechApps\Monitoring\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Monitoring;
use RiseTechApps\Monitoring\Support\Redactor;
use RiseTechApps\Monitoring\Tests\TestCase;

/**
 * Nada de senha, token ou segredo chega à tabela `monitoring` — venha do
 * Loggly, do RequestWatcher ou do HTTP de saída.
 */
class RedactionTest extends TestCase
{
    public function test_loggly_with_request_does_not_store_the_typed_password(): void
    {
        // Como o LoginController do AuthFlow loga "Invalid email or password".
        $request = Request::create('/api/v1/auth/login', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'maria@example.com', 'password' => 'MinhaSenha123']));
        $request->json();

        logglyWarning()->withRequest($request)->log('Invalid email or password');
        Monitoring::flushAll();

        $raw = DB::table('monitoring')->value('content');
        $this->assertStringNotContainsString('MinhaSenha123', $raw);

        $entry = $this->storedEntries('log')[0];
        $this->assertSame('POST', $entry->content['request']['method']);
        $this->assertSame('maria@example.com', $entry->content['request']['input']['email']);
        $this->assertSame(Redactor::MASK, $entry->content['request']['input']['password']);
    }

    public function test_logging_a_request_whose_upload_was_already_moved_does_not_throw(): void
    {
        // Upload de mídia: o arquivo sai do /tmp para o storage e SÓ DEPOIS o
        // controller loga com withRequest(). O getSize() no temporário lançava
        // RuntimeException ("stat failed") e o log derrubava a request com 500.
        $tmp = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($tmp, str_repeat('x', 2048));
        $file = new \Illuminate\Http\UploadedFile($tmp, 'foto.jpeg', 'image/jpeg', null, true);

        $request = Request::create('/api/v1/uploads', 'POST', ['collection' => 'profile'], [], ['file' => $file]);
        unlink($tmp);

        logglyInfo()->withRequest($request)->log('Successful loaded upload');
        Monitoring::flushAll();

        $entry = $this->storedEntries('log')[0];
        $this->assertSame('foto.jpeg', $entry->content['request']['input']['file']['name']);
        $this->assertNull($entry->content['request']['input']['file']['size_kb']);
    }

    public function test_request_watcher_hides_login_token_sensitive_payload_and_query(): void
    {
        Route::post('/api/login', fn () => response()->json([
            'data' => ['access_token' => '12|PLAINTEXT-SANCTUM-TOKEN', 'token_type' => 'Bearer', 'name' => 'Maria'],
        ]));

        $this->postJson('/api/login?token=LINK-TOKEN&page=2', [
            'email' => 'maria@example.com',
            'password' => 'MinhaSenha123',
            'old_password' => 'SenhaAntiga',
            'recovery_code' => 'ABCD-1234',
        ])->assertOk();

        $raw = DB::table('monitoring')->where('type', 'request')->value('content');
        foreach (['PLAINTEXT-SANCTUM-TOKEN', 'MinhaSenha123', 'SenhaAntiga', 'ABCD-1234', 'LINK-TOKEN'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, "{$secret} stored");
        }

        $entry = $this->storedEntries('request')[0];
        $this->assertSame('Maria', $entry->content['response']['data']['name']);
        $this->assertSame('maria@example.com', $entry->content['payload']['email']);
        $this->assertStringContainsString('page=2', $entry->content['uri']);
    }

    public function test_outgoing_http_secrets_are_hidden(): void
    {
        Http::fake(['oauth.example/*' => Http::response(['access_token' => 'OAUTH-ACCESS', 'expires_in' => 3600])]);

        Http::asForm()->post('https://oauth.example/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => 'REFRESH-SECRET',
            'client_secret' => 'CLIENT-SECRET',
        ]);
        Monitoring::flushAll();

        $raw = DB::table('monitoring')->where('type', 'client_request')->value('content');
        $this->assertNotNull($raw, 'outgoing request not recorded');

        foreach (['OAUTH-ACCESS', 'REFRESH-SECRET', 'CLIENT-SECRET'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, "{$secret} stored");
        }

        $this->assertSame(3600, $this->storedEntries('client_request')[0]->content['response']['expires_in']);
    }

    public function test_redactor_rules(): void
    {
        $data = Redactor::redact([
            'user' => ['Password' => 'x', 'name' => 'Maria'],
            'headers' => ['Authorization' => 'Bearer y', 'accept' => 'json'],
            'link' => 'https://app.example/first-access?token=abc&tenant=1#top',
            'signed' => '/files/1?expires=99&signature=deadbeef',
            "\0*\0content" => '{"password":"cru"}',
            'empty_token' => null,
            'list' => [['api_key' => 'k']],
        ]);

        $this->assertSame(Redactor::MASK, $data['user']['Password']);
        $this->assertSame('Maria', $data['user']['name']);
        $this->assertSame(Redactor::MASK, $data['headers']['Authorization']);
        $this->assertSame('json', $data['headers']['accept']);
        $this->assertSame('https://app.example/first-access?token=' . Redactor::MASK . '&tenant=1#top', $data['link']);
        $this->assertSame('/files/1?expires=99&signature=' . Redactor::MASK, $data['signed']);
        $this->assertSame(Redactor::MASK, $data["\0*\0content"]);
        $this->assertNull($data['empty_token'], 'empty values are kept as they are');
        $this->assertSame(Redactor::MASK, $data['list'][0]['api_key']);
    }

    public function test_extra_keys_from_config_are_hidden_too(): void
    {
        config(['monitoring.redact.extra_keys' => ['cpf']]);

        $this->assertSame(Redactor::MASK, Redactor::redact(['cpf' => '12345678900'])['cpf']);
        $this->assertSame(Redactor::MASK, Redactor::redact(['password' => 'x'])['password'], 'extra keys must not replace the defaults');
    }

    public function test_device_has_no_geo_ip_by_default(): void
    {
        // Geo = chamada HTTP externa no caminho do request: desligado por padrão.
        $device = IncomingEntry::make(['x' => 1])->type('log')->toArray()['device'];

        $this->assertArrayNotHasKey('geo_ip', $device);
        $this->assertArrayHasKey('browser', $device);
    }

    public function test_mail_body_is_not_stored_by_default(): void
    {
        config(['mail.default' => 'array']);

        \Illuminate\Support\Facades\Mail::html('<a href="https://app.example/reset?token=abc">Redefinir</a> Código: 998877', function ($message) {
            $message->to('maria@example.com')->subject('Redefinir senha');
        });
        Monitoring::flushAll();

        $entry = $this->storedEntries('mail')[0] ?? null;
        $this->assertNotNull($entry, 'mail not recorded');
        $this->assertSame('Redefinir senha', $entry->content['subject']);
        $this->assertNull($entry->content['html']);
        $this->assertStringNotContainsString('998877', json_encode($entry->content));
    }
}

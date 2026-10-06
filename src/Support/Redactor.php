<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Support;

/**
 * Oculta dado sensível antes de qualquer entrada ir para o armazenamento.
 *
 * Aplicado em IncomingEntry::toArray(), por onde passa TODA entrada (watchers e
 * Loggly): um watcher novo, ou um log com withProperties()/withRequest(), não
 * consegue gravar senha ou token por esquecimento.
 *
 * Duas regras:
 *  - chave sensível (padrões de `monitoring.redact.keys`, sem diferenciar
 *    maiúsculas) → o valor vira '********', em qualquer nível do array;
 *  - string que é URL → os parâmetros de query com chave sensível são ocultados
 *    (link de primeiro acesso com ?token=, URL assinada com ?signature=...).
 *
 * Não pega segredo escrito em texto livre (ex.: "seu código é 123456" no corpo
 * de um e-mail) — para isso, não grave o corpo (MailWatcher `record_html`).
 */
final class Redactor
{
    public const string MASK = '********';

    /** Padrões padrão (fnmatch, case-insensitive). Ampliados por config. */
    public const array DEFAULT_KEYS = [
        '*password*',
        '*token*',
        '*secret*',
        '*signature*',
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'php-auth-pw',
        'x-api-key',
        'api_key',
        'apikey',
        'api-key',
        'private_key',
        'recovery_code',
        'recovery_codes',
        'two_factor_*',
        'otp',
        'cvv',
        'card_number',
    ];

    /** Cache do regex compilado por lista de padrões (o config raramente muda). */
    private static array $compiled = [];

    public static function redact(mixed $data): mixed
    {
        $regex = self::regex();

        return self::walk($data, $regex);
    }

    /** Oculta, numa URL, os parâmetros de query com chave sensível. */
    public static function url(string $url): string
    {
        return self::redactUrl($url, self::regex());
    }

    public static function isSensitiveKey(string $key): bool
    {
        return (bool) preg_match(self::regex(), $key);
    }

    private static function walk(mixed $data, string $regex): mixed
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                // Chave com "\0" = propriedade protegida/privada de objeto
                // despejada por cast (array) — o antigo Loggly::withRequest()
                // gravava assim o corpo cru do request. Nunca é dado útil.
                if (is_string($key) && (str_contains($key, "\0") || preg_match($regex, $key))) {
                    $data[$key] = ($value === null || $value === '' || $value === []) ? $value : self::MASK;
                    continue;
                }

                $data[$key] = self::walk($value, $regex);
            }

            return $data;
        }

        // URL absoluta ou caminho com query (o RequestWatcher grava a URI relativa).
        if (is_string($data) && str_contains($data, '?') && preg_match('#^(https?://|/)#i', $data)) {
            return self::redactUrl($data, $regex);
        }

        return $data;
    }

    private static function redactUrl(string $url, string $regex): string
    {
        $queryStart = strpos($url, '?');

        if ($queryStart === false) {
            return $url;
        }

        $fragment = '';
        $hashPos = strpos($url, '#', $queryStart);
        if ($hashPos !== false) {
            $fragment = substr($url, $hashPos);
            $url = substr($url, 0, $hashPos);
        }

        $base = substr($url, 0, $queryStart);
        $pairs = explode('&', substr($url, $queryStart + 1));

        foreach ($pairs as $i => $pair) {
            $name = urldecode(explode('=', $pair, 2)[0]);
            // a[b]=... → testa "a" e "b"
            $parts = preg_split('/[\[\]]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [$name];

            foreach ($parts as $part) {
                if (preg_match($regex, $part)) {
                    $pairs[$i] = explode('=', $pair, 2)[0] . '=' . self::MASK;
                    break;
                }
            }
        }

        return $base . '?' . implode('&', $pairs) . $fragment;
    }

    private static function regex(): string
    {
        $patterns = self::patterns();
        $cacheKey = implode("\0", $patterns);

        if (isset(self::$compiled[$cacheKey])) {
            return self::$compiled[$cacheKey];
        }

        $alternatives = array_map(
            fn (string $pattern) => str_replace(['\*', '\?'], ['.*', '.'], preg_quote(strtolower($pattern), '/')),
            $patterns
        );

        return self::$compiled[$cacheKey] = '/^(?:' . implode('|', $alternatives) . ')$/i';
    }

    /** @return string[] */
    private static function patterns(): array
    {
        $configured = function_exists('config') ? config('monitoring.redact.keys') : null;
        $extra = function_exists('config') ? (array) config('monitoring.redact.extra_keys', []) : [];

        $patterns = is_array($configured) && $configured !== [] ? $configured : self::DEFAULT_KEYS;

        return array_values(array_unique(array_filter(array_map('strval', array_merge($patterns, $extra)))));
    }
}

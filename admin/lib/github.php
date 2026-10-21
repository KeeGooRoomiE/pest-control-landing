<?php
declare(strict_types=1);
defined('ADMIN_APP') || exit;

/*
 * Клиент GitHub: правки товаров и фото уходят в репозиторий одним коммитом (Git Data API).
 * Дальше работает обычная цепочка: Actions собирает сайт → ветка production → Plesk.
 * Токен нужен fine-grained с правом только Contents: read and write на этот репозиторий.
 */

final class GitHubError extends RuntimeException
{
}

final class GitHub
{
    public const PRODUCTS_PATH = 'src/data/products.json';
    public const IMAGES_DIR = 'src/assets/groceries/';

    public function __construct(
        private string $token,
        private string $repo,
        private string $branch,
        private string $api = 'https://api.github.com',
    ) {
    }

    public function repo(): string
    {
        return $this->repo;
    }

    public function branch(): string
    {
        return $this->branch;
    }

    /** @return array{0:int,1:mixed} */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init($this->api . '/repos/' . $this->repo . $path);
        $headers = [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $this->token,
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: qaz-pest-admin',
        ];
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 8,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($raw)) {
            throw new GitHubError('Нет связи с GitHub, попробуйте ещё раз.');
        }
        return [$code, json_decode($raw, true)];
    }

    private function ok(string $method, string $path, ?array $body = null): array
    {
        [$code, $data] = $this->request($method, $path, $body);
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            $hint = match ($code) {
                401 => ' (токен недействителен или истёк)',
                403, 404 => ' (проверьте права токена: Contents — Read and write, и имя репозитория)',
                default => '',
            };
            $msg = is_array($data) ? (string) ($data['message'] ?? '') : '';
            error_log("admin: GitHub {$method} {$path} → {$code} {$msg}");
            throw new GitHubError("GitHub вернул ошибку {$code}{$hint}.");
        }
        return $data;
    }

    private function exists(string $path, string $ref): bool
    {
        [$code] = $this->request('GET', '/contents/' . $path . '?ref=' . $ref);
        return $code === 200;
    }

    /** Текущий список товаров из ветки (для показа в админке). */
    public function products(): array
    {
        $head = $this->ok('GET', "/git/ref/heads/{$this->branch}")['object']['sha'];
        return $this->readProducts($head);
    }

    private function readProducts(string $ref): array
    {
        $file = $this->ok('GET', '/contents/' . self::PRODUCTS_PATH . '?ref=' . $ref);
        $json = base64_decode(str_replace("\n", '', (string) ($file['content'] ?? '')), true);
        $products = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($products)) {
            throw new GitHubError('Не удалось прочитать products.json из репозитория.');
        }
        return $products;
    }

    /**
     * Применяет правку к свежему списку товаров и коммитит её (вместе с файлами фото).
     * $mutate(array $products): array{0: array, 1: array<string, ?string>, 2: mixed}
     *   → [новый список, [путь => содержимое|null (удалить)], результат для вызывающего].
     * Если ветка успела сдвинуться — читаем заново и повторяем (до 3 раз), без force.
     */
    public function commit(callable $mutate, string $message): mixed
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $head = $this->ok('GET', "/git/ref/heads/{$this->branch}")['object']['sha'];
            $baseTree = $this->ok('GET', "/git/commits/{$head}")['tree']['sha'];
            [$products, $files, $result] = $mutate($this->readProducts($head));
            $files[self::PRODUCTS_PATH] = products_json($products);

            $tree = [];
            foreach ($files as $path => $bytes) {
                if ($bytes === null) {
                    if (!$this->exists($path, $head)) {
                        continue; // удалять нечего
                    }
                    $tree[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => null];
                    continue;
                }
                $blob = $this->ok('POST', '/git/blobs', ['content' => base64_encode($bytes), 'encoding' => 'base64']);
                $tree[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => $blob['sha']];
            }
            $newTree = $this->ok('POST', '/git/trees', ['base_tree' => $baseTree, 'tree' => $tree]);
            $newCommit = $this->ok('POST', '/git/commits', [
                'message' => $message,
                'tree' => $newTree['sha'],
                'parents' => [$head],
            ]);
            [$code] = $this->request('PATCH', "/git/refs/heads/{$this->branch}", ['sha' => $newCommit['sha'], 'force' => false]);
            if ($code === 200) {
                return $result;
            }
            if ($code !== 422 || $attempt === 3) {
                error_log("admin: GitHub обновление ветки → {$code}");
                throw new GitHubError('Не удалось сохранить: ветка в репозитории изменилась, повторите.');
            }
        }
        throw new GitHubError('Не удалось сохранить.');
    }
}

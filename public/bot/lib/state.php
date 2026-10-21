<?php
declare(strict_types=1);
defined('BOT_APP') || exit;

/**
 * Состояние диалога по пользователю: файл JSON в state_dir (вне webroot), блокировка flock.
 * Структура: {last_update:int, session:?array, orders:int[]}. Сессия живёт 24 часа.
 */
class StateStore
{
    public const SESSION_TTL = 86400;

    public function __construct(private string $dir)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать каталог состояния бота.');
        }
    }

    /** Выполняет $fn(array &$state) под эксклюзивной блокировкой файла пользователя. */
    public function locked(int $userId, callable $fn): void
    {
        $fp = fopen($this->dir . '/u' . $userId . '.json', 'c+');
        if ($fp === false) {
            throw new RuntimeException('Не удалось открыть состояние пользователя.');
        }
        flock($fp, LOCK_EX);
        try {
            $state = json_decode((string) stream_get_contents($fp), true);
            $state = is_array($state) ? $state : [];
            $state += ['last_update' => 0, 'session' => null, 'orders' => []];
            if (is_array($state['session']) && time() - (int) ($state['session']['ts'] ?? 0) > self::SESSION_TTL) {
                $state['session'] = null;
            }
            $fn($state);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE));
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}

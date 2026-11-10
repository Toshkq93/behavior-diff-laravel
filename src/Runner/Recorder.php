<?php

namespace Toshkq93\BehaviorDiffLaravel\Runner;

/**
 * Collects what happened during one request: logged errors, database
 * writes and application events. Listeners are registered once and write
 * into the recorder while it is recording.
 */
final class Recorder
{
    /** @var bool */
    private $recording = false;

    /** @var list<array<string, mixed>> */
    private $errors = [];

    /** @var list<string> */
    private $queries = [];

    /** @var list<string> */
    private $events = [];

    /** @var string */
    private $basePath;

    /** @var string */
    private $appNamespace;

    public function __construct(string $basePath, string $appNamespace)
    {
        $this->basePath = rtrim(str_replace('\\', '/', $basePath), '/').'/';
        $this->appNamespace = $appNamespace;
    }

    public function start(): void
    {
        $this->errors = [];
        $this->queries = [];
        $this->events = [];
        $this->recording = true;
    }

    public function stop(): void
    {
        $this->recording = false;
    }

    /**
     * @param string               $level
     * @param mixed                $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        if (! $this->recording) {
            return;
        }

        $exception = isset($context['exception']) && $context['exception'] instanceof \Throwable ? $context['exception'] : null;

        if ($exception !== null) {
            $this->errors[] = [
                'type' => 'exception',
                'message' => get_class($exception).': '.$this->relative($exception->getMessage()),
                'file' => $this->relative($exception->getFile()),
                'line' => $exception->getLine(),
            ];

            return;
        }

        $text = $this->relative(is_string($message) ? $message : (string) json_encode($message));
        $type = stripos($text, 'deprecated') !== false ? 'deprecated' : 'log.'.$level;

        if (in_array($level, ['debug', 'info'], true)) {
            return;
        }

        $this->errors[] = ['type' => $type, 'message' => $text];
    }

    /**
     * @param list<mixed> $bindings
     */
    public function query(string $sql, array $bindings): void
    {
        if (! $this->recording || ! preg_match('/^\s*(insert|update|delete|replace|truncate|upsert|merge)\b/i', $sql)) {
            return;
        }

        $values = array_map(function ($value) {
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }

            if (is_bool($value)) {
                return (int) $value;
            }

            return is_object($value) && method_exists($value, '__toString') ? (string) $value : $value;
        }, $bindings);

        $this->queries[] = trim((string) preg_replace('/\s+/', ' ', $sql)).' '.json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param string|object $event
     */
    public function event($event): void
    {
        $name = is_object($event) ? get_class($event) : (string) $event;

        if ($this->recording && strpos($name, $this->appNamespace) === 0) {
            $this->events[] = $name;
        }
    }

    /** @return list<array<string, mixed>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<string> */
    public function queries(): array
    {
        return $this->queries;
    }

    /** @return list<string> */
    public function events(): array
    {
        return $this->events;
    }

    private function relative(string $text): string
    {
        return str_replace([$this->basePath, str_replace('/', '\\', $this->basePath)], '', $text);
    }
}

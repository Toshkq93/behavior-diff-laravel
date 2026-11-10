<?php

namespace Toshkq93\BehaviorDiffLaravel\Runner;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Toshkq93\BehaviorDiff\Cases\RequestCase;
use Toshkq93\BehaviorDiff\Result\CaseResult;
use Toshkq93\BehaviorDiff\Result\ResultFile;
use Toshkq93\BehaviorDiff\Runner\Runner;

/**
 * Runs the cases inside the Laravel application, through its HTTP kernel,
 * the way feature tests do:
 *
 *  - each request runs in a database transaction that is rolled back;
 *  - mail, notifications, jobs and outgoing HTTP are faked and recorded;
 *  - database writes, logged errors and application events are recorded;
 *  - the clock, the random seed and generated UUIDs are fixed;
 *  - the case user is set on the configured guards; CSRF is not checked.
 */
final class KernelRunner implements Runner
{
    const CSRF_MIDDLEWARE = [
        'App\Http\Middleware\VerifyCsrfToken',
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
    ];

    /** @var Application */
    private $app;

    /** @var array<string, mixed> */
    private $config;

    /** @var Recorder */
    private $recorder;

    /** @var array<string, array<string, string>> scenario => cookies */
    private $jars = [];

    /**
     * @param array<string, mixed> $config the behavior-diff config
     */
    public function __construct(Application $app, array $config)
    {
        $this->app = $app;
        $this->config = $config;
        $this->recorder = new Recorder($app->basePath(), $this->appNamespace());
    }

    /** Events of this namespace are recorded as effects. */
    private function appNamespace(): string
    {
        if (isset($this->config['app_namespace']) && is_string($this->config['app_namespace'])) {
            return $this->config['app_namespace'];
        }

        try {
            return method_exists($this->app, 'getNamespace') ? $this->app->getNamespace() : 'App\\';
        } catch (\Throwable $e) {
            return 'App\\';
        }
    }

    public function run(array $cases, ResultFile $results, ?callable $progress = null): void
    {
        $this->prepare();

        foreach ($cases as $case) {
            $result = $this->runCase($case);
            $results->add($result);

            if ($progress !== null) {
                $progress($case, $result);
            }
        }
    }

    private function prepare(): void
    {
        $reset = isset($this->config['reset']) ? $this->config['reset'] : null;

        if (is_string($reset) && $reset !== '') {
            exec($reset.' 2>&1', $output, $code);

            if ($code !== 0) {
                throw new \RuntimeException("Reset command failed ({$code}): {$reset}\n".implode("\n", $output));
            }
        }

        foreach ((array) $this->option('config', []) as $key => $value) {
            $this->app['config']->set($key, $value);
        }

        foreach (self::CSRF_MIDDLEWARE as $class) {
            $this->app->instance($class, new PassThroughMiddleware());
        }

        $recorder = $this->recorder;
        $events = $this->app['events'];

        $events->listen(MessageLogged::class, function ($event) use ($recorder) {
            $recorder->log($event->level, $event->message, (array) $event->context);
        });

        if ($this->option('record_queries', true)) {
            $events->listen(QueryExecuted::class, function ($query) use ($recorder) {
                $recorder->query($query->sql, (array) $query->bindings);
            });
        }

        if ($this->option('record_events', true)) {
            $events->listen('*', function ($event) use ($recorder) {
                $recorder->event($event);
            });
        }
    }

    private function runCase(RequestCase $case): CaseResult
    {
        $this->freeze();
        $fakes = $this->fake();
        $connections = $this->begin();
        $buffer = ob_get_level();
        $started = microtime(true);
        $errors = [];
        $status = 0;
        $headers = [];
        $body = '';
        $cookies = [];

        $this->recorder->start();
        ob_start();

        try {
            $request = $this->request($case);
            $this->authenticate($case->user);

            $kernel = $this->app->make(HttpKernel::class);
            $response = $kernel->handle($request);
            $content = $this->content($response);
            $kernel->terminate($request, $response);

            $status = $response->getStatusCode();
            $headers = self::headers($response);
            $cookies = $response->headers->getCookies();
            $body = $content;
        } catch (\Throwable $e) {
            $errors[] = [
                'type' => 'exception',
                'message' => get_class($e).': '.$e->getMessage(),
                'file' => str_replace($this->app->basePath().DIRECTORY_SEPARATOR, '', $e->getFile()),
                'line' => $e->getLine(),
            ];
        } finally {
            // Output echoed by the application instead of returned.
            $echoed = '';

            while (ob_get_level() > $buffer) {
                $echoed = ob_get_clean().$echoed;
            }

            $this->recorder->stop();
            $effects = $this->effects($fakes);
            $this->rollback($connections);
            $this->logout();
        }

        if ($case->scenario !== null) {
            foreach ($cookies as $cookie) {
                /** @var \Symfony\Component\HttpFoundation\Cookie $cookie */
                if ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() < time()) {
                    unset($this->jars[$case->scenario][$cookie->getName()]);
                } else {
                    $this->jars[$case->scenario][$cookie->getName()] = (string) $cookie->getValue();
                }
            }
        }

        return new CaseResult(
            $case->id,
            $status,
            $headers,
            $echoed.$body,
            array_merge($this->recorder->errors(), $errors),
            $effects,
            (microtime(true) - $started) * 1000
        );
    }

    private function request(RequestCase $case): Request
    {
        $server = [];

        foreach ($case->headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }

        if (! isset($server['HTTP_ACCEPT'])) {
            $server['HTTP_ACCEPT'] = strpos($case->uri, '/api/') === 0 || $case->uri === '/api' ? 'application/json' : 'text/html,application/xhtml+xml,*/*';
        }

        $cookies = $case->scenario !== null && isset($this->jars[$case->scenario]) ? $this->jars[$case->scenario] : [];
        $parameters = in_array($case->method, ['GET', 'HEAD'], true) ? [] : $case->body;

        $request = Request::create($case->target(), $case->method, $parameters, $cookies, [], $server);

        if (isset($server['CONTENT_TYPE']) && strpos($server['CONTENT_TYPE'], 'json') !== false && $parameters !== []) {
            $request = Request::create($case->target(), $case->method, [], $cookies, [], $server, (string) json_encode($parameters));
        }

        return $request;
    }

    /**
     * @param string|int|null $user
     */
    private function authenticate($user): void
    {
        if ($user === null) {
            return;
        }

        foreach ((array) $this->option('guards', ['web']) as $name) {
            $guard = $this->app['auth']->guard($name);

            if (! method_exists($guard, 'getProvider') || ! method_exists($guard, 'setUser')) {
                continue;
            }

            $model = $guard->getProvider()->retrieveById($user);

            if ($model !== null) {
                $guard->setUser($model);
            }
        }
    }

    private function logout(): void
    {
        if (method_exists($this->app['auth'], 'forgetGuards')) {
            $this->app['auth']->forgetGuards();
        } else {
            $this->app->forgetInstance('auth');
            $this->app->forgetInstance('auth.driver');
            Facade::clearResolvedInstance('auth');
        }
    }

    private function freeze(): void
    {
        $now = (string) $this->option('now', '2024-01-15 12:00:00');

        foreach (['Illuminate\Support\Carbon', 'Carbon\Carbon', 'Carbon\CarbonImmutable'] as $class) {
            if (class_exists($class)) {
                $class::setTestNow($class::parse($now));
            }
        }

        $seed = (int) $this->option('seed', 1);
        mt_srand($seed);
        srand($seed);

        $counter = 0;

        if (method_exists(Str::class, 'createUuidsUsing') && class_exists('Ramsey\Uuid\Uuid')) {
            Str::createUuidsUsing(function () use (&$counter) {
                return \Ramsey\Uuid\Uuid::fromString(sprintf('00000000-0000-4000-8000-%012d', ++$counter));
            });
        }

        if (method_exists(Str::class, 'createRandomStringsUsing')) {
            Str::createRandomStringsUsing(function ($length) use (&$counter, $seed) {
                $counter++;

                return substr(str_repeat(hash('sha256', $seed.'-'.$counter), (int) ceil($length / 64)), 0, $length);
            });
        }
    }

    /**
     * @return array<string, object> kind => fake
     */
    private function fake(): array
    {
        $fake = (array) $this->option('fake', []);
        $fakes = [];
        $facades = [
            'mail' => 'Illuminate\Support\Facades\Mail',
            'notifications' => 'Illuminate\Support\Facades\Notification',
            'queue' => 'Illuminate\Support\Facades\Queue',
            'http' => 'Illuminate\Support\Facades\Http',
        ];

        foreach ($facades as $kind => $facade) {
            if ((! isset($fake[$kind]) || $fake[$kind]) && class_exists($facade)) {
                $result = $facade::fake();
                $fakes[$kind] = $kind === 'http' ? $facade::getFacadeRoot() : ($result !== null ? $result : $facade::getFacadeRoot());

                if ($kind === 'http' && method_exists($fakes[$kind], 'preventStrayRequests')) {
                    $fakes[$kind]->preventStrayRequests();
                }
            }
        }

        return $fakes;
    }

    /**
     * @param array<string, object> $fakes
     * @return array<string, list<string>>
     */
    private function effects(array $fakes): array
    {
        $effects = [];

        if (isset($fakes['mail'])) {
            $effects['mail'] = [];

            foreach (['mailables' => '', 'queuedMailables' => ' (queued)'] as $property => $suffix) {
                foreach ((array) self::property($fakes['mail'], $property) as $mailable) {
                    $effects['mail'][] = get_class($mailable).$suffix.' to '.self::addresses($mailable);
                }
            }
        }

        if (isset($fakes['notifications'])) {
            $effects['notification'] = [];

            foreach ((array) self::property($fakes['notifications'], 'notifications') as $notifiable => $byId) {
                foreach ((array) $byId as $id => $byClass) {
                    foreach ((array) $byClass as $class => $sent) {
                        foreach ((array) $sent as $item) {
                            $channels = isset($item['channels']) ? implode(',', (array) $item['channels']) : '';
                            $effects['notification'][] = $class.' → '.$notifiable.'#'.$id.($channels !== '' ? ' via '.$channels : '');
                        }
                    }
                }
            }
        }

        if (isset($fakes['queue'])) {
            $effects['job'] = [];

            foreach ((array) self::property($fakes['queue'], 'jobs') as $class => $jobs) {
                foreach ((array) $jobs as $job) {
                    $queue = isset($job['queue']) && $job['queue'] !== null ? ' on '.$job['queue'] : '';
                    $effects['job'][] = $class.$queue;
                }
            }

            sort($effects['job']);
        }

        if (isset($fakes['http']) && method_exists($fakes['http'], 'recorded')) {
            $effects['http'] = [];

            foreach ($fakes['http']->recorded() as $pair) {
                $request = $pair[0];
                $effects['http'][] = $request->method().' '.$request->url().($request->body() !== '' ? ' '.$request->body() : '');
            }
        }

        $effects['db'] = $this->recorder->queries();
        $effects['event'] = $this->recorder->events();

        return $effects;
    }

    /**
     * @return list<array{0: \Illuminate\Database\Connection, 1: int}>
     */
    private function begin(): array
    {
        $connections = [];

        foreach ((array) $this->option('connections', [null]) as $name) {
            $connection = $this->app['db']->connection($name);
            $connection->beginTransaction();
            $connections[] = [$connection, $connection->transactionLevel() - 1];
        }

        return $connections;
    }

    /**
     * @param list<array{0: \Illuminate\Database\Connection, 1: int}> $connections
     */
    private function rollback(array $connections): void
    {
        foreach ($connections as $item) {
            list($connection, $level) = $item;

            try {
                while ($connection->transactionLevel() > $level) {
                    $connection->rollBack();
                }
            } catch (\Throwable $e) {
                // An implicit commit (DDL on MySQL) ended the transaction early.
            }
        }
    }

    private function content(Response $response): string
    {
        if ($response instanceof BinaryFileResponse) {
            $file = $response->getFile()->getPathname();

            return '[file '.basename($file).' sha1:'.sha1_file($file).' '.filesize($file).' bytes]';
        }

        if ($response instanceof StreamedResponse) {
            ob_start();
            $response->sendContent();

            return (string) ob_get_clean();
        }

        $content = $response->getContent();

        return $content === false ? '' : (string) $content;
    }

    /**
     * @return array<string, string>
     */
    private static function headers(Response $response): array
    {
        $headers = [];

        foreach ($response->headers->all() as $name => $values) {
            if (in_array($name, ['date', 'set-cookie'], true)) {
                continue;
            }

            $headers[$name] = implode("\n", (array) $values);
        }

        return $headers;
    }

    private static function addresses($mailable): string
    {
        $to = property_exists($mailable, 'to') ? (array) $mailable->to : [];
        $addresses = [];

        foreach ($to as $recipient) {
            $addresses[] = is_array($recipient) && isset($recipient['address']) ? $recipient['address'] : (is_string($recipient) ? $recipient : '?');
        }

        return $addresses === [] ? '(nobody)' : implode(', ', $addresses);
    }

    /**
     * @return mixed
     */
    private static function property($object, string $name)
    {
        try {
            $property = new \ReflectionProperty($object, $name);
            if (PHP_VERSION_ID < 80100) {
                $property->setAccessible(true); // a no-op and deprecated since PHP 8.1
            }

            return $property->getValue($object);
        } catch (\ReflectionException $e) {
            return [];
        }
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    private function option(string $key, $default = null)
    {
        return array_key_exists($key, $this->config) ? $this->config[$key] : $default;
    }
}

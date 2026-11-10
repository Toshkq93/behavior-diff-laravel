<?php

namespace Toshkq93\BehaviorDiffLaravel\Discovery;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Toshkq93\BehaviorDiff\Discovery\Discovery;
use Toshkq93\BehaviorDiff\Discovery\Endpoint;
use Toshkq93\BehaviorDiff\Discovery\Param;

/**
 * Endpoints of a Laravel application, read from its router:
 *
 *  - path parameters bound to models get real keys from the database;
 *  - request fields come from FormRequest rules, laravel-data objects,
 *    inline $request->validate([...]) and $request->input('...') calls;
 *  - routes behind auth middleware are called as a guest and as the
 *    configured users.
 */
final class RouteDiscovery implements Discovery
{
    const READ_METHODS = ['GET', 'HEAD', 'DELETE', 'OPTIONS'];

    const INPUT_CALLS = 'input|get|query|post|string|str|integer|float|boolean|date|enum|has|filled|missing|exists|only|except|file';

    /** @var Application */
    private $app;

    /** @var array<string, mixed> */
    private $config;

    /** @var RuleParser */
    private $rules;

    /**
     * @param array<string, mixed> $config the behavior-diff config
     */
    public function __construct(Application $app, array $config)
    {
        $this->app = $app;
        $this->config = $config;
        $this->rules = new RuleParser();
    }

    public function discover(): array
    {
        $endpoints = [];
        $users = null;
        $methods = array_map('strtoupper', isset($this->config['routes']['methods']) ? (array) $this->config['routes']['methods'] : ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);

        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $uri = '/'.ltrim($route->uri(), '/');

            if ($this->excluded(ltrim($route->uri(), '/'))) {
                continue;
            }

            $routeMethods = array_values(array_intersect($methods, array_map('strtoupper', $route->methods())));

            if ($routeMethods === []) {
                continue;
            }

            $signature = $this->signature($route);
            $path = $this->pathParams($route, $signature);
            $protected = $this->isProtected($route);

            if ($protected && $users === null) {
                $users = $this->users();
            }

            foreach ($routeMethods as $method) {
                $in = in_array($method, self::READ_METHODS, true) ? Param::IN_QUERY : Param::IN_BODY;
                $params = array_merge($path, $this->inputParams($route, $signature, $in, array_map(function (Param $p) {
                    return $p->name;
                }, $path)));

                $endpoints[] = new Endpoint(
                    $method,
                    $uri,
                    $params,
                    $protected ? array_merge([null], (array) $users) : [null],
                    $route->getActionName()
                );
            }
        }

        usort($endpoints, function (Endpoint $a, Endpoint $b) {
            return [$a->uri, $a->method] <=> [$b->uri, $b->method];
        });

        return $endpoints;
    }

    private function excluded(string $uri): bool
    {
        $routes = isset($this->config['routes']) ? (array) $this->config['routes'] : [];
        $only = isset($routes['only']) ? (array) $routes['only'] : [];
        $except = isset($routes['except']) ? (array) $routes['except'] : [];

        foreach ($except as $pattern) {
            if (fnmatch($pattern, $uri)) {
                return true;
            }
        }

        if ($only === []) {
            return false;
        }

        foreach ($only as $pattern) {
            if (fnmatch($pattern, $uri)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parameters of the controller action / closure.
     *
     * @return list<\ReflectionParameter>
     */
    private function signature(Route $route): array
    {
        try {
            return $route->signatureParameters();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param list<\ReflectionParameter> $signature
     * @return list<Param>
     */
    private function pathParams(Route $route, array $signature): array
    {
        $params = [];

        foreach ($route->parameterNames() as $name) {
            $model = null;

            foreach ($signature as $parameter) {
                $class = self::className($parameter);

                if ($parameter->getName() === $name && $class !== null && is_subclass_of($class, UrlRoutable::class)) {
                    $model = $class;
                }
            }

            $values = $model !== null && is_subclass_of($model, Model::class) ? $this->modelKeys($model) : [];
            $type = $values !== [] && self::allInts($values) ? Param::TYPE_INT : ($model !== null ? Param::TYPE_STRING : Param::guessType($name));
            $optional = strpos($route->uri(), '{'.$name.'?}') !== false;

            $params[] = new Param($name, Param::IN_PATH, $type, ! $optional, $values);
        }

        return $params;
    }

    /**
     * @param list<\ReflectionParameter> $signature
     * @param list<string>               $skip path parameter names
     * @return list<Param>
     */
    private function inputParams(Route $route, array $signature, string $in, array $skip): array
    {
        $rules = [];

        foreach ($signature as $parameter) {
            $class = self::className($parameter);

            if ($class === null) {
                continue;
            }

            if (is_subclass_of($class, FormRequest::class)) {
                $rules = array_merge($rules, $this->formRequestRules($route, $class));
            } elseif (method_exists($class, 'getValidationRules')) {
                // spatie/laravel-data objects
                try {
                    $rules = array_merge($rules, (array) $class::getValidationRules([]));
                } catch (\Throwable $e) {
                    // Rules that need a payload.
                }
            }
        }

        $source = $this->actionSource($route);
        $rules = array_merge($this->inlineRules($source), $rules);

        $params = [];

        foreach ($this->rules->parse($rules, $in) as $param) {
            $params[$param->name] = $param;
        }

        foreach ($this->inputCalls($source) as $name => $type) {
            if (! isset($params[$name])) {
                $params[$name] = new Param($name, $in, $type !== null ? $type : Param::guessType($name));
            }
        }

        return array_values(array_filter($params, function (Param $param) use ($skip) {
            return ! in_array($param->name, $skip, true);
        }));
    }

    /**
     * @return array<string, mixed>
     */
    private function formRequestRules(Route $route, string $class): array
    {
        try {
            $request = $class::createFrom(Request::create('/'.ltrim($route->uri(), '/')));
            $request->setContainer($this->app);
            $request->setRouteResolver(function () use ($route) {
                return $route;
            });

            return method_exists($request, 'rules') ? (array) $this->app->call([$request, 'rules']) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function actionSource(Route $route): string
    {
        try {
            $uses = $route->getAction('uses');

            if ($uses instanceof \Closure) {
                $reflection = new \ReflectionFunction($uses);
            } elseif (is_string($uses) && strpos($uses, '@') !== false) {
                list($class, $method) = explode('@', $uses, 2);
                $reflection = new \ReflectionMethod($class, $method);
            } elseif (is_string($uses) && method_exists($uses, '__invoke')) {
                $reflection = new \ReflectionMethod($uses, '__invoke');
            } else {
                return '';
            }

            $file = $reflection->getFileName();

            if ($file === false || ! is_file($file)) {
                return '';
            }

            $lines = file($file);

            return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 'title' => 'required|max:255' pairs inside $request->validate([...]),
     * $this->validate($request, [...]) and Validator::make(..., [...]).
     *
     * @return array<string, string>
     */
    private function inlineRules(string $source): array
    {
        if ($source === '' || ! preg_match('/validate\s*\(|Validator::make\s*\(/', $source)) {
            return [];
        }

        preg_match_all('/[\'"]([\w.*-]+)[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $source, $matches, PREG_SET_ORDER);
        $rules = [];

        foreach ($matches as $match) {
            if (preg_match('/(^|\|)(required|nullable|sometimes|string|integer|numeric|email|boolean|date|array|max:|min:|in:|exists:|unique:|confirmed)/', $match[2])) {
                $rules[$match[1]] = $match[2];
            }
        }

        return $rules;
    }

    /**
     * $request->input('q'), $request->integer('page'), request('sort').
     *
     * @return array<string, string|null> name => type implied by the call
     */
    private function inputCalls(string $source): array
    {
        if ($source === '') {
            return [];
        }

        $names = [];
        $types = ['integer' => Param::TYPE_INT, 'float' => Param::TYPE_FLOAT, 'boolean' => Param::TYPE_BOOL, 'date' => Param::TYPE_DATE];

        preg_match_all('/(?:\$request|request\(\))\s*->\s*('.self::INPUT_CALLS.')\(\s*[\'"]([\w-]+)[\'"]/', $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $names[$match[2]] = isset($types[$match[1]]) ? $types[$match[1]] : (isset($names[$match[2]]) ? $names[$match[2]] : null);
        }

        preg_match_all('/\brequest\(\s*[\'"]([\w-]+)[\'"]/', $source, $matches);

        foreach ($matches[1] as $name) {
            $names += [$name => null];
        }

        return $names;
    }

    private function isProtected(Route $route): bool
    {
        try {
            $middleware = $route->gatherMiddleware();
        } catch (\Throwable $e) {
            return false;
        }

        foreach ($middleware as $name) {
            if (! is_string($name)) {
                continue;
            }

            if (preg_match('/^(auth|can|verified|password\.confirm|abilities|ability)(:|$)/', $name)
                || preg_match('/\\\\(Authenticate|Authorize|EnsureEmailIsVerified)(:|$)/', $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int|string>
     */
    private function users(): array
    {
        if (isset($this->config['users']) && is_array($this->config['users'])) {
            return array_values(array_filter($this->config['users'], function ($user) {
                return $user !== null;
            }));
        }

        $guards = isset($this->config['guards']) ? (array) $this->config['guards'] : ['web'];
        $provider = $this->app['config']->get('auth.guards.'.$guards[0].'.provider');
        $model = $this->app['config']->get('auth.providers.'.$provider.'.model');

        if (! is_string($model) || ! class_exists($model)) {
            return [];
        }

        $keys = $this->modelKeys($model, 1, true);

        return $keys;
    }

    /**
     * @return list<int|string>
     */
    private function modelKeys(string $class, int $limit = 2, bool $primary = false): array
    {
        try {
            /** @var Model $model */
            $model = new $class();
            $column = $primary ? $model->getKeyName() : $model->getRouteKeyName();

            return array_values($class::query()->orderBy($model->getKeyName())->limit($limit)->pluck($column)->all());
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function className(\ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()) {
            return $type->getName();
        }

        return null;
    }

    /** @param list<mixed> $values */
    private static function allInts(array $values): bool
    {
        foreach ($values as $value) {
            if (! is_int($value) && ! (is_string($value) && preg_match('/^\d+$/', $value))) {
                return false;
            }
        }

        return true;
    }
}

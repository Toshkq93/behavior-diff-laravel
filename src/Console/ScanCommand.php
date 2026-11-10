<?php

namespace Toshkq93\BehaviorDiffLaravel\Console;

use Illuminate\Console\Command;
use Toshkq93\BehaviorDiff\Cases\CaseBuilder;
use Toshkq93\BehaviorDiff\Cases\CaseFile;
use Toshkq93\BehaviorDiff\Cases\RequestCase;
use Toshkq93\BehaviorDiff\Discovery\Param;
use Toshkq93\BehaviorDiffLaravel\Discovery\RouteDiscovery;

class ScanCommand extends Command
{
    use ResolvesPaths;

    protected $signature = 'behavior-diff:scan
        {--out= : Cases file (default: config behavior-diff.cases)}
        {--force : Drop hand-written cases too}';

    protected $description = 'Find the routes and write the requests behavior-diff will run';

    public function handle()
    {
        $config = (array) $this->laravel['config']->get('behavior-diff', []);
        $endpoints = (new RouteDiscovery($this->laravel, $config))->discover();
        $cases = (new CaseBuilder(
            isset($config['values']) ? (array) $config['values'] : [],
            isset($config['max_cases_per_endpoint']) ? (int) $config['max_cases_per_endpoint'] : 40
        ))->build($endpoints);

        $path = $this->option('out') ?: $this->projectPath(isset($config['cases']) ? (string) $config['cases'] : 'behavior-diff/cases.json');

        if (is_file($path) && ! $this->option('force')) {
            $manual = array_filter(CaseFile::read($path), function (RequestCase $case) {
                return $case->label === '' || $case->scenario !== null;
            });
            $cases = array_merge($cases, array_values($manual));
        }

        CaseFile::write($path, $cases);

        $rows = [];

        foreach ($endpoints as $endpoint) {
            $rows[] = [
                $endpoint->name(),
                implode(', ', array_map(function (Param $param) {
                    return $param->name.':'.$param->type.($param->values !== [] ? '='.implode('|', array_slice(array_map('strval', $param->values), 0, 3)) : '');
                }, array_values($endpoint->params))),
                count($endpoint->users) > 1 ? 'guest, '.implode(', ', array_filter($endpoint->users, function ($u) {
                    return $u !== null;
                })) : 'guest',
            ];
        }

        $this->table(['Endpoint', 'Parameters', 'Called as'], $rows);
        $this->info(count($endpoints).' endpoints, '.count($cases).' cases → '.$path);

        return 0;
    }
}

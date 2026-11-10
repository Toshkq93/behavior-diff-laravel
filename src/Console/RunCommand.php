<?php

namespace Toshkq93\BehaviorDiffLaravel\Console;

use Illuminate\Console\Command;
use Toshkq93\BehaviorDiff\Cases\CaseFile;
use Toshkq93\BehaviorDiff\Cases\RequestCase;
use Toshkq93\BehaviorDiff\Result\CaseResult;
use Toshkq93\BehaviorDiff\Result\ResultFile;
use Toshkq93\BehaviorDiffLaravel\Runner\KernelRunner;

class RunCommand extends Command
{
    use ResolvesPaths;

    protected $signature = 'behavior-diff:run
        {--out= : Where to write the results, e.g. behavior-diff/old.jsonl}
        {--cases= : Cases file (default: config behavior-diff.cases)}
        {--only= : Only endpoints matching the pattern ("GET /api/*") or one case id}
        {--label= : Free text stored with the results}';

    protected $description = 'Run the behavior-diff requests inside the application and save what it did';

    public function handle()
    {
        $out = $this->option('out');

        if (! $out) {
            $this->error('Missing --out=<file>: where to write the results (e.g. behavior-diff/old.jsonl).');

            return 2;
        }

        $config = (array) $this->laravel['config']->get('behavior-diff', []);
        $casesPath = $this->casesPath($config);
        $cases = CaseFile::read($casesPath);

        if ($only = $this->option('only')) {
            $cases = array_values(array_filter($cases, function (RequestCase $case) use ($only) {
                return fnmatch($only, $case->endpoint) || $case->id === $only;
            }));
        }

        $results = new ResultFile($out, [
            'label' => $this->option('label'),
            'root' => $this->laravel->basePath(),
            'laravel' => $this->laravel->version(),
        ]);

        $bar = $this->output->createProgressBar(count($cases));
        $statuses = [];

        try {
            (new KernelRunner($this->laravel, $config))->run($cases, $results, function (RequestCase $case, CaseResult $result) use ($bar, &$statuses) {
                $statuses[$result->status] = isset($statuses[$result->status]) ? $statuses[$result->status] + 1 : 1;
                $bar->advance();
            });
        } finally {
            $results->close();
            $bar->finish();
            $this->line('');
        }

        ksort($statuses);
        $summary = [];

        foreach ($statuses as $status => $count) {
            $summary[] = ($status === 0 ? 'error' : $status).': '.$count;
        }

        $this->info(count($cases).' cases on PHP '.PHP_VERSION.', Laravel '.$this->laravel->version().' → '.$out);
        $this->line('Statuses: '.implode(', ', $summary));

        return 0;
    }
}

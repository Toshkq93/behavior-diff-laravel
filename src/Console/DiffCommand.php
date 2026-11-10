<?php

namespace Toshkq93\BehaviorDiffLaravel\Console;

use Illuminate\Console\Command;
use Toshkq93\BehaviorDiff\Cases\CaseFile;
use Toshkq93\BehaviorDiff\Diff\Differ;
use Toshkq93\BehaviorDiff\Diff\Difference;
use Toshkq93\BehaviorDiff\Diff\Normalizer;
use Toshkq93\BehaviorDiff\Report\ConsoleReport;
use Toshkq93\BehaviorDiff\Report\HtmlReport;
use Toshkq93\BehaviorDiff\Report\Summary;
use Toshkq93\BehaviorDiff\Result\ResultFile;

class DiffCommand extends Command
{
    use ResolvesPaths;

    protected $signature = 'behavior-diff:diff
        {old : Results of the old version}
        {new : Results of the new version}
        {--noise= : A second run of the old version: what differs there is ignored}
        {--html= : Also write an HTML report}
        {--cases= : Cases file (default: config behavior-diff.cases)}
        {--fail-on=medium : Exit with 1 from this level: critical, high, medium, low, never}';

    protected $description = 'Compare two behavior-diff runs';

    public function handle()
    {
        $config = (array) $this->laravel['config']->get('behavior-diff', []);
        $cases = CaseFile::read($this->casesPath($config));

        $old = ResultFile::read($this->argument('old'));
        $new = ResultFile::read($this->argument('new'));
        $noise = $this->option('noise') ? ResultFile::read($this->option('noise')) : null;

        $normalizer = (new Normalizer(isset($config['masks']) ? (array) $config['masks'] : []))
            ->forRuns(array_filter([$old['meta'], $new['meta'], $noise !== null ? $noise['meta'] : null]));

        $summary = new Summary(
            (new Differ($normalizer))->compare($cases, $old['results'], $new['results'], $noise !== null ? $noise['results'] : null),
            $old['meta'],
            $new['meta']
        );

        $this->output->write((new ConsoleReport($this->output->isDecorated()))->render($summary));

        if ($html = $this->option('html')) {
            CaseFile::ensureDirectory($html);
            file_put_contents($html, (new HtmlReport())->render($summary));
            $this->line('');
            $this->info('HTML report: '.$html);
        }

        $failOn = (string) $this->option('fail-on');
        $levels = array_flip(Difference::NAMES);

        if ($failOn === 'never') {
            return 0;
        }

        return $summary->failed(isset($levels[$failOn]) ? $levels[$failOn] : Difference::MEDIUM) ? 1 : 0;
    }
}

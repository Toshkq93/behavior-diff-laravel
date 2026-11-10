<?php

namespace Toshkq93\BehaviorDiffLaravel\Console;

trait ResolvesPaths
{
    /** Project-relative paths are resolved against base_path(); absolute ones are kept. */
    protected function projectPath(string $path): string
    {
        if ($path !== '' && ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path))) {
            return $path;
        }

        return $this->laravel->basePath().DIRECTORY_SEPARATOR.$path;
    }

    protected function casesPath(array $config): string
    {
        return $this->option('cases') ?: $this->projectPath(isset($config['cases']) ? (string) $config['cases'] : 'behavior-diff/cases.json');
    }
}

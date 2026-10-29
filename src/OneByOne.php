<?php

declare(strict_types=1);

namespace DeployTool;

/** The batch methods of Transport, done one file at a time with the single-file methods. */
trait OneByOne
{
    public function listDetailedMany(array $dirs): array
    {
        $listings = [];
        foreach ($dirs as $dir) {
            $listings[$dir] = $this->listDetailed($dir);
        }
        return $listings;
    }

    public function getMany(array $paths): array
    {
        $contents = [];
        foreach ($paths as $path) {
            $contents[$path] = $this->get($path);
        }
        return $contents;
    }

    public function putMany(array $files): array
    {
        $errors = [];
        foreach ($files as $path => $contents) {
            try {
                $this->put((string) $path, $contents);
            } catch (\RuntimeException $e) {
                $errors[$path] = $e->getMessage();
            }
        }
        return $errors;
    }

    public function deleteMany(array $paths): array
    {
        $errors = [];
        foreach ($paths as $path) {
            try {
                $this->delete($path);
            } catch (\RuntimeException $e) {
                $errors[$path] = $e->getMessage();
            }
        }
        return $errors;
    }
}

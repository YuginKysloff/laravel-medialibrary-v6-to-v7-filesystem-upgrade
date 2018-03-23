<?php

namespace Spatie\MedialibraryV7UpgradeTool\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Console\ConfirmableTrait;

class UpgradeMediaCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'upgrade-media 
    {disk? : Disk to use}
    {--d|dry-run : List files that will be renamed without renaming them}
    {--f|force : Force the operation to run when in production}';

    protected $description = 'Update the names of the version 6 files of spatie/laravel-medialibrary';

    /** @var string */
    protected $disk;

    /** @var string */
    protected $isDryRun;

    /** @var \Illuminate\Support\Collection */
    protected $mediaFilesToChange;

    public function handle()
    {
        if (! $this->confirmToProceed()) {
            return;
        }

        $this->isDryRun = $this->option('dry-run') ?? false;

        $this->disk = $this->argument('disk') ?? config('medialibrary.default_filesystem');

        $this
            ->getMediaFilesToBeRenamed()
            ->renameMediaFiles();

        $this->info('All done!');
    }

    protected function getMediaFilesToBeRenamed(): self
    {
        $this->mediaFilesToChange = collect(Storage::disk($this->disk)->allFiles())
            ->filter(function (string $file): bool {
                return $this->hasOriginal($file);
            })
            ->filter(function (string $file): bool {
                return $this->needsToBeConverted($file);
            })
            ->map(function (string $file): array {
                return $this->getReplaceArray($file);
            });

        return $this;
    }

    protected function hasOriginal(string $filePath): bool
    {
        $path = pathinfo($filePath, PATHINFO_DIRNAME);

        $oneLevelHigher = dirname($path);

        if ($oneLevelHigher === '.') {
            return false;
        }

        $original = Storage::disk($this->disk)->files($oneLevelHigher);

        if (count($original) !== 1) {
            return false;
        }

        return true;
    }

    protected function getOriginal(string $filePath): string
    {
        $path = pathinfo($filePath, PATHINFO_DIRNAME);

        $oneLevelHigher = dirname($path);

        $original = Storage::disk($this->disk)->files($oneLevelHigher);

        return $original[0];
    }

    protected function needsToBeConverted(string $file): bool
    {
        $currentFile = pathinfo($file, PATHINFO_BASENAME);

        $original = $this->getOriginal($file);

        $originalName = pathinfo($original, PATHINFO_FILENAME);

        return strpos($currentFile, $originalName) === false;
    }

    protected function getReplaceArray(string $file): array
    {
        $currentFilePath = pathinfo($file);

        $original = $this->getOriginal($file);

        $originalFilePath = pathinfo($original);

        return [
            'current' => $file,
            'replacement' => "{$currentFilePath['dirname']}/{$originalFilePath['filename']}-{$currentFilePath['basename']}",
        ];

    }

    protected function renameMediaFiles()
    {
        $progressBar = $this->output->createProgressBar($this->mediaFilesToChange->count());

        $this->mediaFilesToChange->each(function (array $filePaths) use ($progressBar) {
            if ($this->isDryRun) {
                $this->info('This is a dry-run and will not actually rename the files');
            }

            if (! $this->isDryRun){
                Storage::disk($this->disk)->move($filePaths['current'], $filePaths['replacement']);
            }

            $this->comment("The file `{$filePaths['current']}` has become `{$filePaths['replacement']}`");

            $progressBar->advance();
        });

        $progressBar->finish();

        $this->output->newLine();
    }
}

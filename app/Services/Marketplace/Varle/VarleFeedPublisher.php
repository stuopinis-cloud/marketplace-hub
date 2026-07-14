<?php

namespace App\Services\Marketplace\Varle;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class VarleFeedPublisher
{
    public function __construct(
        private readonly VarleXmlExporter $exporter,
        private readonly VarleXmlFeedValidator $validator,
    ) {}

    public function publish(bool $debug = false): VarleExportResult
    {
        $channel = $this->exporter->resolveChannelForPublishing();
        $config = $this->exporter->channelConfigForPublishing($channel);
        $finalRelativePath = $this->exporter->feedRelativePathForPublishing($config);
        $tempRelativePath = $this->tempRelativePath($finalRelativePath);
        $disk = Storage::disk('public');

        $disk->makeDirectory('feeds');
        $disk->delete($tempRelativePath);

        try {
            $draftResult = $this->exporter->export(
                debug: $debug,
                relativePath: $tempRelativePath,
                registerFeedFile: false,
            );

            $tempAbsolutePath = $disk->path($tempRelativePath);
            $validation = $this->validator->validate($tempAbsolutePath);

            if (! $validation->valid) {
                $disk->delete($tempRelativePath);
                $this->exporter->markExportPublicationFailed($draftResult->syncJobId, $validation->message());

                throw new RuntimeException($validation->message());
            }

            $this->atomicallyReplace($tempAbsolutePath, $disk->path($finalRelativePath));
            $publicUrl = url('/feeds/'.basename($finalRelativePath));
            $this->exporter->registerPublishedFeed($channel, $config, $finalRelativePath, $publicUrl, $draftResult->syncJobId);

            return new VarleExportResult(
                syncJobId: $draftResult->syncJobId,
                exportedVariants: $draftResult->exportedVariants,
                skippedVariants: $draftResult->skippedVariants,
                feedPath: $disk->path($finalRelativePath),
                publicUrl: $publicUrl,
                debugLines: $draftResult->debugLines,
            );
        } catch (Throwable $exception) {
            if ($disk->exists($tempRelativePath)) {
                $disk->delete($tempRelativePath);
            }

            throw $exception;
        }
    }

    private function tempRelativePath(string $finalRelativePath): string
    {
        $configured = (string) config('marketplace.exports.varle.feed_temp_path', '');

        if ($configured !== '') {
            return ltrim($configured, '/');
        }

        $directory = trim(str_replace('\\', '/', dirname($finalRelativePath)), '.');
        $filename = basename($finalRelativePath).'.tmp';

        return $directory === '' ? $filename : $directory.'/'.$filename;
    }

    private function atomicallyReplace(string $tempAbsolutePath, string $finalAbsolutePath): void
    {
        File::ensureDirectoryExists(dirname($finalAbsolutePath));

        if (! File::exists($tempAbsolutePath)) {
            throw new RuntimeException('Temporary Varle feed file is missing before publication.');
        }

        $contents = File::get($tempAbsolutePath);
        File::replace($finalAbsolutePath, $contents);
        File::delete($tempAbsolutePath);
    }
}

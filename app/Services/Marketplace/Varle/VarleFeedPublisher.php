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

        $syncJobId = null;
        $finalized = false;
        $publicUrl = null;

        try {
            $draftResult = $this->exporter->export(
                debug: $debug,
                relativePath: $tempRelativePath,
                registerFeedFile: false,
            );

            $syncJobId = $draftResult->syncJobId;
            $tempAbsolutePath = $disk->path($tempRelativePath);

            $this->exporter->updateSyncJobStageById($syncJobId, 'validating');
            $validation = $this->validator->validate($tempAbsolutePath);

            if (! $validation->valid) {
                $disk->delete($tempRelativePath);
                $this->exporter->markExportPublicationFailed($syncJobId, $validation->message());
                $finalized = true;

                throw new RuntimeException($validation->message());
            }

            $this->exporter->updateSyncJobStageById($syncJobId, 'publishing');
            $this->atomicallyReplace($tempAbsolutePath, $disk->path($finalRelativePath));
            $publicUrl = url('/feeds/'.basename($finalRelativePath));
            $this->exporter->registerPublishedFeed($channel, $config, $finalRelativePath, $publicUrl, $syncJobId);
            $finalized = true;

            return new VarleExportResult(
                syncJobId: $draftResult->syncJobId,
                exportedVariants: $draftResult->exportedVariants,
                skippedVariants: $draftResult->skippedVariants,
                feedPath: $disk->path($finalRelativePath),
                publicUrl: $publicUrl,
                debugLines: $draftResult->debugLines,
            );
        } catch (Throwable $exception) {
            if ($syncJobId !== null && ! $finalized && ! $this->exporter->isSyncJobFinalized($syncJobId)) {
                $this->exporter->failExportSyncJob($syncJobId, $exception);
                $finalized = true;
            }

            if ($disk->exists($tempRelativePath)) {
                $disk->delete($tempRelativePath);
            }

            throw $exception;
        } finally {
            if ($syncJobId !== null && ! $finalized && ! $this->exporter->isSyncJobFinalized($syncJobId)) {
                $this->exporter->ensureSyncJobFinalized(
                    $syncJobId,
                    $finalRelativePath,
                    $publicUrl,
                );
            }
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

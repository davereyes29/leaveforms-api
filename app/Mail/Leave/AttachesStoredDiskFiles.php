<?php

namespace App\Mail\Leave;

use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Storage;

trait AttachesStoredDiskFiles
{
    /**
     * @param  iterable<int, object{path: string, original_name?: string|null}>  $files
     * @return list<Attachment>
     */
    private function attachmentsFromStoredFiles(iterable $files): array
    {
        $disk = (string) config('leave.attachments.disk', 'local');
        $used = [];
        $out = [];

        foreach ($files as $file) {
            $path = (string) ($file->path ?? '');

            if ($path === '' || ! Storage::disk($disk)->exists($path)) {
                continue;
            }

            $as = $this->uniqueStoredAttachmentName((string) ($file->original_name ?? ''), $path, $used);
            $out[] = Attachment::fromStorageDisk($disk, $path)->as($as);
        }

        return $out;
    }

    /**
     * @param  array<string, true>  $used
     */
    private function uniqueStoredAttachmentName(string $originalName, string $path, array &$used): string
    {
        $name = basename(str_replace(["\0", '/', '\\'], '', $originalName));

        if ($name === '') {
            $name = basename($path);
        }

        if ($name === '') {
            $name = 'attachment';
        }

        $candidate = $name;
        $n = 2;

        while (isset($used[strtolower($candidate)])) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $stem = pathinfo($name, PATHINFO_FILENAME);
            $candidate = $extension !== ''
                ? $stem.'-'.$n.'.'.$extension
                : $stem.'-'.$n;
            $n++;
        }

        $used[strtolower($candidate)] = true;

        return $candidate;
    }
}

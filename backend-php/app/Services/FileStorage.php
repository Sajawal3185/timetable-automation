<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mime\MimeTypes;

/**
 * File uploads for exam papers and lecture materials (backend/app/utils/file_storage.py).
 *
 * Files go to the disk named in config('timetable.uploads.disk') (local for now,
 * the S3 driver later). The database stores only a RELATIVE path. Stored names are
 * random hex, so users cannot overwrite each other and a hostile file name can
 * never escape the upload folder.
 *
 * A file is accepted only if its first bytes match its extension (an "x.pdf" that
 * is really an .exe is refused), not just because of what it is called.
 */
class FileStorage
{
    public const CHUNK = 1024 * 1024;

    public const PAPER_EXTENSIONS = ['.pdf', '.png', '.jpg', '.jpeg', '.doc', '.docx'];

    public const MATERIAL_EXTENSIONS = ['.pdf', '.ppt', '.pptx', '.doc', '.docx', '.txt', '.zip', '.png', '.jpg', '.jpeg'];

    private const OLE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";   // old Word / PowerPoint (.doc / .ppt)

    private const ZIP = "PK\x03\x04";                          // .docx / .pptx / .zip are zip files

    /**
     * The bytes a genuine file of each type starts with. Every extension in the two
     * lists above needs a rule here (or the special case for .txt); an extension
     * with no rule is refused.
     */
    public const SIGNATURES = [
        '.pdf' => ['%PDF-'],
        '.png' => ["\x89PNG\r\n\x1a\n"],
        '.jpg' => ["\xFF\xD8\xFF"],
        '.jpeg' => ["\xFF\xD8\xFF"],
        '.docx' => [self::ZIP],
        '.pptx' => [self::ZIP],
        '.zip' => [self::ZIP],
        '.doc' => [self::OLE, '{\\rtf'],   // some tools save RTF with a .doc name
        '.ppt' => [self::OLE],
    ];

    /** The disk uploads live on. Read at call time so tests can use Storage::fake('local'). */
    public static function disk()
    {
        return Storage::disk(config('timetable.uploads.disk', 'local'));
    }

    /** MAX_UPLOAD_MB, read at call time so a test can change it with config()->set(). */
    public static function maxUploadMb(): int
    {
        return (int) config('timetable.uploads.max_mb', 20);
    }

    /** Python _clean_name: last path part only, odd characters become "_", at most 200 characters. */
    public static function cleanName(?string $name): string
    {
        $name = ($name === null || $name === '') ? 'file' : str_replace('\\', '/', $name);
        $parts = explode('/', $name);
        $name = end($parts);

        $clean = preg_replace('/[^A-Za-z0-9._ -]/u', '_', $name);
        if ($clean === null) {   // not valid UTF-8: fall back to bytes
            $clean = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);
        }
        $clean = mb_substr($clean, 0, 200);

        return $clean === '' ? 'file' : $clean;
    }

    /** Python Path(name).suffix.lower(): ".pdf" for "a.pdf", "" for "a", ".pdf" (dotfile) and "a.". */
    public static function extension(string $name): string
    {
        $i = strrpos($name, '.');
        if ($i === false || $i === 0 || $i === strlen($name) - 1) {
            return '';
        }

        return strtolower(substr($name, $i));
    }

    /** True if the first bytes of a file are what a file with this extension (with the dot) starts with. */
    public static function contentMatchesExtension(string $ext, string $head): bool
    {
        if ($ext === '.txt') {
            // Plain text has no signature: refuse binary data (NUL bytes), allow UTF-16 text (it has a BOM).
            // Known limit (same as Python, fine for the demo): a UTF-16 BOM followed by ANY binary data also
            // passes as .txt. Downloads are served as attachments, so such a file is only ever saved.
            return ! str_contains($head, "\x00")
                || str_starts_with($head, "\xFF\xFE")
                || str_starts_with($head, "\xFE\xFF");
        }
        foreach (self::SIGNATURES[$ext] ?? [] as $signature) {
            if (str_starts_with($head, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate (extension, then content, then size) and store an upload. Aborts with
     * 400 / 413 on bad input, before anything is written, so a refused file leaves nothing behind.
     */
    public static function save(UploadedFile $file, string $subfolder, array $allowedExtensions): SavedFile
    {
        $original = self::cleanName($file->getClientOriginalName());
        $ext = self::extension($original);
        if (! in_array($ext, $allowedExtensions, true)) {
            $sorted = $allowedExtensions;
            sort($sorted, SORT_STRING);
            // Python prints the sorted list like ['.doc', '.docx', ...]
            abort(400, 'File type '.($ext === '' ? '(none)' : $ext)." not allowed. Allowed: ['".implode("', '", $sorted)."']");
        }

        $size = (int) $file->getSize();
        if ($size === 0) {
            abort(400, 'Uploaded file is empty');
        }
        $handle = fopen($file->getRealPath(), 'rb');
        $head = (string) fread($handle, self::CHUNK);
        fclose($handle);
        if (! self::contentMatchesExtension($ext, $head)) {
            abort(400, "File content does not look like a real $ext file");
        }
        if ($size > self::maxUploadMb() * 1024 * 1024) {
            abort(413, 'File too large (max '.self::maxUploadMb().' MB)');
        }

        $stored = self::disk()->putFileAs($subfolder, $file, bin2hex(random_bytes(16)).$ext);
        if ($stored === false) {
            abort(500, 'Could not save the file');
        }

        return new SavedFile($stored, $original, $file->getClientMimeType(), $size);
    }

    /** Download of a stored file, always as an attachment (never rendered in the page). */
    public static function download(string $relativePath, string $filename): StreamedResponse
    {
        if (! self::isSafePath($relativePath)) {
            abort(400, 'Invalid file path');
        }
        $disk = self::disk();
        if (! $disk->exists($relativePath)) {
            abort(404, 'File is missing on the server');
        }
        $type = MimeTypes::getDefault()->getMimeTypes(ltrim(self::extension($filename), '.'))[0] ?? 'application/octet-stream';

        return $disk->download($relativePath, $filename, ['Content-Type' => $type]);
    }

    /** Never fails: a missing or odd file must not break the request (Python swallows OSError). */
    public static function delete(string $relativePath): void
    {
        try {
            if (self::isSafePath($relativePath)) {
                self::disk()->delete($relativePath);
            }
        } catch (\Throwable) {
        }
    }

    private static function isSafePath(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '..') && ! str_contains($path, "\0");
    }
}

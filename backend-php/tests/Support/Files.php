<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/** Fake file contents that start like the real thing (the first bytes are all the check looks at). */
final class Files
{
    public static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< >>\nendobj\ntrailer\n<< >>\n%%EOF\n";
    }

    public static function png(): string
    {
        return "\x89PNG\r\n\x1a\n".str_repeat("\x00", 16);
    }

    public static function jpeg(): string
    {
        return "\xFF\xD8\xFF\xE0".str_repeat("\x00", 8);
    }

    /** Old Word / PowerPoint. */
    public static function ole(): string
    {
        return "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 8);
    }

    /** Any zip-based file: .pptx, .docx, .zip. */
    public static function pptx(): string
    {
        return "PK\x03\x04".str_repeat("\x00", 20);
    }

    /** A Windows program: what a renamed "x.pdf" really is. */
    public static function exe(): string
    {
        return "MZ\x90\x00".str_repeat("\x00", 16);
    }

    public static function upload(string $name, string $data): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $data);
    }
}

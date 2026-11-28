<?php

namespace Tests\Unit;

use App\Services\FileStorage;
use Tests\Support\Files;
use Tests\TestCase;

/** Port of the first half of backend/tests/test_file_content.py (the check itself, without the API). */
class FileStorageTest extends TestCase
{
    public function test_real_files_match_their_extension(): void
    {
        $good = [
            '.pdf' => Files::pdf(), '.png' => Files::png(), '.jpg' => Files::jpeg(), '.jpeg' => Files::jpeg(),
            '.docx' => Files::pptx(), '.pptx' => Files::pptx(), '.zip' => Files::pptx(),
            '.doc' => Files::ole(), '.ppt' => Files::ole(), '.txt' => "just some notes\n",
        ];
        foreach ($good as $ext => $data) {
            $this->assertTrue(FileStorage::contentMatchesExtension($ext, $data), $ext);
        }
    }

    public function test_wrong_content_is_refused(): void
    {
        $bad = [
            ['.pdf', Files::png()], ['.pdf', Files::exe()], ['.pdf', 'not a pdf at all'], ['.png', Files::pdf()],
            ['.jpg', Files::png()], ['.docx', Files::exe()], ['.pptx', Files::pdf()], ['.doc', Files::pdf()],
            ['.zip', Files::exe()], ['.txt', Files::exe()."\x00"],
        ];
        foreach ($bad as [$ext, $data]) {
            $this->assertFalse(FileStorage::contentMatchesExtension($ext, $data), $ext.' '.bin2hex(substr($data, 0, 8)));
        }
    }

    public function test_text_and_rtf_special_cases(): void
    {
        $this->assertTrue(FileStorage::contentMatchesExtension('.txt', 'héllo'));
        // UTF-16 has NUL bytes but also a BOM
        $this->assertTrue(FileStorage::contentMatchesExtension('.txt', "\xFF\xFE".mb_convert_encoding('hi', 'UTF-16LE', 'UTF-8')));
        $this->assertTrue(FileStorage::contentMatchesExtension('.doc', '{\\rtf1\\ansi hello}'));   // RTF saved as .doc
    }

    public function test_every_allowed_extension_has_a_rule(): void
    {
        // Adding an extension to the allowed lists without a signature would refuse every such upload.
        $allowed = array_unique([...FileStorage::PAPER_EXTENSIONS, ...FileStorage::MATERIAL_EXTENSIONS]);
        foreach (array_diff($allowed, ['.txt']) as $ext) {
            $this->assertArrayHasKey($ext, FileStorage::SIGNATURES, "$ext is allowed but has no signature rule");
        }
        $this->assertFalse(FileStorage::contentMatchesExtension('.exe', Files::exe()));   // unknown extension: refused
    }

    // Extra cases for the helpers PHP needed that Python gets from pathlib / re.

    public function test_a_hostile_file_name_is_cut_down_to_its_last_part(): void
    {
        $this->assertSame('passwd.pdf', FileStorage::cleanName('../../etc/passwd.pdf'));
        $this->assertSame('d.pdf', FileStorage::cleanName('a b/c\\d.pdf'));
        $this->assertSame('_.pdf', FileStorage::cleanName('é.pdf'));
        $this->assertSame('file', FileStorage::cleanName(''));
        $this->assertSame(200, strlen(FileStorage::cleanName(str_repeat('a', 300))));
    }

    public function test_extension_matches_pathlib_suffix(): void
    {
        $this->assertSame('.pdf', FileStorage::extension('Paper.PDF'));
        $this->assertSame('.gz', FileStorage::extension('a.tar.gz'));
        $this->assertSame('', FileStorage::extension('noext'));
        $this->assertSame('', FileStorage::extension('.pdf'));    // a dotfile has no suffix
        $this->assertSame('', FileStorage::extension('trailing.'));
    }
}

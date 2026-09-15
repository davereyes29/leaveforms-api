<?php

namespace Tests\Unit;

use App\Services\Leave\LeaveAttachmentService;
use App\Services\Leave\Legacy\LegacyLeaveAttachmentCopier;
use Tests\TestCase;

class LegacyLeaveAttachmentCopierTest extends TestCase
{
    private LegacyLeaveAttachmentCopier $copier;

    private string $uploadsRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploadsRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'legacy_uploads_'.uniqid('', true);
        mkdir($this->uploadsRoot.'/209/2022-12-12', 0777, true);
        file_put_contents($this->uploadsRoot.'/209/2022-12-12/medcert_leal.pdf', 'fake-pdf');

        config(['leave.legacy.uploads_path' => $this->uploadsRoot]);

        $this->copier = new LegacyLeaveAttachmentCopier(
            $this->app->make(LeaveAttachmentService::class),
        );
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->uploadsRoot);
        parent::tearDown();
    }

    public function test_normalizes_filename_and_rejects_junk(): void
    {
        $this->assertSame('medcert.pdf', $this->copier->normalizeFilename('medcert.pdf'));
        $this->assertSame('Capture - Copy.jpg', $this->copier->normalizeFilename('Capture - Copy.jpg'));
        $this->assertNull($this->copier->normalizeFilename(''));
        $this->assertNull($this->copier->normalizeFilename('null'));
        $this->assertNull($this->copier->normalizeFilename('0'));
        $this->assertNull($this->copier->normalizeFilename('../secret.pdf'));
    }

    public function test_resolves_legacy_upload_path(): void
    {
        $path = $this->copier->resolveSourcePath(209, '2022-12-12', 'medcert_leal.pdf');
        $this->assertNotNull($path);
        $this->assertFileExists($path);

        $this->assertNull($this->copier->resolveSourcePath(209, '2022-12-12', 'missing.pdf'));
        $this->assertNull($this->copier->resolveSourcePath(999, '2022-12-12', 'medcert_leal.pdf'));
    }

    public function test_uploads_root_exists(): void
    {
        $this->assertTrue($this->copier->uploadsRootExists());

        config(['leave.legacy.uploads_path' => $this->uploadsRoot.DIRECTORY_SEPARATOR.'does-not-exist']);
        $this->assertFalse($this->copier->uploadsRootExists());
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->deleteTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}

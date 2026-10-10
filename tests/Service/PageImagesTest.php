<?php

namespace App\Tests\Service;

use App\Service\PageImages;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class PageImagesTest extends TestCase
{
    private string $projectDirectory;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->projectDirectory = sys_get_temp_dir().'/apex-page-images-test-'.bin2hex(random_bytes(8));
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->projectDirectory);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectDirectory);
        parent::tearDown();
    }

    public function testAssetMapperImageTakesPrecedenceOverLegacyPublicImage(): void
    {
        $this->copyImage('assets/images/photo.png');
        $this->copyImage('public/assets/images/photo.png');

        self::assertSame('images/photo.png', (new PageImages($this->projectDirectory))->resolve('photo.png'));
    }

    public function testLegacyPublicImageIsUsedWhenMappedImageIsMissing(): void
    {
        $this->copyImage('public/assets/images/photo.png');

        self::assertSame('assets/images/photo.png', (new PageImages($this->projectDirectory))->resolve('photo.png'));
    }

    public function testLegacyPublicImageIsUsedWhenMappedImageIsInvalid(): void
    {
        $this->filesystem->dumpFile($this->projectDirectory.'/assets/images/photo.png', 'invalid image');
        $this->copyImage('public/assets/images/photo.png');

        self::assertSame('assets/images/photo.png', (new PageImages($this->projectDirectory))->resolve('photo.png'));
    }

    public function testMissingImageReturnsNull(): void
    {
        self::assertNull((new PageImages($this->projectDirectory))->resolve('missing.png'));
    }

    public function testInvalidImagesReturnNull(): void
    {
        $this->filesystem->dumpFile($this->projectDirectory.'/assets/images/photo.png', 'invalid image');
        $this->filesystem->dumpFile($this->projectDirectory.'/public/assets/images/photo.png', 'invalid image');

        self::assertNull((new PageImages($this->projectDirectory))->resolve('photo.png'));
    }

    private function copyImage(string $path): void
    {
        $this->filesystem->dumpFile($this->projectDirectory.'/'.$path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j2ioAAAAASUVORK5CYII=',
            true,
        ));
    }
}

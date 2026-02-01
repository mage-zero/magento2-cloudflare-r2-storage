<?php
namespace MageZero\CloudflareR2\Test\Unit\Plugin\Captcha;

use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2Factory;
use MageZero\CloudflareR2\Plugin\Captcha\DefaultModelPlugin;
use Magento\Captcha\Model\DefaultModel;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\DriverPool;
use Magento\MediaStorage\Helper\File\Storage\Database;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DefaultModelPluginTest extends TestCase
{
    public function testAfterGenerateUploadsCaptchaImageWhenSelected(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isR2Selected')->willReturn(true);
        $config->method('isR2Configured')->willReturn(true);

        $storageModel = $this->createMock(R2::class);
        $storageModel->expects($this->once())
            ->method('saveFile')
            ->with('captcha/admin/632506c5da5a5b62ba1fd15d58416a38.png');

        $database = $this->createMock(Database::class);
        $database->method('getStorageDatabaseModel')->willReturn($storageModel);

        $r2Factory = $this->createMock(R2Factory::class);
        $r2Factory->expects($this->never())->method('create');

        $mediaDirectory = $this->createMock(WriteInterface::class);
        $mediaDirectory->method('getRelativePath')
            ->with('/var/www/html/magento/pub/media/captcha/admin/632506c5da5a5b62ba1fd15d58416a38.png')
            ->willReturn('captcha/admin/632506c5da5a5b62ba1fd15d58416a38.png');
        $mediaDirectory->method('isFile')
            ->with('captcha/admin/632506c5da5a5b62ba1fd15d58416a38.png')
            ->willReturn(true);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')
            ->with(DirectoryList::MEDIA, DriverPool::FILE)
            ->willReturn($mediaDirectory);

        $logger = $this->createMock(LoggerInterface::class);

        $subject = $this->createMock(DefaultModel::class);
        $subject->method('getImgDir')->willReturn('/var/www/html/magento/pub/media/captcha/admin/');
        $subject->method('getId')->willReturn('632506c5da5a5b62ba1fd15d58416a38');
        $subject->method('getSuffix')->willReturn('.png');

        $plugin = new DefaultModelPlugin($config, $database, $r2Factory, $filesystem, $logger);
        $plugin->afterGenerate($subject, null);
    }

    public function testAfterGenerateSkipsWhenR2NotSelectedAndNotConfigured(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isR2Selected')->willReturn(false);
        $config->method('isR2Configured')->willReturn(false);

        $database = $this->createMock(Database::class);
        $database->expects($this->never())->method('getStorageDatabaseModel');

        $r2Factory = $this->createMock(R2Factory::class);
        $r2Factory->expects($this->never())->method('create');

        $mediaDirectory = $this->createMock(WriteInterface::class);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($mediaDirectory);

        $logger = $this->createMock(LoggerInterface::class);

        $subject = $this->createMock(DefaultModel::class);

        $plugin = new DefaultModelPlugin($config, $database, $r2Factory, $filesystem, $logger);
        $plugin->afterGenerate($subject, null);
    }

    public function testAfterGenerateUploadsCaptchaImageWhenConfiguredButNotSelected(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isR2Selected')->willReturn(false);
        $config->method('isR2Configured')->willReturn(true);

        $database = $this->createMock(Database::class);
        $database->expects($this->never())->method('getStorageDatabaseModel');

        $storageModel = $this->createMock(R2::class);
        $storageModel->expects($this->once())
            ->method('saveFile')
            ->with('captcha/admin/632506c5da5a5b62ba1fd15d58416a38.png');

        $r2Factory = $this->createMock(R2Factory::class);
        $r2Factory->expects($this->once())->method('create')->willReturn($storageModel);

        $mediaDirectory = $this->createMock(WriteInterface::class);
        $mediaDirectory->method('getRelativePath')
            ->with('/var/www/html/magento/pub/media/captcha/admin/632506c5da5a5b62ba1fd15d58416a38.png')
            ->willReturn('captcha/admin/632506c5da5a5b62ba1fd15d58416a38.png');
        $mediaDirectory->method('isFile')
            ->with('captcha/admin/632506c5da5a5b62ba1fd15d58416a38.png')
            ->willReturn(true);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')
            ->with(DirectoryList::MEDIA, DriverPool::FILE)
            ->willReturn($mediaDirectory);

        $logger = $this->createMock(LoggerInterface::class);

        $subject = $this->createMock(DefaultModel::class);
        $subject->method('getImgDir')->willReturn('/var/www/html/magento/pub/media/captcha/admin/');
        $subject->method('getId')->willReturn('632506c5da5a5b62ba1fd15d58416a38');
        $subject->method('getSuffix')->willReturn('.png');

        $plugin = new DefaultModelPlugin($config, $database, $r2Factory, $filesystem, $logger);
        $plugin->afterGenerate($subject, null);
    }
}

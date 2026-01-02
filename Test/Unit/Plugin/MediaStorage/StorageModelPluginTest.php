<?php
namespace MageZero\CloudflareR2\Test\Unit\Plugin\MediaStorage;

use MageZero\CloudflareR2\Model\MediaStorage\File\Storage as R2Storage;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2Factory;
use MageZero\CloudflareR2\Plugin\MediaStorage\StorageModelPlugin;
use Magento\MediaStorage\Helper\File\Storage as StorageHelper;
use Magento\MediaStorage\Model\File\Storage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StorageModelPluginTest extends TestCase
{
    private StorageHelper|MockObject $storageHelper;
    private R2Factory|MockObject $r2Factory;
    private R2|MockObject $r2Model;
    private StorageModelPlugin $plugin;
    private Storage|MockObject $subject;

    protected function setUp(): void
    {
        $this->storageHelper = $this->createMock(StorageHelper::class);
        $this->r2Factory = $this->createMock(R2Factory::class);
        $this->r2Model = $this->createMock(R2::class);
        $this->subject = $this->createMock(Storage::class);

        $this->r2Factory->method('create')->willReturn($this->r2Model);

        $this->plugin = new StorageModelPlugin(
            $this->storageHelper,
            $this->r2Factory
        );
    }

    public function testAroundGetStorageModelReturnsProceedResultWhenNotFalse(): void
    {
        $existingModel = $this->createMock(R2::class);
        $proceed = fn() => $existingModel;

        $result = $this->plugin->aroundGetStorageModel($this->subject, $proceed);

        $this->assertSame($existingModel, $result);
    }

    public function testAroundGetStorageModelCreatesR2ModelWhenProceedReturnsFalse(): void
    {
        $proceed = fn() => false;

        $this->storageHelper->method('getCurrentStorageCode')
            ->willReturn(R2Storage::STORAGE_MEDIA_R2);

        $result = $this->plugin->aroundGetStorageModel($this->subject, $proceed);

        $this->assertSame($this->r2Model, $result);
    }

    public function testAroundGetStorageModelUsesExplicitStorageParam(): void
    {
        $proceed = fn() => false;

        $result = $this->plugin->aroundGetStorageModel(
            $this->subject,
            $proceed,
            R2Storage::STORAGE_MEDIA_R2
        );

        $this->assertSame($this->r2Model, $result);
    }

    public function testAroundGetStorageModelReturnsFalseForNonR2Storage(): void
    {
        $proceed = fn() => false;

        $this->storageHelper->method('getCurrentStorageCode')
            ->willReturn(0); // File system storage

        $result = $this->plugin->aroundGetStorageModel($this->subject, $proceed);

        $this->assertFalse($result);
    }

    public function testAroundGetStorageModelCallsInitWhenParamSet(): void
    {
        $proceed = fn() => false;

        $this->r2Model->expects($this->once())->method('init');

        $this->plugin->aroundGetStorageModel(
            $this->subject,
            $proceed,
            R2Storage::STORAGE_MEDIA_R2,
            ['init' => true]
        );
    }

    public function testAroundGetStorageModelSkipsInitWhenParamNotSet(): void
    {
        $proceed = fn() => false;

        $this->r2Model->expects($this->never())->method('init');

        $this->plugin->aroundGetStorageModel(
            $this->subject,
            $proceed,
            R2Storage::STORAGE_MEDIA_R2,
            []
        );
    }

    public function testAroundGetStorageModelSkipsInitWhenParamFalse(): void
    {
        $proceed = fn() => false;

        $this->r2Model->expects($this->never())->method('init');

        $this->plugin->aroundGetStorageModel(
            $this->subject,
            $proceed,
            R2Storage::STORAGE_MEDIA_R2,
            ['init' => false]
        );
    }
}

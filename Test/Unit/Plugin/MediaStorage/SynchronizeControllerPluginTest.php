<?php
declare(strict_types=1);

namespace MageZero\CloudflareR2\Test\Unit\Plugin\MediaStorage;

use MageZero\CloudflareR2\Plugin\MediaStorage\SynchronizeControllerPlugin;
use Magento\MediaStorage\Controller\Adminhtml\System\Config\System\Storage\Synchronize;
use Magento\MediaStorage\Model\File\Storage\Flag;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SynchronizeControllerPluginTest extends TestCase
{
    private MockObject $flag;
    private SynchronizeControllerPlugin $plugin;
    private Synchronize $controller;

    protected function setUp(): void
    {
        $this->flag = $this->getMockBuilder(Flag::class)
            ->disableOriginalConstructor()
            ->addMethods(['getState', 'setState'])
            ->onlyMethods(['loadSelf', 'save', 'getFlagData'])
            ->getMock();
        $this->controller = $this->createMock(Synchronize::class);
        $this->plugin = new SynchronizeControllerPlugin($this->flag);
    }

    public function testAfterExecuteSetsStateToNotifiedWhenFinishedWithoutErrors(): void
    {
        $this->flag->expects($this->once())
            ->method('loadSelf')
            ->willReturnSelf();

        $this->flag->expects($this->once())
            ->method('getState')
            ->willReturn(Flag::STATE_FINISHED);

        $this->flag->expects($this->once())
            ->method('getFlagData')
            ->willReturn(['has_errors' => false]);

        $this->flag->expects($this->once())
            ->method('setState')
            ->with(Flag::STATE_NOTIFIED)
            ->willReturnSelf();

        $this->flag->expects($this->once())
            ->method('save');

        $this->plugin->afterExecute($this->controller, null);
    }

    public function testAfterExecuteDoesNotChangeStateWhenFinishedWithErrors(): void
    {
        $this->flag->expects($this->once())
            ->method('loadSelf')
            ->willReturnSelf();

        $this->flag->expects($this->once())
            ->method('getState')
            ->willReturn(Flag::STATE_FINISHED);

        $this->flag->expects($this->once())
            ->method('getFlagData')
            ->willReturn(['has_errors' => true]);

        $this->flag->expects($this->never())
            ->method('setState');

        $this->plugin->afterExecute($this->controller, null);
    }

    public function testAfterExecuteDoesNotChangeStateWhenNotFinished(): void
    {
        $this->flag->expects($this->once())
            ->method('loadSelf')
            ->willReturnSelf();

        $this->flag->expects($this->once())
            ->method('getState')
            ->willReturn(Flag::STATE_RUNNING);

        $this->flag->expects($this->never())
            ->method('getFlagData');

        $this->flag->expects($this->never())
            ->method('setState');

        $this->plugin->afterExecute($this->controller, null);
    }

    public function testAfterExecuteDoesNotChangeStateWhenAlreadyNotified(): void
    {
        $this->flag->expects($this->once())
            ->method('loadSelf')
            ->willReturnSelf();

        $this->flag->expects($this->once())
            ->method('getState')
            ->willReturn(Flag::STATE_NOTIFIED);

        $this->flag->expects($this->never())
            ->method('setState');

        $this->plugin->afterExecute($this->controller, null);
    }
}

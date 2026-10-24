<?php

namespace KanboardTests\units\Helper;

use KanboardTests\units\Base;
use Kanboard\Helper\TaskHelper;
use Kanboard\Model\ProjectModel;

class TaskHelperTest extends Base
{
    public function testSelectPriority()
    {
        $helper = new TaskHelper($this->container);
        $this->assertNotEmpty($helper->renderPriorityField(array('priority_end' => '1', 'priority_start' => '5', 'priority_default' => '2'), array()));
        $this->assertNotEmpty($helper->renderPriorityField(array('priority_end' => '3', 'priority_start' => '1', 'priority_default' => '2'), array()));
    }

    public function testFormatPriority()
    {
        $helper = new TaskHelper($this->container);

        $this->assertEquals(
            '<span class="task-priority" title="Task priority"><span class="ui-helper-hidden-accessible">Task priority </span>P2</span>',
            $helper->renderPriority(2)
        );

        $this->assertEquals(
            '<span class="task-priority" title="Task priority"><span class="ui-helper-hidden-accessible">Task priority </span>-P6</span>',
            $helper->renderPriority(-6)
        );
    }

    public function testGetCoverLayout()
    {
        $helper = new TaskHelper($this->container);
        $task = array('project_id' => 1, 'cover_image_id' => 3, 'cover_image_path' => 'tasks/1/abc');

        $this->assertEquals('full', $helper->getCoverLayout(array('cover_layout' => ProjectModel::COVER_LAYOUT_FULL), $task));
        $this->assertEquals('thumbnail', $helper->getCoverLayout(array('cover_layout' => ProjectModel::COVER_LAYOUT_THUMBNAIL), $task));
        $this->assertEquals('', $helper->getCoverLayout(array('cover_layout' => ProjectModel::COVER_LAYOUT_NONE), $task));
        $this->assertEquals('', $helper->getCoverLayout(array('cover_layout' => ProjectModel::COVER_LAYOUT_FULL), array('project_id' => 1, 'cover_image_id' => null, 'cover_image_path' => null)));
    }
}

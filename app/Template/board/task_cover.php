<div class="task-board-cover">
    <a href="<?= $this->url->href('TaskViewController', 'show', array('task_id' => $task['id'])) ?>" tabindex="-1" aria-hidden="true">
        <img
            src="<?= $this->url->href('FileViewerController', $layout === 'banner' || $layout === 'full' ? 'cover' : 'thumbnail', array('task_id' => $task['id'], 'file_id' => $task['cover_image_id'], 'etag' => md5($task['cover_image_path']))) ?>"
            alt=""
            loading="lazy">
    </a>
</div>

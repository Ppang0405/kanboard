<?php if (! empty($images)): ?>
    <?php
    /**
     * Performance optimization: Store images data ONCE instead of duplicating for each thumbnail.
     * This prevents N² memory usage when there are many attachments.
     */
    $slideshowId = 'slideshow-data-' . $task['id'];
    $slideshowConfig = array(
        'images' => $images,
        'regex_file_id' => 'FILE_ID',
        'regex_etag' => 'ETAG',
        'url' => array(
            'image' => $this->url->to('FileViewerController', 'image', array('file_id' => 'FILE_ID', 'task_id' => $task['id'], 'etag' => 'ETAG')),
            'thumbnail' => $this->url->to('FileViewerController', 'thumbnail', array('file_id' => 'FILE_ID', 'task_id' => $task['id'], 'etag' => 'ETAG')),
            'download' => $this->url->to('FileViewerController', 'download', array('file_id' => 'FILE_ID', 'task_id' => $task['id'], 'etag' => 'ETAG')),
        )
    );
    ?>
    <script type="application/json" id="<?= $slideshowId ?>"><?= json_encode($slideshowConfig, JSON_HEX_APOS | JSON_HEX_TAG) ?></script>
    <div class="file-thumbnails">
        <?php foreach ($images as $file): ?>
            <div class="file-thumbnail">
                <div class="js-image-slideshow-optimized" data-slideshow-id="<?= $slideshowId ?>" data-image-id="<?= $file['id'] ?>"></div>

                <div class="file-thumbnail-content">
                    <div class="file-thumbnail-title">
                        <div class="dropdown">
                            <a href="#" class="dropdown-menu dropdown-menu-link-text" title="<?= $this->text->e($file['name']) ?>"><?= $this->text->e($file['name']) ?> <i class="fa fa-caret-down"></i></a>
                            <ul>
                                <li>
                                    <?= $this->url->icon('external-link', t('View file'), 'FileViewerController', 'image', array('task_id' => $task['id'], 'file_id' => $file['id'], 'etag' => $file['etag']), false, '', '', true) ?>
                                </li>
                                <li>
                                    <?= $this->url->icon('download', t('Download'), 'FileViewerController', 'download', array('task_id' => $task['id'], 'file_id' => $file['id'], 'etag' => $file['etag'])) ?>
                                </li>
                                <?php if ($this->user->hasProjectAccess('TaskFileController', 'remove', $task['project_id'])): ?>
                                    <li>
                                        <?= $this->modal->confirm('trash-o', t('Remove'), 'TaskFileController', 'confirm', array('task_id' => $task['id'], 'file_id' => $file['id'])) ?>
                                    </li>
                                <?php endif ?>
                                <?= $this->hook->render('template:task-file:images:dropdown', array('task' => $task, 'file' => $file)) ?>
                            </ul>
                        </div>
                    </div>
                    <div class="file-thumbnail-description">
                        <?= $this->hook->render('template:task-file:images:before-thumbnail-description', array('task' => $task, 'file' => $file)) ?>
                        <?= $this->app->tooltipMarkdown(t('Uploaded: %s', $this->dt->datetime($file['date']))."\n\n".t('Size: %s', $this->text->bytes($file['size']))) ?>
                        <?php if (! empty($file['user_id'])): ?>
                            <?= t('Uploaded by %s', $file['user_name'] ?: $file['username']) ?>
                        <?php else: ?>
                            <?= t('Uploaded: %s', $this->dt->datetime($file['date'])) ?>
                        <?php endif ?>
                    </div>
                </div>
            </div>
        <?php endforeach ?>
    </div>
<?php endif ?>

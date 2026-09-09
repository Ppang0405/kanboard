<div class="page-header">
    <h2><?= $this->text->e($file['name']) ?></h2>
</div>
<div class="file-viewer">
    <?php if ($file['is_image']): ?>
        <img src="<?= $this->url->href('FileViewerController', 'image', $params) ?>" alt="<?= $this->text->e($file['name']) ?>">
    <?php elseif ($type === 'markdown'): ?>
        <?php if (! empty($too_large)): ?>
            <p><?= t('This file is too large to preview.') ?> <?= $this->url->link(t('Download'), 'FileViewerController', 'download', $params) ?></p>
        <?php else: ?>
            <article class="markdown">
                <?= $this->text->markdown($content) ?>
            </article>
        <?php endif ?>
    <?php elseif ($type === 'text'): ?>
        <?php if (! empty($too_large)): ?>
            <p><?= t('This file is too large to preview.') ?> <?= $this->url->link(t('Download'), 'FileViewerController', 'download', $params) ?></p>
        <?php else: ?>
            <pre><?= $this->text->e($content) ?></pre>
        <?php endif ?>
    <?php elseif ($type === 'html'): ?>
        <iframe
            src="<?= $this->url->href('FileViewerController', 'html', $params) ?>"
            sandbox
            title="<?= $this->text->e($file['name']) ?>"
            style="width: 100%; height: 600px; border: 1px solid #dedede; border-radius: 3px; background: #fff;"></iframe>
    <?php elseif ($type === 'spreadsheet'): ?>
        <?php if (! empty($too_large)): ?>
            <p><?= t('This file is too large to preview.') ?> <?= $this->url->link(t('Download'), 'FileViewerController', 'download', $params) ?></p>
        <?php else: ?>
            <iframe
                src="<?= $this->url->href('FileViewerController', 'spreadsheet', $params) ?>"
                sandbox
                title="<?= $this->text->e($file['name']) ?>"
                style="width: 100%; height: 600px; border: 1px solid #dedede; border-radius: 3px; background: #fff;"></iframe>
        <?php endif ?>
    <?php elseif ($type === 'office'): ?>
        <?php if (! empty($too_large)): ?>
            <p><?= t('This file is too large to preview.') ?> <?= $this->url->link(t('Download'), 'FileViewerController', 'download', $params) ?></p>
        <?php else: ?>
            <iframe
                src="<?= $this->url->href('FileViewerController', 'office', $params) ?>"
                sandbox
                title="<?= $this->text->e($file['name']) ?>"
                style="width: 100%; height: 600px; border: 1px solid #dedede; border-radius: 3px; background: #fff;"></iframe>
        <?php endif ?>
    <?php elseif ($type === 'pdf'): ?>
        <iframe
            src="<?= $this->url->href('FileViewerController', 'browser', $params) ?>"
            title="<?= $this->text->e($file['name']) ?>"
            style="width: 100%; height: 600px; border: 1px solid #dedede; border-radius: 3px; background: #fff;"></iframe>
    <?php elseif ($type === 'audio'): ?>
        <audio controls preload="metadata" src="<?= $this->url->href('FileViewerController', 'media', $params) ?>" style="width: 100%"></audio>
    <?php elseif ($type === 'video'): ?>
        <video controls preload="metadata" src="<?= $this->url->href('FileViewerController', 'media', $params) ?>" style="width: 100%; max-height: 600px; background: #000;"></video>
    <?php endif ?>
</div>

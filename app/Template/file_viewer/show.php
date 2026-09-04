<div class="page-header">
    <h2><?= $this->text->e($file['name']) ?></h2>
</div>
<div class="file-viewer">
    <?php if ($file['is_image']): ?>
        <img src="<?= $this->url->href('FileViewerController', 'image', $params) ?>" alt="<?= $this->text->e($file['name']) ?>">
    <?php elseif ($type === 'markdown'): ?>
        <article class="markdown">
            <?= $this->text->markdown($content) ?>
        </article>
    <?php elseif ($type === 'text'): ?>
        <pre><?= $this->text->e($content) ?></pre>
    <?php elseif ($type === 'html'): ?>
        <iframe
            src="<?= $this->url->href('FileViewerController', 'html', $params) ?>"
            sandbox
            title="<?= $this->text->e($file['name']) ?>"
            style="width: 100%; height: 600px; border: 1px solid #dedede; border-radius: 3px; background: #fff;"></iframe>
    <?php endif ?>
</div>

<?php

namespace Kanboard\Model;

use Kanboard\Core\ObjectStorage\ObjectStorageException;
use Kanboard\Core\Thumbnail;

/**
 * Task File Model
 *
 * @package  Kanboard\Model
 * @author   Frederic Guillot
 */
class TaskFileModel extends FileModel
{
    /**
     * Table name
     *
     * @var string
     */
    const TABLE = 'task_has_files';

    /**
     * Events
     *
     * @var string
     */
    const EVENT_CREATE = 'task.file.create';
    const EVENT_DESTROY = 'task.file.destroy';

    /**
     * Maximum size of the image displayed as card cover
     *
     * @var integer
     */
    const COVER_MAX_WIDTH = 600;
    const COVER_MAX_HEIGHT = 1200;

    /**
     * Get the table
     *
     * @abstract
     * @access protected
     * @return string
     */
    protected function getTable()
    {
        return self::TABLE;
    }

    /**
     * Define the foreign key
     *
     * @abstract
     * @access protected
     * @return string
     */
    protected function getForeignKey()
    {
        return 'task_id';
    }

    /**
     * Define the path prefix
     *
     * @abstract
     * @access protected
     * @return string
     */
    protected function getPathPrefix()
    {
        return 'tasks';
    }

    /**
     * Get projectId from fileId
     *
     * @access public
     * @param  integer $file_id
     * @return integer
     */
    public function getProjectId($file_id)
    {
        return $this->db
            ->table(self::TABLE)
            ->eq(self::TABLE.'.id', $file_id)
            ->join(TaskModel::TABLE, 'id', 'task_id')
            ->findOneColumn(TaskModel::TABLE . '.project_id') ?: 0;
    }

    /**
     * Handle screenshot upload
     *
     * @access public
     * @param  integer  $task_id      Task id
     * @param  string   $blob         Base64 encoded image
     * @return bool|integer
     */
    public function uploadScreenshot($task_id, $blob)
    {
        $original_filename = e('Screenshot taken %s', $this->helper->dt->datetime(time())).'.png';
        return $this->uploadContent($task_id, $original_filename, $blob);
    }

    /**
     * Create a file entry and select the first image of the task as cover if the project enables it
     *
     * @access public
     * @param  integer $foreign_key_id Task id
     * @param  string  $name           Filename
     * @param  string  $path           Path on the disk
     * @param  integer $size           File size
     * @return bool|integer
     */
    public function create($foreign_key_id, $name, $path, $size)
    {
        $file_id = parent::create($foreign_key_id, $name, $path, $size);

        if ($file_id !== false && $this->isImage($name)) {
            $this->selectAutomaticCover($foreign_key_id, $file_id);
        }

        return $file_id;
    }

    /**
     * Remove a file and unset the task cover when it was this file
     *
     * @access public
     * @param  integer $file_id
     * @return bool
     */
    public function remove($file_id)
    {
        $result = parent::remove($file_id);

        if ($result) {
            $this->db->table(TaskModel::TABLE)->eq('cover_image_id', $file_id)->update(array('cover_image_id' => null));
        }

        return $result;
    }

    /**
     * Check if card covers are enabled for this instance
     *
     * @access public
     * @return bool
     */
    public function isCoverEnabled()
    {
        return TASK_COVERS_ENABLED;
    }

    /**
     * Use an image attached to the task as card cover
     *
     * @access public
     * @param  integer $task_id
     * @param  integer $file_id
     * @return bool
     */
    public function setCover($task_id, $file_id)
    {
        if (! $this->isCoverEnabled()) {
            return false;
        }

        $file = $this->getById($file_id);

        if (empty($file) || $file['task_id'] != $task_id || $file['is_image'] != 1) {
            return false;
        }

        return $this->db->table(TaskModel::TABLE)->eq('id', $task_id)->update(array('cover_image_id' => $file['id']));
    }

    /**
     * Remove the card cover of a task, the image stays attached
     *
     * @access public
     * @param  integer $task_id
     * @return bool
     */
    public function removeCover($task_id)
    {
        return $this->db->table(TaskModel::TABLE)->eq('id', $task_id)->update(array('cover_image_id' => null));
    }

    /**
     * Select the cover when the project enables automatic covers and this is the first image of the task
     *
     * @access protected
     * @param  integer $task_id
     * @param  integer $file_id
     */
    protected function selectAutomaticCover($task_id, $file_id)
    {
        if (! $this->isCoverEnabled()) {
            return;
        }

        $task = $this->taskFinderModel->getById($task_id);

        if (empty($task) || ! empty($task['cover_image_id'])) {
            return;
        }

        $project = $this->projectModel->getById($task['project_id']);

        if (empty($project['cover_auto'])) {
            return;
        }

        if ($this->db->table(self::TABLE)->eq('task_id', $task_id)->eq('is_image', 1)->count() === 1) {
            $this->setCover($task_id, $file_id);
        }
    }

    /**
     * Generate the path of the image displayed as card cover
     *
     * @access public
     * @param  string  $key  Storage key
     * @return string
     */
    public function getCoverPath($key)
    {
        return 'covers'.DIRECTORY_SEPARATOR.$key;
    }

    /**
     * Generate the card cover image from the original image
     *
     * @access public
     * @param  string $key  Storage key of the original image
     */
    public function generateCover($key)
    {
        $data = $this->objectStorage->get($key);
        $blob = Thumbnail::createFromString($data)
            ->fitWidth(self::COVER_MAX_WIDTH, self::COVER_MAX_HEIGHT)
            ->toJpegString();

        $this->objectStorage->put($this->getCoverPath($key), $blob);
    }

    /**
     * Remove the thumbnail and the card cover image
     *
     * @access protected
     * @param  string $key
     */
    protected function removeImageVariants($key)
    {
        parent::removeImageVariants($key);

        // Card covers are generated on demand, so most images do not have one
        try {
            $this->objectStorage->remove($this->getCoverPath($key));
        } catch (ObjectStorageException $e) {
        }
    }

    /**
     * Fire file creation event
     *
     * @access protected
     * @param  integer $file_id
     */
    protected function fireCreationEvent($file_id)
    {
        $this->queueManager->push($this->taskFileEventJob->withParams($file_id, self::EVENT_CREATE));
    }

    /**
     * Fire file destruction event
     *
     * @access protected
     * @param  integer $file_id
     */
    protected function fireDestructionEvent($file_id)
    {
        $this->queueManager->push($this->taskFileEventJob->withParams($file_id, self::EVENT_DESTROY));
    }
}

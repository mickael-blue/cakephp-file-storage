<?php

declare(strict_types=1);

/**
 * File Storage Plugin for CakePHP
 *
 * @author Florian Krämer
 * @copyright 2012 - 2017 Florian Krämer
 * @license MIT
 */

namespace Burzum\FileStorage\Model\Behavior;

use ArrayAccess;
use ArrayObject;
use Burzum\FileStorage\Storage\StorageTrait;
use Burzum\FileStorage\Storage\StorageUtils;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Event\EventDispatcherTrait;
use Cake\Event\EventInterface;
use Shim\Filesystem\File;
use Cake\ORM\Behavior;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Storage Behavior
 *
 * The behavior will fire events to deal with the file storage logic and gather
 * the data from the uploaded file that is stored. If you're looking for the
 * actual storage logic and processing take a look at the Storage Listeners.
 *
 * The behavior encapsulates all the logic that is needed to make a table work
 * as reference table for keeping the references to all the stored files.
 *
 * A table that will work with this behavior requires at least these fields:
 * id, filename, identifier, foreign_key, path, adapter, filename, mime_type, hash
 *
 * Behavior options:
 *
 * - `defaultStorageConfig`: The default storage config name to use. `Local` by default.
 * - `ignoreEmptyFile`: If not file is present nothing will be saved.
 * - `fileField`: The field that will be checked for a file upload.
 */
class FileStorageBehavior extends Behavior
{
    use EventDispatcherTrait;
    use StorageTrait;

    /**
     * Default config
     *
     * @var array
     */
    protected array $_defaultConfig = [
        'defaultStorageConfig' => 'Local',
        'ignoreEmptyFile' => true,
        'fileField' => 'file',
        'getFileHash' => false,
    ];

    /**
     * Checks if a file upload is present.
     *
     * @param \Cake\Datasource\EntityInterface|array $entity
     * @return bool
     */
    protected function _isFileUploadPresent($entity): bool
    {
        $field = $this->getConfig('fileField');

        // Handle EntityInterface objects
        if ($entity instanceof EntityInterface) {
            if (!$entity->has($field)) {
                return false;
            }
            $file = $entity->get($field);
        } elseif (is_array($entity) || $entity instanceof ArrayAccess) {
            // Handle arrays and ArrayAccess objects
            if (!isset($entity[$field])) {
                return false;
            }
            $file = $entity[$field];
        } else {
            return false;
        }

        if ($this->getConfig('ignoreEmptyFile') === true) {
            // Handle UploadedFileInterface objects (CakePHP 4+)
            if ($file instanceof UploadedFileInterface) {
                if ($file->getError() === UPLOAD_ERR_NO_FILE) {
                    return false;
                }
            } elseif (is_array($file)) {
                // Handle legacy array format
                if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
                    return false;
                }
            } else {
                return false;
            }
        }

        return true;
    }

    /**
     * beforeMarshal callback
     *
     * @param \Cake\Event\Event $event
     * @param \ArrayAccess $data
     * @return void
     */
    public function beforeMarshal(Event $event, ArrayAccess $data): void
    {
        if (!$this->_isFileUploadPresent($data)) {
            return;
        }

        $this->_getFileInfoFromUpload($data);
    }

    /**
     * beforeSave callback
     *
     * @param \Cake\Event\EventInterface $event The beforeSave event that was fired
     * @param \Cake\Datasource\EntityInterface $entity The entity that is going to be saved
     * @param \ArrayObject $options The options for the query
     * @return void
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): bool
    {
        if (!$this->_isFileUploadPresent($entity)) {
            $event->stopPropagation();

            return false;
        }

        $this->_checkEntityBeforeSave($entity);

        $this->dispatchEvent('FileStorage.beforeSave', [
            'entity' => $entity,
            'storageAdapter' => $this->getStorageAdapter($entity->get('adapter')),
        ], $this->_table);

        return true;
    }

    /**
     * afterSave callback
     *
     * @param \Cake\Event\EventInterface $event The beforeSave event that was fired
     * @param \Cake\Datasource\EntityInterface $entity The entity that is going to be saved
     * @param \ArrayObject $options The options for the query
     * @return void
     */
    public function afterSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $this->dispatchEvent('FileStorage.afterSave', [
            'entity' => $entity,
            'storageAdapter' => $this->getStorageAdapter($entity->get('adapter')),
        ], $this->_table);
    }

    /**
     * _checkEntityBeforeSave
     *
     * @param \Cake\Datasource\EntityInterface $entity
     * @return void
     */
    protected function _checkEntityBeforeSave(EntityInterface &$entity): void
    {
        if ($entity->isNew()) {
            if (!$entity->has('model')) {
                $entity->set('model', $this->_table->getTable());
            }

            if (!$entity->has('adapter')) {
                $entity->set('adapter', $this->getConfig('defaultStorageConfig'));
            }

            $fileHashMethod = $this->getConfig('getFileHash');
            if ($fileHashMethod) {
                if ($fileHashMethod === true) {
                    $fileHashMethod = 'sha1';
                }
                $file = $entity->get('file');
                $tmpName = null;

                // Handle UploadedFileInterface objects (CakePHP 4+)
                if ($file instanceof UploadedFileInterface) {
                    $stream = $file->getStream();
                    $tmpName = $stream->getMetadata('uri');
                } elseif (is_array($file) && isset($file['tmp_name'])) {
                    // Handle legacy array format
                    $tmpName = $file['tmp_name'];
                }

                if ($tmpName) {
                    $entity->set('hash', StorageUtils::getFileHash($tmpName, $fileHashMethod));
                }
            }
        }
    }

    /**
     * afterDelete callback
     *
     * @param \Cake\Event\EventInterface $event The beforeSave event that was fired
     * @param \Cake\Datasource\EntityInterface $entity The entity that is going to be saved
     * @param \ArrayObject $options The options for the query
     * @return void
     */
    public function afterDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $this->dispatchEvent('FileStorage.afterDelete', [
            'entity' => $entity,
            'storageAdapter' => $this->getStorageAdapter($entity->get('adapter')),
        ], $this->_table);
    }

    /**
     * Deletes an old file to replace it with the new one if an old id was passed.
     *
     * Thought to be called in Table::afterSave() but can be used from any other
     * place as well like Table::beforeSave() as long as the field data is present.
     * The old id has to be the UUID of the file_storage record that should be deleted.
     *
     * Table::deleteAll() is intentionally not used because it doesn't trigger
     * callbacks.
     *
     * @param \Cake\Datasource\EntityInterface $entity
     * @param string $oldIdField Name of the field in the data that holds the old id.
     * @return bool Returns true if the old record was deleted
     */
    public function deleteOldFileOnSave(EntityInterface $entity, string $oldIdField = 'old_file_id'): bool
    {
        if (!empty($entity->get($oldIdField)) && $entity->get('model')) {
            $oldEntity = $this->_table->find()
                ->contain([])
                ->where([
                    $this->_table->getAlias() . '.' . $this->_table->getPrimaryKey() => $entity->get($oldIdField),
                    'model' => $entity->get('model'),
                ])
                ->first();

            if (!empty($oldEntity)) {
                return $this->_table->delete($oldEntity);
            }
        }

        return false;
    }

    /**
     * Normalizes a filename to ensure proper UTF-8 encoding for database storage.
     *
     * @param string $filename The filename to normalize
     * @return string The normalized filename
     */
    protected function _normalizeFilename(string $filename): string
    {
        // Normalize Unicode characters (NFC form) - combines combining characters
        if (class_exists('Normalizer') && function_exists('normalizer_normalize')) {
            $normalized = normalizer_normalize($filename, \Normalizer::FORM_C);
            if ($normalized !== false) {
                $filename = $normalized;
            }
        }

        // Ensure proper UTF-8 encoding
        if (!mb_check_encoding($filename, 'UTF-8')) {
            $filename = mb_convert_encoding($filename, 'UTF-8', 'UTF-8');
        }

        // Remove any invalid UTF-8 characters and ensure it's valid UTF-8
        $filename = mb_convert_encoding($filename, 'UTF-8', 'UTF-8');

        // Remove any remaining invalid characters
        $filename = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $filename);

        return $filename;
    }

    /**
     * Gets information about the file that is being uploaded.
     *
     * - gets the file size
     * - gets the mime type
     * - gets the extension if present
     *
     * @param array|\ArrayAccess $upload
     * @param string $field
     * @return void
     */
    public function _getFileInfoFromUpload(&$upload, string $field = 'file'): void
    {
        if (!isset($upload[$field])) {
            return;
        }

        $file = $upload[$field];
        $tmpName = null;
        $fileName = null;

        // Handle UploadedFileInterface objects (CakePHP 4+)
        if ($file instanceof UploadedFileInterface) {
            $stream = $file->getStream();
            $tmpName = $stream->getMetadata('uri');
            $fileName = $file->getClientFilename();

            if ($tmpName) {
                $File = new File($tmpName);
                $upload['filesize'] = $file->getSize();
                $upload['mime_type'] = $file->getClientMediaType() ?: $File->mime();
            }

            if ($fileName) {
                $fileName = $this->_normalizeFilename($fileName);
                $upload['extension'] = pathinfo($fileName, PATHINFO_EXTENSION);
                $upload['filename'] = $fileName;
            }
        } elseif (is_array($file)) {
            // Handle legacy array format
            if (!empty($file['tmp_name'])) {
                $File = new File($file['tmp_name']);
                $upload['filesize'] = filesize($file['tmp_name']);
                $upload['mime_type'] = $File->mime();
            }

            if (!empty($file['name'])) {
                $fileName = $this->_normalizeFilename($file['name']);
                $upload['extension'] = pathinfo($fileName, PATHINFO_EXTENSION);
                $upload['filename'] = $fileName;
            }
        }
    }

    /**
     * Don't use Table::deleteAll() if you don't want to end up with orphaned
     * files! The reason for that is that deleteAll() doesn't fire the
     * callbacks. So the events that will remove the files won't get fired.
     *
     * @param array $conditions Query::where() array structure.
     * @return int Number of deleted records / files
     */
    public function deleteAllFiles(array $conditions): int
    {
        $results = $this->_table->find()
            ->select((array)$this->_table->getPrimaryKey())
            ->where($conditions)
            ->all();

        if ($results->count() > 0) {
            foreach ($results as $result) {
                $this->_table->delete($result);
            }
        }

        return $results->count();
    }
}

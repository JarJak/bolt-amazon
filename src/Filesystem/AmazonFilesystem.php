<?php

declare(strict_types=1);

namespace Bolt\Extension\JarJak\Amazon\Filesystem;

use Bolt\Filesystem;
use Bolt\Filesystem\Handler\HandlerInterface;
use Exception;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Util\ContentListingFormatter;

class AmazonFilesystem extends Filesystem\Filesystem
{
    /**
     * @var int
     */
    private $awsFileListLimit = 0;

    public function setAwsFileListLimit(int $limit): void
    {
        $this->awsFileListLimit = $limit;
    }

    /**
     * {@inheritdoc}
     */
    public function listContents($directory = '', $recursive = false)
    {
        $directory = $this->normalizePath($directory);

        try {
            $contents = $this->getAdapter()->listContents($directory, $recursive);
        } catch (Exception $e) {
            throw $this->handleEx($e, $directory);
        }

        $formatter = new ContentListingFormatter($directory, $recursive);
        $contents = $formatter->formatListing($contents);

        if ($this->awsFileListLimit) {
            $contents = array_slice($contents, 0, $this->awsFileListLimit);
        }

        $contents = array_map(
            function ($entry) {
                $type = $this->getTypeFromMetadata($entry);

                $handler = $this->getHandlerForType($entry['path'], $type);

                $handler->setMountPoint($this->getMountPoint());

                return $handler;
            },
            $contents
        );

        return $contents;
    }

    public function createDir($dirname, $config = []): void
    {
        $config = array_merge($config, ['visibility' => AdapterInterface::VISIBILITY_PUBLIC]);
        parent::createDir($dirname, $config);
    }

    /**
     * @param string $path
     * @param string $type
     *
     * @return HandlerInterface
     */
    private function getHandlerForType($path, $type)
    {
        switch ($type) {
            case 'dir':
                return new Filesystem\Handler\Directory($this, $path);
            case 'image':
                return new Filesystem\Handler\Image($this, $path);
            case 'json':
                return new Filesystem\Handler\JsonFile($this, $path);
            case 'yaml':
                return new Filesystem\Handler\YamlFile($this, $path);
            default:
                return new Filesystem\Handler\File($this, $path);
        }
    }

    private function getTypeFromMetadata($metadata)
    {
        switch ($metadata['type']) {
            case 'dir':
                return 'dir';
            /** @noinspection PhpMissingBreakStatementInspection */
            case 'file':
                if ($type = $this->getTypeFromPath($metadata['path'])) {
                    return $type;
                }
            default:
                return $metadata['type'];
        }
    }

    private function getTypeFromPath($path)
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        if (in_array($ext,Filesystem\Handler\Image\Type::getExtensions())) {
            return 'image';
        } elseif ($ext === 'json') {
            return 'json';
        } elseif ($ext === 'yaml' || $ext === 'yml') {
            return 'yaml';
        } elseif (in_array($ext, $this->getDocumentExtensions())) {
            return 'document';
        }

        return null;
    }

    private function getDocumentExtensions()
    {
        return $this->getConfig()->get(
            'doc_extensions',
            ['doc', 'docx', 'txt', 'md', 'pdf', 'xls', 'xlsx', 'ppt', 'pptx', 'csv']
        );
    }
}

<?php

declare(strict_types=1);

namespace Bolt\Extension\JarJak\Amazon\Filesystem;

use Bolt\Filesystem\Filesystem;

class AmazonFilesystem extends Filesystem
{
    /**
     * @var int
     */
    private $awsFileListLimit = 0;

    public function setAwsFileListLimit(int $limit): void
    {
        $this->awsFileListLimit = $limit;
    }

    public function listContents($directory = '', $recursive = false)
    {
        $contents = parent::listContents($directory, $recursive);

        if ($this->awsFileListLimit) {
            return array_slice($contents, 0, $this->awsFileListLimit);
        }

        return $contents;
    }
}

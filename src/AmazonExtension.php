<?php

declare(strict_types=1);

namespace Bolt\Extension\JarJak\Amazon;

use Aws\S3\S3Client;
use Bolt\Extension\JarJak\Amazon\Filesystem\AmazonFilesystem;
use Bolt\Extension\SimpleExtension;
use Bolt\Filesystem\Adapter\Cached;
use Bolt\Filesystem\Adapter\S3;
use Bolt\Filesystem\Cached\DoctrineCache;
use Bolt\Filesystem\Manager;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\PredisCache;
use League\Flysystem\AdapterInterface;
use Predis\Silex\ClientServiceProvider;
use Silex\Application;
use Symfony\Component\Asset\UrlPackage;

/**
 * Amazon extension class.
 *
 * @author Jarek Jakubowski <egger1991@gmail.com>
 */
class AmazonExtension extends SimpleExtension
{
    /**
     * @var string
     */
    protected $bucketRegion;

    /**
     * @var string
     */
    protected $bucketName;

    /**
     * @var string
     */
    protected $filesystemPrefix = '';

    /**
     * @var string
     */
    protected $clientVersion = 'latest';

    /**
     * @var string
     */
    protected $filesystemName = 'files';

    /**
     * @var int
     */
    protected $cacheTtl = 3600;

    /**
     * @var bool
     */
    protected $enabled = false;

    /**
     * @var bool
     */
    protected $useRedis = false;

    /**
     * @var string
     */
    protected $redisHost;

    /**
     * @var int
     */
    protected $redisPort;

    /**
     * @var string
     */
    protected $redisPrefix;

    /**
     * @var int
     */
    protected $fileListLimit = 1000;

    protected function setupConfig(): void
    {
        $config = array_merge($this->getDefaultConfig(), array_filter($this->getConfig()));

        if ($config['enabled']) {
            $this->enabled = (bool) $config['enabled'];
        }

        if ($config['client_version']) {
            $this->clientVersion = $config['client_version'];
        }

        if ($config['bucket_region']) {
            $this->bucketRegion = $config['bucket_region'];
        }
        if ($config['bucket_name']) {
            $this->bucketName = $config['bucket_name'];
        }

        if ($config['filesystem_prefix']) {
            $this->filesystemPrefix = $config['filesystem_prefix'];
        }
        if ($config['filesystem_name']) {
            $this->filesystemName = $config['filesystem_name'];
        }
        if ($config['file_list_limit']) {
            $this->fileListLimit = (int) $config['file_list_limit'];
        }

        if ($config['cache_ttl']) {
            $this->cacheTtl = (int) $config['cache_ttl'];
        }

        if ($config['use_redis']) {
            $this->useRedis = (bool) $config['use_redis'];
        }
        if ($config['redis_host']) {
            $this->redisHost = $config['redis_host'];
        }
        if ($config['redis_port']) {
            $this->redisPort = (int) $config['redis_port'];
        }
        if ($config['redis_prefix']) {
            $this->redisPrefix = $config['redis_prefix'];
        }
    }

    protected function getDefaultConfig()
    {
        return [
            'enabled' => getenv('AWS_ENABLED'),
            'client_version' => getenv('AWS_CLIENT_VERSION'),
            'access_key_id' => getenv('AWS_ACCESS_KEY_ID'),
            'secret_access_key' => getenv('AWS_SECRET_ACCESS_KEY'),
            'bucket_region' => getenv('AWS_BUCKET_REGION'),
            'bucket_name' => getenv('AWS_BUCKET_NAME'),
            'filesystem_prefix' => getenv('AWS_FILESYSTEM_PREFIX'),
            'filesystem_name' => getenv('AWS_FILESYSTEM_NAME'),
            'file_list_limit' => getenv('AWS_FILE_LIST_LIMIT'),
            'cache_ttl' => getenv('AWS_CACHE_TTL'),
            'use_redis' => getenv('AWS_USE_REDIS'),
            'redis_host' => getenv('REDIS_PORT'),
            'redis_port' => getenv('REDIS_HOST'),
            'redis_prefix' => getenv('REDIS_PREFIX'),
        ];
    }

    protected function registerServices(Application $app): void
    {
        $this->setupConfig();

        if ($this->enabled === false) {
            return;
        }

        if (!$this->bucketRegion) {
            throw new \LogicException('Either configure AWS properly or disable it');
        }

        $this->replaceFilesFilesystem($app);
        $this->replaceThumbnailsFilesystem($app);
        $this->registerAssetsPackage($app);
    }

    protected function replaceThumbnailsFilesystem(Application $app): void
    {
        $app['thumbnails.filesystem_cache'] = $app->share(function ($app) {
            if ($app['thumbnails.save_files'] === false) {
                return null;
            }

            return $app['filesystem']->getFilesystem($this->filesystemName);
        });
    }
    protected function registerAssetsPackage(Application $app)
    {
        $app['amazon_base_url'] = $this->getAssetsBaseUrl();
        $app['asset.packages'] = $app->share($app->extend(
            'asset.packages',
            function ($packages, $app) {
                $package = new UrlPackage(
                    $app['amazon_base_url'],
                    $app['asset.version_strategy']($this->filesystemName),
                    $app['asset.context']
                );
                $packages->addPackage($this->filesystemName, $package);

                return $packages;
            }
        ));
    }

    /**
     * @return string
     */
    protected function getAssetsBaseUrl()
    {
        $baseUrl = "https://s3.{$this->bucketRegion}.amazonaws.com/{$this->bucketName}/";
        if ($this->filesystemPrefix) $baseUrl .= "{$this->filesystemPrefix}/";

        return $baseUrl;
    }

    protected function replaceFilesFilesystem(Application $app): void
    {
        $app['filesystem'] = $app->share($app->extend(
            'filesystem',
            function (Manager $filesystem, Application $app) {
                $filesystems = [
                    $this->filesystemName => $this->getFilesystemFactory($app)($this->filesystemName, $this->bucketName, $this->filesystemPrefix),
                ];
                $filesystem->mountFilesystems($filesystems);

                return $filesystem;
            }
        ));
    }

    /**
     * @param Application $app
     *
     * @return \Closure
     */
    protected function getFilesystemFactory(Application $app)
    {
        if (!$app->offsetExists('filesystem.s3_factory')) {
            $app['filesystem.s3_factory'] = $app->protect(
                function ($name, $bucket, $prefix = '') use ($app) {
                    $adapter = new S3($this->getS3Provider($app), $bucket, $prefix);
                    $cachedAdapter = $this->getCacheFactory($app)($adapter, 'flysystem-'.$name, $this->cacheTtl);

                    $filesystem = new AmazonFilesystem($cachedAdapter, ['visibility' => AdapterInterface::VISIBILITY_PUBLIC]);
                    $filesystem->setAwsFileListLimit($this->fileListLimit);

                    return $filesystem;
                }
            );
        }

        return $app['filesystem.s3_factory'];
    }

    /**
     * @param Application $app
     *
     * @return S3Client
     */
    protected function getS3Provider(Application $app)
    {
        if (!$app->offsetExists('aws.s3')) {
            $app['aws.s3'] = $app->share(function () {
                return new S3Client([
                    'region' => $this->bucketRegion,
                    'version' => $this->clientVersion,
                ]);
            });
        }

        return $app['aws.s3'];
    }

    /**
     * @param Application $app
     *
     * @return \Closure
     */
    protected function getCacheFactory(Application $app)
    {
        if (!$app->offsetExists('filesystem.cache_factory')) {
            $app['filesystem.cache_factory'] = $app->protect(
                function (AdapterInterface $adapter, $name, $expire = null) use ($app) {
                    $cache = new DoctrineCache($this->getCache($app), $name, $expire);

                    return new Cached($adapter, $cache);
                }
            );
        }

        return $app['filesystem.cache_factory'];
    }

    /**
     * @param Application $app
     *
     * @return Cache
     */
    protected function getCache(Application $app)
    {
        if (class_exists(ClientServiceProvider::class)) {
            if (!$app->offsetExists('predis_cache')) {
                $app->register(new ClientServiceProvider(), [
                    'predis.parameters' => 'tcp://'.$this->redisHost.':'.$this->redisPort,
                    'predis.options' => [
                        'prefix' => $this->redisPrefix.':',
                        'profile' => '3.0',
                    ],
                ]);

                $app['predis_cache'] = $app->share(function ($app) {
                    return new PredisCache($app['predis']);
                });
            }

            return $app['predis_cache'];
        }
        return new ArrayCache();
    }
}

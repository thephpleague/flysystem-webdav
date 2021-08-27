<?php

namespace League\Flysystem\WebDAV;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use LogicException;
use RuntimeException;
use Sabre\DAV\Client;
use Sabre\DAV\Xml\Property\ResourceType;
use Sabre\HTTP\ClientHttpException;

class WebDAVAdapter implements FilesystemAdapter
{
    protected static $metadataFields = [
        '{DAV:}displayname',
        '{DAV:}getcontentlength',
        '{DAV:}getcontenttype',
        '{DAV:}getlastmodified',
        '{DAV:}iscollection',
        '{DAV:}resourcetype',
    ];

    /**
     * @var Client
     */
    protected $client;

    /**
     * Constructor.
     *
     * @param Client $client
     */
    public function __construct(Client $client)
    {
        $client->setThrowExceptions(true);
        $this->client = $client;
    }

    /**
     * url encode a path
     *
     * @param string $path
     *
     * @return string
     */
    protected function encodePath(string $path): string
	{
		$parts = explode('/', $path);
        foreach ($parts as &$part) {
            $part = rawurlencode($part);
        }
		return implode('/', $parts);
	}

    public function visibility(string $path): FileAttributes
    {
        $class = __CLASS__;
        throw new LogicException("$class does not support visibility. Path: $path");
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $class = __CLASS__;
        throw new LogicException("$class does not support visibility. Path: $path, visibility: $visibility");
    }

    private function getMetadata(string $path, string $metadataType): ?StorageAttributes
    {
        $location = $this->encodePath($path);

        try {
            $result = $this->client->propFind($location, static::$metadataFields);
        } catch (ClientHttpException $exception) {
            throw UnableToRetrieveMetadata::create($path, $metadataType, '', $exception);
        }

        if (empty($result)) {
            return null;
        }

        $path = trim($path, '/');
        if ($this->isDirectory($result)) {
            return DirectoryAttributes::fromArray([StorageAttributes::ATTRIBUTE_PATH => $path]);
        }
        $lastModified = $object['{DAV:}getlastmodified'] ?? null;
        return FileAttributes::fromArray([
            StorageAttributes::ATTRIBUTE_PATH => $path,
            StorageAttributes::ATTRIBUTE_FILE_SIZE => $object['content-length'] ?? $object['{DAV:}getcontentlength'] ?? null,
            StorageAttributes::ATTRIBUTE_LAST_MODIFIED => $lastModified !== null ? strtotime($lastModified) : null,
            StorageAttributes::ATTRIBUTE_MIME_TYPE => $object['content-type'] ?? $object['{DAV:}getcontenttype'] ?? null,
        ]);
    }

    public function fileExists(string $path): bool
    {
        try {
            return $this->getMetadata($path, 'fileExists') !== null;
        } catch (FilesystemException $exception) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $path): string
    {
        try {
            [
                'body' => $body,
                'statusCode' => $statusCode,
                'headers' => $headers,
            ] = $this->client->request('GET', $this->encodePath($path));
        } catch (ClientException | ClientHttpException $exception) {
            throw UnableToReadFile::fromLocation($path, '', $exception);
        }

        if ($statusCode !== 200) {
            throw UnableToReadFile::fromLocation($path, "HTTP status code is $statusCode, not 200.");
        }

        $timestamp = strtotime(current((array)$headers['last-modified']));
        $size = $headers['content-length'] ?? $headers['{DAV:}getcontentlength'] ?? null;
        $mimetype = $headers['content-type'] ?? $headers['{DAV:}getcontenttype'] ?? null;
        return $body;
    }

    /**
     * {@inheritdoc}
     */
    public function readStream(string $path)
    {
        $data = $this->read($path);

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException("opening temporary stream failed");
        }
        fwrite($stream, $data);
        rewind($stream);
        return $stream;
    }

    /**
     * @param string $path
     * @param string|resource $contents
     * @param Config $config
     * @throws FilesystemException
     */
    protected function writeImpl(string $path, $contents, Config $config): void
    {
        if ($config->get(StorageAttributes::ATTRIBUTE_VISIBILITY)) {
            throw new LogicException(__CLASS__.' does not support visibility settings.');
        }

        $directory = dirname($path);
        if ($directory === '.') {
            $directory = '';
        }
        $this->createDirectory($directory, $config);

        $location = $this->encodePath($path);
        try {
            $this->client->request('PUT', $location, $contents);
        } catch (ClientHttpException $exception) {
            throw UnableToWriteFile::atLocation($path, '', $exception);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $this->writeImpl($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->writeImpl($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function move(string $source, string $destination, Config $config): void
    {
        $location = $this->encodePath($source);
        $newLocation = $this->encodePath($destination);

        try {
            ['statusCode' => $statusCode] = $this->client->request('MOVE', '/' . ltrim($location, '/'), null, [
                'Destination' => '/' . ltrim($newLocation, '/'),
            ]);
        } catch (ClientHttpException $exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }
        if ($statusCode < 200 || 300 <= $statusCode) {
            throw UnableToMoveFile::fromLocationTo($source, $destination);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $this->nativeCopy($source, $destination, $config);
    }

    /**
     * @param string $path
     * @param string|UnableToDeleteFile|UnableToDeleteDirectory $exceptionToThrow
     */
    public function deleteImpl(string $path, string $exceptionToThrow): void
    {
        $location = $this->encodePath($path);

        try {
            ['statusCode' => $statusCode] = $this->client->request('DELETE', $location);
        } catch (ClientHttpException $exception) {
            throw $exceptionToThrow::atLocation($path, '', $exception);
        }

        if ($statusCode < 200 || 300 <= $statusCode) {
            throw $exceptionToThrow::atLocation($path);
        }
    }

    public function delete(string $path): void
    {
        $this->deleteImpl($path, UnableToDeleteFile::class);
    }

    /**
     * {@inheritdoc}
     */
    public function createDirectory(string $path, Config $config): void
    {
        $encodedPath = $this->encodePath($path);
        $path = trim($path, '/');

        if ($path === '.' || $path === '' || $this->fileExists($path)) {
            return;
        }

        $directories = explode('/', $path);
        if (count($directories) > 1) {
            $parentDirectories = array_splice($directories, 0, count($directories) - 1);
            $this->createDirectory(implode('/', $parentDirectories), $config);
        }

        ['statusCode' => $statusCode] = $this->client->request('MKCOL', $encodedPath . '/');

        if ($statusCode !== 201) {
            throw UnableToCreateDirectory::atLocation($path);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDirectory(string $path): void
    {
        $this->deleteImpl($path, UnableToDeleteDirectory::class);
    }

    /**
     * {@inheritdoc}
     */
    public function listContents(string $path, bool $deep): iterable
    {
        try {
            $response = $this->client->propFind($this->encodePath($path) . '/', static::$metadataFields, 1);
        } catch (ClientHttpException $exception) {
            throw UnableToRetrieveMetadata::create($path, 'listContents', "HTTP status code is {$exception->getHttpStatus()}, not 200.", $exception);
        }

        array_shift($response);

        foreach ($response as $rawChildPath => $object) {
            $childPath = trim(rawurldecode($rawChildPath), '/');
            if ($this->isDirectory($object)) {
                yield DirectoryAttributes::fromArray([
                    StorageAttributes::ATTRIBUTE_PATH => $childPath
                ]);
                if ($deep) {
                    yield from $this->listContents($childPath, true);
                }
            } else {
                $lastModified = $object['{DAV:}getlastmodified'] ?? null;
                $fileSize = $object['content-length'] ?? $object['{DAV:}getcontentlength'] ?? null;
                if ($fileSize !== null) {
                    $fileSize = (int)$fileSize;
                }
                yield FileAttributes::fromArray([
                    StorageAttributes::ATTRIBUTE_PATH => $childPath,
                    StorageAttributes::ATTRIBUTE_FILE_SIZE => $fileSize,
                    StorageAttributes::ATTRIBUTE_LAST_MODIFIED => $lastModified !== null ? strtotime($lastModified) : null,
                    StorageAttributes::ATTRIBUTE_MIME_TYPE => $object['content-type'] ?? $object['{DAV:}getcontenttype'] ?? null,
                ]);
            }
        }
    }

    private static function ensureFileAttributes(?StorageAttributes $metadata, string $path, string $metadataType): FileAttributes
    {
        if ($metadata === null) {
            throw UnableToRetrieveMetadata::create($path, $metadataType, 'file not found');
        }
        if ($metadata instanceof DirectoryAttributes) {
            throw UnableToRetrieveMetadata::create($path, $metadataType, 'not a file');
        }
        if ($metadata instanceof FileAttributes) {
            return $metadata;
        }
        throw new LogicException("never happen");
    }

    /**
     * {@inheritdoc}
     */
    public function fileSize(string $path): FileAttributes
    {
        $metadata = $this->getMetadata($path, StorageAttributes::ATTRIBUTE_FILE_SIZE);
        return self::ensureFileAttributes($metadata, $path, StorageAttributes::ATTRIBUTE_FILE_SIZE);
    }

    /**
     * {@inheritdoc}
     */
    public function lastModified(string $path): FileAttributes
    {
        $metadata = $this->getMetadata($path, StorageAttributes::ATTRIBUTE_LAST_MODIFIED);
        return self::ensureFileAttributes($metadata, $path, StorageAttributes::ATTRIBUTE_LAST_MODIFIED);
    }

    /**
     * {@inheritdoc}
     */
    public function mimeType(string $path): FileAttributes
    {
        $metadata = $this->getMetadata($path, StorageAttributes::ATTRIBUTE_MIME_TYPE);
        return self::ensureFileAttributes($metadata, $path, StorageAttributes::ATTRIBUTE_MIME_TYPE);
    }

    /**
     * Copy a file through WebDav COPY method.
     *
     * @param string $source
     * @param string $destination
     * @throws FilesystemException
     */
    protected function nativeCopy(string $source, string $destination, Config $config): void
    {
        $directory = dirname($destination);
        if ($directory === '.') {
            $directory = '';
        }
        $this->createDirectory($directory, $config);

        $location = $this->encodePath($source);
        try {
            ['statusCode' => $statusCode] = $this->client->request('COPY', '/' . ltrim($location, '/'), null, [
                'Destination' => $this->client->getAbsoluteUrl($this->encodePath($destination)),
            ]);
        } catch (ClientHttpException $exception) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $exception);
        }

        if ($statusCode < 200 || 300 <= $statusCode) {
            throw UnableToCopyFile::fromLocationTo($source, $destination);
        }
    }

    /**
     * @param array $object
     * @return bool
     */
    protected function isDirectory(array $object)
    {
        $resourceType = $object['{DAV:}resourcetype'] ?? null;
        if ($resourceType instanceof ResourceType) {
            return $resourceType->is('{DAV:}collection');
        }

        return ($object['{DAV:}iscollection'] ?? null) === '1';
    }
}

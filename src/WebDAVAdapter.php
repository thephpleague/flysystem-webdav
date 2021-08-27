<?php

namespace League\Flysystem\WebDAV;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\Util;
use LogicException;
use RuntimeException;
use Sabre\DAV\Client;
use Sabre\DAV\Exception;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Xml\Property\ResourceType;
use Sabre\HTTP\ClientHttpException;
use Sabre\HTTP\HttpException;

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

    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        $location = $this->encodePath($path);

        try {
            $result = $this->client->propFind($location, static::$metadataFields);

            if (empty($result)) {
                return false;
            }

            return $this->normalizeObject($result, $path);
        } catch (Exception $e) {
            return false;
        } catch (HttpException $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
        return $this->getMetadata($path);
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
     * {@inheritdoc}
     */
    public function write($path, $contents, Config $config)
    {
        if (!$this->createDir(Util::dirname($path), $config)) {
            return false;
        }

        $location = $this->encodePath($path);
        $response = $this->client->request('PUT', $location, $contents);

        if ($response['statusCode'] >= 400) {
            return false;
        }

        $result = compact('path', 'contents');

        if ($config->get('visibility')) {
            throw new LogicException(__CLASS__.' does not support visibility settings.');
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream($path, $resource, Config $config)
    {
        return $this->write($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->update($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function rename($path, $newpath)
    {
        $location = $this->encodePath($path);
        $newLocation = $this->encodePath($newpath);

        try {
            $response = $this->client->request('MOVE', '/'.ltrim($location, '/'), null, [
                'Destination' => '/'.ltrim($newLocation, '/'),
            ]);

            if ($response['statusCode'] >= 200 && $response['statusCode'] < 300) {
                return true;
            }
        } catch (NotFound $e) {
            // Would have returned false here, but would be redundant
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function copy($path, $newpath)
    {
        return $this->nativeCopy($path, $newpath);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($path)
    {
        $location = $this->encodePath($path);

        try {
            $response =  $this->client->request('DELETE', $location)['statusCode'];


            return $response >= 200 && $response < 300;
        } catch (NotFound $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createDir($path, Config $config)
    {
        $encodedPath = $this->encodePath($path);
        $path = trim($path, '/');

        $result = compact('path') + ['type' => 'dir'];

        if (Util::normalizeDirname($path) === '' || $this->has($path)) {
            return $result;
        }

        $directories = explode('/', $path);
        if (count($directories) > 1) {
            $parentDirectories = array_splice($directories, 0, count($directories) - 1);
            if (!$this->createDir(implode('/', $parentDirectories), $config)) {
                return false;
            }
        }

        $response = $this->client->request('MKCOL', $encodedPath . '/');

        if ($response['statusCode'] !== 201) {
            return false;
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDir($dirname)
    {
        return $this->delete($dirname);
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

    /**
     * {@inheritdoc}
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Copy a file through WebDav COPY method.
     *
     * @param string $path
     * @param string $newPath
     *
     * @return bool
     */
    protected function nativeCopy($path, $newPath)
    {
        if (!$this->createDir(Util::dirname($newPath), new Config())) {
            return false;
        }

        $location = $this->encodePath($path);
        $newLocation = $this->encodePath($newPath);

        try {
            $destination = $this->client->getAbsoluteUrl($newLocation);
            $response = $this->client->request('COPY', '/'.ltrim($location, '/'), null, [
                'Destination' => $destination,
            ]);

            if ($response['statusCode'] >= 200 && $response['statusCode'] < 300) {
                return true;
            }
        } catch (NotFound $e) {
            // Would have returned false here, but would be redundant
        }

        return false;
    }

    /**
     * Normalise a WebDAV repsonse object.
     *
     * @param array  $object
     * @param string $path
     *
     * @return array
     */
    protected function normalizeObject(array $object, $path)
    {
        if ($this->isDirectory($object)) {
            return ['type' => 'dir', 'path' => trim($path, '/')];
        }

        $result = [
            'type' => 'file',
            'path' => trim($path, '/'),
            'size' => $object['content-length'] ?? $object['{DAV:}getcontentlength'] ?? null,
            'mimetype' => $object['content-type'] ?? $object['{DAV:}getcontenttype'] ?? null,
        ];

        if (isset($object['{DAV:}getlastmodified'])) {
            $result['timestamp'] = strtotime($object['{DAV:}getlastmodified']);
        }

        return $result;
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

<?php

declare(strict_types=1);

namespace League\Flysystem\WebDAV;

use League\Flysystem\FilesystemAdapter;

class ByteMarkWebDAVServerTest extends WebDAVAdapterTestCase
{
    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        if (($_ENV['TEST_WEBDAV'] ?? '') !== 'YES') {
            self::markTestSkipped('Library regression');
        }
        $client = new UrlPrefixingClientStub(['baseUri' => 'http://localhost:4080/', 'userName' => 'alice', 'password' => 'secret1234']);

        return new WebDAVAdapter($client, manualCopy: true, manualMove: true);
    }
}

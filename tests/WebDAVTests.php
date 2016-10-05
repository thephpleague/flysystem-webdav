<?php

use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\WebDAV\WebDAVAdapter;

class WebDAVTests extends PHPUnit_Framework_TestCase
{
    protected function getClient()
    {
        return Mockery::mock('Sabre\DAV\Client');
    }

    public function testHas()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('propFind')->once()->andReturn([
            '{DAV:}getcontentlength' => 20,
        ]);
        $adapter = new Filesystem(new WebDAVAdapter($mock));
        $this->assertTrue($adapter->has('something'));
    }

    /**
     * @dataProvider provideExceptionsForHasFail
     */
    public function testHasFail($exceptionClass)
    {
        $mock = $this->getClient();
        $mock->shouldReceive('propFind')->once()->andThrow($exceptionClass);
        $adapter = new WebDAVAdapter($mock);
        $this->assertFalse($adapter->has('something'));
    }

    public function provideExceptionsForHasFail()
    {
        return [
            [Mockery::mock('Sabre\DAV\Exception\NotFound')],
            [Mockery::mock('Sabre\HTTP\ClientHttpException')],
        ];
    }

    public function testWrite()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->once()->andReturn([
            'statusCode' => 200,
        ]);
        $adapter = new WebDAVAdapter($mock);
        $this->assertInternalType('array', $adapter->write('something', 'something', new Config()));
    }

    public function testWriteFail()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->with('PUT', 'something', 'something')->once()->andReturn([
            'statusCode' => 500,
        ]);
        $adapter = new WebDAVAdapter($mock);
        $result = $adapter->write('something', 'something', new Config());
        $this->assertFalse($result);
    }

    public function testUpdate()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->once();
        $adapter = new WebDAVAdapter($mock);
        $this->assertInternalType('array', $adapter->update('something', 'something', new Config()));
    }

    /**
     * @expectedException LogicException
     */
    public function testWriteVisibility()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->once()->andReturn([
            'statusCode' => 200,
        ]);
        $adapter = new WebDAVAdapter($mock);
        $this->assertInternalType('array', $adapter->write('something', 'something', new Config([
            'visibility' => 'private',
        ])));
    }

    public function testReadStream()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->andReturn([
            'statusCode' => 200,
            'body' => 'contents',
            'headers' => [
                'last-modified' => date('Y-m-d H:i:s'),
            ],
        ]);
        $adapter = new WebDAVAdapter($mock, 'bucketname', 'prefix');
        $result = $adapter->readStream('file.txt');
        $this->assertInternalType('resource', $result['stream']);
    }

    public function testRename()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->once()->andReturn([
            'statusCode' => 200,
        ]);
        $adapter = new WebDAVAdapter($mock, 'bucketname');
        $result = $adapter->rename('old', 'new');
        $this->assertTrue($result);
    }

    public function testRenameFail()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->once()->andReturn([
            'statusCode' => 404,
        ]);
        $adapter = new WebDAVAdapter($mock, 'bucketname');
        $result = $adapter->rename('old', 'new');
        $this->assertFalse($result);
    }

    public function testRenameFailException()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->once()->andThrow('Sabre\DAV\Exception\NotFound');
        $adapter = new WebDAVAdapter($mock, 'bucketname');
        $result = $adapter->rename('old', 'new');
        $this->assertFalse($result);
    }

    public function testDeleteDir()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->with('DELETE', 'some/dirname')->once()->andReturn(true);
        $adapter = new WebDAVAdapter($mock);
        $result = $adapter->deleteDir('some/dirname');
        $this->assertTrue($result);
    }

    public function testDeleteDirFail()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->with('DELETE', 'some/dirname')->once()->andThrow('Sabre\DAV\Exception\NotFound');
        $adapter = new WebDAVAdapter($mock);
        $result = $adapter->deleteDir('some/dirname');
        $this->assertFalse($result);
    }

    public function testListContents()
    {
        $mock = $this->getClient();
        $first = [
            [],
            'filename' => [
                '{DAV:}getcontentlength' => 20,
            ],
            'dirname' => [],
        ];

        $second = [
            [],
            'deeper_filename.ext' => [
                '{DAV:}getcontentlength' => 20,
            ],
        ];
        $mock->shouldReceive('propFind')->twice()->andReturn($first, $second);
        $adapter = new WebDAVAdapter($mock, 'bucketname');
        $listing = $adapter->listContents('', true);
        $this->assertInternalType('array', $listing);
    }

    public function methodProvider()
    {
        return [
            ['getMetadata'],
            ['getTimestamp'],
            ['getMimetype'],
            ['getSize'],
        ];
    }

    /**
     * @dataProvider  methodProvider
     */
    public function testMetaMethods($method)
    {
        $mock = $this->getClient();
        $mock->shouldReceive('propFind')->once()->andReturn([
            '{DAV:}displayname' => 'object.ext',
            '{DAV:}getcontentlength' => 30,
            '{DAV:}getcontenttype' => 'plain/text',
            '{DAV:}getlastmodified' => date('Y-m-d H:i:s'),
        ]);
        $adapter = new WebDAVAdapter($mock);
        $result = $adapter->{$method}('object.ext');
        $this->assertInternalType('array', $result);
    }

    public function testCreateDir()
    {
        /** @var Sabre\DAV\Client|Mockery\Mock $mock */
        $mock = $this->getClient();

        $mock->shouldReceive('propFind')
            ->once()
            ->andThrow(new \Sabre\DAV\Exception('Not found'));

        $mock->shouldReceive('request')
            ->once()
            ->with('MKCOL', 'dirname')
            ->andReturn([
                'statusCode' => 201,
            ]);

        $adapter = new WebDAVAdapter($mock);
        $result = $adapter->createDir('dirname', new Config());
        $this->assertInternalType('array', $result);
    }

    public function testCreateDirRecursive()
    {
        /** @var Sabre\DAV\Client|Mockery\Mock $mock */
        $mock = $this->getClient();

        $mock->shouldReceive('propFind')
            ->times(2)
            ->andThrow(new \Sabre\DAV\Exception('Not found'));

        $mock->shouldReceive('request')
            ->once()
            ->with('MKCOL', 'dirname')
            ->andReturn([
                'statusCode' => 201,
            ]);

        $mock->shouldReceive('request')
            ->once()
            ->with('MKCOL', 'dirname/subdirname')
            ->andReturn([
                'statusCode' => 201,
            ]);

        $adapter = new WebDAVAdapter($mock);
        $result = $adapter->createDir('dirname/subdirname', new Config());
        $this->assertInternalType('array', $result);
    }

    public function testCreateDirIfExists()
    {
        /** @var Sabre\DAV\Client|Mockery\Mock $mock */
        $mock = $this->getClient();

        $mock->shouldReceive('propFind')
            ->once()
            ->andReturn([
                '{DAV:}displayname' => 'dirname',
                '{DAV:}getcontentlength' => 30,
                '{DAV:}getcontenttype' => 'dir',
                '{DAV:}getlastmodified' => date('Y-m-d H:i:s'),
            ]);

        $mock->shouldReceive('request')
            ->never();

        $adapter = new WebDAVAdapter($mock);
        $result = $adapter->createDir('dirname', new Config());
        $this->assertInternalType('array', $result);
    }

    public function testCreateDirFail()
    {
        /** @var Sabre\DAV\Client|Mockery\Mock $mock */
        $mock = $this->getClient();

        $mock->shouldReceive('propFind')
            ->once()
            ->andThrow(new \Sabre\DAV\Exception('Not found'));

        $mock->shouldReceive('request')
            ->once()
            ->with('MKCOL', 'dirname')
            ->andReturn([
                'statusCode' => 500,
            ]);

        $adapter = new WebDAVAdapter($mock);
        $result = $adapter->createDir('dirname', new Config());
        $this->assertFalse($result);
    }

    public function testRead()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->andReturn([
            'statusCode' => 200,
            'body' => 'contents',
            'headers' => [
                'last-modified' => [date('Y-m-d H:i:s')],
            ],
        ]);
        $adapter = new WebDAVAdapter($mock, 'bucketname', 'prefix');
        $result = $adapter->read('file.txt');
        $this->assertInternalType('array', $result);
    }

    public function testReadFail()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->andReturn([
            'statusCode' => 404,
            'body' => 'contents',
            'headers' => [
                'last-modified' => [date('Y-m-d H:i:s')],
            ],
        ]);
        $adapter = new WebDAVAdapter($mock, 'bucketname', 'prefix');
        $result = $adapter->read('file.txt');
        $this->assertFalse($result);
    }

    public function testReadStreamFail()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->andReturn([
            'statusCode' => 404,
            'body' => 'contents',
            'headers' => [
                'last-modified' => [date('Y-m-d H:i:s')],
            ],
        ]);
        $adapter = new WebDAVAdapter($mock, 'bucketname', 'prefix');
        $result = $adapter->readStream('file.txt');
        $this->assertFalse($result);
    }

    public function testReadException()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->andThrow('Sabre\DAV\Exception\NotFound');
        $adapter = new WebDAVAdapter($mock, 'bucketname', 'prefix');
        $result = $adapter->read('file.txt');
        $this->assertFalse($result);
    }

    public function testNativeCopy()
    {
        /** @var Sabre\DAV\Client|Mockery\Mock $clientMock */
        $clientMock = $this->getClient();

        $clientMock->shouldReceive('getAbsoluteUrl')->andReturn('http://webdav.local/prefix/newFile.txt');

        $clientMock->shouldReceive('request')->andReturn([
            'statusCode' => 201
        ]);

        $adapter = new WebDAVAdapter($clientMock, 'prefix', false);
        $result = $adapter->copy('file.txt', 'newFile.txt');
        $this->assertTrue($result);
    }
}

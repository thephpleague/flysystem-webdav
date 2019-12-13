<?php

use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\WebDAV\WebDAVAdapter;
use PHPUnit\Framework\TestCase;

class WebDAVTests extends TestCase
{
    use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

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

    public function testWriteStream()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->once()->andReturn([
            'statusCode' => 200,
        ]);
        $adapter = new WebDAVAdapter($mock);

        $tmp = $this->getLargeTmpStream();

        $this->assertInternalType('array', $adapter->writeStream('something', $tmp, new Config()));

        if (is_resource($tmp)) {
            fclose($tmp);
        }
    }

    protected function getLargeTmpStream()
    {
        $size = intval($this->getMemoryLimit() * 1.5);
        $tmp = tmpfile();
        fseek($tmp, $size);
        fprintf($tmp, 'a');
        fflush($tmp);

        return $tmp;
    }

    protected function getMemoryLimit()
    {
        $unit_factor = [
            ''  => 0,
            'K' => 1,
            'M' => 2,
            'G' => 3,
        ];

        if (!preg_match("/^(\d+)([KMG]?)$/i", ini_get('memory_limit'), $match)) {
            throw new Exception('invalid memory_limit?');
        }

        $limit = $match[1] * pow(1024, $unit_factor[strtoupper($match[2])]);

        return $limit;
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
        $mock->shouldReceive('request')->with('DELETE', 'some/dirname')->once()->andReturn(['statusCode' => 200]);
        $adapter = new WebDAVAdapter($mock);
        $result = $adapter->deleteDir('some/dirname');
        $this->assertTrue($result);
    }

    public function testDeleteDirFailNotFound()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->with('DELETE', 'some/dirname')->once()->andThrow('Sabre\DAV\Exception\NotFound');
        $adapter = new WebDAVAdapter($mock);
        $result = $adapter->deleteDir('some/dirname');
        $this->assertFalse($result);
    }

    public function testDeleteDirFailNot200Status()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->with('DELETE', 'some/dirname')->once()->andReturn(['statusCode' => 403]);
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
                '{DAV:}getcontentlength' => "20",
                '{DAV:}iscollection' => "0",
            ],
            'dirname' => [
                '{DAV:}getcontentlength' => "0",
                '{DAV:}iscollection' => "1",
            ],
        ];

        $second = [
            [],
            'deeper_filename.ext' => [
                '{DAV:}getcontentlength' => "20",
                '{DAV:}iscollection' => "0",
            ],
        ];
        $mock->shouldReceive('propFind')->twice()->andReturn($first, $second);
        $adapter = new WebDAVAdapter($mock, 'bucketname');
        $listing = $adapter->listContents('', true);
        $this->assertInternalType('array', $listing);
    }

    public function testListContentsWithPlusInName()
    {
        $mock = $this->getClient();
        $first = [
            [],
            'bucketname/dirname+something' => [
                '{DAV:}getcontentlength' => "0",
                '{DAV:}iscollection' => "1",
            ],
        ];

        $mock->shouldReceive('propFind')->once()->andReturn($first);
        $adapter = new WebDAVAdapter($mock, 'bucketname');
        $listing = $adapter->listContents('', false);
        $this->assertInternalType('array', $listing);
        $this->assertCount(1, $listing);
        $this->assertEquals('dirname+something', $listing[0]['path']);
    }

    public function testListContentsWithUrlEncodedSpaceInName()
    {
        $mock = $this->getClient();
        $first = [
            [],
            '/My%20Library/New%20Record%201.mp3' => [
                '{DAV:}displayname' => "New Record 1.mp3",
                '{DAV:}getcontentlength' => "8223370",
            ],
        ];

        $mock->shouldReceive('propFind')->once()->andReturn($first);
        $adapter = new WebDAVAdapter($mock, '/My Library');
        $listing = $adapter->listContents('', false);
        $this->assertInternalType('array', $listing);
        $this->assertCount(1, $listing);
        $this->assertEquals('New Record 1.mp3', $listing[0]['path']);
        $this->assertEquals('file', $listing[0]['type']);
        $this->assertEquals('8223370', $listing[0]['size']);
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
            ->with('MKCOL', 'dirname/')
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
            ->with('MKCOL', 'dirname/')
            ->andReturn([
                'statusCode' => 201,
            ]);

        $mock->shouldReceive('request')
            ->once()
            ->with('MKCOL', 'dirname/subdirname/')
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
            ->with('MKCOL', 'dirname/')
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

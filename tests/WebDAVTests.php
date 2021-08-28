<?php

use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\WebDAV\WebDAVAdapter;
use PHPUnit\Framework\TestCase;
use Sabre\HTTP\Response;

class WebDAVTests extends TestCase
{
    use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    /**
     * @return \Mockery\LegacyMockInterface|\Mockery\MockInterface|\Sabre\DAV\Client
     */
    protected function getClient()
    {
        $mock = Mockery::mock(Sabre\DAV\Client::class);
        $mock->shouldReceive('setThrowExceptions')->once();
        return $mock;
    }

    protected function newClientHttpException(int $httpStatus, array $headers = [], ?string $body = null)
    {
        return new Sabre\HTTP\ClientHttpException(new Response($httpStatus, $headers, $body));
    }

    public function testFileExists()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('propFind')->once()->andReturn([
            '{DAV:}getcontentlength' => 20,
        ]);
        $adapter = new Filesystem(new WebDAVAdapter($mock));
        $this->assertTrue($adapter->fileExists('something'));
    }

    public function testHasFail()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('propFind')->once()->andThrow($this->newClientHttpException(404));
        $adapter = new WebDAVAdapter($mock);
        $this->assertFalse($adapter->fileExists('something'));
    }

    public function testWrite()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->once()->andReturn([
            'statusCode' => 200,
        ]);
        $adapter = new WebDAVAdapter($mock);
        $adapter->write('something', 'something', new Config());
    }

    public function testWriteFail()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->with('PUT', 'something', 'something')->once()->andThrow($this->newClientHttpException(500));
        $adapter = new WebDAVAdapter($mock);
        $this->expectException(UnableToWriteFile::class);
        $adapter->write('something', 'something', new Config());
    }

    public function testWriteStream()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->once()->andReturn([
            'statusCode' => 200,
        ]);
        $adapter = new WebDAVAdapter($mock);

        $tmp = $this->getLargeTmpStream();

        $adapter->writeStream('something', $tmp, new Config());

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

    public function testWriteVisibility()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->never();
        $adapter = new WebDAVAdapter($mock);
        $this->expectException(LogicException::class);
        $adapter->write('something', 'something', new Config([
            'visibility' => 'private',
        ]));
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
        $adapter = new WebDAVAdapter($mock);
        $resource = $adapter->readStream('file.txt');
        $this->assertIsResource($resource);
        $result = "";
        while (!feof($resource)) {
            $read = fread($resource, 100);
            $this->assertIsString($read);
            $result .= $read;
        }
        $this->assertSame('contents', $result);
    }

    public function testRename()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->once()->andReturn([
            'statusCode' => 200,
        ]);
        $adapter = new WebDAVAdapter($mock);
        $adapter->move('old', 'new', new Config());
    }

    public function testRenameFail()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->once()->andReturn([
            'statusCode' => 404,
        ]);
        $adapter = new WebDAVAdapter($mock);
        $this->expectException(UnableToMoveFile::class);
        $adapter->move('old', 'new', new Config());
    }

    public function testRenameFailException()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->once()->andThrow($this->newClientHttpException(500));
        $adapter = new WebDAVAdapter($mock);
        $this->expectException(UnableToMoveFile::class);
        $adapter->move('old', 'new', new Config());
    }

    public function testDeleteDir()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->with('DELETE', 'some/dirname')->once()->andReturn(['statusCode' => 200]);
        $adapter = new WebDAVAdapter($mock);
        $adapter->deleteDirectory('some/dirname');
    }

    public function testDeleteDirFailNotFound()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->with('DELETE', 'some/dirname')->once()->andThrow($this->newClientHttpException(404));
        $adapter = new WebDAVAdapter($mock);
        $this->expectException(UnableToDeleteDirectory::class);
        $adapter->deleteDirectory('some/dirname');
    }

    public function testDeleteDirFailNot200Status()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->with('DELETE', 'some/dirname')->once()->andReturn(['statusCode' => 403]);
        $adapter = new WebDAVAdapter($mock);
        $this->expectException(UnableToDeleteDirectory::class);
        $adapter->deleteDirectory('some/dirname');
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
        $adapter = new WebDAVAdapter($mock);
        $listing = $adapter->listContents('', true);
        $this->assertInstanceOf(Generator::class, $listing);
        iterator_to_array($listing);
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
        $adapter = new WebDAVAdapter($mock);
        $listing = $adapter->listContents('', false);
        $this->assertInstanceOf(Generator::class, $listing);
        $listing = iterator_to_array($listing);
        $this->assertCount(1, $listing);
        $this->assertEquals('bucketname/dirname+something', $listing[0]['path']);
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
        $adapter = new WebDAVAdapter($mock);
        $listing = $adapter->listContents('', false);
        $this->assertInstanceOf(Generator::class, $listing);
        $listing = iterator_to_array($listing);
        $this->assertCount(1, $listing);
        $attributes = $listing[0];
        $this->assertInstanceOf(\League\Flysystem\FileAttributes::class, $attributes);
        $this->assertEquals('My Library/New Record 1.mp3', $attributes->path());
        $this->assertEquals('file', $attributes->type());
        $this->assertEquals(8223370, $attributes->fileSize());
    }

    public function methodProvider(): array
    {
        return [
            ['lastModified'],
            ['mimeType'],
            ['fileSize'],
        ];
    }

    /**
     * @dataProvider  methodProvider
     */
    public function testMetaMethods(string $method)
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
        $this->assertInstanceOf(\League\Flysystem\FileAttributes::class, $result);
    }

    public function testCreateDir()
    {
        /** @var Sabre\DAV\Client|Mockery\Mock $mock */
        $mock = $this->getClient();

        $mock->shouldReceive('propFind')
            ->once()
            ->andThrow($this->newClientHttpException(404));

        $mock->shouldReceive('request')
            ->once()
            ->with('MKCOL', 'dirname/')
            ->andReturn([
                'statusCode' => 201,
            ]);

        $adapter = new WebDAVAdapter($mock);
        $adapter->createDirectory('dirname', new Config());
    }

    public function testCreateDirRecursive()
    {
        /** @var Sabre\DAV\Client|Mockery\Mock $mock */
        $mock = $this->getClient();

        $mock->shouldReceive('propFind')
            ->times(2)
            ->andThrow($this->newClientHttpException(404));

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
        $adapter->createDirectory('dirname/subdirname', new Config());
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
        $adapter->createDirectory('dirname', new Config());
    }

    public function testCreateDirFail()
    {
        /** @var Sabre\DAV\Client|Mockery\Mock $mock */
        $mock = $this->getClient();

        $mock->shouldReceive('propFind')
            ->once()
            ->andThrow($this->newClientHttpException(404));

        $mock->shouldReceive('request')
            ->once()
            ->with('MKCOL', 'dirname/')
            ->andReturn([
                'statusCode' => 500,
            ]);

        $adapter = new WebDAVAdapter($mock);
        $this->expectException(UnableToCreateDirectory::class);
        $adapter->createDirectory('dirname', new Config());
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
        $adapter = new WebDAVAdapter($mock);
        $result = $adapter->read('file.txt');
        $this->assertSame('contents', $result);
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
        $adapter = new WebDAVAdapter($mock);
        $this->expectException(UnableToReadFile::class);
        $adapter->read('file.txt');
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
        $adapter = new WebDAVAdapter($mock);
        $this->expectException(UnableToReadFile::class);
        $adapter->readStream('file.txt');
    }

    public function testReadException()
    {
        $mock = $this->getClient();
        $mock->shouldReceive('request')->andThrow($this->newClientHttpException(404));
        $adapter = new WebDAVAdapter($mock);
        $this->expectException(UnableToReadFile::class);
        $adapter->read('file.txt');
    }

    public function testNativeCopy()
    {
        /** @var Sabre\DAV\Client|Mockery\Mock $clientMock */
        $clientMock = $this->getClient();

        $clientMock->shouldReceive('getAbsoluteUrl')->andReturn('http://webdav.local/prefix/newFile.txt');

        $clientMock->shouldReceive('request')->andReturn([
            'statusCode' => 201
        ]);

        $adapter = new WebDAVAdapter($clientMock);
        $adapter->copy('file.txt', 'newFile.txt', new Config());
    }
}

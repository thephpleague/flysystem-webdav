<?php

use League\Flysystem\Filesystem;
use League\Flysystem\Plugin\ListPaths;
use League\Flysystem\WebDAV\WebDAVAdapter;
use PHPUnit\Framework\TestCase;

class WebDAVIntegrationTests extends TestCase
{
    use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    /** @var Filesystem */
    protected $filesystem;

    protected function setUp()
    {
        $client = new Sabre\DAV\Client([
            'baseUri' => 'http://localhost',
            'userName' => 'alice',
            'password' => 'secret1234',
        ]);

        $this->filesystem = new Filesystem(new WebDAVAdapter($client));
        $this->filesystem->addPlugin(new ListPaths());

        foreach ($this->filesystem->listContents('', true) as $item) {
            if ($item['path'] === '') {
                continue;
            }

            if ($item['type'] === 'dir') {
                $this->filesystem->deleteDir($item['path']);
            } else {
                $this->filesystem->delete($item['path']);
            }
        }
    }

    /**
     * @test
     */
    public function writing_reading_deleting()
    {
        $filesystem = $this->filesystem;
        $this->assertTrue($filesystem->put('path.txt', 'file contents'));
        $this->assertEquals('file contents', $filesystem->read('path.txt'));
        $this->assertTrue($filesystem->delete('path.txt'));
    }


    /**
     * @test
     */
    public function creating_a_directory()
    {
        $this->filesystem->createDir('dirname/directory');
        $metadata = $this->filesystem->getMetadata('dirname/directory');
        self::assertEquals('dir', $metadata['type']);
        $this->filesystem->deleteDir('dirname');
    }

    /**
     * @test
     */
    public function writing_in_a_directory_and_deleting_the_directory()
    {
        $filesystem = $this->filesystem;
        $this->assertTrue($filesystem->write('deeply/nested/path.txt', 'contents'));
        $this->assertTrue($filesystem->has('deeply/nested'));
        $this->assertTrue($filesystem->has('deeply'));
        $this->assertTrue($filesystem->has('deeply/nested/path.txt'));
        $this->assertTrue($filesystem->deleteDir('deeply/nested'));
        $this->assertFalse($filesystem->has('deeply/nested'));
        $this->assertFalse($filesystem->has('deeply/nested/path.txt'));
        $this->assertTrue($filesystem->has('deeply'));
        $this->assertTrue($filesystem->deleteDir('deeply'));
        $this->assertFalse($filesystem->has('deeply'));
    }

    /**
     * @test
     */
    public function listing_files_of_a_directory()
    {
        $filesystem = $this->filesystem;
        $filesystem->write('dirname/a.txt', 'contents');
        $filesystem->write('dirname/b/b.txt', 'contents');
        $filesystem->write('dirname/c.txt', 'contents');
        $files = $filesystem->listPaths('', true);
        $expected = ['dirname', 'dirname/a.txt', 'dirname/b', 'dirname/b/b.txt', 'dirname/c.txt'];
        $filesystem->deleteDir('dirname');
        $this->assertEquals($expected, $files);
    }
}

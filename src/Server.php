<?php

namespace think\ChunkUpload;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use think\exception\HttpException;
use think\File;
use think\Request;

class Server
{
    /** @var Request */
    protected $request;

    protected $onComplete;

    /** @var Filesystem */
    protected $filesystem;

    protected $tempDir;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    public function serve(Request $request, $onComplete, $tempDir = null)
    {
        $this->request    = $request;
        $this->onComplete = $onComplete;
        $this->tempDir    = $tempDir ?: sys_get_temp_dir();

        $stage = $request->header('x-stage');

        if (empty($stage)) {
            return $this->single();
        } else {
            return $this->{$stage}();
        }
    }

    protected function single()
    {
        $filename = Path::join($this->tempDir, Uuid::uuid4()->toString());

        try {
            $content = $this->request->getContent();

            $this->filesystem->dumpFile($filename, $content);

            $result = $this->onComplete($filename);

            if (!empty($result)) {
                return json($result);
            }

            return response('', 204);
        } finally {
            $this->filesystem->remove($filename);
        }
    }

    protected function initiate()
    {
        $id = $this->createTemporaryDirectory();

        return response('', 201)->header([
            'x-id' => $id,
        ]);
    }

    protected function upload()
    {
        $dir = $this->getTemporaryDirectory();

        $index = $this->request->header('x-index');

        if (empty($index)) {
            throw new HttpException(400);
        }

        $content = $this->request->getContent();
        $sha1    = sha1($content);

        $this->filesystem->dumpFile(Path::join($dir, "part-{$index}-{$sha1}"), $content);

        return response('', 202)->header([
            'ETag' => $sha1,
        ]);
    }

    protected function complete()
    {
        $dir = $this->getTemporaryDirectory();

        $parts = $this->request->param('parts');
        if (empty($parts)) {
            throw new HttpException(400);
        }

        $dest = Path::join($dir, "file");

        $fp = fopen($dest, 'w');

        try {
            foreach ($parts as $part) {
                $filename = Path::join($dir, "part-{$part['index']}-{$part['etag']}");
                if (!$this->filesystem->exists($filename)) {
                    throw new HttpException(400, 'InvalidPart');
                }
                $content = file_get_contents($filename);
                fwrite($fp, $content);
            }

            fclose($fp);

            $result = $this->onComplete($dest);

            if (!empty($result)) {
                return json($result);
            }

            return response('', 204);
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
            $this->filesystem->remove($dir);
        }
    }

    protected function onComplete($filename)
    {
        if ($this->onComplete) {
            $metadata = json_decode($this->request->header('x-metadata', ''), true);
            return call_user_func($this->onComplete, new File($filename), $metadata);
        }
    }

    protected function createTemporaryDirectory()
    {
        $id = Uuid::uuid4()->toString();

        $dir = Path::join($this->tempDir, $id);

        $this->filesystem->mkdir($dir);

        return $id;
    }

    protected function getTemporaryDirectory()
    {
        $id = $this->request->header('x-id');
        if (empty($id)) {
            throw new HttpException(400);
        }

        $dir = Path::join($this->tempDir, $id);

        if (!$this->filesystem->exists($dir)) {
            throw new HttpException(400);
        }

        return $dir;
    }
}

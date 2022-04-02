<?php

namespace think\ChunkUpload;

use Ramsey\Uuid\Uuid;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use think\exception\HttpException;
use think\File;
use think\Request;

class Server
{
    /** @var Request */
    protected $request;

    protected $onComplete;

    public function serve(Request $request, $onComplete)
    {
        $this->request    = $request;
        $this->onComplete = $onComplete;

        $stage = $request->header('x-stage');

        return $this->{$stage}();
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

        file_put_contents($dir->path("part-{$index}-{$sha1}"), $content);

        return response()->header([
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

        $source = $dir->path('file');
        $fp     = fopen($source, 'w');
        try {
            foreach ($parts as $part) {
                $filename = $dir->path("part-{$part['index']}-{$part['etag']}");
                if (!file_exists($filename)) {
                    abort(400, 'InvalidPart');
                }
                $content = file_get_contents($filename);
                fwrite($fp, $content);
            }

            if ($this->onComplete) {
                call_user_func($this->onComplete, new File($source));
            }

            return response('', 204);
        } finally {
            fclose($fp);
            $dir->delete();
        }
    }

    protected function createTemporaryDirectory()
    {
        $id = Uuid::uuid4()->toString();

        (new TemporaryDirectory())
            ->name($id)
            ->force()
            ->create();

        return $id;
    }

    protected function getTemporaryDirectory()
    {
        $id = $this->request->header('x-id');
        if (empty($id)) {
            throw new HttpException(400);
        }
        $dir = (new TemporaryDirectory())
            ->name($id);

        if (!file_exists($dir->path())) {
            throw new HttpException(400);
        }

        return $dir;
    }
}

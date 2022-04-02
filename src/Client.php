<?php

namespace think\ChunkUpload;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use think\File;

class Client
{
    const BLOCK_SIZE = 1024 ** 2 * 4;

    protected $endpoint;

    protected $method;

    /** @var \GuzzleHttp\Client */
    protected $client;

    public function __construct($endpoint, $method = 'PUT', $headers = [])
    {
        $this->endpoint = $endpoint;
        $this->method   = $method;

        $this->client = new \GuzzleHttp\Client([
            'headers' => array_merge([
                'Accept' => 'application/json',
            ], $headers),
        ]);
    }

    public function upload(File $file)
    {
        if ($file->getSize() <= self::BLOCK_SIZE) {
            $this->client->request($this->method, $this->endpoint, [
                'body' => Psr7\Utils::streamFor($file->openFile()),
            ]);
        } else {
            $id    = $this->doInitiate();
            $parts = $this->doUpload($id, $file);
            $this->doComplete($id, $parts);
        }
    }

    protected function doInitiate()
    {
        $response = $this->client->request($this->method, $this->endpoint, [
            'headers' => [
                'x-stage' => 'initiate',
            ],
        ]);

        return $response->getHeaderLine('x-id');
    }

    protected function doUpload($id, File $file)
    {
        $stream = Psr7\Utils::streamFor($file->openFile());

        $requests = function () use ($id, $stream) {
            $total = ceil($stream->getSize() / self::BLOCK_SIZE);
            for ($i = 0; $i < $total; $i++) {
                $fileBlock = $stream->read(self::BLOCK_SIZE);
                yield new Request($this->method, $this->endpoint, [
                    'x-stage' => 'upload',
                    'x-id'    => $id,
                    'x-index' => $i + 1,
                ], Psr7\Utils::streamFor($fileBlock));
            }
        };

        $parts = [];

        $pool = new Pool($this->client, $requests(), [
            'concurrency' => 3,
            'fulfilled'   => function (Response $response, $index) use (&$parts) {
                $etag = $response->getHeaderLine('etag');

                if (empty($etag)) {
                    throw new \InvalidArgumentException('etag header not found');
                }

                $parts[$index] = [
                    'index' => $index + 1,
                    'etag'  => $etag,
                ];
            },
            'rejected'    => function (RequestException $reason) {
                throw $reason;
            },
        ]);

        $pool->promise()->wait();

        ksort($parts);

        return $parts;
    }

    protected function doComplete($id, $parts)
    {
        $this->client->request($this->method, $this->endpoint, [
            'headers' => [
                'x-stage' => 'complete',
                'x-id'    => $id,
            ],
            'json'    => [
                'parts' => $parts,
            ],
        ]);
    }
}

<?php

namespace think\ChunkUpload;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7;
use InvalidArgumentException;
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

        $stack = HandlerStack::create();
        $stack->remove('allow_redirects');

        $this->client = new \GuzzleHttp\Client([
            'handler' => $stack,
            'headers' => array_merge([
                'Accept' => 'application/json',
            ], $headers),
        ]);
    }

    public function upload(File $file, $metadata = [])
    {
        if ($file->getSize() <= self::BLOCK_SIZE) {
            $response = $this->client->request($this->method, $this->endpoint, [
                'body'    => $this->getFileStream($file),
                'headers' => [
                    'x-metadata' => json_encode($metadata),
                ],
            ]);
        } else {
            $id       = $this->doInitiate();
            $parts    = $this->doUpload($id, $file);
            $response = $this->doComplete($id, $parts, $metadata);
        }

        return $response;
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
        $stream = $this->getFileStream($file);

        $total = ceil($stream->getSize() / self::BLOCK_SIZE);

        $parts = [];

        for ($i = 0; $i < $total; $i++) {
            $fileBlock = $stream->read(self::BLOCK_SIZE);

            $response = $this->client->request($this->method, $this->endpoint, [
                'body'    => Psr7\Utils::streamFor($fileBlock),
                'headers' => [
                    'x-stage' => 'upload',
                    'x-id'    => $id,
                    'x-index' => $i + 1,
                ],
            ]);

            $etag = $response->getHeaderLine('etag');

            if (empty($etag)) {
                throw new InvalidArgumentException('etag header not found');
            }

            $parts[$i] = [
                'index' => $i + 1,
                'etag'  => $etag,
            ];
        }

        return $parts;
    }

    protected function doComplete($id, $parts, $metadata = [])
    {
        return $this->client->request($this->method, $this->endpoint, [
            'headers' => [
                'x-stage'    => 'complete',
                'x-id'       => $id,
                'x-metadata' => json_encode($metadata),
            ],
            'json'    => [
                'parts' => $parts,
            ],
        ]);
    }

    protected function getFileStream(File $file)
    {
        return Psr7\Utils::streamFor($file->openFile(), ['size' => $file->getSize()]);
    }
}

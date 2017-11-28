<?php

namespace React\Http\Io;

use Psr\Http\Message\ServerRequestInterface;
use RingCentral\Psr7;

/**
 * [Internal] Parses a string body with "Content-Type: multipart/form-data" into structured data
 *
 * This is use internally to parse incoming request bodies into structured data
 * that resembles PHP's `$_POST` and `$_FILES` superglobals.
 *
 * @internal
 */
final class MultipartParser
{
    /**
     * @var string
     */
    protected $buffer = '';

    /**
     * @var string
     */
    protected $boundary;

    /**
     * @var ServerRequestInterface
     */
    protected $request;

    /**
     * @var HttpBodyStream
     */
    protected $body;

    /**
     * @var callable
     */
    protected $onDataCallable;

    /**
     * @var int|null
     */
    protected $maxFileSize;

    public static function parseRequest(ServerRequestInterface $request)
    {
        $parser = new self($request);
        return $parser->parse();
    }

    private function __construct(ServerRequestInterface $request)
    {
        $this->request = $request;
    }

    private function parse()
    {
        $this->buffer = (string)$this->request->getBody();

        $contentType = $this->request->getHeaderLine('content-type');
        if(!preg_match('/boundary="?(.*)"?$/', $contentType, $matches)) {
            return $this->request;
        }

        $this->boundary = $matches[1];
        $this->parseBuffer();

        return $this->request;
    }

    private function parseBuffer()
    {
        $chunks = explode('--' . $this->boundary, $this->buffer);
        $this->buffer = array_pop($chunks);
        foreach ($chunks as $chunk) {
            $chunk = $this->stripTrailingEOL($chunk);
            $this->parseChunk($chunk);
        }
    }

    private function parseChunk($chunk)
    {
        $pos = strpos($chunk, "\r\n\r\n");
        if ($pos === false) {
            return;
        }

        $headers = $this->parseHeaders((string)substr($chunk, 0, $pos));
        $body = (string)substr($chunk, $pos + 4);

        if (!isset($headers['content-disposition'])) {
            return;
        }

        $name = $this->getParameterFromHeader($headers['content-disposition'], 'name');
        if ($name === null) {
            return;
        }

        $filename = $this->getParameterFromHeader($headers['content-disposition'], 'filename');
        if ($filename !== null) {
            $this->parseFile(
                $name,
                $filename,
                isset($headers['content-type'][0]) ? $headers['content-type'][0] : null,
                $body
            );
        } else {
            $this->parsePost($name, $body);
        }
    }

    private function parseFile($name, $filename, $contentType, $contents)
    {
        $this->request = $this->request->withUploadedFiles($this->extractPost(
            $this->request->getUploadedFiles(),
            $name,
            $this->parseUploadedFile($filename, $contentType, $contents)
        ));
    }

    private function parseUploadedFile($filename, $contentType, $contents)
    {
        $size = strlen($contents);

        // no file selected (zero size and empty filename)
        if ($size === 0 && $filename === '') {
            return new UploadedFile(
                Psr7\stream_for(''),
                $size,
                UPLOAD_ERR_NO_FILE,
                $filename,
                $contentType
            );
        }

        // file exceeds MAX_FILE_SIZE value
        if ($this->maxFileSize !== null && $size > $this->maxFileSize) {
            return new UploadedFile(
                Psr7\stream_for(''),
                $size,
                UPLOAD_ERR_FORM_SIZE,
                $filename,
                $contentType
            );
        }

        return new UploadedFile(
            Psr7\stream_for($contents),
            $size,
            UPLOAD_ERR_OK,
            $filename,
            $contentType
        );
    }

    private function parsePost($name, $value)
    {
        $this->request = $this->request->withParsedBody($this->extractPost(
            $this->request->getParsedBody(),
            $name,
            $value
        ));

        if (strtoupper($name) === 'MAX_FILE_SIZE') {
            $this->maxFileSize = (int)$value;

            if ($this->maxFileSize === 0) {
                $this->maxFileSize = null;
            }
        }
    }

    private function parseHeaders($header)
    {
        $headers = array();

        foreach (explode("\r\n", trim($header)) as $line) {
            list($key, $values) = explode(':', $line, 2);
            $key = trim($key);
            $key = strtolower($key);
            $values = explode(';', $values);
            $values = array_map('trim', $values);
            $headers[$key] = $values;
        }

        return $headers;
    }

    private function getParameterFromHeader(array $header, $parameter)
    {
        foreach ($header as $part) {
            if (preg_match('/' . $parameter . '="?(.*)"$/', $part, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    private function stripTrailingEOL($chunk)
    {
        if (substr($chunk, -2) === "\r\n") {
            return substr($chunk, 0, -2);
        }

        return $chunk;
    }

    private function extractPost($postFields, $key, $value)
    {
        $chunks = explode('[', $key);
        if (count($chunks) == 1) {
            $postFields[$key] = $value;
            return $postFields;
        }

        $chunkKey = rtrim($chunks[0], ']');
        $parent = &$postFields;
        for ($i = 1; isset($chunks[$i]); $i++) {
            $previousChunkKey = $chunkKey;

            if ($previousChunkKey === '') {
                $parent[] = array();
                end($parent);
                $parent = &$parent[key($parent)];
            } else {
                if (!isset($parent[$previousChunkKey]) || !is_array($parent[$previousChunkKey])) {
                    $parent[$previousChunkKey] = array();
                }
                $parent = &$parent[$previousChunkKey];
            }

            $chunkKey = rtrim($chunks[$i], ']');
        }

        if ($chunkKey === '') {
            $parent[] = $value;
        } else {
            $parent[$chunkKey] = $value;
        }

        return $postFields;
    }
}

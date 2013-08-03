<?php

namespace Guzzle\Stream;

/**
 * Stream decorator that can cache previously read bytes from a sequentially read stream
 */
class CachingStream implements StreamInterface
{
    use StreamDecorator;

    /** @var StreamInterface Remote stream used to actually pull data onto the buffer */
    private $remoteStream;

    /** @var int The number of bytes to skip reading due to a write on the temporary buffer */
    private $skipReadBytes = 0;

    /**
     * We will treat the buffer object as the body of the stream
     *
     * @param StreamInterface $stream Stream to cache
     * @param StreamInterface $target Optionally specify where data is cached
     */
    public function __construct(StreamInterface $stream, StreamInterface $target = null)
    {
        $this->remoteStream = $stream;
        $this->stream = $target ?: new Stream(fopen('php://temp', 'r+'));
    }

    /**
     * Will give the contents of the buffer followed by the exhausted remote stream.
     *
     * Warning: Loads the entire stream into memory
     *
     * @return string
     */
    public function __toString()
    {
        $pos = $this->tell();
        $this->rewind();

        $str = '';
        while (!$this->eof()) {
            $str .= $this->read(16384);
        }

        $this->seek($pos);

        return $str;
    }

    public function getSize()
    {
        return max($this->stream->getSize(), $this->remoteStream->getSize());
    }

    /**
     * {@inheritdoc}
     * @throws \RuntimeException When seeking with SEEK_END or when seeking past the total size of the buffer stream
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        if ($whence == SEEK_SET) {
            $byte = $offset;
        } elseif ($whence == SEEK_CUR) {
            $byte = $offset + $this->tell();
        } else {
            throw new \RuntimeException(__CLASS__ . ' supports only SEEK_SET and SEEK_CUR seek operations');
        }

        // You cannot skip ahead past where you've read from the remote stream
        if ($byte > $this->stream->getSize()) {
            throw new \RuntimeException(
                "Cannot seek to byte {$byte} when the buffered stream only contains {$this->stream->getSize()} bytes"
            );
        }

        return $this->stream->seek($byte);
    }

    public function rewind()
    {
        return $this->seek(0);
    }

    public function read($length)
    {
        // Perform a regular read on any previously read data from the buffer
        $data = $this->stream->read($length);
        $remaining = $length - strlen($data);

        // More data was requested so read from the remote stream
        if ($remaining) {
            // If data was written to the buffer in a position that would have been filled from the remote stream,
            // then we must skip bytes on the remote stream to emulate overwriting bytes from that position. This
            // mimics the behavior of other PHP stream wrappers.
            $remoteData = $this->remoteStream->read($remaining + $this->skipReadBytes);

            if ($this->skipReadBytes) {
                $len = strlen($remoteData);
                $remoteData = substr($remoteData, $this->skipReadBytes);
                $this->skipReadBytes = max(0, $this->skipReadBytes - $len);
            }

            $data .= $remoteData;
            $this->stream->write($remoteData);
        }

        return $data;
    }

    public function write($string)
    {
        // When appending to the end of the currently read stream, you'll want to skip bytes from being read from
        // the remote stream to emulate other stream wrappers. Basically replacing bytes of data of a fixed length.
        $overflow = (strlen($string) + $this->tell()) - $this->remoteStream->tell();
        if ($overflow > 0) {
            $this->skipReadBytes += $overflow;
        }

        return $this->stream->write($string);
    }

    /**
     * {@inheritdoc}
     * @link http://php.net/manual/en/function.fgets.php
     */
    public function readLine($maxLength = null)
    {
        $buffer = '';
        $size = 0;
        while (!$this->eof()) {
            $byte = $this->read(1);
            $buffer .= $byte;
            // Break when a new line is found or the max length - 1 is reached
            if ($byte == PHP_EOL || ++$size == $maxLength - 1) {
                break;
            }
        }

        return $buffer;
    }

    public function eof()
    {
        return $this->stream->eof() && $this->remoteStream->eof();
    }

    /**
     * Close both the remote stream and buffer stream
     */
    public function close()
    {
        $this->remoteStream->close() && $this->stream->close();
    }

    public function getMetaData($key = null)
    {
        return $this->remoteStream->getMetaData($key);
    }

    public function setMetaData($key, $value)
    {
        $this->remoteStream->setMetaData($key, $value);

        return $this;
    }

    public function getStream()
    {
        return $this->remoteStream->getStream();
    }

    public function getUri()
    {
        return $this->remoteStream->getUri();
    }
}

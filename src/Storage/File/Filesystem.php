<?php


namespace Sajya\Lucene\Storage\File;

use Sajya\Lucene\Exception\InvalidArgumentException;
use Sajya\Lucene\Exception\RuntimeException;
use Zend\Stdlib\ErrorHandler;

/**
 * @category   Zend
 * @package    Zend_Search_Lucene
 * @subpackage Storage
 */
class Filesystem extends AbstractFile
{
    /**
     * Resource of the open file
     *
     * @var resource
     */
    protected $_fileHandle;


    /**
     * Class constructor.  Open the file.
     *
     * @param string $filename
     * @param string $mode
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function __construct($filename, $mode = 'r+b')
    {
        global $php_errormsg;

        if (strpos($mode, 'w') === false && !is_readable($filename)) {
            // opening for reading non-readable file
            throw new InvalidArgumentException('File \'' . $filename . '\' is not readable.');
        }

        $trackErrors = ini_get('track_errors');
        ini_set('track_errors', '1');

        $this->_fileHandle = @fopen($filename, $mode);

        if ($this->_fileHandle === false) {
            ini_set('track_errors', $trackErrors);
            throw new RuntimeException($php_errormsg);
        }

        ini_set('track_errors', $trackErrors);
    }

    /**
     * Sets the file position indicator and advances the file pointer.
     * The new position, measured in bytes from the beginning of the file,
     * is obtained by adding offset to the position specified by whence,
     * whose values are defined as follows:
     * SEEK_SET - Set position equal to offset bytes.
     * SEEK_CUR - Set position to current location plus offset.
     * SEEK_END - Set position to end-of-file plus offset. (To move to
     * a position before the end-of-file, you need to pass a negative value
     * in offset.)
     * SEEK_CUR is the only supported offset type for compound files
     *
     * Upon success, returns 0; otherwise, returns -1
     *
     * @param integer $offset
     * @param integer $whence
     *
     * @return integer
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        return fseek($this->_fileHandle, $offset, $whence);
    }


    /**
     * Get file position.
     *
     * @return integer
     */
    public function tell()
    {
        return ftell($this->_fileHandle);
    }

    /**
     * Flush output.
     *
     * Returns true on success or false on failure.
     *
     * @return boolean
     */
    public function flush()
    {
        return fflush($this->_fileHandle);
    }

    /**
     * Close File object
     */
    public function close(): void
    {
        if ($this->_fileHandle !== null) {
            ErrorHandler::start(E_WARNING);
            fclose($this->_fileHandle);
            ErrorHandler::stop();
            $this->_fileHandle = null;
        }
    }

    /**
     * Get the size of the already opened file
     *
     * @return integer
     */
    public function size(): int
    {
        $position = ftell($this->_fileHandle);
        fseek($this->_fileHandle, 0, SEEK_END);
        $size = ftell($this->_fileHandle);
        fseek($this->_fileHandle, $position);

        return $size;
    }

    /**
     * Lock file
     *
     * Lock type may be a LOCK_SH (shared lock) or a LOCK_EX (exclusive lock)
     *
     * @param integer $lockType
     * @param boolean $nonBlockingLock
     *
     * @return boolean
     */
    public function lock($lockType, $nonBlockingLock = false)
    {
        if ($nonBlockingLock) {
            return flock($this->_fileHandle, $lockType | LOCK_NB);
        }

        return flock($this->_fileHandle, $lockType);
    }

    /**
     * Unlock file
     *
     * Returns true on success
     *
     * @return boolean
     */
    public function unlock()
    {
        if ($this->_fileHandle !== null) {
            return flock($this->_fileHandle, LOCK_UN);
        }

        return true;
    }

    /**
     * Read a $length bytes from the file and advance the file pointer.
     *
     * @param integer $length
     *
     * @return string
     */
    protected function _fread($length = 1): string
    {
        if ($length == 0) {
            return '';
        }

        if ($length < 1024) {
            return fread($this->_fileHandle, $length);
        }

        $data = '';
        while ($length > 0 && ($nextBlock = fread($this->_fileHandle, $length)) != false) {
            $data .= $nextBlock;
            $length -= strlen($nextBlock);
        }
        return $data;
    }

    /**
     * Writes $length number of bytes (all, if $length===null) to the end
     * of the file.
     *
     * @param string  $data
     * @param integer $length
     */
    protected function _fwrite($data, $length = null): void
    {
        if ($length === null) {
            fwrite($this->_fileHandle, $data);
        } else {
            fwrite($this->_fileHandle, $data, $length);
        }
    }
}

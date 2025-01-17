<?php


namespace Sajya\Lucene\Storage\Directory;

use Sajya\Lucene\Exception\ExceptionInterface;
use Sajya\Lucene\Exception\InvalidArgumentException;
use Sajya\Lucene\Exception\RuntimeException;
use Sajya\Lucene\Storage\File;
use Sajya\Lucene\Storage\File\FileInterface;
use Zend\Stdlib\ErrorHandler;

/**
 * FileSystem implementation of DirectoryInterface abstraction.
 *
 * @category   Zend
 * @package    Zend_Search_Lucene
 * @subpackage Storage
 */
class Filesystem implements DirectoryInterface
{
    /**
     * Default file permissions
     *
     * @var integer
     */
    protected static $_defaultFilePermissions = 0666;
    /**
     * Filesystem path to the directory
     *
     * @var string
     */
    protected $_dirPath = null;
    /**
     * Cache for Zend_Search_Lucene_Storage_Filefilesystem objects
     * Array: filename => Zend_Search_Lucene_Storage_File object
     *
     * @throws ExceptionInterface
     * @var array
     */
    protected $_fileHandlers;

    /**
     * Object constructor
     * Checks if $path is a directory or tries to create it.
     *
     * @param string $path
     *
     * @throws InvalidArgumentException
     */
    public function __construct($path)
    {
        if (!is_dir($path)) {
            if (file_exists($path)) {
                throw new InvalidArgumentException(
                    'Path exists, but it\'s not a directory'
                );
            }

            if (!self::mkdirs($path)) {
                throw new InvalidArgumentException(
                    "Can't create directory '$path'."
                );
            }
        }
        $this->_dirPath = $path;
        $this->_fileHandlers = [];
    }

    /**
     * Utility function to recursive directory creation
     *
     * @param string  $dir
     * @param integer $mode
     * @param boolean $recursive
     *
     * @return boolean
     */

    public static function mkdirs($dir, $mode = 0777, $recursive = true): bool
    {
        if (($dir === null) || $dir === '') {
            return false;
        }
        if (is_dir($dir) || $dir === '/') {
            return true;
        }
        if (self::mkdirs(dirname($dir), $mode, $recursive)) {
            return mkdir($dir, $mode);
        }
        return false;
    }

    /**
     * Get default file permissions
     *
     * @return integer
     */
    public static function getDefaultFilePermissions(): int
    {
        return self::$_defaultFilePermissions;
    }

    /**
     * Set default file permissions
     *
     * @param integer $mode
     */
    public static function setDefaultFilePermissions($mode): void
    {
        self::$_defaultFilePermissions = $mode;
    }

    /**
     * Closes the store.
     *
     * @return void
     */
    public function close()
    {
        foreach ($this->_fileHandlers as $fileObject) {
            $fileObject->close();
        }

        $this->_fileHandlers = [];
    }


    /**
     * Returns an array of strings, one for each file in the directory.
     *
     * @return array
     */
    public function fileList()
    {
        $result = [];

        $dirContent = opendir($this->_dirPath);
        while (($file = readdir($dirContent)) !== false) {
            if (($file == '..') || ($file == '.')) {
                continue;
            }

            if (!is_dir($this->_dirPath . '/' . $file)) {
                $result[] = $file;
            }
        }
        closedir($dirContent);

        return $result;
    }

    /**
     * Creates a new, empty file in the directory with the given $filename.
     *
     * @param string $filename
     *
     * @return FileInterface
     */
    public function createFile($filename)
    {
        if (isset($this->_fileHandlers[$filename])) {
            $this->_fileHandlers[$filename]->close();
        }
        unset($this->_fileHandlers[$filename]);
        $this->_fileHandlers[$filename] = new File\Filesystem($this->_dirPath . '/' . $filename, 'w+b');

        // Set file permissions, but don't care about any possible failures, since file may be already
        // created by anther user which has to care about right permissions
        ErrorHandler::start(E_WARNING);
        chmod($this->_dirPath . '/' . $filename, self::$_defaultFilePermissions);
        ErrorHandler::stop();

        return $this->_fileHandlers[$filename];
    }


    /**
     * Removes an existing $filename in the directory.
     *
     * @param string $filename
     *
     * @return void
     * @throws RuntimeException
     */
    public function deleteFile($filename)
    {
        if (isset($this->_fileHandlers[$filename])) {
            $this->_fileHandlers[$filename]->close();
        }
        unset($this->_fileHandlers[$filename]);

        global $php_errormsg;
        $trackErrors = ini_get('track_errors');
        ini_set('track_errors', '1');
        if (!@unlink($this->_dirPath . '/' . $filename)) {
            ini_set('track_errors', $trackErrors);
            throw new RuntimeException('Can\'t delete file: ' . $php_errormsg);
        }
        ini_set('track_errors', $trackErrors);
    }

    /**
     * Purge file if it's cached by directory object
     *
     * Method is used to prevent 'too many open files' error
     *
     * @param string $filename
     *
     * @return void
     */
    public function purgeFile($filename)
    {
        if (isset($this->_fileHandlers[$filename])) {
            $this->_fileHandlers[$filename]->close();
        }
        unset($this->_fileHandlers[$filename]);
    }


    /**
     * Returns true if a file with the given $filename exists.
     *
     * @param string $filename
     *
     * @return boolean
     */
    public function fileExists($filename)
    {
        return isset($this->_fileHandlers[$filename]) ||
            file_exists($this->_dirPath . '/' . $filename);
    }


    /**
     * Returns the length of a $filename in the directory.
     *
     * @param string $filename
     *
     * @return integer
     */
    public function fileLength($filename)
    {
        if (isset($this->_fileHandlers[$filename])) {
            return $this->_fileHandlers[$filename]->size();
        }
        return filesize($this->_dirPath . '/' . $filename);
    }


    /**
     * Returns the UNIX timestamp $filename was last modified.
     *
     * @param string $filename
     *
     * @return integer
     */
    public function fileModified($filename)
    {
        return filemtime($this->_dirPath . '/' . $filename);
    }


    /**
     * Renames an existing file in the directory.
     *
     * @param string $from
     * @param string $to
     *
     * @return void
     * @throws RuntimeException
     */
    public function renameFile($from, $to)
    {
        global $php_errormsg;

        if (isset($this->_fileHandlers[$from])) {
            $this->_fileHandlers[$from]->close();
        }
        unset($this->_fileHandlers[$from]);

        if (isset($this->_fileHandlers[$to])) {
            $this->_fileHandlers[$to]->close();
        }
        unset($this->_fileHandlers[$to]);

        if (file_exists($this->_dirPath . '/' . $to) && !unlink($this->_dirPath . '/' . $to)) {
            throw new RuntimeException(
                'Delete operation failed'
            );
        }

        $trackErrors = ini_get('track_errors');
        ini_set('track_errors', '1');

        ErrorHandler::start(E_WARNING);
        $success = rename($this->_dirPath . '/' . $from, $this->_dirPath . '/' . $to);
        ErrorHandler::stop();
        if (!$success) {
            ini_set('track_errors', $trackErrors);
            throw new RuntimeException($php_errormsg);
        }

        ini_set('track_errors', $trackErrors);

        return $success;
    }


    /**
     * Sets the modified time of $filename to now.
     *
     * @param string $filename
     *
     * @return void
     */
    public function touchFile($filename)
    {
        return touch($this->_dirPath . '/' . $filename);
    }


    /**
     * Returns a Zend_Search_Lucene_Storage_File object for a given $filename in the directory.
     *
     * If $shareHandler option is true, then file handler can be shared between File Object
     * requests. It speed-ups performance, but makes problems with file position.
     * Shared handler are good for short atomic requests.
     * Non-shared handlers are useful for stream file reading (especial for compound files).
     *
     * @param string  $filename
     * @param boolean $shareHandler
     *
     * @return FileInterface
     */
    public function getFileObject($filename, $shareHandler = true)
    {
        $fullFilename = $this->_dirPath . '/' . $filename;

        if (!$shareHandler) {
            return new File\Filesystem($fullFilename);
        }

        if (isset($this->_fileHandlers[$filename])) {
            $this->_fileHandlers[$filename]->seek(0);
            return $this->_fileHandlers[$filename];
        }

        $this->_fileHandlers[$filename] = new File\Filesystem($fullFilename);
        return $this->_fileHandlers[$filename];
    }
}

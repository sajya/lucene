<?php


namespace Sajya\Lucene\Analysis\Analyzer\Common;

use Sajya\Lucene\Analysis\Token;
use Sajya\Lucene\Exception\RuntimeException;
use Zend\Stdlib\ErrorHandler;

/**
 * @category   Zend
 * @package    Zend_Search_Lucene
 * @subpackage Analysis
 */
class Utf8 extends AbstractCommon
{
    /**
     * Current char position in an UTF-8 stream
     *
     * @var integer
     */
    private $position;

    /**
     * Current binary position in an UTF-8 stream
     *
     * @var integer
     */
    private $_bytePosition;

    /**
     * Object constructor
     *
     * @throws RuntimeException
     */
    public function __construct()
    {
        ErrorHandler::start(E_WARNING);
        $result = preg_match('/\pL/u', 'a');
        ErrorHandler::stop();
        if ($result != 1) {
            // PCRE unicode support is turned off
            throw new RuntimeException('Utf8 analyzer needs PCRE unicode support to be enabled.');
        }
    }

    /**
     * Reset token stream
     */
    public function reset()
    {
        $this->position = 0;
        $this->_bytePosition = 0;

        // convert input into UTF-8
        if (strcasecmp($this->encoding, 'utf8') != 0 &&
            strcasecmp($this->encoding, 'utf-8') != 0) {
            $this->input = iconv($this->encoding, 'UTF-8', $this->input);
            $this->encoding = 'UTF-8';
        }
    }

    /**
     * Tokenization stream API
     * Get next token
     * Returns null at the end of stream
     *
     * @return Token|null
     */
    public function nextToken()
    {
        if ($this->input === null) {
            return null;
        }

        do {
            if (!preg_match('/[\p{L}]+/u', $this->input, $match, PREG_OFFSET_CAPTURE, $this->_bytePosition)) {
                // It covers both cases a) there are no matches (preg_match(...) === 0)
                // b) error occured (preg_match(...) === FALSE)
                return null;
            }

            // matched string
            $matchedWord = $match[0][0];

            // binary position of the matched word in the input stream
            $binStartPos = $match[0][1];

            // character position of the matched word in the input stream
            $startPos = $this->position +
                iconv_strlen(substr($this->input,
                    $this->_bytePosition,
                    $binStartPos - $this->_bytePosition),
                    'UTF-8');
            // character postion of the end of matched word in the input stream
            $endPos = $startPos + iconv_strlen($matchedWord, 'UTF-8');

            $this->_bytePosition = $binStartPos + strlen($matchedWord);
            $this->position = $endPos;

            $token = $this->normalize(new Token($matchedWord, $startPos, $endPos));
        } while ($token === null); // try again if token is skipped

        return $token;
    }
}


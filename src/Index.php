<?php


namespace Sajya\Lucene;

use Exception;
use Sajya\Lucene\Exception\InvalidArgumentException;
use Sajya\Lucene\Exception\InvalidFileFormatException;
use Sajya\Lucene\Exception\OutOfRangeException;
use Sajya\Lucene\Exception\RuntimeException;
use Sajya\Lucene\Index\DocsFilter;
use Sajya\Lucene\Index\SegmentInfo;
use Sajya\Lucene\Index\Term;
use Sajya\Lucene\Index\Writer;
use Sajya\Lucene\Search\QueryHit;
use Sajya\Lucene\Search\QueryParser;
use Sajya\Lucene\Search\Similarity\AbstractSimilarity;
use Sajya\Lucene\Storage\Directory\DirectoryInterface;
use Sajya\Lucene\Storage\Directory\Filesystem;

/**
 * @category   Zend
 * @package    Zend_Search_Lucene
 */
class Index implements SearchIndexInterface
{
    public const FORMAT_PRE_2_1 = 0;
    public const FORMAT_2_1 = 1;
    public const FORMAT_2_3 = 2;
    /** Generation retrieving counter */
    public const GENERATION_RETRIEVE_COUNT = 10;
    /** Pause between generation retrieving attempts in milliseconds */
    public const GENERATION_RETRIEVE_PAUSE = 50;
    /**
     * File system adapter.
     *
     * @var DirectoryInterface
     */
    private $directory = null;
    /**
     * File system adapter closing option
     *
     * @var boolean
     */
    private $_closeDirOnExit = true;
    /**
     * Writer for this index, not instantiated unless required.
     *
     * @var Writer
     */
    private $_writer = null;
    /**
     * Array of Zend_Search_Lucene_Index_SegmentInfo objects for current version of index.
     *
     * @var array|SegmentInfo
     */
    private $segmentInfos = [];
    /**
     * Number of documents in this index.
     *
     * @var integer
     */
    private $docCount = 0;
    /**
     * Flag for index changes
     *
     * @var boolean
     */
    private $_hasChanges = false;
    /**
     * Current segment generation
     *
     * @var integer
     */
    private $_generation;
    /**
     * Index format version
     *
     * @var integer
     */
    private $_formatVersion;
    /**
     * Terms stream priority queue object
     *
     * @var TermStreamsPriorityQueue
     */
    private $_termsStream = null;

    /**
     * Opens the index.
     *
     * IndexReader constructor needs Directory as a parameter. It should be
     * a string with a path to the index folder or a Directory object.
     *
     * @param Filesystem|string $directory
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function __construct($directory = null, $create = false)
    {
        if ($directory === null) {
            throw new InvalidArgumentException('No index directory specified');
        }

        if (is_string($directory)) {
            $this->directory = new Filesystem($directory);
            $this->_closeDirOnExit = true;
        } else {
            $this->directory = $directory;
            $this->_closeDirOnExit = false;
        }

        $this->segmentInfos = [];

        // Mark index as "under processing" to prevent other processes from premature index cleaning
        LockManager::obtainReadLock($this->directory);

        $this->_generation = self::getActualGeneration($this->directory);

        if ($create) {
            try {
                LockManager::obtainWriteLock($this->directory);
            } catch (Exception $e) {
                LockManager::releaseReadLock($this->directory);

                if (strpos($e->getMessage(), 'Can\'t obtain exclusive index lock') === false) {
                    throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
                }

                throw new RuntimeException('Can\'t create index. It\'s under processing now', 0, $e);
            }

            if ($this->_generation == -1) {
                // Directory doesn't contain existing index, start from 1
                $this->_generation = 1;
                $nameCounter = 0;
            } else {
                // Directory contains existing index
                $segmentsFile = $this->directory->getFileObject(self::getSegmentFileName($this->_generation));
                $segmentsFile->seek(12); // 12 = 4 (int, file format marker) + 8 (long, index version)

                $nameCounter = $segmentsFile->readInt();
                $this->_generation++;
            }

            Index\Writer::createIndex($this->directory, $this->_generation, $nameCounter);

            LockManager::releaseWriteLock($this->directory);
        }

        if ($this->_generation == -1) {
            throw new RuntimeException('Index doesn\'t exists in the specified directory.');
        }

        if ($this->_generation == 0) {
            $this->_readPre21SegmentsFile();
        } else {
            $this->_readSegmentsFile();
        }
    }

    /**
     * Get current generation number
     *
     * Returns generation number
     * 0 means pre-2.1 index format
     * -1 means there are no segments files.
     *
     * @param DirectoryInterface $directory
     *
     * @return integer
     * @throws RuntimeException
     */
    public static function getActualGeneration(DirectoryInterface $directory)
    {
        /**
         * Zend_Search_Lucene uses segments.gen file to retrieve current generation number
         *
         * Apache Lucene index format documentation mentions this method only as a fallback method
         *
         * Nevertheless we use it according to the performance considerations
         *
         * @todo check if we can use some modification of Apache Lucene generation determination algorithm
         *       without performance problems
         */
        try {
            for ($count = 0; $count < self::GENERATION_RETRIEVE_COUNT; $count++) {
                // Try to get generation file
                $genFile = $directory->getFileObject('segments.gen', false);

                $format = $genFile->readInt();
                if ($format != (int)0xFFFFFFFE) {
                    throw new RuntimeException('Wrong segments.gen file format');
                }

                $gen1 = $genFile->readLong();
                $gen2 = $genFile->readLong();

                if ($gen1 == $gen2) {
                    return $gen1;
                }

                usleep(self::GENERATION_RETRIEVE_PAUSE * 1000);
            }

            // All passes are failed
            throw new RuntimeException('Index is under processing now');
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'is not readable') !== false) {
                try {
                    // Try to open old style segments file
                    $segmentsFile = $directory->getFileObject('segments', false);

                    // It's pre-2.1 index
                    return 0;
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'is not readable') !== false) {
                        return -1;
                    }

                    throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
                }
            } else {
                throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
            }
        }

        return -1;
    }

    /**
     * Get segments file name
     *
     * @param integer $generation
     *
     * @return string
     */
    public static function getSegmentFileName($generation)
    {
        if ($generation == 0) {
            return 'segments';
        }

        return 'segments_' . base_convert($generation, 10, 36);
    }

    /**
     * Read segments file for pre-2.1 Lucene index format
     *
     * @throws InvalidFileFormatException
     */
    private function _readPre21SegmentsFile(): void
    {
        $segmentsFile = $this->directory->getFileObject('segments');

        $format = $segmentsFile->readInt();

        if ($format != (int)0xFFFFFFFF) {
            throw new InvalidFileFormatException('Wrong segments file format');
        }

        // read version
        $segmentsFile->readLong();

        // read segment name counter
        $segmentsFile->readInt();

        $segments = $segmentsFile->readInt();

        $this->docCount = 0;

        // read segmentInfos
        for ($count = 0; $count < $segments; $count++) {
            $segName = $segmentsFile->readString();
            $segSize = $segmentsFile->readInt();
            $this->docCount += $segSize;

            $this->segmentInfos[$segName] = new Index\SegmentInfo($this->directory,
                $segName,
                $segSize);
        }

        // Use 2.1 as a target version. Index will be reorganized at update time.
        $this->_formatVersion = self::FORMAT_2_1;
    }

    /**
     * Read segments file
     *
     * @throws InvalidFileFormatException
     * @throws RuntimeException
     */
    private function _readSegmentsFile(): void
    {
        $segmentsFile = $this->directory->getFileObject(self::getSegmentFileName($this->_generation));

        $format = $segmentsFile->readInt();

        if ($format == (int)0xFFFFFFFC) {
            $this->_formatVersion = self::FORMAT_2_3;
        } else if ($format == (int)0xFFFFFFFD) {
            $this->_formatVersion = self::FORMAT_2_1;
        } else {
            throw new InvalidFileFormatException('Unsupported segments file format');
        }

        // read version
        $segmentsFile->readLong();

        // read segment name counter
        $segmentsFile->readInt();

        $segments = $segmentsFile->readInt();

        $this->docCount = 0;

        // read segmentInfos
        for ($count = 0; $count < $segments; $count++) {
            $segName = $segmentsFile->readString();
            $segSize = $segmentsFile->readInt();

            // 2.1+ specific properties
            $delGen = $segmentsFile->readLong();

            if ($this->_formatVersion == self::FORMAT_2_3) {
                $docStoreOffset = $segmentsFile->readInt();

                if ($docStoreOffset != (int)0xFFFFFFFF) {
                    $docStoreSegment = $segmentsFile->readString();
                    $docStoreIsCompoundFile = $segmentsFile->readByte();

                    $docStoreOptions = ['offset'     => $docStoreOffset,
                                        'segment'    => $docStoreSegment,
                                        'isCompound' => ($docStoreIsCompoundFile == 1)];
                } else {
                    $docStoreOptions = null;
                }
            } else {
                $docStoreOptions = null;
            }

            $hasSingleNormFile = $segmentsFile->readByte();
            $numField = $segmentsFile->readInt();

            $normGens = [];
            if ($numField != (int)0xFFFFFFFF) {
                for ($count1 = 0; $count1 < $numField; $count1++) {
                    $normGens[] = $segmentsFile->readLong();
                }

                throw new RuntimeException(
                    'Separate norm files are not supported. Optimize index to use it with Sajya\Lucene.'
                );
            }

            $isCompoundByte = $segmentsFile->readByte();

            if ($isCompoundByte == 0xFF) {
                // The segment is not a compound file
                $isCompound = false;
            } else if ($isCompoundByte == 0x00) {
                // The status is unknown
                $isCompound = null;
            } else if ($isCompoundByte == 0x01) {
                // The segment is a compound file
                $isCompound = true;
            }

            $this->docCount += $segSize;

            $this->segmentInfos[$segName] = new Index\SegmentInfo($this->directory,
                $segName,
                $segSize,
                $delGen,
                $docStoreOptions,
                $hasSingleNormFile,
                $isCompound);
        }
    }

    /**
     * Get generation number associated with this index instance
     *
     * The same generation number in pair with document number or query string
     * guarantees to give the same result while index retrieving.
     * So it may be used for search result caching.
     *
     * @return integer
     */
    public function getGeneration(): int
    {
        return $this->_generation;
    }

    /**
     * Get index format version
     *
     * @return integer
     */
    public function getFormatVersion()
    {
        return $this->_formatVersion;
    }

    /**
     * Set index format version.
     * Index is converted to this format at the nearest upfdate time
     *
     * @param int $formatVersion
     *
     * @throws InvalidArgumentException
     */
    public function setFormatVersion($formatVersion)
    {
        if ($formatVersion != self::FORMAT_PRE_2_1 &&
            $formatVersion != self::FORMAT_2_1 &&
            $formatVersion != self::FORMAT_2_3) {
            throw new InvalidArgumentException('Unsupported index format');
        }

        $this->_formatVersion = $formatVersion;
    }

    /**
     * Object destructor
     */
    public function __destruct()
    {
        $this->commit();

        // Release "under processing" flag
        LockManager::releaseReadLock($this->directory);

        if ($this->_closeDirOnExit) {
            $this->directory->close();
        }

        $this->directory = null;
        $this->_writer = null;
        $this->segmentInfos = null;
    }

    /**
     * Commit changes resulting from delete() or undeleteAll() operations.
     *
     * @todo undeleteAll processing.
     */
    public function commit()
    {
        if ($this->_hasChanges) {
            $this->_getIndexWriter()->commit();

            $this->_updateDocCount();

            $this->_hasChanges = false;
        }
    }

    /**
     * Returns an instance of Zend_Search_Lucene_Index_Writer for the index
     *
     * @return Writer
     */
    private function _getIndexWriter(): Writer
    {
        if ($this->_writer === null) {
            $this->_writer = new Index\Writer($this->directory,
                $this->segmentInfos,
                $this->_formatVersion);
        }

        return $this->_writer;
    }

    /**
     * Update document counter
     */
    private function _updateDocCount(): void
    {
        $this->docCount = 0;
        foreach ($this->segmentInfos as $segInfo) {
            $this->docCount += $segInfo->count();
        }
    }

    /**
     * Returns the Zend_Search_Lucene_Storagedirectory instance for this index.
     *
     * @return DirectoryInterface
     */
    public function getDirectory()
    {
        return $this->directory;
    }

    /**
     * Returns one greater than the largest possible document number.
     * This may be used to, e.g., determine how big to allocate a structure which will have
     * an element for every document number in an index.
     *
     * @return integer
     */
    public function maxDoc()
    {
        return $this->count();
    }

    /**
     * Returns the total number of documents in this index (including deleted documents).
     *
     * @return integer
     */
    public function count()
    {
        return $this->docCount;
    }

    /**
     * Returns the total number of non-deleted documents in this index.
     *
     * @return integer
     */
    public function numDocs()
    {
        $numDocs = 0;

        foreach ($this->segmentInfos as $segmentInfo) {
            $numDocs += $segmentInfo->numDocs();
        }

        return $numDocs;
    }

    /**
     * Checks, that document is deleted
     *
     * @param integer $id
     *
     * @return boolean
     * @throws OutOfRangeException    is thrown if $id is out of the range
     */
    public function isDeleted($id)
    {
        if ($id >= $this->docCount) {
            throw new OutOfRangeException('Document id is out of the range.');
        }

        $segmentStartId = 0;
        foreach ($this->segmentInfos as $segmentInfo) {
            if ($segmentStartId + $segmentInfo->count() > $id) {
                break;
            }

            $segmentStartId += $segmentInfo->count();
        }

        if (isset($segmentInfo)) {
            return $segmentInfo->isDeleted($id - $segmentStartId);
        }
        return false;
    }

    /**
     * Retrieve index maxBufferedDocs option
     *
     * maxBufferedDocs is a minimal number of documents required before
     * the buffered in-memory documents are written into a new Segment
     *
     * Default value is 10
     *
     * @return integer
     */
    public function getMaxBufferedDocs()
    {
        return $this->_getIndexWriter()->maxBufferedDocs;
    }

    /**
     * Set index maxBufferedDocs option
     *
     * maxBufferedDocs is a minimal number of documents required before
     * the buffered in-memory documents are written into a new Segment
     *
     * Default value is 10
     *
     * @param integer $maxBufferedDocs
     */
    public function setMaxBufferedDocs($maxBufferedDocs)
    {
        $this->_getIndexWriter()->maxBufferedDocs = $maxBufferedDocs;
    }

    /**
     * Retrieve index maxMergeDocs option
     *
     * maxMergeDocs is a largest number of documents ever merged by addDocument().
     * Small values (e.g., less than 10,000) are best for interactive indexing,
     * as this limits the length of pauses while indexing to a few seconds.
     * Larger values are best for batched indexing and speedier searches.
     *
     * Default value is PHP_INT_MAX
     *
     * @return integer
     */
    public function getMaxMergeDocs()
    {
        return $this->_getIndexWriter()->maxMergeDocs;
    }

    /**
     * Set index maxMergeDocs option
     *
     * maxMergeDocs is a largest number of documents ever merged by addDocument().
     * Small values (e.g., less than 10,000) are best for interactive indexing,
     * as this limits the length of pauses while indexing to a few seconds.
     * Larger values are best for batched indexing and speedier searches.
     *
     * Default value is PHP_INT_MAX
     *
     * @param integer $maxMergeDocs
     */
    public function setMaxMergeDocs($maxMergeDocs)
    {
        $this->_getIndexWriter()->maxMergeDocs = $maxMergeDocs;
    }

    /**
     * Retrieve index mergeFactor option
     *
     * mergeFactor determines how often segment indices are merged by addDocument().
     * With smaller values, less RAM is used while indexing,
     * and searches on unoptimized indices are faster,
     * but indexing speed is slower.
     * With larger values, more RAM is used during indexing,
     * and while searches on unoptimized indices are slower,
     * indexing is faster.
     * Thus larger values (> 10) are best for batch index creation,
     * and smaller values (< 10) for indices that are interactively maintained.
     *
     * Default value is 10
     *
     * @return integer
     */
    public function getMergeFactor()
    {
        return $this->_getIndexWriter()->mergeFactor;
    }

    /**
     * Set index mergeFactor option
     *
     * mergeFactor determines how often segment indices are merged by addDocument().
     * With smaller values, less RAM is used while indexing,
     * and searches on unoptimized indices are faster,
     * but indexing speed is slower.
     * With larger values, more RAM is used during indexing,
     * and while searches on unoptimized indices are slower,
     * indexing is faster.
     * Thus larger values (> 10) are best for batch index creation,
     * and smaller values (< 10) for indices that are interactively maintained.
     *
     * Default value is 10
     *
     * @param integer $maxMergeDocs
     */
    public function setMergeFactor($mergeFactor)
    {
        $this->_getIndexWriter()->mergeFactor = $mergeFactor;
    }

    /**
     * Performs a query against the index and returns an array
     * of Zend_Search_Lucene_Search_QueryHit objects.
     * Input is a string or Zend_Search_Lucene_Search_Query.
     *
     * @param QueryParser|string $query
     *
     * @return array|QueryHit
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function find($query)
    {
        if (is_string($query)) {
            $query = Search\QueryParser::parse($query);
        } else if (!$query instanceof Search\Query\AbstractQuery) {
            throw new InvalidArgumentException('Query must be a string or Sajya\Lucene\Search\Query object');
        }


        $this->commit();

        $hits = [];
        $scores = [];
        $ids = [];

        $query = $query->rewrite($this)->optimize($this);

        $query->execute($this);

        $topScore = 0;

        $resultSetLimit = Lucene::getResultSetLimit();
        foreach ($query->matchedDocs() as $id => $num) {
            $docScore = $query->score($id, $this);
            if ($docScore != 0) {
                $hit = new Search\QueryHit($this);
                $hit->document_id = $hit->id = $id;
                $hit->score = $docScore;

                $hits[] = $hit;
                $ids[] = $id;
                $scores[] = $docScore;

                if ($docScore > $topScore) {
                    $topScore = $docScore;
                }
            }

            if ($resultSetLimit != 0 && count($hits) >= $resultSetLimit) {
                break;
            }
        }

        if (count($hits) == 0) {
            // skip sorting, which may cause a error on empty index
            return [];
        }

        if ($topScore > 1) {
            foreach ($hits as $hit) {
                $hit->score /= $topScore;
            }
        }

        if (func_num_args() == 1) {
            // sort by scores
            array_multisort($scores, SORT_DESC, SORT_NUMERIC,
                $ids, SORT_ASC, SORT_NUMERIC,
                $hits);
        } else {
            // sort by given field names

            $argList = func_get_args();
            $fieldNames = $this->getFieldNames();
            $sortArgs = [];

            // PHP 5.3 now expects all arguments to array_multisort be passed by
            // reference (if it's invoked through call_user_func_array());
            // since constants can't be passed by reference, create some placeholder variables.
            $sortReg = SORT_REGULAR;
            $sortAsc = SORT_ASC;
            $sortNum = SORT_NUMERIC;

            $sortFieldValues = [];

            for ($count = 1, $countMax = count($argList); $count < $countMax; $count++) {
                $fieldName = $argList[$count];

                if (!is_string($fieldName)) {
                    throw new RuntimeException('Field name must be a string.');
                }

                if (strtolower($fieldName) == 'score') {
                    $sortArgs[] = &$scores;
                } else {
                    if (!in_array($fieldName, $fieldNames)) {
                        throw new RuntimeException('Wrong field name.');
                    }

                    if (!isset($sortFieldValues[$fieldName])) {
                        $valuesArray = [];
                        foreach ($hits as $hit) {
                            try {
                                $value = $hit->getDocument()->getFieldValue($fieldName);
                            } catch (Exception $e) {
                                if (strpos($e->getMessage(), 'not found') === false) {
                                    throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
                                }

                                $value = null;
                            }

                            $valuesArray[] = $value;
                        }

                        // Collect loaded values in $sortFieldValues
                        // Required for PHP 5.3 which translates references into values when source
                        // variable is destroyed
                        $sortFieldValues[$fieldName] = $valuesArray;
                    }

                    $sortArgs[] = &$sortFieldValues[$fieldName];
                }

                if ($count + 1 < count($argList) && is_int($argList[$count + 1])) {
                    $count++;
                    $sortArgs[] = &$argList[$count];

                    if ($count + 1 < count($argList) && is_int($argList[$count + 1])) {
                        $count++;
                        $sortArgs[] = &$argList[$count];
                    } else if ($argList[$count] == SORT_ASC || $argList[$count] == SORT_DESC) {
                        $sortArgs[] = &$sortReg;
                    } else {
                        $sortArgs[] = &$sortAsc;
                    }
                } else {
                    $sortArgs[] = &$sortAsc;
                    $sortArgs[] = &$sortReg;
                }
            }

            // Sort by id's if values are equal
            $sortArgs[] = &$ids;
            $sortArgs[] = &$sortAsc;
            $sortArgs[] = &$sortNum;

            // Array to be sorted
            $sortArgs[] = &$hits;

            // Do sort
            array_multisort(...$sortArgs);
        }

        return $hits;
    }

    /**
     * Returns a list of all unique field names that exist in this index.
     *
     * @param boolean $indexed
     *
     * @return array
     */
    public function getFieldNames($indexed = false)
    {
        $result = [];
        foreach ($this->segmentInfos as $segmentInfo) {
            $result = array_merge($result, $segmentInfo->getFields($indexed));
        }
        return $result;
    }

    /**
     * Returns a Zend_Search_Lucene_Document object for the document
     * number $id in this index.
     *
     * @param integer|QueryHit $id
     *
     * @return Document
     * @throws \Sajya\Lucene\OutOfRangeException    is thrown if $id is out of the range
     */
    public function getDocument($id)
    {
        if ($id instanceof Search\QueryHit) {
            /* @var $id QueryHit */
            $id = $id->id;
        }

        if ($id >= $this->docCount) {
            throw new OutOfRangeException('Document id is out of the range.');
        }

        $segmentStartId = 0;
        foreach ($this->segmentInfos as $segmentInfo) {
            if ($segmentStartId + $segmentInfo->count() > $id) {
                break;
            }

            $segmentStartId += $segmentInfo->count();
        }

        $fdxFile = $segmentInfo->openCompoundFile('.fdx');
        $fdxFile->seek(($id - $segmentStartId) * 8, SEEK_CUR);
        $fieldValuesPosition = $fdxFile->readLong();

        $fdtFile = $segmentInfo->openCompoundFile('.fdt');
        $fdtFile->seek($fieldValuesPosition, SEEK_CUR);
        $fieldCount = $fdtFile->readVInt();

        $doc = new Document();
        for ($count = 0; $count < $fieldCount; $count++) {
            $fieldNum = $fdtFile->readVInt();
            $bits = $fdtFile->readByte();

            $fieldInfo = $segmentInfo->getField($fieldNum);

            if (!($bits & 2)) { // Text data
                $field = new Document\Field($fieldInfo->name,
                    $fdtFile->readString(),
                    'UTF-8',
                    true,
                    $fieldInfo->isIndexed,
                    $bits & 1);
            } else {            // Binary data
                $field = new Document\Field($fieldInfo->name,
                    $fdtFile->readBinary(),
                    '',
                    true,
                    $fieldInfo->isIndexed,
                    $bits & 1,
                    true);
            }

            $doc->addField($field);
        }

        return $doc;
    }

    /**
     * Returns true if index contain documents with specified term.
     *
     * Is used for query optimization.
     *
     * @param Term $term
     *
     * @return boolean
     */
    public function hasTerm(Index\Term $term)
    {
        foreach ($this->segmentInfos as $segInfo) {
            if ($segInfo->getTermInfo($term) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns IDs of all documents containing term.
     *
     * @param Term            $term
     * @param DocsFilter|null $docsFilter
     *
     * @return array
     */
    public function termDocs(Index\Term $term, $docsFilter = null)
    {
        $subResults = [];
        $segmentStartDocId = 0;

        foreach ($this->segmentInfos as $segmentInfo) {
            $subResults[] = $segmentInfo->termDocs($term, $segmentStartDocId, $docsFilter);

            $segmentStartDocId += $segmentInfo->count();
        }

        if (count($subResults) == 0) {
            return [];
        }

        if (count($subResults) == 1) {
            // Index is optimized (only one segment)
            // Do not perform array reindexing
            return reset($subResults);
        } else {
            $result = call_user_func_array('array_merge', $subResults);
        }

        return $result;
    }

    /**
     * Returns documents filter for all documents containing term.
     *
     * It performs the same operation as termDocs, but return result as
     * Zend_Search_Lucene_Index_DocsFilter object
     *
     * @param Term            $term
     * @param DocsFilter|null $docsFilter
     *
     * @return DocsFilter
     */
    public function termDocsFilter(Index\Term $term, $docsFilter = null)
    {
        $segmentStartDocId = 0;
        $result = new Index\DocsFilter();

        foreach ($this->segmentInfos as $segmentInfo) {
            $subResults[] = $segmentInfo->termDocs($term, $segmentStartDocId, $docsFilter);

            $segmentStartDocId += $segmentInfo->count();
        }

        if (count($subResults) == 0) {
            return [];
        }

        if (count($subResults) == 1) {
            // Index is optimized (only one segment)
            // Do not perform array reindexing
            return reset($subResults);
        } else {
            $result = call_user_func_array('array_merge', $subResults);
        }

        return $result;
    }

    /**
     * Returns an array of all term freqs.
     * Result array structure: array(docId => freq, ...)
     *
     * @param Term            $term
     * @param DocsFilter|null $docsFilter
     *
     * @return integer
     */
    public function termFreqs(Index\Term $term, $docsFilter = null)
    {
        $result = [];
        $segmentStartDocId = 0;
        foreach ($this->segmentInfos as $segmentInfo) {
            $result += $segmentInfo->termFreqs($term, $segmentStartDocId, $docsFilter);

            $segmentStartDocId += $segmentInfo->count();
        }

        return $result;
    }

    /**
     * Returns an array of all term positions in the documents.
     * Result array structure: array(docId => array(pos1, pos2, ...), ...)
     *
     * @param Term            $term
     * @param DocsFilter|null $docsFilter
     *
     * @return array
     */
    public function termPositions(Index\Term $term, $docsFilter = null)
    {
        $result = [];
        $segmentStartDocId = 0;
        foreach ($this->segmentInfos as $segmentInfo) {
            $result += $segmentInfo->termPositions($term, $segmentStartDocId, $docsFilter);

            $segmentStartDocId += $segmentInfo->count();
        }

        return $result;
    }

    /**
     * Returns the number of documents in this index containing the $term.
     *
     * @param Term $term
     *
     * @return integer
     */
    public function docFreq(Index\Term $term)
    {
        $result = 0;
        foreach ($this->segmentInfos as $segInfo) {
            $termInfo = $segInfo->getTermInfo($term);
            if ($termInfo !== null) {
                $result += $termInfo->docFreq;
            }
        }

        return $result;
    }

    /**
     * Retrive similarity used by index reader
     *
     * @return AbstractSimilarity
     */
    public function getSimilarity()
    {
        return AbstractSimilarity::getDefault();
    }

    /**
     * Returns a normalization factor for "field, document" pair.
     *
     * @param integer $id
     * @param string  $fieldName
     *
     * @return float
     */
    public function norm($id, $fieldName)
    {
        if ($id >= $this->docCount) {
            return null;
        }

        $segmentStartId = 0;
        foreach ($this->segmentInfos as $segInfo) {
            if ($segmentStartId + $segInfo->count() > $id) {
                break;
            }

            $segmentStartId += $segInfo->count();
        }

        if ($segInfo->isDeleted($id - $segmentStartId)) {
            return 0;
        }

        return $segInfo->norm($id - $segmentStartId, $fieldName);
    }

    /**
     * Deletes a document from the index.
     * $id is an internal document id
     *
     * @param integer|QueryHit $id
     *
     * @throws OutOfRangeException
     */
    public function delete($id)
    {
        if ($id instanceof Search\QueryHit) {
            /* @var $id QueryHit */
            $id = $id->id;
        }

        if ($id >= $this->docCount) {
            throw new OutOfRangeException('Document id is out of the range.');
        }

        $segmentStartId = 0;
        foreach ($this->segmentInfos as $segmentInfo) {
            if ($segmentStartId + $segmentInfo->count() > $id) {
                break;
            }

            $segmentStartId += $segmentInfo->count();
        }
        $segmentInfo->delete($id - $segmentStartId);

        $this->_hasChanges = true;
    }

    /**
     * Adds a document to this index.
     *
     * @param Document $document
     */
    public function addDocument(Document $document)
    {
        $this->_getIndexWriter()->addDocument($document);
        $this->docCount++;

        $this->_hasChanges = true;
    }

    /**
     * Optimize index.
     *
     * Merges all segments into one
     */
    public function optimize()
    {
        // Commit changes if any changes have been made
        $this->commit();

        if (count($this->segmentInfos) > 1 || $this->hasDeletions()) {
            $this->_getIndexWriter()->optimize();
            $this->_updateDocCount();
        }
    }

    /**
     * Returns true if any documents have been deleted from this index.
     *
     * @return boolean
     */
    public function hasDeletions()
    {
        foreach ($this->segmentInfos as $segmentInfo) {
            if ($segmentInfo->hasDeletions()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns an array of all terms in this index.
     *
     * @return array
     */
    public function terms()
    {
        $result = [];

        $segmentInfoQueue = new Index\TermsPriorityQueue();

        foreach ($this->segmentInfos as $segmentInfo) {
            $segmentInfo->resetTermsStream();

            // Skip "empty" segments
            if ($segmentInfo->currentTerm() !== null) {
                $segmentInfoQueue->put($segmentInfo);
            }
        }

        while (($segmentInfo = $segmentInfoQueue->pop()) !== null) {
            if ($segmentInfoQueue->top() === null ||
                $segmentInfoQueue->top()->currentTerm()->key() !=
                $segmentInfo->currentTerm()->key()) {
                // We got new term
                $result[] = $segmentInfo->currentTerm();
            }

            if ($segmentInfo->nextTerm() !== null) {
                // Put segment back into the priority queue
                $segmentInfoQueue->put($segmentInfo);
            }
        }

        return $result;
    }

    /**
     * Reset terms stream.
     */
    public function resetTermsStream()
    {
        if ($this->_termsStream === null) {
            $this->_termsStream = new TermStreamsPriorityQueue($this->segmentInfos);
        } else {
            $this->_termsStream->resetTermsStream();
        }
    }

    /**
     * Skip terms stream up to specified term preffix.
     *
     * Prefix contains fully specified field info and portion of searched term
     *
     * @param Term $prefix
     */
    public function skipTo(Index\Term $prefix)
    {
        $this->_termsStream->skipTo($prefix);
    }

    /**
     * Scans terms dictionary and returns next term
     *
     * @return Term|null
     */
    public function nextTerm()
    {
        return $this->_termsStream->nextTerm();
    }

    /**
     * Returns term in current position
     *
     * @return Term|null
     */
    public function currentTerm()
    {
        return $this->_termsStream->currentTerm();
    }

    /**
     * Close terms stream
     *
     * Should be used for resources clean up if stream is not read up to the end
     */
    public function closeTermsStream()
    {
        $this->_termsStream->closeTermsStream();
        $this->_termsStream = null;
    }


    /*************************************************************************
     * @todo UNIMPLEMENTED
     *************************************************************************/
    /**
     * Undeletes all documents currently marked as deleted in this index.
     *
     * @todo Implementation
     */
    public function undeleteAll()
    {
    }
}

<?php


namespace Sajya\Lucene\Test;

use PHPUnit\Framework\TestCase;
use Sajya\Lucene;
use Sajya\Lucene\Document;
use Sajya\Lucene\Index;
use Sajya\Lucene\Search\Similarity\AbstractSimilarity;

/**
 * @category   Zend
 * @package    Zend_Search_Lucene
 * @subpackage UnitTests
 * @group      Zend_Search_Lucene
 */
class IndexTest extends TestCase
{
    public function tearDown(): void
    {
        $this->_clearDirectory(__DIR__ . '/_index/_files');
    }

    private function _clearDirectory($dirName): void
    {
        if (!file_exists($dirName) || !is_dir($dirName)) {
            return;
        }

        // remove files from temporary directory
        $dir = opendir($dirName);
        while (($file = readdir($dir)) !== false) {
            if (!is_dir($dirName . '/' . $file)) {
                @unlink($dirName . '/' . $file);
            }
        }
        closedir($dir);
    }

    public function testCreate(): void
    {
        $index = Lucene\Lucene::create(__DIR__ . '/_index/_files');

        $this->assertTrue($index instanceof Lucene\SearchIndexInterface);
    }

    public function testOpen(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $this->assertTrue($index instanceof Lucene\SearchIndexInterface);
    }

    public function testOpenNonCompound(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_nonCompoundIndexFiles');

        $this->assertTrue($index instanceof Lucene\SearchIndexInterface);
    }

    public function testDefaultSearchField(): void
    {
        $currentDefaultSearchField = Lucene\Lucene::getDefaultSearchField();
        $this->assertEquals($currentDefaultSearchField, null);

        Lucene\Lucene::setDefaultSearchField('anotherField');
        $this->assertEquals(Lucene\Lucene::getDefaultSearchField(), 'anotherField');

        Lucene\Lucene::setDefaultSearchField($currentDefaultSearchField);
    }

    public function testCount(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $this->assertEquals($index->count(), 10);
    }

    public function testMaxDoc(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $this->assertEquals($index->maxDoc(), 10);
    }

    public function testNumDocs(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $this->assertEquals($index->numDocs(), 9);
    }

    public function testIsDeleted(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $this->assertFalse($index->isDeleted(3));
        $this->assertTrue($index->isDeleted(6));
    }

    public function testMaxBufferedDocs(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $currentMaxBufferedDocs = $index->getMaxBufferedDocs();

        $index->setMaxBufferedDocs(234);
        $this->assertEquals($index->getMaxBufferedDocs(), 234);

        $index->setMaxBufferedDocs($currentMaxBufferedDocs);
    }

    public function testMaxMergeDocs(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $currentMaxMergeDocs = $index->getMaxMergeDocs();

        $index->setMaxMergeDocs(34);
        $this->assertEquals($index->getMaxMergeDocs(), 34);

        $index->setMaxMergeDocs($currentMaxMergeDocs);
    }

    public function testMergeFactor(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $currentMergeFactor = $index->getMergeFactor();

        $index->setMergeFactor(113);
        $this->assertEquals($index->getMergeFactor(), 113);

        $index->setMergeFactor($currentMergeFactor);
    }

    public function testFind(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $hits = $index->find('submitting');
        $this->assertEquals(count($hits), 3);
    }

    public function testGetFieldNames(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $this->assertTrue(array_values($index->getFieldNames()) == ['path', 'modified', 'contents']);
    }

    public function testGetDocument(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $doc = $index->getDocument(3);

        $this->assertTrue($doc instanceof Document);
        $this->assertEquals($doc->path, 'IndexSource/about-pear.html');
    }

    public function testHasTerm(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $this->assertTrue($index->hasTerm(new Index\Term('packages', 'contents')));
        $this->assertFalse($index->hasTerm(new Index\Term('nonusedword', 'contents')));
    }

    public function testTermDocs(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $this->assertTrue(array_values($index->termDocs(new Index\Term('packages', 'contents'))) ==
            [0, 2, 6, 7, 8]);
    }

    public function testTermPositions(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $this->assertTrue($index->termPositions(new Index\Term('packages', 'contents')) ==
            [0 => [174],
             2 => [40, 742],
             6 => [6, 156, 163],
             7 => [194],
             8 => [55, 190, 405]]);
    }

    public function testDocFreq(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $this->assertEquals($index->docFreq(new Index\Term('packages', 'contents')), 5);
    }

    public function testGetSimilarity(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $this->assertTrue($index->getSimilarity() instanceof AbstractSimilarity);
    }

    public function testNorm(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $this->assertTrue(abs($index->norm(3, 'contents') - 0.054688) < 0.000001);
    }

    public function testHasDeletions(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $this->assertTrue($index->hasDeletions());
    }

    public function testDelete(): void
    {
        // Copy index sample into _files directory
        $sampleIndexDir = __DIR__ . '/_indexSample/_files';
        $tempIndexDir = __DIR__ . '/_files';
        if (!is_dir($tempIndexDir)) {
            mkdir($tempIndexDir);
        }

        $this->_clearDirectory($tempIndexDir);

        $indexDir = opendir($sampleIndexDir);
        while (($file = readdir($indexDir)) !== false) {
            if (!is_dir($sampleIndexDir . '/' . $file)) {
                copy($sampleIndexDir . '/' . $file, $tempIndexDir . '/' . $file);
            }
        }
        closedir($indexDir);


        $index = Lucene\Lucene::open($tempIndexDir);

        $this->assertFalse($index->isDeleted(2));
        $index->delete(2);
        $this->assertTrue($index->isDeleted(2));

        $index->commit();

        unset($index);

        $index1 = Lucene\Lucene::open($tempIndexDir);
        $this->assertTrue($index1->isDeleted(2));
        unset($index1);
    }

    public function testAddDocument(): void
    {
        $index = Lucene\Lucene::create(__DIR__ . '/_index/_files');

        $indexSourceDir = __DIR__ . '/_indexSource/_files';
        $dir = opendir($indexSourceDir);
        while (($file = readdir($dir)) !== false) {
            if (is_dir($indexSourceDir . '/' . $file)) {
                continue;
            }
            if (strcasecmp(substr($file, strlen($file) - 5), '.html') != 0) {
                continue;
            }

            // Create new Document from a file
            $doc = new Document();
            $doc->addField(Document\Field::Text('path', 'IndexSource/' . $file));
            $doc->addField(Document\Field::Keyword('modified', filemtime($indexSourceDir . '/' . $file)));

            $f = fopen($indexSourceDir . '/' . $file, 'rb');
            $byteCount = filesize($indexSourceDir . '/' . $file);

            $data = '';
            while ($byteCount > 0 && ($nextBlock = fread($f, $byteCount)) != false) {
                $data .= $nextBlock;
                $byteCount -= strlen($nextBlock);
            }
            fclose($f);

            $doc->addField(Document\Field::Text('contents', $data, 'ISO-8859-1'));

            // Add document to the index
            $index->addDocument($doc);
        }
        closedir($dir);

        unset($index);

        $index1 = Lucene\Lucene::open(__DIR__ . '/_index/_files');
        $this->assertTrue($index1 instanceof Lucene\SearchIndexInterface);
    }

    public function testOptimize(): void
    {
        $index = Lucene\Lucene::create(__DIR__ . '/_index/_files');

        $index->setMaxBufferedDocs(2);

        $indexSourceDir = __DIR__ . '/_indexSource/_files';
        $dir = opendir($indexSourceDir);
        while (($file = readdir($dir)) !== false) {
            if (is_dir($indexSourceDir . '/' . $file)) {
                continue;
            }
            if (strcasecmp(substr($file, strlen($file) - 5), '.html') != 0) {
                continue;
            }

            // Create new Document from a file
            $doc = new Document();
            $doc->addField(Document\Field::Keyword('path', 'IndexSource/' . $file));
            $doc->addField(Document\Field::Keyword('modified', filemtime($indexSourceDir . '/' . $file)));

            $f = fopen($indexSourceDir . '/' . $file, 'rb');
            $byteCount = filesize($indexSourceDir . '/' . $file);

            $data = '';
            while ($byteCount > 0 && ($nextBlock = fread($f, $byteCount)) != false) {
                $data .= $nextBlock;
                $byteCount -= strlen($nextBlock);
            }
            fclose($f);

            $doc->addField(Document\Field::Text('contents', $data, 'ISO-8859-1'));

            // Add document to the index
            $index->addDocument($doc);
        }
        closedir($dir);
        unset($index);

        $index1 = Lucene\Lucene::open(__DIR__ . '/_index/_files');
        $this->assertTrue($index1 instanceof Lucene\SearchIndexInterface);
        $pathTerm = new Index\Term('IndexSource/contributing.html', 'path');
        $contributingDocs = $index1->termDocs($pathTerm);
        foreach ($contributingDocs as $id) {
            $index1->delete($id);
        }
        $index1->optimize();
        unset($index1);

        $index2 = Lucene\Lucene::open(__DIR__ . '/_index/_files');
        $this->assertTrue($index2 instanceof Lucene\SearchIndexInterface);

        $hits = $index2->find('submitting');
        $this->assertEquals(count($hits), 3);
    }

    public function testTerms(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $this->assertEquals(count($index->terms()), 607);
    }

    public function testTermsStreamInterface(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $terms = [];

        $index->resetTermsStream();
        while ($index->currentTerm() !== null) {
            $terms[] = $index->currentTerm();
            $index->nextTerm();
        }

        $this->assertEquals(count($terms), 607);
    }

    public function testTermsStreamInterfaceSkipTo(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $terms = [];

        $index->resetTermsStream();
        $index->skipTo(new Index\Term('one', 'contents'));

        while ($index->currentTerm() !== null) {
            $terms[] = $index->currentTerm();
            $index->nextTerm();
        }

        $this->assertEquals(count($terms), 244);
    }

    public function testTermsStreamInterfaceSkipToTermsRetrieving(): void
    {
        $index = Lucene\Lucene::open(__DIR__ . '/_indexSample/_files');

        $terms = [];

        $index->resetTermsStream();
        $index->skipTo(new Index\Term('one', 'contents'));

        $terms[] = $index->currentTerm();
        $terms[] = $index->nextTerm();
        $terms[] = $index->nextTerm();

        $index->closeTermsStream();

        $this->assertTrue($terms ==
            [new Index\Term('one', 'contents'),
             new Index\Term('only', 'contents'),
             new Index\Term('open', 'contents'),
            ]);
    }

    public function testTermsStreamInterfaceSkipToTermsRetrievingZeroTermsCase(): void
    {
        $index = Lucene\Lucene::create(__DIR__ . '/_index/_files');

        // Zero terms
        $doc = new Document();
        $doc->addField(Document\Field::Text('contents', ''));
        $index->addDocument($doc);

        unset($index);


        $index = Lucene\Lucene::open(__DIR__ . '/_index/_files');

        $index->resetTermsStream();
        $index->skipTo(new Index\Term('term', 'contents'));

        $this->assertTrue($index->currentTerm() === null);

        $index->closeTermsStream();
    }

    public function testTermsStreamInterfaceSkipToTermsRetrievingOneTermsCase(): void
    {
        $index = Lucene\Lucene::create(__DIR__ . '/_index/_files');

        // Zero terms
        $doc = new Document();
        $doc->addField(Document\Field::Text('contents', 'someterm'));
        $index->addDocument($doc);

        unset($index);


        $index = Lucene\Lucene::open(__DIR__ . '/_index/_files');

        $index->resetTermsStream();
        $index->skipTo(new Index\Term('term', 'contents'));

        $this->assertTrue($index->currentTerm() === null);

        $index->closeTermsStream();
    }

    public function testTermsStreamInterfaceSkipToTermsRetrievingTwoTermsCase(): void
    {
        $index = Lucene\Lucene::create(__DIR__ . '/_index/_files');

        // Zero terms
        $doc = new Document();
        $doc->addField(Document\Field::Text('contents', 'someterm word'));
        $index->addDocument($doc);

        unset($index);


        $index = Lucene\Lucene::open(__DIR__ . '/_index/_files');

        $index->resetTermsStream();
        $index->skipTo(new Index\Term('term', 'contents'));

        $this->assertTrue($index->currentTerm() == new Index\Term('word', 'contents'));

        $index->closeTermsStream();
    }

    /**
     * @group ZF-9680
     */
    public function testIsDeletedWithoutExplicitCommit(): void
    {
        $index = Lucene\Lucene::create(__DIR__ . '/_index/_files');

        $document = new Document;
        $document->addField(Document\Field::Keyword('_id', 'myId'));
        $document->addField(Document\Field::Keyword('bla', 'blubb'));
        $index->addDocument($document);

        $this->assertFalse($index->isDeleted(0));
    }
}

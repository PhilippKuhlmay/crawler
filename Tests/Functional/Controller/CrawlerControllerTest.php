<?php

declare(strict_types=1);

namespace AOE\Crawler\Tests\Functional\Controller;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2019 AOE GmbH <dev@aoe.com>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use AOE\Crawler\Controller\CrawlerController;
use AOE\Crawler\Domain\Repository\QueueRepository;
use AOE\Crawler\Value\QueueFilter;
use Nimut\TestingFramework\MockObject\AccessibleMockObjectInterface;
use Nimut\TestingFramework\TestCase\FunctionalTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;

/**
 * Class CrawlerControllerTest
 *
 * @package AOE\Crawler\Tests\Functional\Controller
 */
class CrawlerControllerTest extends FunctionalTestCase
{
    /**
     * @var array
     */
    protected $testExtensionsToLoad = ['typo3conf/ext/crawler'];

    /**
     * @var MockObject|AccessibleMockObjectInterface|CrawlerController
     */
    protected $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importDataSet(__DIR__ . '/../Fixtures/pages.xml');
        $this->importDataSet(__DIR__ . '/../Fixtures/tx_crawler_configuration.xml');
        $this->importDataSet(__DIR__ . '/../Fixtures/tx_crawler_queue.xml');
        $this->importDataSet(__DIR__ . '/../Fixtures/tx_crawler_process.xml');
        $this->importDataSet(__DIR__ . '/../Fixtures/tt_content.xml');
        $this->subject = $this->getAccessibleMock(CrawlerController::class, ['dummy']);
    }

    /**
     * @test
     */
    public function cleanUpOldQueueEntries(): void
    {
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $queryRepository = $objectManager->get(QueueRepository::class);

        $recordsFromFixture = 15;
        $expectedRemainingRecords = 2;

        // Add records to queue repository to ensure we always have records,
        // that will not be deleted with the cleanUpOldQueueEntries-function
        $connectionForCrawlerQueue = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_crawler_queue');

        // Done for performance reason, as it gets repeated often
        $time = time() + (7 * 24 * 60 * 60);

        for ($i = 0; $i < $expectedRemainingRecords; $i++) {
            $connectionForCrawlerQueue
                ->insert(
                    'tx_crawler_queue',
                    [
                        'exec_time' => $time,
                        'scheduled' => $time,
                        'parameters' => 'not important parameters',
                        'result_data' => 'not important result_data',
                    ]
                );
        }

        // Check total entries before cleanup
        self::assertSame(
            $recordsFromFixture + $expectedRemainingRecords,
            $queryRepository->findAll()->count()
        );

        $this->subject->_call('cleanUpOldQueueEntries');

        // Check total entries after cleanup
        self::assertSame(
            $expectedRemainingRecords,
            $queryRepository->findAll()->count()
        );
    }

    /**
     * @test
     *
     * @dataProvider getLogEntriesForPageIdDataProvider
     */
    public function getLogEntriesForPageId(int $id, QueueFilter $queueFilter, bool $doFlush, bool $doFullFlush, int $itemsPerPage, array $expected): void
    {
        self::assertEquals(
            $expected,
            $this->subject->getLogEntriesForPageId($id, $queueFilter, $doFlush, $doFullFlush, $itemsPerPage)
        );
    }

    /**
     * @test
     *
     * @dataProvider getLogEntriesForSetIdDataProvider
     */
    public function getLogEntriesForSetId(int $setId, string $filter, bool $doFlush, bool $doFullFlush, int $itemsPerPage, array $expected): void
    {
        self::assertEquals(
            $expected,
            $this->subject->getLogEntriesForSetId($setId, $filter, $doFlush, $doFullFlush, $itemsPerPage)
        );
    }

    /**
     * @test
     */
    public function getConfigurationsForBranch(): void
    {
        $GLOBALS['BE_USER'] = $this->getMockBuilder(BackendUserAuthentication::class)
            ->disableOriginalConstructor()
            ->setMethods(['isAdmin', 'getTSConfig', 'getPagePermsClause', 'isInWebMount', 'backendCheckLogin'])
            ->getMock();

        $configurationsForBranch = $this->subject->getConfigurationsForBranch(5, 99);

        self::assertNotEmpty($configurationsForBranch);
        self::assertCount(
            4,
            $configurationsForBranch
        );

        self::assertEquals(
            $configurationsForBranch,
            [
                'Not hidden or deleted',
                'Not hidden or deleted - uid 5',
                'Not hidden or deleted - uid 6',
                'default',
            ]
        );
    }

    /**
     * @test
     * @dataProvider getDuplicateRowsIfExistDataProvider
     */
    public function getDuplicateRowsIfExist(bool $timeslotActive, int $tstamp, int $current, array $fieldArray, array $expected): void
    {
        $mockedCrawlerController = $this->getAccessibleMock(CrawlerController::class, ['getCurrentTime']);
        $mockedCrawlerController->expects($this->any())->method('getCurrentTime')->willReturn($current);

        $mockedCrawlerController->setExtensionSettings([
            'enableTimeslot' => $timeslotActive,
        ]);

        self::assertSame(
            $expected,
            $mockedCrawlerController->_call('getDuplicateRowsIfExist', $tstamp, $fieldArray)
        );
    }

    /**
     * @test
     * @dataProvider addUrlDataProvider
     */
    public function addUrl(int $id, string $url, array $subCfg, int $tstamp, string $configurationHash, bool $skipInnerDuplicationCheck, array $mockedDuplicateRowResult, bool $registerQueueEntriesInternallyOnly, bool $expected): void
    {
        $mockedQueueRepository = $this->getAccessibleMock(QueueRepository::class, ['getDuplicateQueueItemsIfExists']);
        $mockedQueueRepository->expects($this->any())->method('getDuplicateQueueItemsIfExists')->willReturn($mockedDuplicateRowResult);

        $mockedCrawlerController = $this->getAccessibleMock(CrawlerController::class, ['dummy']);

        $mockedCrawlerController->_set('registerQueueEntriesInternallyOnly', $registerQueueEntriesInternallyOnly);
        $mockedCrawlerController->_set('queueRepository', $mockedQueueRepository);

        self::assertEquals(
            $expected,
            $mockedCrawlerController->addUrl($id, $url, $subCfg, $tstamp, $configurationHash, $skipInnerDuplicationCheck)
        );
    }

    /**
     * @test
     * @dataProvider expandParametersDataProvider
     */
    public function expandParameters(array $paramArray, int $pid, array $expected): void
    {
        $output = $this->subject->expandParameters($paramArray, $pid);

        self::assertEquals(
            $expected,
            $output
        );
    }

    /**
     * @test
     */
    public function expandExcludeStringReturnsArraysOfIntegers(): void
    {
        $GLOBALS['BE_USER'] = $this->getMockBuilder(BackendUserAuthentication::class)
            ->disableOriginalConstructor()
            ->setMethods(['isAdmin', 'getTSConfig', 'getPagePermsClause', 'isInWebMount', 'backendCheckLogin'])
            ->getMock();

        $excludeStringArray = $this->subject->expandExcludeString('1,2,4,6,8');

        foreach ($excludeStringArray as $excluded) {
            self::assertIsInt($excluded);
        }
    }

    public function expandParametersDataProvider(): array
    {
        return [
            'Parameters with range' => [
                'paramArray' => ['range' => '[1-5]'],
                'pid' => 1,
                'expected' => [
                    'range' => [1, 2, 3, 4, 5],
                ],
            ],
            'Parameters with _TABLE _PID & _WHERE (hidden = 0)' => [
                'paramArray' => ['table' => '[_TABLE:pages;_PID:5;_WHERE: hidden = 0]'],
                'pid' => 1,
                'expected' => [
                    'table' => [7],
                ],
            ],
            'Parameters with _TABLE _PID & _WHERE (hidden = 1)' => [
                'paramArray' => ['table' => '[_TABLE:pages;_PID:5:_WHERE: hidden = 1]'],
                'pid' => 1,
                'expected' => [
                    'table' => [7, 8],
                ],
            ],
            'Parameters with _TABLE no _PID, then pid from input is used' => [
                'paramArray' => ['table' => '[_TABLE:pages]'],
                'pid' => 1,
                'expected' => [
                    'table' => [2, 3, 4, 5],
                ],
            ],
            'Parameters with _TABLE _PID _RECURSIVE(:0) & _WHERE (hidden = 0)' => [
                'paramArray' => ['table' => '[_TABLE:tt_content;_PID:5;_RECURSIVE:0;_WHERE: hidden = 0]'],
                'pid' => 1,
                'expected' => [
                    'table' => [1, 2],
                ],
            ],
            'Parameters with _TABLE _PID _RECURSIVE(:1) & _WHERE (hidden = 0)' => [
                'paramArray' => ['table' => '[_TABLE:tt_content;_PID:5;_RECURSIVE:1;_WHERE: hidden = 0]'],
                'pid' => 1,
                'expected' => [
                    'table' => [1, 2, 3],
                ],
            ],
            'Parameters with _TABLE _PID _RECURSIVE(:2) & _WHERE (hidden = 0)' => [
                'paramArray' => ['table' => '[_TABLE:tt_content;_PID:5;_RECURSIVE:2;_WHERE: hidden = 0]'],
                'pid' => 1,
                'expected' => [
                    'table' => [1, 2, 3, 5, 6],
                ],
            ],
        ];
    }

    public function addUrlDataProvider(): array
    {
        return [
            'Queue entry added' => [
                'id' => 0,
                'url' => '',
                'subCfg' => [
                    'key' => 'some-key',
                    'procInstrFilter' => 'tx_crawler_post',
                    'procInstrParams.' => [
                        'action' => true,
                    ],
                    'userGroups' => '12,14',
                ],
                'tstamp' => 1563287062,
                'configurationHash' => '',
                'skipInnerDuplicationCheck' => false,
                'mockedDuplicateRowResult' => [],
                'registerQueueEntriesInternallyOnly' => false,
                'expected' => true,
            ],
            'Queue entry is NOT added, due to duplication check return not empty array (mocked)' => [
                'id' => 0,
                'url' => '',
                'subCfg' => ['key' => 'some-key'],
                'tstamp' => 1563287062,
                'configurationHash' => '',
                'skipInnerDuplicationCheck' => false,
                'mockedDuplicateRowResult' => ['duplicate-exists' => true],
                'registerQueueEntriesInternallyOnly' => false,
                'expected' => false,
            ],
            'Queue entry is added, due to duplication is ignored' => [
                'id' => 0,
                'url' => '',
                'subCfg' => ['key' => 'some-key'],
                'tstamp' => 1563287062,
                'configurationHash' => '',
                'skipInnerDuplicationCheck' => true,
                'mockedDuplicateRowResult' => ['duplicate-exists' => true],
                'registerQueueEntriesInternallyOnly' => false,
                'expected' => true,
            ],
            'Queue entry is NOT added, due to registerQueueEntriesInternalOnly' => [
                'id' => 0,
                'url' => '',
                'subCfg' => ['key' => 'some-key'],
                'tstamp' => 1563287062,
                'configurationHash' => '',
                'skipInnerDuplicationCheck' => true,
                'mockedDuplicateRowResult' => ['duplicate-exists' => true],
                'registerQueueEntriesInternallyOnly' => true,
                'expected' => false,
            ],
        ];
    }

    public function getDuplicateRowsIfExistDataProvider(): array
    {
        return [
            'EnableTimeslot is true and timestamp is <= current' => [
                'timeslotActive' => true,
                'tstamp' => 10,
                'current' => 12,
                'fieldArray' => [
                    'page_id' => 10,
                    'parameters_hash' => '',
                ],
                'expected' => [18, 20],
            ],
            'EnableTimeslot is false and timestamp is <= current' => [
                'timeslotActive' => false,
                'tstamp' => 11,
                'current' => 11,
                'fieldArray' => [
                    'page_id' => 10,
                    'parameters_hash' => '',
                ],
                'expected' => [18],
            ],
            'EnableTimeslot is true and timestamp is > current' => [
                'timeslotActive' => true,
                'tstamp' => 12,
                'current' => 10,
                'fieldArray' => [
                    'page_id' => 10,
                    'parameters_hash' => '',
                ],
                'expected' => [20],
            ],
            'EnableTimeslot is false and timestamp is > current' => [
                'timeslotActive' => false,
                'tstamp' => 12,
                'current' => 10,
                'fieldArray' => [
                    'page_id' => 10,
                    'parameters_hash' => '',
                ],
                'expected' => [20],
            ],
            'EnableTimeslot is false and timestamp is > current and parameters_hash is set' => [
                'timeslotActive' => false,
                'tstamp' => 12,
                'current' => 10,
                'fieldArray' => [
                    'page_id' => 10,
                    'parameters_hash' => 'NotReallyAHashButWillDoForTesting',
                ],
                'expected' => [19],
            ],
        ];
    }

    /**
     * @return array
     */
    public function getLogEntriesForSetIdDataProvider()
    {
        return [
            'Do Flush' => [
                'setId' => 456,
                'filter' => '',
                'doFlush' => true,
                'doFullFlush' => false,
                'itemsPerPage' => 5,
                'expected' => [],
            ],
            'Do Full Flush' => [
                'setId' => 456,
                'filter' => '',
                'doFlush' => true,
                'doFullFlush' => true,
                'itemsPerPage' => 5,
                'expected' => [],
            ],
            'Check that doFullFlush do not flush if doFlush is not true' => [
                'setId' => 456,
                'filter' => '',
                'doFlush' => false,
                'doFullFlush' => true,
                'itemsPerPage' => 5,
                'expected' => [[
                    'qid' => '8',
                    'page_id' => '3',
                    'parameters' => '',
                    'parameters_hash' => '',
                    'configuration_hash' => '',
                    'scheduled' => '0',
                    'exec_time' => '0',
                    'set_id' => '456',
                    'result_data' => '',
                    'process_scheduled' => '0',
                    'process_id' => '1007',
                    'process_id_completed' => 'asdfgh',
                    'configuration' => 'ThirdConfiguration',
                ]],
            ],
            'Get entries for set_id 456' => [
                'setId' => 456,
                'filter' => '',
                'doFlush' => false,
                'doFullFlush' => false,
                'itemsPerPage' => 1,
                'expected' => [[
                    'qid' => '8',
                    'page_id' => '3',
                    'parameters' => '',
                    'parameters_hash' => '',
                    'configuration_hash' => '',
                    'scheduled' => '0',
                    'exec_time' => '0',
                    'set_id' => '456',
                    'result_data' => '',
                    'process_scheduled' => '0',
                    'process_id' => '1007',
                    'process_id_completed' => 'asdfgh',
                    'configuration' => 'ThirdConfiguration',
                ]],
            ],
            'Do Flush Pending' => [
                'setId' => 456,
                'filter' => 'pending',
                'doFlush' => true,
                'doFullFlush' => false,
                'itemsPerPage' => 5,
                'expected' => [],
            ],
            'Do Flush Finished' => [
                'setId' => 456,
                'filter' => 'finished',
                'doFlush' => true,
                'doFullFlush' => false,
                'itemsPerPage' => 5,
                'expected' => [],
            ],
        ];
    }

    /**
     * @return array
     */
    public function getLogEntriesForPageIdDataProvider()
    {
        return [
            'Do Flush' => [
                'id' => 1002,
                'filter' => new QueueFilter(),
                'doFlush' => true,
                'doFullFlush' => false,
                'itemsPerPage' => 5,
                'expected' => [],
            ],
            'Do Full Flush' => [
                'id' => 1002,
                'filter' => new QueueFilter(),
                'doFlush' => true,
                'doFullFlush' => true,
                'itemsPerPage' => 5,
                'expected' => [],
            ],
            'Check that doFullFlush do not flush if doFlush is not true' => [
                'id' => 2,
                'filter' => new QueueFilter(),
                'doFlush' => false,
                'doFullFlush' => true,
                'itemsPerPage' => 5,
                'expected' => [[
                    'qid' => '6',
                    'page_id' => '2',
                    'parameters' => '',
                    'parameters_hash' => '',
                    'configuration_hash' => '7b6919e533f334550b6f19034dfd2f81',
                    'scheduled' => '0',
                    'exec_time' => '0',
                    'set_id' => '123',
                    'result_data' => '',
                    'process_scheduled' => '0',
                    'process_id' => '1006',
                    'process_id_completed' => 'qwerty',
                    'configuration' => 'SecondConfiguration',
                ]],
            ],
            'Get entries for page_id 2001' => [
                'id' => 2,
                'filter' => new QueueFilter(),
                'doFlush' => false,
                'doFullFlush' => false,
                'itemsPerPage' => 1,
                'expected' => [[
                    'qid' => '6',
                    'page_id' => '2',
                    'parameters' => '',
                    'parameters_hash' => '',
                    'configuration_hash' => '7b6919e533f334550b6f19034dfd2f81',
                    'scheduled' => '0',
                    'exec_time' => '0',
                    'set_id' => '123',
                    'result_data' => '',
                    'process_scheduled' => '0',
                    'process_id' => '1006',
                    'process_id_completed' => 'qwerty',
                    'configuration' => 'SecondConfiguration',
                ]],
            ],
        ];
    }
}

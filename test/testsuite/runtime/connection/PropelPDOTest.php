<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Propeller\Tests\TestCase;

/**
 * Test for PropelPDO subclass.
 *
 * @package    runtime.connection
 */
class PropelPDOTest extends TestCase
{
    public function testSetAttribute()
    {
        $con = $this->getConnection(BookPeer::DATABASE_NAME);

        $this->assertFalse($con->getAttribute(PropelPDO::PROPEL_ATTR_CACHE_PREPARES));
        $con->setAttribute(PropelPDO::PROPEL_ATTR_CACHE_PREPARES, true);
        $this->assertTrue($con->getAttribute(PropelPDO::PROPEL_ATTR_CACHE_PREPARES));

        $con->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
        $this->assertEquals(PDO::CASE_LOWER, $con->getAttribute(PDO::ATTR_CASE));
    }

    public function testCommitBeforeFetch()
    {
        $con = $this->getConnection(BookPeer::DATABASE_NAME);

        AuthorPeer::doDeleteAll($con);
        $a = new Author();
        $a->setFirstName('Test');
        $a->setLastName('User');
        $a->save($con);

        $con->beginTransaction();
        $stmt = $con->prepare('SELECT author.FIRST_NAME, author.LAST_NAME FROM author');

        $stmt->execute();
        $con->commit();
        $authorArr = [0 => 'Test', 1 => 'User'];

        $i = 0;
        try {
            $row = $stmt->fetch(PDO::FETCH_NUM);
            $stmt->closeCursor();
            $this->assertEquals($authorArr, $row, 'PDO driver supports calling $stmt->fetch after the transaction has been closed');
        } catch (PDOException $e) {
            $this->fail("PDO driver does not support calling \$stmt->fetch after the transaction has been closed.\nFails with error " . $e->getMessage());
        }
    }

    public function testCommitAfterFetch()
    {
        $con = $this->getConnection(BookPeer::DATABASE_NAME);

        AuthorPeer::doDeleteAll($con);
        $a = new Author();
        $a->setFirstName('Test');
        $a->setLastName('User');
        $a->save($con);

        $con->beginTransaction();
        $stmt = $con->prepare('SELECT author.FIRST_NAME, author.LAST_NAME FROM author');

        $stmt->execute();
        $authorArr = [0 => 'Test', 1 => 'User'];

        $i = 0;
        $row = $stmt->fetch(PDO::FETCH_NUM);
        $stmt->closeCursor();
        $con->commit();
        $this->assertEquals($authorArr, $row, 'PDO driver supports calling $stmt->fetch before the transaction has been closed');
    }

    public function testNestedTransactionCommit()
    {
        $con = $this->getConnection(BookPeer::DATABASE_NAME);

        $this->assertEquals(0, $con->getNestedTransactionCount(), 'nested transaction is equal to 0 before transaction');
        $this->assertFalse($con->isInTransaction(), 'PropelPDO is not in transaction by default');

        $con->beginTransaction();

        $this->assertEquals(1, $con->getNestedTransactionCount(), 'nested transaction is incremented after main transaction begin');
        $this->assertTrue($con->isInTransaction(), 'PropelPDO is in transaction after main transaction begin');

        try {

            $a = new Author();
            $a->setFirstName('Test');
            $a->setLastName('User');
            $a->save($con);
            $authorId = $a->getId();
            $this->assertNotNull($authorId, "Expected valid new author ID");

            $con->beginTransaction();

            $this->assertEquals(2, $con->getNestedTransactionCount(), 'nested transaction is incremented after nested transaction begin');
            $this->assertTrue($con->isInTransaction(), 'PropelPDO is in transaction after nested transaction begin');

            try {

                $a2 = new Author();
                $a2->setFirstName('Test2');
                $a2->setLastName('User2');
                $a2->save($con);
                $authorId2 = $a2->getId();
                $this->assertNotNull($authorId2, "Expected valid new author ID");

                $con->commit();

                $this->assertEquals(1, $con->getNestedTransactionCount(), 'nested transaction decremented after nested transaction commit');
                $this->assertTrue($con->isInTransaction(), 'PropelPDO is in transaction after main transaction commit');

            } catch (Exception $e) {
                $con->rollBack();
                throw $e;
            }

            $con->commit();

            $this->assertEquals(0, $con->getNestedTransactionCount(), 'nested transaction decremented after main transaction commit');
            $this->assertFalse($con->isInTransaction(), 'PropelPDO is not in transaction after main transaction commit');

        } catch (Exception $e) {
            $con->rollBack();
        }

        AuthorPeer::clearInstancePool();
        $at = AuthorPeer::retrieveByPK($authorId);
        $this->assertNotNull($at, "Committed transaction is persisted in database");
        $at2 = AuthorPeer::retrieveByPK($authorId2);
        $this->assertNotNull($at2, "Committed transaction is persisted in database");
    }

    /**
     * @link       http://trac.propelorm.org/ticket/699
     */
    public function testNestedTransactionRollBackRethrow()
    {
        $con = $this->getConnection(BookPeer::DATABASE_NAME);

        $con->beginTransaction();
        try {

            $a = new Author();
            $a->setFirstName('Test');
            $a->setLastName('User');
            $a->save($con);
            $authorId = $a->getId();

            $this->assertNotNull($authorId, "Expected valid new author ID");

            $con->beginTransaction();

            $this->assertEquals(2, $con->getNestedTransactionCount(), 'nested transaction is incremented after nested transaction begin');
            $this->assertTrue($con->isInTransaction(), 'PropelPDO is in transaction after nested transaction begin');

            try {
                $con->exec('INVALID SQL');
                $this->fail("Expected exception on invalid SQL");
            } catch (PDOException $x) {
                $con->rollBack();

                $this->assertEquals(1, $con->getNestedTransactionCount(), 'nested transaction decremented after nested transaction rollback');
                $this->assertTrue($con->isInTransaction(), 'PropelPDO is in transaction after main transaction rollback');

                throw $x;
            }

            $con->commit();
        } catch (Exception $x) {
            $con->rollBack();
        }

        AuthorPeer::clearInstancePool();
        $at = AuthorPeer::retrieveByPK($authorId);
        $this->assertNull($at, "Rolled back transaction is not persisted in database");
    }

    /**
     * @link       http://trac.propelorm.org/ticket/699
     */
    public function testNestedTransactionRollBackSwallow()
    {
        $con = $this->getConnection(BookPeer::DATABASE_NAME);

        $con->beginTransaction();
        try {

            $a = new Author();
            $a->setFirstName('Test');
            $a->setLastName('User');
            $a->save($con);

            $authorId = $a->getId();
            $this->assertNotNull($authorId, "Expected valid new author ID");

            $con->beginTransaction();
            try {

                $a2 = new Author();
                $a2->setFirstName('Test2');
                $a2->setLastName('User2');
                $a2->save($con);
                $authorId2 = $a2->getId();
                $this->assertNotNull($authorId2, "Expected valid new author ID");

                $con->exec('INVALID SQL');
                $this->fail("Expected exception on invalid SQL");
            } catch (PDOException $e) {
                $con->rollBack();
                // NO RETHROW
            }

            $a3 = new Author();
            $a3->setFirstName('Test2');
            $a3->setLastName('User2');
            $a3->save($con);

            $authorId3 = $a3->getId();
            $this->assertNotNull($authorId3, "Expected valid new author ID");

            $con->commit();
            $this->fail("Commit fails after a nested rollback");
        } catch (PropelException $e) {
            $this->assertTrue(true, "Commit fails after a nested rollback");
            $con->rollback();
        }

        AuthorPeer::clearInstancePool();
        $at = AuthorPeer::retrieveByPK($authorId);
        $this->assertNull($at, "Rolled back transaction is not persisted in database");
        $at2 = AuthorPeer::retrieveByPK($authorId2);
        $this->assertNull($at2, "Rolled back transaction is not persisted in database");
        $at3 = AuthorPeer::retrieveByPK($authorId3);
        $this->assertNull($at3, "Rolled back nested transaction is not persisted in database");
    }

    public function testNestedTransactionForceRollBack()
    {
        $con = $this->getConnection(BookPeer::DATABASE_NAME);

        // main transaction
        $con->beginTransaction();

        $a = new Author();
        $a->setFirstName('Test');
        $a->setLastName('User');
        $a->save($con);
        $authorId = $a->getId();

        // nested transaction
        $con->beginTransaction();

        $a2 = new Author();
        $a2->setFirstName('Test2');
        $a2->setLastName('User2');
        $a2->save($con);
        $authorId2 = $a2->getId();

        // force rollback
        $con->forceRollback();

        $this->assertEquals(0, $con->getNestedTransactionCount(), 'nested transaction is null after nested transaction forced rollback');
        $this->assertFalse($con->isInTransaction(), 'PropelPDO is not in transaction after nested transaction force rollback');

        AuthorPeer::clearInstancePool();
        $at = AuthorPeer::retrieveByPK($authorId);
        $this->assertNull($at, "Rolled back transaction is not persisted in database");
        $at2 = AuthorPeer::retrieveByPK($authorId2);
        $this->assertNull($at2, "Forced Rolled back nested transaction is not persisted in database");
    }

    public function testLatestQuery()
    {
        $con = $this->getConnection(BookPeer::DATABASE_NAME);

        $con->setLastExecutedQuery(123);
        $this->assertEquals(123, $con->getLastExecutedQuery(), 'PropelPDO has getter and setter for last executed query');
    }

    public function testLatestQueryMoreThanTenArgs()
    {
        $con = $this->getConnection(BookPeer::DATABASE_NAME);

        $c = new Criteria();
        $c->add(BookPeer::ID, [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1], Criteria::IN);
        $books = BookPeer::doSelect($c, $con);
        $expected = "SELECT book.id, book.title, book.isbn, book.price, book.publisher_id, book.author_id FROM `book` WHERE book.id IN (1,1,1,1,1,1,1,1,1,1,1,1)";
        $this->assertEquals($expected, $con->getLastExecutedQuery(), 'PropelPDO correctly replaces arguments in queries');
    }

    public function testQueryCount()
    {
        $con = $this->getConnection(BookPeer::DATABASE_NAME);

        $count = $con->getQueryCount();
        $con->incrementQueryCount();
        $this->assertEquals($count + 1, $con->getQueryCount(), 'PropelPDO has getter and incrementer for query count');
    }

    public function testUseDebug()
    {
        $con = $this->getConnection(BookPeer::DATABASE_NAME);

        $this->useDebug($con, false);

        $this->assertEquals([PDOStatement::class], $con->getAttribute(PDO::ATTR_STATEMENT_CLASS), 'Statement is PDOStatement when debug is false');

        $this->useDebug($con);

        $this->assertEquals(
            [DebugPDOStatement::class, [$con]],
            $con->getAttribute(PDO::ATTR_STATEMENT_CLASS),
            'statement is DebugPDOStatement when debug is true'
        );
    }

    public function testDebugLatestQuery()
    {
        $con = $this->getConnection(BookPeer::DATABASE_NAME);

        $this->useDebug($con);

        $c = new Criteria();
        $c->add(BookPeer::TITLE, 'Harry%s', Criteria::LIKE);

        $this->useDebug($con, false);

        $this->assertEquals('', $con->getLastExecutedQuery(), 'PropelPDO reinitializes the latest query when debug is set to false');

        $books = BookPeer::doSelect($c, $con);
        $this->assertEquals('', $con->getLastExecutedQuery(), 'PropelPDO does not update the last executed query when useLogging is false');

        $this->useDebug($con);

        $books = BookPeer::doSelect($c, $con);
        $latestExecutedQuery = "SELECT book.id, book.title, book.isbn, book.price, book.publisher_id, book.author_id FROM `book` WHERE book.title LIKE 'Harry%s'";
        if (!Propel::getDB(BookPeer::DATABASE_NAME)->useQuoteIdentifier()) {
            $latestExecutedQuery = str_replace('`', '', $latestExecutedQuery);
        }
        $this->assertEquals($latestExecutedQuery, $con->getLastExecutedQuery(), 'PropelPDO updates the last executed query when useLogging is true');

        BookPeer::doDeleteAll($con);
        $latestExecutedQuery = "DELETE FROM `book`";
        $this->assertEquals($latestExecutedQuery, $con->getLastExecutedQuery(), 'PropelPDO updates the last executed query on delete operations');

        $sql = 'DELETE FROM book WHERE 1=1';
        $con->exec($sql);
        $this->assertEquals($sql, $con->getLastExecutedQuery(), 'PropelPDO updates the last executed query on exec operations');

        $sql = 'DELETE FROM book WHERE 2=2';
        $con->query($sql);
        $this->assertEquals($sql, $con->getLastExecutedQuery(), 'PropelPDO updates the last executed query on query operations');

        $stmt = $con->prepare('DELETE FROM book WHERE 1=:p1');
        $stmt->bindValue(':p1', '2');
        $stmt->execute();
        $this->assertEquals("DELETE FROM book WHERE 1='2'", $con->getLastExecutedQuery(), 'PropelPDO updates the last executed query on prapared statements');

        $this->useDebug($con, false);

        $this->assertEquals('', $con->getLastExecutedQuery(), 'PropelPDO reinitializes the latest query when debug is set to false');
    }

    public function testDebugQueryCount()
    {
        $con = $this->getConnection(BookPeer::DATABASE_NAME);

        $this->useDebug($con, false);

        $c = new Criteria();
        $c->add(BookPeer::TITLE, 'Harry%s', Criteria::LIKE);

        $this->assertEquals(0, $con->getQueryCount(), 'PropelPDO does not update the query count when useLogging is false');

        $books = BookPeer::doSelect($c, $con);
        $this->assertEquals(0, $con->getQueryCount(), 'PropelPDO does not update the query count when useLogging is false');

        $this->useDebug($con);

        $books = BookPeer::doSelect($c, $con);
        $this->assertEquals(1, $con->getQueryCount(), 'PropelPDO updates the query count when useLogging is true');

        BookPeer::doDeleteAll($con);
        $this->assertEquals(2, $con->getQueryCount(), 'PropelPDO updates the query count on delete operations');

        $sql = 'DELETE FROM book WHERE 1=1';
        $con->exec($sql);
        $this->assertEquals(3, $con->getQueryCount(), 'PropelPDO updates the query count on exec operations');

        $sql = 'DELETE FROM book WHERE 2=2';
        $con->query($sql);
        $this->assertEquals(4, $con->getQueryCount(), 'PropelPDO updates the query count on query operations');

        $stmt = $con->prepare('DELETE FROM book WHERE 1=:p1');
        $stmt->bindValue(':p1', '2');
        $stmt->execute();
        $this->assertEquals(5, $con->getQueryCount(), 'PropelPDO updates the query count on prapared statements');

        $this->useDebug($con, false);

        $this->assertEquals(0, $con->getQueryCount(), 'PropelPDO reinitializes the query count when debug is set to false');
    }

    public function testDebugLog()
    {
        $con = $this->getConnection(BookPeer::DATABASE_NAME);

        $this->useDebug($con);

        $logger = new class extends AbstractLogger {
            public $latestMessage = '';

            public function log($level, $message, array $context = []): void
            {
                $this->latestMessage = "{$level}: {$message}";
            }
        };

        $this->useLogger($con, $logger);

        $this->useConfiguration('debugpdo.logging.methods', [
            'PropelPDO::exec',
            'PropelPDO::query',
            'PropelPDO::beginTransaction',
            'PropelPDO::commit',
            'PropelPDO::rollBack',
            'DebugPDOStatement::execute',
        ]);

        // test transaction log
        $con->beginTransaction();
        $this->assertEquals('debug: Begin transaction', $logger->latestMessage, 'PropelPDO logs begin transaction in debug mode');

        $con->commit();
        $this->assertEquals('debug: Commit transaction', $logger->latestMessage, 'PropelPDO logs commit transaction in debug mode');

        $con->beginTransaction();
        $con->rollBack();
        $this->assertEquals('debug: Rollback transaction', $logger->latestMessage, 'PropelPDO logs rollback transaction in debug mode');

        $con->beginTransaction();
        $logger->latestMessage = '';
        $con->beginTransaction();
        $this->assertEquals('', $logger->latestMessage, 'PropelPDO does not log nested begin transaction in debug mode');
        $con->commit();
        $this->assertEquals('', $logger->latestMessage, 'PropelPDO does not log nested commit transaction in debug mode');
        $con->beginTransaction();
        $con->rollBack();
        $this->assertEquals('', $logger->latestMessage, 'PropelPDO does not log nested rollback transaction in debug mode');
        $con->rollback();

        // test query log
        $con->beginTransaction();

        $c = new Criteria();
        $c->add(BookPeer::TITLE, 'Harry%s', Criteria::LIKE);

        $books = BookPeer::doSelect($c, $con);
        $latestExecutedQuery = "SELECT book.id, book.title, book.isbn, book.price, book.publisher_id, book.author_id FROM `book` WHERE book.title LIKE 'Harry%s'";
        $this->assertEquals('debug: ' . $latestExecutedQuery, $logger->latestMessage, 'PropelPDO logs queries and populates bound parameters in debug mode');

        BookPeer::doDeleteAll($con);
        $latestExecutedQuery = "DELETE FROM `book`";
        $this->assertEquals('debug: ' . $latestExecutedQuery, $logger->latestMessage, 'PropelPDO logs deletion queries in debug mode');

        $latestExecutedQuery = 'DELETE FROM book WHERE 1=1';
        $con->exec($latestExecutedQuery);
        $this->assertEquals('debug: ' . $latestExecutedQuery, $logger->latestMessage, 'PropelPDO logs exec queries in debug mode');

        $con->commit();
    }

    /**
     * Testing if string values will be quoted correctly by DebugPDOStatement::getExecutedQueryString
     */
    public function testDebugExecutedQueryStringValue()
    {
        $con = Propel::getConnection(BookPeer::DATABASE_NAME);

        assert($con instanceof PropelPDO);

        $this->useDebug($con);

        // different method must all result in this given querystring, using a string value
        $bindParamStringValue = "%Harry%";
        $expectedQuery = "SELECT book.id FROM `book` WHERE book.title LIKE '{$bindParamStringValue}'";

        // simple statement without params
        $prepStmt = $con->prepare($expectedQuery);
        $prepStmt->execute();
        $this->assertEquals($expectedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        // statement with named placeholder
        $prepStmt = $con->prepare("SELECT book.id FROM `book` WHERE book.title LIKE :p1");
        $prepStmt->bindValue(':p1', '%Harry%'); // bind value variant
        $prepStmt->execute();
        $this->assertEquals($expectedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        $prepStmt->bindParam(':p1', $bindParamStringValue); // bind param variant
        $prepStmt->execute();
        $this->assertEquals($expectedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        // passing params directly
        $prepStmt->execute([':p1' => '%Harry%']);
        $this->assertEquals($expectedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        // statement with named placeholder, this one won't get substituted
        $expectedNotSubstitutedQuery = "SELECT book.id FROM `book` WHERE book.title LIKE :name";
        $prepStmt = $con->prepare($expectedNotSubstitutedQuery);
        $prepStmt->bindValue(':name', '%Harry%'); // bind value variant
        $prepStmt->execute();
        $this->assertEquals($expectedNotSubstitutedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        $prepStmt->bindParam(':name', $bindParamStringValue); // bind param variant
        $prepStmt->execute();
        $this->assertEquals($expectedNotSubstitutedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        // passing params directly
        $prepStmt->execute([':name' => '%Harry%']);
        $this->assertEquals($expectedNotSubstitutedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');


        // statement with positional placeholder, this one won't get substituted either
        $expectedNotSubstitutedQuery = "SELECT book.id FROM `book` WHERE book.title LIKE ?";
        $prepStmt = $con->prepare($expectedNotSubstitutedQuery);
        $prepStmt->bindValue(1, '%Harry%');
        $prepStmt->execute();
        $this->assertEquals($expectedNotSubstitutedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        $prepStmt->bindParam(1, $bindParamStringValue);
        $prepStmt->execute();
        $this->assertEquals($expectedNotSubstitutedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        // passing params directly
        $prepStmt->execute(['%Harry%']);
        $this->assertEquals($expectedNotSubstitutedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');
    }

    /**
     * Testing if integer values will be quoted correctly by DebugPDOStatement::getExecutedQueryString
     */
    public function testDebugExecutedQueryIntegerValue()
    {
        $con = Propel::getConnection(BookPeer::DATABASE_NAME);

        assert($con instanceof PropelPDO);

        $this->useDebug($con);

        // different method must all result in this given querystring, using an integer value
        $bindParamIntegerValue = 123;
        $expectedQuery = "SELECT book.title FROM `book` WHERE book.id = {$bindParamIntegerValue}";

        // simple statement without params
        $prepStmt = $con->prepare($expectedQuery);
        $prepStmt->execute();
        $this->assertEquals($expectedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        // statement with named placeholder
        $prepStmt = $con->prepare("SELECT book.title FROM `book` WHERE book.id = :p1");
        $prepStmt->bindValue(':p1', 123); // bind value variant
        $prepStmt->execute();
        $this->assertEquals($expectedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        $prepStmt->bindParam(':p1', $bindParamIntegerValue); // bind param variant
        $prepStmt->execute();
        $this->assertEquals($expectedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        // passing params directly
        $prepStmt->execute([':p1' => 123]);
        $this->assertEquals($expectedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        // statement with named placeholder, this one won't get substituted
        $expectedNotSubstitutedQuery = "SELECT book.title FROM `book` WHERE book.id = :name";
        $prepStmt = $con->prepare($expectedNotSubstitutedQuery);
        $prepStmt->bindValue(':name', 123); // bind value variant
        $prepStmt->execute();
        $this->assertEquals($expectedNotSubstitutedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        $prepStmt->bindParam(':name', $bindParamIntegerValue); // bind param variant
        $prepStmt->execute();
        $this->assertEquals($expectedNotSubstitutedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        // passing params directly
        $prepStmt->execute([':name' => 123]);
        $this->assertEquals($expectedNotSubstitutedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');


        // statement with positional placeholder, this one won't get substituted either
        $expectedNotSubstitutedQuery = "SELECT book.title FROM `book` WHERE book.id = ?";
        $prepStmt = $con->prepare($expectedNotSubstitutedQuery);
        $prepStmt->bindValue(1, 123);
        $prepStmt->execute();
        $this->assertEquals($expectedNotSubstitutedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        $prepStmt->bindParam(1, $bindParamIntegerValue);
        $prepStmt->execute();
        $this->assertEquals($expectedNotSubstitutedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        // passing params directly
        $prepStmt->execute([123]);
        $this->assertEquals($expectedNotSubstitutedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');
    }

    /**
     * Testing if numeric values will be quoted correctly by DebugPDOStatement::getExecutedQueryString
     * Numeric values sometimes will get handled differently, since there are numeric values which are non-integer
     */
    public function testDebugExecutedQueryNumericValue()
    {
        $con = Propel::getConnection(BookPeer::DATABASE_NAME);

        assert($con instanceof PropelPDO);

        $this->useDebug($con);

        // different method must all result in this given querystring, using an integer value
        $bindParamNumericValue = 0002000;
        $expectedQuery = "SELECT book.title FROM `book` WHERE book.id = {$bindParamNumericValue}";

        // simple statement without params
        $prepStmt = $con->prepare($expectedQuery);
        $prepStmt->execute();
        $this->assertEquals($expectedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        // statement with named placeholder
        $prepStmt = $con->prepare("SELECT book.title FROM `book` WHERE book.id = :p1");
        $prepStmt->bindValue(':p1', 0002000); // bind value variant
        $prepStmt->execute();
        $this->assertEquals($expectedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        $prepStmt->bindParam(':p1', $bindParamNumericValue); // bind param variant
        $prepStmt->execute();
        $this->assertEquals($expectedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        // passing params directly
        $prepStmt->execute([':p1' => 0002000]);
        $this->assertEquals($expectedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        // statement with named placeholder, this one won't get substituted
        $expectedNotSubstitutedQuery = "SELECT book.title FROM `book` WHERE book.id = :name";
        $prepStmt = $con->prepare($expectedNotSubstitutedQuery);
        $prepStmt->bindValue(':name', 0002000); // bind value variant
        $prepStmt->execute();
        $this->assertEquals($expectedNotSubstitutedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        $prepStmt->bindParam(':name', $bindParamNumericValue); // bind param variant
        $prepStmt->execute();
        $this->assertEquals($expectedNotSubstitutedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        // passing params directly
        $prepStmt->execute([':name' => 0002000]);
        $this->assertEquals($expectedNotSubstitutedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');


        // statement with positional placeholder, this one won't get substituted either
        $expectedNotSubstitutedQuery = "SELECT book.title FROM `book` WHERE book.id = ?";
        $prepStmt = $con->prepare($expectedNotSubstitutedQuery);
        $prepStmt->bindValue(1, 0002000);
        $prepStmt->execute();
        $this->assertEquals($expectedNotSubstitutedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        $prepStmt->bindParam(1, $bindParamNumericValue);
        $prepStmt->execute();
        $this->assertEquals($expectedNotSubstitutedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');

        // passing params directly
        $prepStmt->execute([0002000]);
        $this->assertEquals($expectedNotSubstitutedQuery, $con->getLastExecutedQuery(), 'DebugPDO failed to quote prepared statement on execute properly');
    }

    private function useLogger(PropelPDO $con, LoggerInterface $logger): callable
    {
        return $this->useEffect(function () use ($con, $logger) {
            // save data to return to normal state after test
            $prevLogger = $con->getLogger();

            $con->setLogger($logger);

            return function () use ($prevLogger, $con) {
                $con->setLogger($prevLogger);
            };
        });
    }

    private function useConfiguration(string $entry, $value): callable
    {
        return $this->useEffect(function () use ($entry, $value) {
            $config = Propel::getConfiguration(PropelConfiguration::TYPE_OBJECT);

            assert($config instanceof PropelConfiguration);

            $prevValue = $config->getParameter($entry);

            $config->setParameter($entry, $value, false);

            return function () use ($config, $entry, $prevValue) {
                $config->setParameter($entry, $prevValue, false);
            };
        });
    }
}
<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Test class for BaseObject.
 *
 * @author     François Zaninotto
 * @version    $Id: BaseObjectTest.php 1347 2009-12-03 21:06:36Z francois $
 * @package    runtime.om
 */
class BaseObjectTest extends \PHPUnit\Framework\TestCase
{
    public function testGetVirtualColumns()
    {
        $b = new TestableBaseObject();
        $this->assertEquals(array(), $b->getVirtualColumns(), 'getVirtualColumns() returns an empty array for new objects');
        $b->virtualColumns = array('foo' => 'bar');
        $this->assertEquals(array('foo' => 'bar'), $b->getVirtualColumns(), 'getVirtualColumns() returns an associative array of virtual columns');
    }

    public function testHasVirtualColumn()
    {
        $b = new TestableBaseObject();
        $this->assertFalse($b->hasVirtualColumn('foo'), 'hasVirtualColumn() returns false if the virtual column is not set');
        $b->virtualColumns = array('foo' => 'bar');
        $this->assertTrue($b->hasVirtualColumn('foo'), 'hasVirtualColumn() returns true if the virtual column is set');
    }

    public function testGetVirtualColumnWrongKey()
    {
        $b = new TestableBaseObject();

        $this->expectException(PropelException::class);

        $b->getVirtualColumn('foo');
    }

    public function testGetVirtualColumn()
    {
        $b = new TestableBaseObject();
        $b->virtualColumns = array('foo' => 'bar');
        $this->assertEquals('bar', $b->getVirtualColumn('foo'), 'getVirtualColumn() returns a virtual column value based on its key');
    }

    public function testSetVirtualColumn()
    {
        $b = new TestableBaseObject();
        $b->setVirtualColumn('foo', 'bar');
        $this->assertEquals('bar', $b->getVirtualColumn('foo'), 'setVirtualColumn() sets a virtual column value based on its key');
        $b->setVirtualColumn('foo', 'baz');
        $this->assertEquals('baz', $b->getVirtualColumn('foo'), 'setVirtualColumn() can modify the value of an existing virtual column');
        $this->assertEquals($b, $b->setVirtualColumn('foo', 'bar'), 'setVirtualColumn() returns the current object');
    }

    public function testSetNewReturnsSelf()
    {
        $b = new TestableBaseObject();
        $this->assertInstanceOf(TestableBaseObject::class, $b->setNew(false));
        $this->assertInstanceOf(TestableBaseObject::class, $b->setNew(true));
    }

    public function testSetDeletedReturnsSelf()
    {
        $b = new TestableBaseObject();
        $this->assertInstanceOf(TestableBaseObject::class, $b->setDeleted(false));
        $this->assertInstanceOf(TestableBaseObject::class, $b->setDeleted(true));
    }

    public function testResetModifiedReturnsSelf()
    {
        $b = new TestableBaseObject();
        $this->assertInstanceOf(TestableBaseObject::class, $b->resetModified());
    }
}

class TestableBaseObject extends BaseObject
{
    public $virtualColumns = array();
}

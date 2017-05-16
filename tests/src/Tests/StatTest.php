<?php
/**
 * This work has been adapted from the original work by Stickee Technology Limited
 * and is based on works licenced under the MIT Licence by Chris Boulton, also
 * previously adapted by Wan Qi Chen
 *
 * Original work Copyright (c) 2010 Chris Boulton <chris@bigcommerce.com>
 * Modified Work Copyright (c) 2017 Stickee Technology Limited
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
 * of the Software, and to permit persons to whom the Software is furnished to do
 * so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace Resque\Tests;

use Resque\Stat;

/**
 * Resque_Stat tests.
 *
 * @package        Resque/Tests
 *
 * @author        Chris Boulton <chris@bigcommerce.com>
 * @license        http://www.opensource.org/licenses/mit-license.php
 */
class StatTest extends TestCase
{
    public function testStatCanBeIncremented()
    {
        Stat::incr('test_incr');
        Stat::incr('test_incr');
        $this->assertEquals(2, $this->redis->get('stat:test_incr'));
    }

    public function testStatCanBeIncrementedByX()
    {
        Stat::incr('test_incrX', 10);
        Stat::incr('test_incrX', 11);
        $this->assertEquals(21, $this->redis->get('stat:test_incrX'));
    }

    public function testStatCanBeDecremented()
    {
        Stat::incr('test_decr', 22);
        Stat::decr('test_decr');
        $this->assertEquals(21, $this->redis->get('stat:test_decr'));
    }

    public function testStatCanBeDecrementedByX()
    {
        Stat::incr('test_decrX', 22);
        Stat::decr('test_decrX', 11);
        $this->assertEquals(11, $this->redis->get('stat:test_decrX'));
    }

    public function testGetStatByName()
    {
        Stat::incr('test_get', 100);
        $this->assertEquals(100, Stat::get('test_get'));
    }

    public function testGetUnknownStatReturns0()
    {
        $this->assertEquals(0, Stat::get('test_get_unknown'));
    }
}
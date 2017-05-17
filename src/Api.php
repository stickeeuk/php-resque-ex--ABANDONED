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

namespace Resque;

use Redis;

/**
 * Extended Redisent class used by Resque for all communication with
 * redis. Essentially adds namespace support to Redisent.
 *
 * @package Resque
 *
 * @author Chris Boulton <chris.boulton@interspire.com>
 * @copyright (c) 2010 Chris Boulton
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class Api extends Redis
{
    private static $defaultNamespace = 'resque:';

    public function __construct($host, $port, $timeout = 5, $password = null)
    {
        parent::__construct();
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
        $this->password = $password;
        $this->establishConnection();
    }

    public function establishConnection()
    {
        $this->pconnect($this->host, (int)$this->port, (int)$this->timeout, getmypid());
        if ($this->password !== null) {
            $this->auth($this->password);
        }
        $this->setOption(Redis::OPT_PREFIX, self::$defaultNamespace);
    }

    public function prefix($namespace)
    {
        if (empty($namespace)) {
            $namespace = self::$defaultNamespace;
        }
        if (strpos($namespace, ':') === false) {
            $namespace .= ':';
        }
        self::$defaultNamespace = $namespace;
        $this->setOption(Redis::OPT_PREFIX, self::$defaultNamespace);
    }

    public static function getPrefix()
    {
        return '';
    }
}

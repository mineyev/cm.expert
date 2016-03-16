<?php
namespace Harpoon;

use Harpoon\Common\Lang\StringUtils;
use Sys_Date as Date;

/**
 * @method keys($pattern)
 * @method delete($key)
 * @method lRemove($key, $value, $count)
 * @method lRem($key, $value, $count)
 * @method exists($key)
 * @method expire($key, $expire)
 * @method expireAt($key, $expireAt)
 * @method pexpire($key, $expireInMilliseconds)
 * @method pexpireAt($key, $expireAtInMilliseconds)
 * @method ttl($key)
 * @method pttl($key)
 * @method lSize($key)
 * @method lGet($key, $index)
 * @method lLen($key)
 * @method lPop($key)
 * @method sAdd($key, $index)
 * @method sRemove($key, $index)
 * @method sMembers($key)
 * @method hGetAll($key)
 * @method hLen($key)
 * @method rPush($key, $index)
 * @method lPush($key, $index)
 * @method lTrim($key, $start, $stop)
 * @method lRange($key, $index, $offset)
 * @method lGetRange($key, $index, $offset)
 * @method incr($key)
 * @method multi()
 * @method exec()
 * @method decr($key)
 *
 * @method connect($host, $port)
 * @method auth($password)
 * @method select($dbIndex)
 */
class Redis {

	/** @var int|null  */
	private $dbIndex = null;

	/**
	 * @param string $host
	 * @param int    $port
	 * @param string $password
	 * @param int    $dbIndex
	 */
	public function __construct($host, $port, $password, $dbIndex) {
		$this->redis   = new \Redis();
		$this->dbIndex = $dbIndex;

		$this->redis->connect($host, $port);
		$this->redis->auth($password);
	}

	/**
	 * @param string $hashName
	 * @param string $key
	 *
	 * @return mixed
	 */
	public function hGet($hashName, $key) {
		$this->redis->select($this->dbIndex);

		$value  = $this->redis->hGet($hashName, $key);
		$result = StringUtils::jsonDecode($value);

		if ($result) {
			return $result;
		} else {
			return $value;
		}
	}

	/**
	 * @param $key
	 * @param bool $needEncodeValue - если необходимость в json-декодировании результата
	 * @return bool|mixed|string
	 */
	public function get($key, $needEncodeValue = true) {
		$this->redis->select($this->dbIndex);
		$value = $this->redis->get($key);
		if (!$needEncodeValue || !$value) {
			return $value;
		}
		$result = StringUtils::jsonDecode($value);

		if ($result) {
			return $result;
		} else {
			return $value;
		}
	}

	/**
	 * Просто устанавливает значение для ключа
	 *
	 * @param string $key
	 * @param mixed  $value
	 *
	 * @return bool
	 */
	public function set($key, $value) {
		$this->redis->select($this->dbIndex);
		$value = $this->jsonEncodeIfNeed($value);

		return $this->redis->set($key, $value);
	}

	/**
	 * Записывает значение и устанавливает время жизни в секундах
	 *
	 * @param string $key
	 * @param int    $seconds
	 * @param mixed  $value
	 *
	 * @return bool
	 */
	public function setex($key, $seconds, $value) {
		$this->redis->select($this->dbIndex);
		$value = $this->jsonEncodeIfNeed($value);

		return $this->redis->setex($key, $seconds, $value);
	}

	/**
	 * Записывает значение и устанавливает время жизни в миллисекундах (1/1000 секунды)
	 *
	 * @param string $key
	 * @param int    $milliseconds
	 * @param mixed  $value
	 *
	 * @return bool
	 */
	public function psetex($key, $milliseconds, $value) {
		$this->redis->select($this->dbIndex);
		$value = $this->jsonEncodeIfNeed($value);

		return $this->redis->psetex($key, $milliseconds, $value);
	}

	/**
	 * Устанавливает значение только если указанного ключа не существует.
	 * Возвращает true или false в зависимости от результата установки
	 *
	 * @param string $key
	 * @param mixed  $value
	 *
	 * @return bool
	 */
	public function setnx($key, $value) {
		$this->redis->select($this->dbIndex);
		$value = $this->jsonEncodeIfNeed($value);

		return $this->redis->setnx($key, $value);
	}

	/**
	 * Хеш - это по сути ассоциативный массив
	 * А $key - это ключ к одному из его элементов
	 *
	 * @param string $hashName
	 * @param string $key
	 * @param mixed  $value
	 *
	 * @return int|bool
	 */
	public function hSet($hashName, $key, $value) {
		$this->redis->select($this->dbIndex);
		$value = $this->jsonEncodeIfNeed($value);

		return $this->redis->hSet($hashName, $key, $value);
	}

	/**
	 * @param mixed $value
	 *
	 * @return string
	 */
	private function jsonEncodeIfNeed($value) {
		if (is_array($value)) {
			$value = StringUtils::jsonEncode($value);
		}

		return $value;
	}

	/**
	 * Перегрузка методов Redis делается.
	 * Нужно для жесткого указания базы
	 *
	 * @param $method
	 * @param $params
	 * @return mixed
	 *
	 * @throws Redis\Exception
	 */
	public function __call($method, $params) {
		$this->redis->select($this->dbIndex);
		if (!method_exists($this->redis, $method)) {
			throw new Redis\Exception('Метод: ' . $method . ' отсутствует в Redis');
		}
		$i = call_user_func_array([$this->redis, $method], $params);

		return $i;
	}

	/**
	 * Исполняет callback функцию, блокируя ее повторное исполнение
	 * на определенное время, в любом другом потоке.
	 * После выполнения функции lockKey - ключ для блокировки, будет удален.
	 *
	 * @param callback   $callback                    Исполняемая функция.
	 * @param string     $lockKey                     Ключ, по которому функчия будет заблокирована.
	 * @param int        $lockTTLInMilliseconds       Время в милисикундах через которое мы будем повторять вызов callback функции.
	 *                                                Так же это время на которое мы заблокируем выполнение функции
	 * @param int        $lockWaitingTimeoutInSeconds Время ожидания разблокировки lockKey.
	 *                                                Когда оно закончитья будет выброшенно исключение.
	 * @param \Exception $customException             Исключение выбрасываемое, если время ожидания выполнения функции закончилось.
	 *
	 * @throws \Exception|null|\Harpoon\Redis\LockWaitingException
	 * @return mixed
	 */
	public function tryLockWithCallback(
		$callback, $lockKey, $lockTTLInMilliseconds, $lockWaitingTimeoutInSeconds, $customException = null
	) {
		$stopTime = time() + $lockWaitingTimeoutInSeconds;

		try {
			while ($this->setnx($lockKey, 1) === false) {
				if ($stopTime - time() > 0) {
					usleep($lockTTLInMilliseconds * 1000);
				} else {
					throw new Redis\LockWaitingException;
				}
			}
		} catch (Redis\LockWaitingException $e) {
			\Di::log()->warning('Redis заблокировал выполнение. Ключ: ' . $lockKey . ' уже используется');
			throw (is_null($customException) ? $e : $customException);
		}

		$lockExpireAt = Date::getMilliseconds() + $lockTTLInMilliseconds;
		$this->pexpireAt($lockKey, $lockExpireAt);

		$result = call_user_func($callback, $this);

		$this->delete($lockKey);

		return $result;
	}

	/**
	 * @throws Redis\Exception
	 */
	public function flushAll() {
		if (\Di::isProductionServer()) {
			throw new Redis\Exception('Нельзя удалять все');
		}
		return $this->redis->flushAll();
	}

	/**
	 * @return bool
	 * @throws Redis\Exception
	 */
	public function flushDb() {
		if (\Di::isProductionServer()) {
			throw new Redis\Exception('Нельзя удалять базу');
		}
		return $this->redis->flushDB();
	}

	/**
	 * @param int $dbIndex
	 */
	public function setDbIndex($dbIndex) {
		$this->dbIndex = $dbIndex;
	}
}

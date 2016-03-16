<?php
namespace ToolTips;

use Api\HelpDesk;

class ToolTipsCacheTest extends \Sys_Test_PHPUnit_TestCase {

	const APP_HOST                 = 'garpun.prog45.lan';
	const REDIS_SECTION_ID         = 5;
	const HELPDESK_SECTION_ID      = 4;
	const TOOLTIP_ENABLE_STATUS    = 'ENABLED';
	const HD_SECTION_ENABLE_STATUS = 'ENABLED';

	/**
	 * Ключи: ids
	 * Значения: налчичие id в redis
	 * @var array
	 */
	private $redisKeyExists = [
		1  => true,
		2  => true,
		3  => false,
		11 => false,
		22 => false,
		33 => false
	];

	private $expectedData = [
		1  => ['sectionId' => self::REDIS_SECTION_ID],
		2  => ['sectionId' => self::REDIS_SECTION_ID],
		3  => ['sectionId' => self::HELPDESK_SECTION_ID],
		11 => ['sectionId' => self::HELPDESK_SECTION_ID],
		22 => ['sectionId' => self::HELPDESK_SECTION_ID]
	];

	/***
	 * Возвращает параметризированный \Harpoon\RedisMock объект
	 *
	 * @return \PHPUnit_Framework_MockObject_MockObject
	 */
	private function getRedisMock() {
		$redis = $this->getMockBuilder('\Harpoon\Redis')
			->disableOriginalConstructor()
			->setMethods(['exists', 'get', 'set'])
			->getMock();

		$redis->expects($this->exactly(count($this->redisKeyExists)))
			->method('exists', 'getStatus')
			->will(
				$this->onConsecutiveCallsArray(
					array_values($this->redisKeyExists)
				)
			);
		$redis->expects($this->exactly(4))
			->method('get')
			->will(
				$this->onConsecutiveCallsArray([
					self::TOOLTIP_ENABLE_STATUS,
					self::REDIS_SECTION_ID,
					self::TOOLTIP_ENABLE_STATUS,
					self::REDIS_SECTION_ID
				]));

		$redis->expects($this->exactly(6))
			->method('set');

		return $redis;
	}

	/***
	 * Возвращает параметризированный \Api\HelpDeskMock объект
	 *
	 * @return \PHPUnit_Framework_MockObject_MockObject
	 */
	private function getHDApiMock() {
		$helpDeskApi = $this->getMockBuilder('\Api\HelpDesk')
			->disableOriginalConstructor()
			->setMethods(['getToolTipById', 'set'])
			->getMock();

		$tooltip = $this->getMockBuilder('\Api\HelpDesk\ToolTip')
			->setMethods(['getSectionId', 'getStatus'])
			->getMock();

		$tooltip->expects($this->any())
			->method('getSectionId')
			->will($this->onConsecutiveCallsArray([
				self::HELPDESK_SECTION_ID,
				self::HELPDESK_SECTION_ID,
				self::HELPDESK_SECTION_ID,
				null
			]));

		$tooltip->expects($this->any())
			->method('getStatus')
			->will($this->returnValue('ENABLED'));
		$helpDeskApi
			->expects($this->exactly(4))
			->method('getToolTipById')
			->will($this->returnValue($tooltip));

		return $helpDeskApi;
	}

	/**
	 * Тестирование метода TooltipCache::checkInCache()
	 *
	 * @test
	 */
	public function testCheckInCache() {
		$tcMock = $this->getMockBuilder('\ToolTips\ToolTipsCache')
			->setConstructorArgs([
					$this->getRedisMock(),
					$this->getHDApiMock(),
					self::APP_HOST
				]
			)->setMethods(['canLinkToSection'])->getMock();

		$tcMock->expects($this->exactly(3))
			->method('canLinkToSection')
			->will($this->returnValue(true));

		$result = $tcMock->checkInCache(array_keys($this->redisKeyExists));
		$this->assertEquals($this->expectedData, $result);
	}

	/***
	 * Тестирование метода TooltipCache::canLinkToSection()
	 *
	 * @test
	 */
	public function testCanLinkToSection() {
		$inputData      = [1, 22, 31, 201, 331];
		$expectedValues = [false, true, true, false, false];

		$helpDeskApi = $this->getMockBuilder('\Api\HelpDesk')
			->disableOriginalConstructor()
			->setMethods(['getHelpSectionById', 'getHelpSectionsChildrenShortList'])
			->getMock();

		$helpDeskApi->expects($this->exactly(2))
			->method('getHelpSectionsChildrenShortList')->will(
				$this->onConsecutiveCallsArray([
					['test', 'test2'],
					null
				])
			);

		$hdSectionMock = $this->getMockBuilder('Api\HelpDesk\HelpSection')
			->disableOriginalConstructor()
			->setMethods(['getIntroductoryText', 'getStatus'])
			->getMock();

		$hdSectionMock->expects($this->exactly(3))
			->method('getIntroductoryText')
			->will($this->onConsecutiveCallsArray([
					'test',
					null
				])
			);

		$hdSectionMock->expects($this->exactly(4))
			->method('getStatus')
			->will($this->onConsecutiveCallsArray([
					self::HD_SECTION_ENABLE_STATUS,
					self::HD_SECTION_ENABLE_STATUS,
					self::HD_SECTION_ENABLE_STATUS,
					false
				])
			);

		$helpDeskApi->expects($this->exactly(count($inputData)))
			->method('getHelpSectionById')->will(
				$this->onConsecutiveCallsArray([
					null,
					$hdSectionMock,
					$hdSectionMock,
					$hdSectionMock,
					$hdSectionMock,
				])
			);

		$redisMock = $this->getMockBuilder('\Harpoon\Redis')
			->disableOriginalConstructor()
			->getMock();

		$tc           = new ToolTipsCache($redisMock, $helpDeskApi, self::APP_HOST);
		$tcReflection = new \ReflectionClass('\ToolTips\ToolTipsCache');

		$method = $tcReflection->getMethod('canLinkToSection');
		$method->setAccessible(true);

		$outputData = [];
		foreach ($inputData as $sectionId) {
			$outputData[] = $method->invokeArgs($tc, [$sectionId]);
		}
		$this->assertEquals($outputData, $expectedValues);
	}

	private function getFirstElements($inputData) {
		$result = [];
		foreach ($inputData as $params) {
			$result[] = current($params);
		}

		return $result;
	}

	/**
	 * @test
	 */
	public function testOnLink() {
		$inputData = [[55, 2], ['test', 132]];

		$redisMock = $this->getMockBuilder('\Harpoon\Redis')
			->disableOriginalConstructor()
			->setMethods(['set', 'delete'])
			->getMock();

		$hdMock = $this->getMockBuilder('\Api\HelpDesk')
			->disableOriginalConstructor()
			->getMock();

		$tcMock = $this->getMockBuilder('\ToolTips\ToolTipsCache')
			->setConstructorArgs([
					$redisMock,
					$hdMock,
					self::APP_HOST
				]
			)
			->setMethods(['canLinkToSection', 'getSectionKey'])->getMock();

		$tcMock->expects($this->exactly(count($inputData)))
			->method('canLinkToSection')
			->will($this->onConsecutiveCallsArray([
				true,
				false
			]));

		$tcMock->expects($this->exactly(count($inputData)))
			->method('getSectionKey')
			->will($this->onConsecutiveCallsArray(
				$this->getFirstElements($inputData)
			));

		$redisMock->expects($this->once())
			->method('set')
			->with(55);

		$redisMock->expects($this->once())
			->method('delete')
			->with('test');

		foreach ($inputData as $inputParams) {
			$tcMock->onLink($inputParams[0], $inputParams[1]);
		}
	}
}
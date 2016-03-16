<?php
namespace ToolTips;

use Api\HelpDesk;
use Harpoon\Redis;

class ToolTipsCache {

	const REDIS_MAIN_KEY = "ToolTip";
	const ENABLED        = "ENABLED";
	const REMOVED        = "REMOVED";

	/** @var Redis|null  */
	protected $redis = null;
	/** @var HelpDesk|null  */
	protected $helpDeskApi = null;
	/** @var string|null  */
	protected $hostApp = null;
	/** @var array  */
	static protected $errorToolTipsIds = [];

	/**
	 * @param Redis     $redis
	 * @param HelpDesk  $helpDesk
	 * @param string    $appHost
	 */
	public function __construct(Redis $redis, HelpDesk $helpDesk, $appHost) {
		$this->redis       = $redis;
		$this->helpDeskApi = $helpDesk;
		$this->hostApp     = $appHost;
	}

	/**
	 * @param int $toolTipId
	 * @return string
	 */
	protected function getStatusKey($toolTipId) {
		return $this->hostApp . ':' . self::REDIS_MAIN_KEY . ":" . $toolTipId . ":status";
	}

	/***
	 * @param int $toolTipId
	 * @return string
	 */
	protected function getSectionKey($toolTipId) {
		return $this->hostApp . ':' . self::REDIS_MAIN_KEY . ":" . $toolTipId . ":sectionId";
	}

	/**
	 * @param $toolTipId int
	 */
	public function onCreate($toolTipId) {
		$this->redis->set($this->getStatusKey($toolTipId), self::ENABLED);
	}

	/**
	 * @param $toolTipId int
	 */
	public function onDelete($toolTipId) {
		$this->redis->set($this->getStatusKey($toolTipId), self::REMOVED);
	}

	/**
	 * @param $toolTipId int
	 * @param $sectionId int
	 */
	public function onLink($toolTipId, $sectionId) {
		$sectionKey = $this->getSectionKey($toolTipId);
		if ($this->canLinkToSection($sectionId)) {
			$this->redis->set($sectionKey, $sectionId);
		} else {
			$this->redis->delete($sectionKey);
		}
	}

	/**
	 * @param $ids array
	 * @return array
	 */
	public function checkInCache($ids) {
		$result = [];
		$ids = array_unique($ids);
		foreach ($ids as $id) {
			$statusKey  = $this->getStatusKey($id);
			$sectionKey = $this->getSectionKey($id);

			if (isset(self::$errorToolTipsIds[$id])) {
				continue;
			}

			if ($this->redis->exists($statusKey)) {
				if ($this->redis->get($statusKey) == self::ENABLED) {
					$result[$id]['sectionId'] = $this->redis->get($sectionKey);
				}
			} else {
				$tooltip   = $this->helpDeskApi->getToolTipById($id);
				if (empty($tooltip)) {
					self::$errorToolTipsIds[$id] = 1;
					continue;
				}
				$sectionId = $tooltip->getSectionId();
				if (!empty($sectionId)) {
					$this->redis->set($statusKey, $tooltip->getStatus());
					if ($this->canLinkToSection($sectionId)) {
						$this->redis->set($sectionKey, $sectionId);
						if ($tooltip->getStatus() == self::ENABLED) {
							$result[$id]['sectionId'] = $sectionId;
						}
					}
				} else {
					self::$errorToolTipsIds[$id] = 1;
				}
			}
		}

		return $result;
	}

	/**
	 * @param $sectionId int
	 * @return bool
	 */
	protected function canLinkToSection($sectionId) {
		$linkedSection = $this->helpDeskApi->getHelpSectionById($sectionId);
		if (empty($linkedSection) || $linkedSection->getStatus() != self::ENABLED) {
			return false;
		}
		$introText = $linkedSection->getIntroductoryText();
		if (empty($introText)) {
			$children = $this->helpDeskApi->getHelpSectionsChildrenShortList($sectionId);
			if (empty($children)) {
				return false;
			}
		}

		return true;
	}
}
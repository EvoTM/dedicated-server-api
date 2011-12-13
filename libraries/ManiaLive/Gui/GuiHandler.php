<?php
/**
 * ManiaLive - TrackMania dedicated server manager in PHP
 *
 * @copyright   Copyright (c) 2009-2011 NADEO (http://www.nadeo.com)
 * @license     http://www.gnu.org/licenses/lgpl.html LGPL License 3
 * @version     $Revision$:
 * @author      $Author$:
 * @date        $Date$:
 */

namespace ManiaLive\Gui;

use ManiaLib\Gui\Elements\Bgs1;
use ManiaLive\Event\Dispatcher;
use ManiaLive\Application\Listener as AppListener;
use ManiaLive\Application\Event as AppEvent;
use ManiaLive\DedicatedApi\Callback\Listener as ServerListener;
use ManiaLive\DedicatedApi\Callback\Event as ServerEvent;
use ManiaLive\DedicatedApi\Connection;
use ManiaLive\DedicatedApi\Structures\Player;
use ManiaLive\Data\Storage;
use ManiaLive\Gui\Controls\Frame;
use ManiaLive\Gui\Windows\Info;
use ManiaLive\Gui\Windows\Shortkey;
use ManiaLive\Gui\Windows\Thumbnail;

/**
 * Description of GuiHandler
 */
final class GuiHandler extends \ManiaLib\Utils\Singleton implements AppListener, ServerListener
{
	const MAX_THUMBNAILS = 5;
	const NEXT_IS_MODAL = 0xFA15EADD;
	
	private $hidingGui = array();
	private $modals = array();
	private $modalsRecipients = array();
	private $modalShown = array();
	private $managedWindow = array();
	private $thumbnails = array();
	
	private $currentWindows = array();
	private $nextWindows = array();
	private $modalBg;
	
	private $nextLoop;
	
	// Profiling
	private $sendingTimes = array();
	private $averageSendingTimes;
	
	protected function __construct()
	{
		$this->modalBg = new Bgs1(340, 200);
		$this->modalBg->setSubStyle(Bgs1::BgDialogBlur);
		$this->modalBg->setAlign('center', 'center');
		$this->modalBg->setPosZ(Window::Z_MODAL);
		$this->modalBg->setScriptEvents();
		$this->nextLoop = microtime(true);
		Dispatcher::register(AppEvent::getClass(), $this, AppEvent::ON_RUN | AppEvent::ON_PRE_LOOP);
		Dispatcher::register(ServerEvent::getClass(), $this, ServerEvent::ON_PLAYER_CONNECT | ServerEvent::ON_PLAYER_DISCONNECT);
	}
	
	function getAverageSendingTimes()
	{
		return $this->averageSendingTimes;
	}
	
	function toggleGui($login)
	{
		$this->hidingGui[$login] = !$this->hidingGui[$login];
		if($this->hidingGui[$login])
		{
			$connection = Connection::getInstance();
			$connection->chatSendServerMessage('ManiaLive interface has been deactivated, press F8 to enable...', $login, true);
			$connection->sendHideManialinkPage($login, true);
			Manialinks::load();
			$this->drawWindow(Shortkey::Create($login));
			CustomUI::Create($login)->saveToDefault();
			$connection->sendDisplayManialinkPage($login, Manialinks::getXml(), 0, false, true);
			$connection->executeMulticall();
		}
		else
		{
			Manialinks::load();
			foreach($this->currentWindows as $visibilityByLogin)
				if(isset($visibilityByLogin[$login]))
					$this->drawWindow($visibilityByLogin[$login]);
			if($this->modalShown[$login])
				$this->drawModal($this->modalShown[$login]);
			$this->drawWindow(Shortkey::Create($login));
			CustomUI::Create($login)->save();
			$connection = Connection::getInstance();
			$connection->sendDisplayManialinkPage($login, Manialinks::getXml(), 0, false);
		}
	}
	
	function addtoShow(Window $window, $recipients)
	{
		if($window instanceof ManagedWindow)
		{
			if($this->managedWindow[$recipients] && $this->managedWindow[$recipients] !== $window && !$this->sendToTaskbar($recipients))
				return false;
			$this->managedWindow[$recipients] = $window;
			if(($thumbnail = $this->getThumbnail($window)))
				$thumbnail->hide();
		}
		
		$windowId = $window->getId();
		if(!is_array($recipients) && !($recipients instanceof Group))
			$recipients = array($recipients);
		
		foreach($recipients as $login)
		{
			if(Storage::getInstance()->getPlayerObject($login))
			{
				if(isset($this->nextWindows[$windowId]))
					$this->nextWindows[$windowId][$login] = $window;
				else
					$this->nextWindows[$windowId] = array($login => $window);
			}
		}
		
		return true;
	}
	
	function addToHide(Window $window, $recipients)
	{
		if($window instanceof ManagedWindow && $this->managedWindow[$recipients] === $window)
			$this->managedWindow[$recipients] = null;
		
		$windowId = $window->getId();
		if(!is_array($recipients) && !($recipients instanceof Group))
			$recipients = array($recipients);
		
		if(isset($this->currentWindows[$windowId]))
		{
			foreach($recipients as $login)
			{
				if(isset($this->currentWindows[$windowId][$login]))
				{
					if(isset($this->nextWindows[$windowId]))
						$this->nextWindows[$windowId][$login] = false;
					else
						$this->nextWindows[$windowId] = array($login => false);
				}
				else
				{
					unset($this->nextWindows[$windowId][$login]);
					if(!$this->nextWindows[$windowId])
						unset($this->nextWindows[$windowId]);
				}
			}
		}
		else if(isset($this->modalsRecipients[$windowId]))
		{
			foreach($recipients as $login)
			{
				if(isset($this->modalShown[$login]) && $this->modalShown[$login] === $window)
				{
					if(isset($this->nextWindows[$windowId]))
						$this->nextWindows[$windowId][$login] = false;
					else
						$this->nextWindows[$windowId] = array($login => false);
				}
			}
		}
		else
			unset($this->nextWindows[$windowId]);
		
		return true;
	}
	
	function addToRedraw(Window $window, $recipients = null)
	{
		$windowId = $window->getId();
		
		if($window instanceof ManagedWindow && ($thumbnail = $this->getThumbnail($window)))
			$thumbnail->enableHighlight();
		else if(isset($this->currentWindows[$windowId]))
		{
			if(!is_array($recipients) && !($recipients instanceof Group))
				$recipients = array($recipients);
			
			foreach($recipients as $login)
				if(isset($this->currentWindows[$windowId][$login]))
				{
					if(isset($this->nextWindows[$windowId]))
					{
						if(!isset($this->nextWindows[$windowId][$login]))
							$this->nextWindows[$windowId][$login] = $window;
					}
					else
						$this->nextWindows[$windowId] = array($login => $window);
				}
		}
		
		return true;
	}
	
	function sendToTaskbar($login)
	{
		$window = $this->managedWindow[$login];
		// seeking an empty place in the player taskbar
		$taskbarIndex = 0;
		$freePlaceFound = false;
		foreach($this->thumbnails[$login] as $taskbarIndex => $placedThumbnail)
			if(!$placedThumbnail)
			{
				$freePlaceFound = true;
				break;
			}
		if(!$freePlaceFound)
		{
			if($taskbarIndex == self::MAX_THUMBNAILS - 1)
			{
				$info = Info::Create($login, false);
				$info->setSize(40, 25);
				$info->setTitle('Too many Windows!');
				$info->setText("You are in the process of minimizing another window ...\n".
					"Due to restricted resources you have reached the limit of allowed concurrent displayable minimized windows.\n".
					"Please close some old windows in order to be able to open and minimize new ones.");
				$this->addModal($info);
				return false;
			}
			else
				$taskbarIndex = count($this->thumbnails[$login]);
		}
		
		// create the thumbnail
		$thumbnail = Thumbnail::Create($login, false, $window);
		$this->thumbnails[$login][$taskbarIndex] = $thumbnail;
		$thumbnail->setSize(30, 26);
		$thumbnail->setPosition(80 - 31 * $taskbarIndex, 85);
		$thumbnail->addCloseCallback(array($this, 'onThumbnailClosed'));
		$thumbnail->show();
		$window->hide();
		$this->managedWindow[$login] = null;
		
		return true;
	}
	
	function onThumbnailClosed($login, Thumbnail $thumbnail)
	{
		$taskbarIndex = array_search($thumbnail, $this->thumbnails[$login], true);
		if($taskbarIndex !== false)
			$this->thumbnails[$login][$taskbarIndex] = false;
		$thumbnail->destroy();
	}
	
	private function getNextModal($login)
	{
		if($this->modalShown[$login])
			return null;
		return array_shift($this->modals[$login]);
	}
	
	function addModal(Window $modal, $recipients)
	{
		if(!is_array($recipients) && !($recipients instanceof Group))
			$recipients = array($recipients);
		
		foreach($recipients as $login)
		{
			if(isset($this->modals[$login]) && !isset($this->modalsRecipients[$modal->getId()][$login]))
			{
				$this->modals[$login][] = $modal;
				$this->modalsRecipients[$modal->getId()][$login] = true;
			}
		}
		
		$modal->addCloseCallback(array($this, 'onModalClosed'));
	}
	
	function onModalClosed($login, Window $dialog)
	{
		$dialogId = $dialog->getId();
		unset($this->modalsRecipients[$dialogId][$login]);
		if(empty($this->modalsRecipients[$dialogId]))
		{
			$dialog->destroy();
			unset($this->modalsRecipients[$dialogId]);
		}
		$this->modalShown[$login] = null;
	}
	
	function getThumbnail(ManagedWindow $window)
	{
		$login = $window->getRecipient();
		if(isset($this->thumbnails[$login]))
			foreach($this->thumbnails[$login] as $thumbnail)
				if($thumbnail && $thumbnail->getWindow() === $window)
					return $thumbnail;
		return null;
	}
	
	// Application Listener
	
	function onRun()
	{
		foreach(Storage::getInstance()->players as $login => $player)
			$this->onPlayerConnect($login, false);

		foreach(Storage::getInstance()->spectators as $login => $spectator)
			$this->onPlayerConnect($login, true);
	}
	
	function onInit() {}
	
	function onPreLoop()
	{
		// Before loops (stopping if too soon)
		$startTime = microtime(true);
		if($startTime < $this->nextLoop)
			return;
		
		$connection = Connection::getInstance();
		$stackByPlayer = array();
		$playersHidingGui = array_keys(array_filter($this->hidingGui));
		$playersShowingGui = array_diff(array_keys($this->hidingGui), $playersHidingGui);
		// First loop to prepare player stacks
		foreach($this->nextWindows as $windowId => $visibilityByLogin)
		{
			$showing = array_diff(array_keys(array_filter($visibilityByLogin)), $playersHidingGui);
			$hiding = array_diff(array_keys($visibilityByLogin), $showing, $playersHidingGui);
			if(count($showing))
			{
				sort($showing);
				$stackByPlayer[implode(',', $showing)][] = $visibilityByLogin[reset($showing)];
			}
			if(count($hiding))
			{
				sort($hiding);
				$stackByPlayer[implode(',', $hiding)][] = $windowId;
			}
		}
		// Second loop to add dialogs and regroup identical custom UIs
		$loginsByDiff = array();
		$customUIsByDiff = array();
		foreach($playersShowingGui as $login)
		{
			$dialog = $this->getNextModal($login);
			if($dialog)
			{
				$stackByPlayer[$login][] = self::NEXT_IS_MODAL;
				$stackByPlayer[$login][] = $dialog;
				$this->modalShown[$login] = $dialog;
			}
			
			$customUI = CustomUI::Create($login);
			$diff = $customUI->getDiff();
			if($diff)
			{
				$loginsByDiff[$diff][] = $login;
				$customUIsByDiff[$diff][] = $customUI;
			}
		}
		// Third loop to add custom UIs
		foreach($loginsByDiff as $diff => $logins)
			$stackByPlayer[implode(',', $logins)][] = $customUIsByDiff[$diff];
		
		// Final loop to send manialinks
		$nextIsDialog = false;
		foreach($stackByPlayer as $login => $data)
		{
			Manialinks::load();
			foreach($data as $toDraw)
			{
				if($nextIsDialog) // this element can't be anything else than a window
				{
					$this->drawModal($toDraw);
					$nextIsDialog = false;
				}
				else if($toDraw === self::NEXT_IS_MODAL) // special delimiter for dialogs
					$nextIsDialog = true;
				else if(is_string($toDraw)) // a window's id alone means it has to be hidden
					$this->drawHidden($toDraw);
				else if(is_array($toDraw)) // custom ui's special case
				{
					array_shift($toDraw)->save();
					foreach($toDraw as $customUI)
						$customUI->hasBeenSaved();
				}
				else // else it can only be a window to show
					$this->drawWindow($toDraw);
			}
			$connection->sendDisplayManialinkPage($login, Manialinks::getXml(), 0, false, true);
		}
		$connection->executeMulticall();
		
		// Merging windows and deleting hidden ones to keep clean the current state
		foreach($this->nextWindows as $windowId => $visibilityByLogin)
		{
			if(isset($this->currentWindows[$windowId]))
				$newCurrent = array_filter(array_merge($this->currentWindows[$windowId], $visibilityByLogin));
			else
				$newCurrent = array_filter($visibilityByLogin);
			
			if($newCurrent)
				$this->currentWindows[$windowId] = $newCurrent;
			else
				unset($this->currentWindows[$windowId]);
		}
		$this->nextWindows = array();
		
		// After loops
		$endTime = microtime(true);
		$this->nextLoop += 0.2;
		// Profiling
		$this->sendingTimes[] = $endTime - $startTime;
		if (count($this->sendingTimes) >= 10)
		{
			$this->averageSendingTimes = array_sum($this->sendingTimes) / count($this->sendingTimes);
			$this->sendingTimes = array();
		}
	}
	
	final private function drawWindow(Window $window)
	{
		if($window instanceof ManagedWindow && $window->isMaximized())
			$window->setPosZ(Window::Z_MAXIMIZED);
		else
			$window->setPosZ($window->getMinZ());
		
		Manialinks::beginManialink($window->getId());
		$window->save();
		Manialinks::endManialink();
	}
	
	final private function drawModal(Window $window)
	{
		$window->setPosZ(Window::Z_MODAL + Window::Z_OFFSET);
		
		Manialinks::beginManialink($window->getId());
		$this->modalBg->save();
		$window->save();
		Manialinks::endManialink();
	}
	
	final private function drawHidden($windowId)
	{
		Manialinks::beginManialink($windowId);
		Manialinks::endManialink();
	}
	
	function onPostLoop() {}
	function onTerminate() {}
	
	// Dedicated Listener
	
	function onPlayerConnect($login, $IsSpectator)
	{
		$this->hidingGui[$login] = false;
		$this->modals[$login] = array();
		$this->modalShown[$login] = null;
		$this->managedWindow[$login] = null;
		$this->thumbnails[$login] = array();
		
		$sk = Shortkey::Create($login);
		$sk->addCallback(Shortkey::F8, array($this, 'toggleGui'));
		$sk->show();
	}
	
	function onPlayerDisconnect($login)
	{
		Window::Erase($login);
		CustomUI::Erase($login);
		
		foreach($this->modals[$login] as $dialog)
			$this->onModalClosed($login, $dialog);
		if($this->modalShown[$login])
			$this->onModalClosed($login, $this->modalShown[$login]);
		
		unset($this->hidingGui[$login]);
		unset($this->modals[$login]);
		unset($this->modalShown[$login]);
		unset($this->managedWindow[$login]);
		unset($this->thumbnails[$login]);
	}
	
	function onBeginMap($map, $warmUp, $matchContinuation) {}
	function onBeginMatch($map) {}
	function onBeginRound() {}
	function onBillUpdated($billId, $state, $stateName, $transactionId) {}
	function onMapListModified($curMapIndex, $nextMapIndex, $isListModified) {}
	function onEcho($internal, $public) {}
	function onEndMap($rankings, $map, $wasWarmUp, $matchContinuesOnNextMap, $restartMap) {}
	function onEndMatch($rankings, $map) {}
	function onEndRound() {}
	function onManualFlowControlTransition($transition) {}
	function onPlayerChat($playerUid, $login, $text, $isRegistredCmd) {}
	function onPlayerCheckpoint($playerUid, $login, $timeOrScore, $curLap, $checkpointIndex) {}
	function onPlayerFinish($playerUid, $login, $timeOrScore) {}
	function onPlayerIncoherence($playerUid, $login) {}
	function onPlayerInfoChanged($playerInfo) {}
	function onPlayerManialinkPageAnswer($playerUid, $login, $answer, array $entries) {}
	function onServerStart() {}
	function onServerStop() {}
	function onStatusChanged($statusCode, $statusName) {}
	function onTunnelDataReceived($playerUid, $login, $data) {}
	function onVoteUpdated($stateName, $login, $cmdName, $cmdParam) {}
	function onRulesScriptCallback($param1, $param2) {}
}

?>
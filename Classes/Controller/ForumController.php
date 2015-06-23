<?php
namespace Mittwald\Typo3Forum\Controller;

/*                                                                      *
 *  COPYRIGHT NOTICE                                                    *
 *                                                                      *
 *  (c) 2015 Mittwald CM Service GmbH & Co KG                           *
 *           All rights reserved                                        *
 *                                                                      *
 *  This script is part of the TYPO3 project. The TYPO3 project is      *
 *  free software; you can redistribute it and/or modify                *
 *  it under the terms of the GNU General Public License as published   *
 *  by the Free Software Foundation; either version 2 of the License,   *
 *  or (at your option) any later version.                              *
 *                                                                      *
 *  The GNU General Public License can be found at                      *
 *  http://www.gnu.org/copyleft/gpl.html.                               *
 *                                                                      *
 *  This script is distributed in the hope that it will be useful,      *
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of      *
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the       *
 *  GNU General Public License for more details.                        *
 *                                                                      *
 *  This copyright notice MUST APPEAR in all copies of the script!      *
 *                                                                      */

use Mittwald\Typo3Forum\Domain\Exception\Authentication\NoAccessException;
use Mittwald\Typo3Forum\Domain\Exception\Authentication\NotLoggedInException;
use Mittwald\Typo3Forum\Domain\Model\Forum\Forum;

class ForumController extends AbstractController {

	/**
	 * @var \TYPO3\CMS\Core\Database\DatabaseConnection
	 */
	protected $databaseConnection;

	/**
	 * @var \Mittwald\Typo3Forum\Domain\Repository\Forum\ForumRepository
	 * @inject
	 */
	protected $forumRepository;

	/**
	 * @var \Mittwald\Typo3Forum\Domain\Repository\Forum\TopicRepository
	 * @inject
	 */
	protected $topicRepository;

	/**
	 * @var \Mittwald\Typo3Forum\Domain\Repository\Forum\AdsRepository
	 * @inject
	 */
	protected $adsRepository;

	/**
	 * @var \Mittwald\Typo3Forum\Domain\Model\Forum\RootForum
	 * @inject
	 */
	protected $rootForum;

	/**
	 *
	 */
	public function initializeAction() {
		$this->databaseConnection = $GLOBALS['TYPO3_DB'];
	}

	/**
	 * Index action. Displays the first two levels of the forum tree.
	 * @return void
	 */
	public function indexAction() {
		$this->authenticationService->assertReadAuthorization($this->rootForum);
		$forums = $this->forumRepository->findForIndex();
		$this->view->assign('forums', $forums);
	}

	/**
	 * Show action. Displays a single forum, all subforums of this forum and the
	 * topics contained in this forum.
	 *
	 * @param Forum $forum The forum that is to be displayed.
	 * @return void
	 */
	public function showAction(Forum $forum) {
		$topics = $this->topicRepository->findForIndex($forum);
		$this->authenticationService->assertReadAuthorization($forum);
		$this->view->assignMultiple([
			'forum' => $forum,
			'topics' => $topics
		]);
	}

	/**
	 * Updates a forum.
	 * This action method updates a forum. Admin authorization is required.
	 *
	 * @param Forum $forum The forum to be updated.
	 * @dontverifyrequesthash
	 */
	public function updateAction(Forum $forum) {
		$this->authenticationService->assertAdministrationAuthorization($forum);

		$this->forumRepository->update($forum);

		$this->clearCacheForCurrentPage();
		$this->addLocalizedFlashmessage('Forum_Update_Success');
		$this->redirect('index');
	}

	/**
	 * Creates a forum.
	 * This action method creates a new forum. Admin authorization is required for
	 * creating child forums, root forums may only be created from backend.
	 *
	 * @param Forum $forum The forum to be created.
	 *
	 * @throws NoAccessException
	 * @dontverifyrequesthash
	 */
	public function createAction(Forum $forum) {
		if ($forum->getParent() !== NULL) {
			$this->authenticationService->assertAdministrationAuthorization($forum->getParent());
		} /** @noinspection PhpUndefinedConstantInspection */ elseif (TYPO3_MODE !== 'BE') {
			throw new NoAccessException('This operation is allowed only from the TYPO3 backend.');
		}

		$this->forumRepository->add($forum);

		$this->clearCacheForCurrentPage();
		$this->addLocalizedFlashmessage('Forum_Create_Success');
		$this->redirect('index');
	}

	/**
	 * Mark a whole forum as read
	 * @param Forum $forum
	 *
	 * @throws NotLoggedInException
	 * @return void
	 */
	public function markReadAction(Forum $forum) {
		$user = $this->getCurrentUser();
		if ($user->isAnonymous()) {
			throw new NotLoggedInException("You need to be logged in.", 1288084981);
		}
		$forumStorage = array();
		$forumStorage[] = $forum;
		foreach ($forum->getChildren() AS $children) {
			$forumStorage[] = $children;
		}

		foreach ($forumStorage AS $checkForum) {
			/** @var Forum $checkForum */
			if (intval($this->settings['useSqlStatementsOnCriticalFunctions']) == 0) {
				foreach ($checkForum->getTopics() AS $topic) {
					$topic->addReader($user);
				}
			} else {
				$topics = $this->topicRepository->getUnreadTopics($checkForum, $user);

				foreach ($topics AS $topic) {
					$values = array('uid_foreign' => intval($topic['uid']),
						'uid_local' => intval($user->getUid()));
					$this->databaseConnection->exec_INSERTquery('tx_typo3forum_domain_model_user_readtopic', $values);
				}
			}

			$checkForum->addReader($user);
			$this->forumRepository->update($checkForum);
		}

		$this->redirect('show', 'Forum', NULL, array('forum' => $forum));
	}

	/**
	 * Show all unread topics of the current user
	 * @param Forum $forum
	 *
	 * @throws NotLoggedInException
	 * @return void
	 */
	public function showUnreadAction(Forum $forum) {
		$user = $this->getCurrentUser();
		if ($user->isAnonymous()) {
			throw new NotLoggedInException("You need to be logged in.", 1288084981);
		}
		$topics = array();
		$unreadTopics = array();

		$tmpTopics = $this->topicRepository->getUnreadTopics($forum, $user);
		foreach ($tmpTopics AS $tmpTopic) {
			$unreadTopics[] = $tmpTopic['uid'];
		}
		if (!empty($unreadTopics)) {
			$topics = $this->topicRepository->findByUids($unreadTopics);
		}

		$this->view->assign('forum', $forum)->assign('topics', $topics);
	}

}

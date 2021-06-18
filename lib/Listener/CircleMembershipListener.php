<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020 Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Talk\Listener;

use OCA\Circles\Events\AddingCircleMemberEvent;
use OCA\Circles\Events\RemovingCircleMemberEvent;
use OCA\Circles\Model\Circle;
use OCA\Circles\Model\Member;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use OCA\Talk\Service\ParticipantService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;

class CircleMembershipListener implements IEventListener {

	/** @var ISession */
	private $session;
	/** @var IUserManager */
	private $userManager;
	/** @var IGroupManager */
	private $groupManager;
	/** @var Manager */
	private $manager;
	/** @var ParticipantService */
	private $participantService;

	public function __construct(ISession $session,
								IUserManager $userManager,
								IGroupManager $groupManager,
								Manager $manager,
								ParticipantService $participantService) {
		$this->session = $session;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->manager = $manager;
		$this->participantService = $participantService;
	}

	public function handle(Event $event): void {
		if ($event instanceof AddingCircleMemberEvent) {
			$this->addingCircleMemberEvent($event);
		}

		if ($event instanceof RemovingCircleMemberEvent) {
			$this->removeFormerMemberFromRooms($event->getCircle(), $event->getMember());
		}
	}

	protected function addingCircleMemberEvent(AddingCircleMemberEvent $event): void {
		$roomsForTargetCircle = $this->manager->getRoomsForActor(Attendee::ACTOR_CIRCLES, $event->getCircle()->getSingleId());
		$roomsToAdd = [];
		foreach ($roomsForTargetCircle as $room) {
			$roomsToAdd[$room->getId()] = $room;
		}

		// Check nested circles
		$memberships = $event->getCircle()->getMemberships();
		foreach ($memberships as $membership) {
			$parentId = $membership->getCircleId();
			$parentRooms = $this->manager->getRoomsForActor(Attendee::ACTOR_CIRCLES, $parentId);
			foreach ($parentRooms as $room) {
				if (isset($roomsToAdd[$room->getId()])) {
					continue;
				}
				$roomsToAdd[$room->getId()] = $room;
			}
		}


		if (empty($roomsToAdd)) {
			// The circle is not in any room => bye!
			return;
		}

		// This member is a "membership" in circles which links to entities such as users, groups or circles
		$newMember = $event->getMember();
		// Get the base circle of the membership
		$basedOnCircle = $newMember->getBasedOn();
		// Get all (nested) memberships in the added $newMember as a flat list
		$userMembers = $basedOnCircle->getInheritedMembers();

		$invitedBy = $newMember->getInvitedBy();
		if ($invitedBy->getUserType() === Member::TYPE_USER && $invitedBy->getUserId() !== '') {
			$this->session->set('talk-overwrite-actor', $invitedBy->getUserId());
		} else if ($invitedBy->getUserType() === Member::TYPE_APP && $invitedBy->getUserId() === 'occ') {
			$this->session->set('talk-overwrite-actor-cli', 'cli');
		}

		foreach ($userMembers as $userMember) {
			$this->addNewMemberToRooms(array_values($roomsToAdd), $userMember);
		}
		$this->session->remove('talk-overwrite-actor');
		$this->session->remove('talk-overwrite-actor-cli');
	}

	protected function addNewMemberToRooms(array $rooms, Member $member): void {
		if ($member->getUserType() !== Member::TYPE_USER || $member->getUserId() === '') {
			// Not a user?
			return;
		}

		$user = $this->userManager->get($member->getUserId());
		if (!$user instanceof IUser) {
			return;
		}

		foreach ($rooms as $room) {
			try {
				$participant = $room->getParticipant($member->getUserId());
				if ($participant->getAttendee()->getParticipantType() === Participant::USER_SELF_JOINED) {
					$this->participantService->updateParticipantType($room, $participant, Participant::USER);
				}
			} catch (ParticipantNotFoundException $e) {
				$this->participantService->addUsers($room, [[
					'actorType' => Attendee::ACTOR_USERS,
					'actorId' => $member->getUserId(),
					'displayName' => $user->getDisplayName(),
				]]);
			}
		}
	}

	protected function removeFormerMemberFromRooms(Circle $circle, Member $member): void {
		if ($member->getUserType() !== Member::TYPE_USER || $member->getUserId() === '') {
			// Not a user?
			return;
		}

		$rooms = $this->manager->getRoomsForActor(Attendee::ACTOR_CIRCLES, $circle->getSingleId());
		if (empty($rooms)) {
			return;
		}

		// FIXME we now need to check user groups and circles?
	}
}

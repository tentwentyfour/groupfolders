<?php
/**
 * @copyright Copyright (c) 2017 Robin Appelman <robin@icewind.nl>
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

namespace OCA\GroupFolders\Controller;

use OCA\GroupFolders\ACL\Rule;
use OCA\GroupFolders\ACL\RuleManager;
use OCA\GroupFolders\ACL\UserMapping\UserMapping;

use OCA\GroupFolders\Folder\FolderManager;
use OCA\GroupFolders\Mount\MountProvider;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\IRootFolder;
use OCP\IRequest;

class FolderController extends OCSController {

	// /** @var RuleManager */
	private $ruleManager;
	/** @var FolderManager */
	private $manager;
	/** @var MountProvider */
	private $mountProvider;
	/** @var IRootFolder */
	private $rootFolder;
	/** @var string */
	private $userId;

	public function __construct(
		$AppName,
		IRequest $request,
		FolderManager $manager,
		RuleManager $ruleManager,
		MountProvider $mountProvider,
		IRootFolder $rootFolder,
		$userId
	) {
		parent::__construct($AppName, $request);
		$this->manager = $manager;
		$this->ruleManager = $ruleManager;
		$this->mountProvider = $mountProvider;
		$this->rootFolder = $rootFolder;
		$this->userId = $userId;

		$this->registerResponder('xml', function ($data) {
			return $this->buildOCSResponseXML('xml', $data);
		});
	}

	public function getFolders() {
		return new DataResponse($this->manager->getAllFoldersWithSize($this->getRootFolderStorageId()));
	}

	/**
	 * @param int $id
	 * @return DataResponse
	 */
	public function getFolder($id) {
		return new DataResponse($this->manager->getFolder((int)$id, $this->getRootFolderStorageId()));
	}

	private function getRootFolderStorageId() {
		return $this->rootFolder->getMountPoint()->getNumericStorageId();
	}

	/**
	 * @param string $mountpoint
	 * @return DataResponse
	 */
	public function addFolder($mountpoint) {
		$id = $this->manager->createFolder($mountpoint);
		return new DataResponse(['id' => $id]);
	}

	/**
	 * @param int $id
	 * @return DataResponse
	 */
	public function removeFolder($id) {
		$folder = $this->mountProvider->getFolder($id);
		if ($folder) {
			$folder->delete();
		}
		$this->manager->removeFolder($id);
		return new DataResponse(['success' => true]);
	}

	/**
	 * @param int $id
	 * @param string $mountPoint
	 * @return DataResponse
	 */
	public function setMountPoint($id, $mountPoint) {
		$this->manager->setMountPoint($id, $mountPoint);
		return new DataResponse(['success' => true]);
	}

	/**
	 * @param int $id
	 * @param string $group
	 * @return DataResponse
	 */
	public function addGroup($id, $group) {
		$this->manager->addApplicableGroup($id, $group);
		return new DataResponse(['success' => true]);
	}

	/**
	 * @param int $id
	 * @param string $group
	 * @return DataResponse
	 */
	public function removeGroup($id, $group) {
		$this->manager->removeApplicableGroup($id, $group);
		return new DataResponse(['success' => true]);
	}

	/**
	 * @param int $id
	 * @param string $group
	 * @param string $permissions
	 * @return DataResponse
	 */
	public function setPermissions($id, $group, $permissions) {
		$this->manager->setGroupPermissions($id, $group, $permissions);
		return new DataResponse(['success' => true]);
	}

	/**
	 * @param int $id
	 * @param string $mappingType
	 * @param string $mappingId
	 * @param bool $manageAcl
	 * @return DataResponse
	 */
	public function setManageACL($id, $mappingType, $mappingId, $manageAcl) {
		$this->manager->setManageACL($id, $mappingType, $mappingId, $manageAcl);
		return new DataResponse(['success' => true]);
	}

	/**
	 * @NoAdminRequired
	 * @param int $id
	 * @param string $mappingType
	 * @param string $mappingId
	 * @param string $path
	 * @param string $permission
	 * @return DataResponse
	 */
	public function setACLPermissions(
		int $id,
		string $mappingType,
		string $mappingId,
		string $path,
		array $permissions
	) {
		if (!$this->manager->canManageACL($id, $this->userId) === true) {
			return new DataResponse([
				'success' => false,
				'error' => sprintf(
					'User "%d" is not allowed to manage folder "%d"',
					$this->userId,
					$id
				),
				'code' => 404,
			]);
		}

		$folder = $this->manager->getFolder($id, $this->getRootFolderStorageId());
		if (!$folder) {
			return new DataResponse([
				'success' => false,
				'error' => sprintf(
					'Folder not found: %d',
					$id
				),
				'code' => 404,
			]);
		}

		if (!$folder['acl']) {
			return new DataResponse([
				'success' => false,
				'error' => sprintf(
					'Advanced permissions not enabled for folder: %d',
					$id
				),
			]);
		}

		$path = trim($path, '/');

		// Note: getFolder returns neither permissions nor rootCacheEntry
		// for folder entries!
		$mount = $this->mountProvider->getMount(
			$folder['id'],
			$folder['mount_point'],
			$folder['permissions'] ?? null,		// getFolder does not return this either
			$folder['quota'],
			$folder['rootCacheEntry'] ?? null,	// getFolder does not return this
			null,
			$folder['acl']
			// getMount takes an IUser, but it's unclear whether this user is the acting
			// user or the user for to whom we would like to give permissions
			// I tend towards the latter.
			// In which case we'll need to inject the IUserManager,
			// see https://nextcloud-server.netlify.app/classes/ocp-iusermanager#method_get
		);

		$id = $mount->getStorage()->getCache()->getId($path);
		if ($id === -1) {
			return new DataResponse([
				'success' => false,
				'error' => 'Path not found in folder: ' . $path,
			]);
		}

		$mappingType = $mappingType === 'user' ? 'user' : 'group';
		if ($permissions === ['clear']) {
			$this->ruleManager->deleteRule(new Rule(
				new UserMapping($mappingType, $mappingId),
				$id,
				0,
				0
			));
		} else {
			foreach ($permissions as $permission) {
				if ($permission[0] !== '+' && $permission[0] !== '-') {
					return new DataResponse([
						'success' => false,
						'error' => sprintf(
							'Incorrect format for permissions "%s"',
							$permission
						),
					]);
				}
				$name = substr($permission, 1);
				if (!isset(Rule::PERMISSIONS_MAP[$name])) {
					return new DataResponse([
						'success' => false,
						'error' => sprintf(
							'Unknown/invalid permission "%s"',
							$permission
						),
					]);
				}
			}

			[$mask, $parsedPermissions] = Rule::parsePermissions($permissions);

			$this->ruleManager->saveRule(new Rule(
				new UserMapping($mappingType, $mappingId),
				$id,
				$mask,
				$parsedPermissions
			));
		}

		return new DataResponse([
			'success' => true,
			'message' => sprintf(
				'ACL applied successfully to folder %d',
				$id
			),
		]);
	}

	/**
	 * @param int $id
	 * @param float $quota
	 * @return DataResponse
	 */
	public function setQuota($id, $quota) {
		$this->manager->setFolderQuota($id, $quota);
		return new DataResponse(['success' => true]);
	}

	/**
	 * @param int $id
	 * @param bool $acl
	 * @return DataResponse
	 */
	public function setACL($id, $acl) {
		$this->manager->setFolderACL($id, $acl);
		return new DataResponse(['success' => true]);
	}

	/**
	 * @param int $id
	 * @param string $mountpoint
	 * @return DataResponse
	 */
	public function renameFolder($id, $mountpoint) {
		$this->manager->renameFolder($id, $mountpoint);
		return new DataResponse(['success' => true]);
	}

	/**
	 * Overwrite response builder to customize xml handling to deal with spaces in folder names
	 *
	 * @param string $format json or xml
	 * @param DataResponse $data the data which should be transformed
	 * @since 8.1.0
	 * @return \OC\AppFramework\OCS\BaseResponse
	 */
	private function buildOCSResponseXML($format, DataResponse $data) {
		/** @var array $folderData */
		$folderData = $data->getData();
		if (isset($folderData['id'])) {
			// single folder response
			$folderData = $this->folderDataForXML($folderData);
		} elseif (is_array($folderData) && count($folderData) && isset(current($folderData)['id'])) {
			// folder list
			$folderData = array_map([$this, 'folderDataForXML'], $folderData);
		}
		$data->setData($folderData);
		return new \OC\AppFramework\OCS\V1Response($data, $format);
	}

	private function folderDataForXML($data) {
		$groups = $data['groups'];
		$data['groups'] = [];
		foreach ($groups as $id => $permissions) {
			$data['groups'][] = ['@group_id' => $id, '@permissions' => $permissions];
		}
		return $data;
	}

	/**
	 * @NoAdminRequired
	 * @param $id
	 * @param $fileId
	 * @param string $search
	 * @return DataResponse
	 */
	public function aclMappingSearch($id, $fileId, $search = ''): DataResponse {
		$users = [];
		$groups = [];

		if ($this->manager->canManageACL($id, $this->userId) === true) {
			$groups = $this->manager->searchGroups($id, $search);
			$users = $this->manager->searchUsers($id, $search);
		}
		return new DataResponse([
			'users' => $users,
			'groups' => $groups,
		]);
	}
}

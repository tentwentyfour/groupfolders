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

use OCA\GroupFolders\Folder\FolderManager;
use OCA\GroupFolders\Mount\MountProvider;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\IRootFolder;
use OCP\IRequest;

class FolderController extends OCSController {
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
		MountProvider $mountProvider,
		IRootFolder $rootFolder,
		$userId
	) {
		parent::__construct($AppName, $request);
		$this->manager = $manager;
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
		error_log('FolderController->addFolder');

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
	 * @param int $id
	 * @param string $mappingType
	 * @param string $mappingId
	 * @param string $path
	 * @param string $permission
	 * @return DataResponse
	 */
	public function setACLPermissions($id, $mappingType, $mappingId, $path, $permission) {
	// public function setACLPermissions($folderId, $type, $mappingId, $path, $permissions) {
		error_log('BRUHsetACLPermissions');
		// $this->manager->setACLPermissions($id, $mappingType, $mappingId, $path, $permission);
		error_log($id);
		error_log($mappingType);
		error_log($mappingId);
		error_log($path);
		error_log($permission);

		// $folder = $this->getFolder($id, $this->rootFolder->getMountPoint()->getNumericStorageId());
		$folder = $this->manager->getFolder((int)$id, $this->getRootFolderStorageId());

		error_log('BRUH');
		$permissions = $permission;

		error_log(print_r($folder, TRUE));
		$mappingType = $mappingType === 'user' ? 'user' : 'group';

		if ($folder) {
			if (!$folder['acl']) {
				// $output->writeln('<error>Advanced permissions not enabled for folder: ' . $id . '</error>');
				error_log('<error>Advanced permissions not enabled for folder: ' . $id . '</error>');
				// return -2;
				return new DataResponse(false);
			} else if (!$path) {
				error_log('<error><path> argument has to be set when not using --enable or --disable</error>');
				// return -3;
				return new DataResponse(false);
			} else if (!$permissions) {
				error_log('<error><permissions> argument has to be set when not using --enable or --disable</error>');
				// return -3;
				return new DataResponse(false);
			} else {
				$path = trim($path, '/');
				error_log($path);
				$permissionStrings = $permissions;

				error_log(print_r($permissionStrings, TRUE));
				error_log(print_r($folder['permissions'], TRUE));

				$mount = $this->mountProvider->getMount(
					$folder['id'],
					$folder['mount_point'],
					$folder['permissions'],
					$folder['quota'],
					$folder['rootCacheEntry'],
					null,
					$folder['acl']
				);

				error_log(isset($mount));
				$id = $mount->getStorage()->getCache()->getId($path);
				error_log($id);

				if ($id === -1) {
					$output->writeln('<error>Path not found in folder: ' . $path . '</error>');
					// return -1;
					return new DataResponse(false);
				}

				if ($permissionStrings === ['clear']) {
					$this->ruleManager->deleteRule(new Rule(
						new UserMapping($mappingType, $mappingId),
						$id,
						0,
						0
					));
				} else {
					error_log('here');

					foreach ($permissionStrings as $permission) {
						if ($permission[0] !== '+' && $permission[0] !== '-') {
							$output->writeln('<error>incorrect format for permissions "' . $permission . '"</error>');
							// return -3;
							return new DataResponse(false);
						}
						$name = substr($permission, 1);
						if (!isset(self::PERMISSIONS_MAP[$name])) {
							$output->writeln('<error>incorrect format for permissions2 "' . $permission . '"</error>');
							// return -3;
							return new DataResponse(false);
						}
					}
					error_log('still here');

					[$mask, $permissions] = $this->parsePermissions($permissionStrings);

					$this->ruleManager->saveRule(new Rule(
						new UserMapping($mappingType, $mappingId),
						$id,
						$mask,
						$permissions
					));
					error_log('but not here');
				}
			}
		} else {
			$output->writeln('<error>Folder not found: ' . $id . '</error>');
			return -1;
			return new DataResponse(false);
		}
		// return 0;

		return new DataResponse(true);
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

<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OC\Files\Cache;

use Doctrine\DBAL\Exception;
use OC\Files\Storage\Wrapper\Encryption;
use OC\Files\Storage\Wrapper\Jail;
use OC\Hooks\BasicEmitter;
use OC\SystemConfig;
use OCP\Files\Cache\IScanner;
use OCP\Files\ForbiddenException;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\ILockingStorage;
use OCP\Files\Storage\IReliableEtagStorage;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

/**
 * Class Scanner
 *
 * Hooks available in scope \OC\Files\Cache\Scanner:
 *  - scanFile(string $path, string $storageId)
 *  - scanFolder(string $path, string $storageId)
 *  - postScanFile(string $path, string $storageId)
 *  - postScanFolder(string $path, string $storageId)
 *
 * @package OC\Files\Cache
 */
class Scanner extends BasicEmitter implements IScanner {
	/**
	 * @var \OC\Files\Storage\Storage $storage
	 */
	protected $storage;

	/**
	 * @var string $storageId
	 */
	protected $storageId;

	/**
	 * @var \OC\Files\Cache\Cache $cache
	 */
	protected $cache;

	/**
	 * @var boolean $cacheActive If true, perform cache operations, if false, do not affect cache
	 */
	protected $cacheActive;

	/**
	 * @var bool $useTransactions whether to use transactions
	 */
	protected $useTransactions = true;

	/**
	 * @var \OCP\Lock\ILockingProvider
	 */
	protected $lockingProvider;

	protected IDBConnection $connection;

	public function __construct(\OC\Files\Storage\Storage $storage) {
		$this->storage = $storage;
		$this->storageId = $this->storage->getId();
		$this->cache = $storage->getCache();
		/** @var SystemConfig $config */
		$config = \OC::$server->get(SystemConfig::class);
		$this->cacheActive = !$config->getValue('filesystem_cache_readonly', false);
		$this->useTransactions = !$config->getValue('filescanner_no_transactions', false);
		$this->lockingProvider = \OC::$server->get(ILockingProvider::class);
		$this->connection = \OC::$server->get(IDBConnection::class);
	}

	/**
	 * Whether to wrap the scanning of a folder in a database transaction
	 * On default transactions are used
	 *
	 * @param bool $useTransactions
	 */
	public function setUseTransactions($useTransactions): void {
		$this->useTransactions = $useTransactions;
	}

	/**
	 * get all the metadata of a file or folder
	 * *
	 *
	 * @param string $path
	 * @return array|null an array of metadata of the file
	 */
	protected function getData($path) {
		$data = $this->storage->getMetaData($path);
		if (is_null($data)) {
			\OC::$server->get(LoggerInterface::class)->debug("!!! Path '$path' is not accessible or present !!!", ['app' => 'core']);
		}
		return $data;
	}

	/**
	 * scan a single file and store it in the cache
	 *
	 * @param string $file
	 * @param int $reuseExisting
	 * @param int $parentId
	 * @param array|CacheEntry|null|false $cacheData existing data in the cache for the file to be scanned
	 * @param bool $lock set to false to disable getting an additional read lock during scanning
	 * @param array|null $data the metadata for the file, as returned by the storage
	 * @return array|null an array of metadata of the scanned file
	 * @throws \OCP\Lock\LockedException
	 */
	public function scanFile($file, $reuseExisting = 0, $parentId = -1, $cacheData = null, $lock = true, $data = null) {
		if ($file !== '') {
			try {
				$this->storage->verifyPath(dirname($file), basename($file));
			} catch (\Exception $e) {
				return null;
			}
		}

		// only proceed if $file is not a partial file, blacklist is handled by the storage
		if (self::isPartialFile($file)) {
			return null;
		}

		// acquire a lock
		if ($lock) {
			if ($this->storage->instanceOfStorage(ILockingStorage::class)) {
				$this->storage->acquireLock($file, ILockingProvider::LOCK_SHARED, $this->lockingProvider);
			}
		}

		try {
			$data = $data ?? $this->getData($file);
		} catch (ForbiddenException $e) {
			if ($lock) {
				if ($this->storage->instanceOfStorage(ILockingStorage::class)) {
					$this->storage->releaseLock($file, ILockingProvider::LOCK_SHARED, $this->lockingProvider);
				}
			}

			return null;
		}

		try {
			if ($data === null) {
				$this->removeFromCache($file);
			} else {
				// pre-emit only if it was a file. By that we avoid counting/treating folders as files
				if ($data['mimetype'] !== 'httpd/unix-directory') {
					$this->emit('\OC\Files\Cache\Scanner', 'scanFile', [$file, $this->storageId]);
					\OC_Hook::emit('\OC\Files\Cache\Scanner', 'scan_file', ['path' => $file, 'storage' => $this->storageId]);
				}

				$parent = dirname($file);
				if ($parent === '.' || $parent === '/') {
					$parent = '';
				}
				if ($parentId === -1) {
					$parentId = $this->cache->getParentId($file);
				}

				// scan the parent if it's not in the cache (id -1) and the current file is not the root folder
				if ($file && $parentId === -1) {
					$parentData = $this->scanFile($parent);
					if ($parentData === null) {
						return null;
					}

					$parentId = $parentData['fileid'];
				}
				if ($parent) {
					$data['parent'] = $parentId;
				}

				$cacheData = $cacheData ?? $this->cache->get($file);
				if ($reuseExisting && $cacheData !== false && isset($cacheData['fileid'])) {
					// prevent empty etag
					$etag = empty($cacheData['etag']) ? $data['etag'] : $cacheData['etag'];
					$fileId = $cacheData['fileid'];
					$data['fileid'] = $fileId;
					// only reuse data if the file hasn't explicitly changed
					$mtimeUnchanged = isset($data['storage_mtime']) && isset($cacheData['storage_mtime']) && $data['storage_mtime'] === $cacheData['storage_mtime'];
					// if the folder is marked as unscanned, never reuse etags
					if ($mtimeUnchanged && $cacheData['size'] !== -1) {
						$data['mtime'] = $cacheData['mtime'];
						if (($reuseExisting & self::REUSE_SIZE) && ($data['size'] === -1)) {
							$data['size'] = $cacheData['size'];
						}
						if ($reuseExisting & self::REUSE_ETAG && !$this->storage->instanceOfStorage(IReliableEtagStorage::class)) {
							$data['etag'] = $etag;
						}
					}

					// we only updated unencrypted_size if it's already set
					if (isset($cacheData['unencrypted_size']) && $cacheData['unencrypted_size'] === 0) {
						unset($data['unencrypted_size']);
					}

					/**
					 * Only update metadata that has changed.
					 * i.e. get all the values in $data that are not present in the cache already
					 *
					 * We need the OC implementation for usage of "getData" method below.
					 * @var \OC\Files\Cache\CacheEntry $cacheData
					 */
					$newData = $this->array_diff_assoc_multi($data, $cacheData->getData());

					// make it known to the caller that etag has been changed and needs propagation
					if (isset($newData['etag'])) {
						$data['etag_changed'] = true;
					}
				} else {
					unset($data['unencrypted_size']);
					$newData = $data;
					$fileId = -1;
				}
				if (!empty($newData)) {
					// Reset the checksum if the data has changed
					$newData['checksum'] = '';
					$newData['parent'] = $parentId;
					$data['fileid'] = $this->addToCache($file, $newData, $fileId);
				}

				if ($cacheData !== false) {
					$data['oldSize'] = $cacheData['size'] ?? 0;
					$data['encrypted'] = $cacheData['encrypted'] ?? false;
				}

				// post-emit only if it was a file. By that we avoid counting/treating folders as files
				if ($data['mimetype'] !== 'httpd/unix-directory') {
					$this->emit('\OC\Files\Cache\Scanner', 'postScanFile', [$file, $this->storageId]);
					\OC_Hook::emit('\OC\Files\Cache\Scanner', 'post_scan_file', ['path' => $file, 'storage' => $this->storageId]);
				}
			}
		} finally {
			// release the acquired lock
			if ($lock && $this->storage->instanceOfStorage(ILockingStorage::class)) {
				$this->storage->releaseLock($file, ILockingProvider::LOCK_SHARED, $this->lockingProvider);
			}
		}

		return $data;
	}

	protected function removeFromCache($path) {
		\OC_Hook::emit('Scanner', 'removeFromCache', ['file' => $path]);
		$this->emit('\OC\Files\Cache\Scanner', 'removeFromCache', [$path]);
		if ($this->cacheActive) {
			$this->cache->remove($path);
		}
	}

	/**
	 * @param string $path
	 * @param array $data
	 * @param int $fileId
	 * @return int the id of the added file
	 */
	protected function addToCache($path, $data, $fileId = -1) {
		if (isset($data['scan_permissions'])) {
			$data['permissions'] = $data['scan_permissions'];
		}
		\OC_Hook::emit('Scanner', 'addToCache', ['file' => $path, 'data' => $data]);
		$this->emit('\OC\Files\Cache\Scanner', 'addToCache', [$path, $this->storageId, $data, $fileId]);
		if ($this->cacheActive) {
			if ($fileId !== -1) {
				$this->cache->update($fileId, $data);
				return $fileId;
			} else {
				return $this->cache->insert($path, $data);
			}
		} else {
			return -1;
		}
	}

	/**
	 * @param string $path
	 * @param array $data
	 * @param int $fileId
	 */
	protected function updateCache($path, $data, $fileId = -1) {
		\OC_Hook::emit('Scanner', 'addToCache', ['file' => $path, 'data' => $data]);
		$this->emit('\OC\Files\Cache\Scanner', 'updateCache', [$path, $this->storageId, $data]);
		if ($this->cacheActive) {
			if ($fileId !== -1) {
				$this->cache->update($fileId, $data);
			} else {
				$this->cache->put($path, $data);
			}
		}
	}

	/**
	 * scan a folder and all it's children
	 *
	 * @param string $path
	 * @param bool $recursive
	 * @param int $reuse
	 * @param bool $lock set to false to disable getting an additional read lock during scanning
	 * @return array|null an array of the meta data of the scanned file or folder
	 */
	public function scan($path, $recursive = self::SCAN_RECURSIVE, $reuse = -1, $lock = true) {
		if ($reuse === -1) {
			$reuse = ($recursive === self::SCAN_SHALLOW) ? self::REUSE_ETAG | self::REUSE_SIZE : self::REUSE_ETAG;
		}

		if ($lock && $this->storage->instanceOfStorage(ILockingStorage::class)) {
			$this->storage->acquireLock('scanner::' . $path, ILockingProvider::LOCK_EXCLUSIVE, $this->lockingProvider);
			$this->storage->acquireLock($path, ILockingProvider::LOCK_SHARED, $this->lockingProvider);
		}

		try {
			$data = $this->scanFile($path, $reuse, -1, lock: $lock);

			if ($data !== null && $data['mimetype'] === 'httpd/unix-directory') {
				$size = $this->scanChildren($path, $recursive, $reuse, $data['fileid'], $lock, $data['size']);
				$data['size'] = $size;
			}
		} catch (NotFoundException $e) {
			$this->removeFromCache($path);
			return null;
		} finally {
			if ($lock && $this->storage->instanceOfStorage(ILockingStorage::class)) {
				$this->storage->releaseLock($path, ILockingProvider::LOCK_SHARED, $this->lockingProvider);
				$this->storage->releaseLock('scanner::' . $path, ILockingProvider::LOCK_EXCLUSIVE, $this->lockingProvider);
			}
		}
		return $data;
	}

	/**
	 * Compares $array1 against $array2 and returns all the values in $array1 that are not in $array2
	 * Note this is a one-way check - i.e. we don't care about things that are in $array2 that aren't in $array1
	 *
	 * Supports multi-dimensional arrays
	 * Also checks keys/indexes
	 * Comparisons are strict just like array_diff_assoc
	 * Order of keys/values does not matter
	 *
	 * @param array $array1
	 * @param array $array2
	 * @return array with the differences between $array1 and $array1
	 * @throws \InvalidArgumentException if $array1 isn't an actual array
	 *
	 */
	protected function array_diff_assoc_multi(array $array1, array $array2) {

		$result = [];

		foreach ($array1 as $key => $value) {

			// if $array2 doesn't have the same key, that's a result
			if (!array_key_exists($key, $array2)) {
				$result[$key] = $value;
				continue;
			}

			// if $array2's value for the same key is different, that's a result
			if ($array2[$key] !== $value && !is_array($value)) {
				$result[$key] = $value;
				continue;
			}

			if (is_array($value)) {
				$nestedDiff = $this->array_diff_assoc_multi($value, $array2[$key]);
				if (!empty($nestedDiff)) {
					$result[$key] = $nestedDiff;
					continue;
				}
			}
		}
		return $result;
	}

	/**
	 * Get the children currently in the cache
	 *
	 * @param int $folderId
	 * @return array<string, \OCP\Files\Cache\ICacheEntry>
	 */
	protected function getExistingChildren($folderId): array {
		$existingChildren = [];
		$children = $this->cache->getFolderContentsById($folderId);
		foreach ($children as $child) {
			$existingChildren[$child['name']] = $child;
		}
		return $existingChildren;
	}

	/**
	 * scan all the files and folders in a folder
	 *
	 * @param string $path
	 * @param bool|IScanner::SCAN_RECURSIVE_INCOMPLETE $recursive
	 * @param int $reuse a combination of self::REUSE_*
	 * @param int $folderId id for the folder to be scanned
	 * @param bool $lock set to false to disable getting an additional read lock during scanning
	 * @param int|float $oldSize the size of the folder before (re)scanning the children
	 * @return int|float the size of the scanned folder or -1 if the size is unknown at this stage
	 */
	protected function scanChildren(string $path, $recursive, int $reuse, int $folderId, bool $lock, int|float $oldSize, &$etagChanged = false) {
		if ($reuse === -1) {
			$reuse = ($recursive === self::SCAN_SHALLOW) ? self::REUSE_ETAG | self::REUSE_SIZE : self::REUSE_ETAG;
		}
		$this->emit('\OC\Files\Cache\Scanner', 'scanFolder', [$path, $this->storageId]);
		$size = 0;
		$childQueue = $this->handleChildren($path, $recursive, $reuse, $folderId, $lock, $size, $etagChanged);

		foreach ($childQueue as $child => [$childId, $childSize]) {
			// "etag changed" propagates up, but not down, so we pass `false` to the children even if we already know that the etag of the current folder changed
			$childEtagChanged = false;
			$childSize = $this->scanChildren($child, $recursive, $reuse, $childId, $lock, $childSize, $childEtagChanged);
			$etagChanged |= $childEtagChanged;

			if ($childSize === -1) {
				$size = -1;
			} elseif ($size !== -1) {
				$size += $childSize;
			}
		}

		// for encrypted storages, we trigger a regular folder size calculation instead of using the calculated size
		// to make sure we also updated the unencrypted-size where applicable
		if ($this->storage->instanceOfStorage(Encryption::class)) {
			$this->cache->calculateFolderSize($path);
		} else {
			if ($this->cacheActive) {
				$updatedData = [];
				if ($oldSize !== $size) {
					$updatedData['size'] = $size;
				}
				if ($etagChanged) {
					$updatedData['etag'] = uniqid();
				}
				if ($updatedData) {
					$this->cache->update($folderId, $updatedData);
				}
			}
		}
		$this->emit('\OC\Files\Cache\Scanner', 'postScanFolder', [$path, $this->storageId]);
		return $size;
	}

	/**
	 * @param bool|IScanner::SCAN_RECURSIVE_INCOMPLETE $recursive
	 */
	private function handleChildren(string $path, $recursive, int $reuse, int $folderId, bool $lock, int|float &$size, bool &$etagChanged): array {
		// we put this in it's own function so it cleans up the memory before we start recursing
		$existingChildren = $this->getExistingChildren($folderId);
		$newChildren = iterator_to_array($this->storage->getDirectoryContent($path));

		if (count($existingChildren) === 0 && count($newChildren) === 0) {
			// no need to do a transaction
			return [];
		}

		if ($this->useTransactions) {
			$this->connection->beginTransaction();
		}

		$exceptionOccurred = false;
		$childQueue = [];
		$newChildNames = [];
		foreach ($newChildren as $fileMeta) {
			$permissions = $fileMeta['scan_permissions'] ?? $fileMeta['permissions'];
			if ($permissions === 0) {
				continue;
			}
			$originalFile = $fileMeta['name'];
			$file = trim(\OC\Files\Filesystem::normalizePath($originalFile), '/');
			if (trim($originalFile, '/') !== $file) {
				// encoding mismatch, might require compatibility wrapper
				\OC::$server->get(LoggerInterface::class)->debug('Scanner: Skipping non-normalized file name "' . $originalFile . '" in path "' . $path . '".', ['app' => 'core']);
				$this->emit('\OC\Files\Cache\Scanner', 'normalizedNameMismatch', [$path ? $path . '/' . $originalFile : $originalFile]);
				// skip this entry
				continue;
			}

			$newChildNames[] = $file;
			$child = $path ? $path . '/' . $file : $file;
			try {
				$existingData = $existingChildren[$file] ?? false;
				$data = $this->scanFile($child, $reuse, $folderId, $existingData, $lock, $fileMeta);
				if ($data) {
					if ($data['mimetype'] === 'httpd/unix-directory' && $recursive === self::SCAN_RECURSIVE) {
						$childQueue[$child] = [$data['fileid'], $data['size']];
					} elseif ($data['mimetype'] === 'httpd/unix-directory' && $recursive === self::SCAN_RECURSIVE_INCOMPLETE && $data['size'] === -1) {
						// only recurse into folders which aren't fully scanned
						$childQueue[$child] = [$data['fileid'], $data['size']];
					} elseif ($data['size'] === -1) {
						$size = -1;
					} elseif ($size !== -1) {
						$size += $data['size'];
					}

					if (isset($data['etag_changed']) && $data['etag_changed']) {
						$etagChanged = true;
					}
				}
			} catch (Exception $ex) {
				// might happen if inserting duplicate while a scanning
				// process is running in parallel
				// log and ignore
				if ($this->useTransactions) {
					$this->connection->rollback();
					$this->connection->beginTransaction();
				}
				\OC::$server->get(LoggerInterface::class)->debug('Exception while scanning file "' . $child . '"', [
					'app' => 'core',
					'exception' => $ex,
				]);
				$exceptionOccurred = true;
			} catch (\OCP\Lock\LockedException $e) {
				if ($this->useTransactions) {
					$this->connection->rollback();
				}
				throw $e;
			}
		}
		$removedChildren = \array_diff(array_keys($existingChildren), $newChildNames);
		foreach ($removedChildren as $childName) {
			$child = $path ? $path . '/' . $childName : $childName;
			$this->removeFromCache($child);
		}
		if ($this->useTransactions) {
			$this->connection->commit();
		}
		if ($exceptionOccurred) {
			// It might happen that the parallel scan process has already
			// inserted mimetypes but those weren't available yet inside the transaction
			// To make sure to have the updated mime types in such cases,
			// we reload them here
			\OC::$server->getMimeTypeLoader()->reset();
		}
		return $childQueue;
	}

	/**
	 * check if the file should be ignored when scanning
	 * NOTE: files with a '.part' extension are ignored as well!
	 *       prevents unfinished put requests to be scanned
	 *
	 * @param string $file
	 * @return boolean
	 */
	public static function isPartialFile($file) {
		if (pathinfo($file, PATHINFO_EXTENSION) === 'part') {
			return true;
		}
		if (str_contains($file, '.part/')) {
			return true;
		}

		return false;
	}

	/**
	 * walk over any folders that are not fully scanned yet and scan them
	 */
	public function backgroundScan() {
		if ($this->storage->instanceOfStorage(Jail::class)) {
			// for jail storage wrappers (shares, groupfolders) we run the background scan on the source storage
			// this is mainly done because the jail wrapper doesn't implement `getIncomplete` (because it would be inefficient).
			//
			// Running the scan on the source storage might scan more than "needed", but the unscanned files outside the jail will
			// have to be scanned at some point anyway.
			$unJailedScanner = $this->storage->getUnjailedStorage()->getScanner();
			$unJailedScanner->backgroundScan();
		} else {
			if (!$this->cache->inCache('')) {
				// if the storage isn't in the cache yet, just scan the root completely
				$this->runBackgroundScanJob(function () {
					$this->scan('', self::SCAN_RECURSIVE, self::REUSE_ETAG);
				}, '');
			} else {
				$lastPath = null;
				// find any path marked as unscanned and run the scanner until no more paths are unscanned (or we get stuck)
				while (($path = $this->cache->getIncomplete()) !== false && $path !== $lastPath) {
					$this->runBackgroundScanJob(function () use ($path) {
						$this->scan($path, self::SCAN_RECURSIVE_INCOMPLETE, self::REUSE_ETAG | self::REUSE_SIZE);
					}, $path);
					// FIXME: this won't proceed with the next item, needs revamping of getIncomplete()
					// to make this possible
					$lastPath = $path;
				}
			}
		}
	}

	protected function runBackgroundScanJob(callable $callback, $path) {
		try {
			$callback();
			\OC_Hook::emit('Scanner', 'correctFolderSize', ['path' => $path]);
			if ($this->cacheActive && $this->cache instanceof Cache) {
				$this->cache->correctFolderSize($path, null, true);
			}
		} catch (\OCP\Files\StorageInvalidException $e) {
			// skip unavailable storages
		} catch (\OCP\Files\StorageNotAvailableException $e) {
			// skip unavailable storages
		} catch (\OCP\Files\ForbiddenException $e) {
			// skip forbidden storages
		} catch (\OCP\Lock\LockedException $e) {
			// skip unavailable storages
		}
	}

	/**
	 * Set whether the cache is affected by scan operations
	 *
	 * @param boolean $active The active state of the cache
	 */
	public function setCacheActive($active) {
		$this->cacheActive = $active;
	}
}

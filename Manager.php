<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\MailCustomImapFolderPrefixPlugin;

use Aurora\Modules\Mail\Classes\Folder;
use Aurora\Modules\Mail\Models\MailAccount;
use Aurora\Modules\Mail\Enums\FolderType;

/**
 * Manager for work with ImapClient.
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2023, Afterlogic Corp.
 *
 * @package Mail
 *
 * @property Module $oModule
 */
class Manager extends \Aurora\Modules\Mail\Managers\Main\Manager
{
    private function getPrefixForAccount($oAccount)
    {
        $prefix = trim($oAccount->getExtendedProp('MailCustomImapFolderPrefixPlugin::Prefix', ''));
        if (!empty(trim($prefix))) {
            $prefix = trim($prefix) . Module::$delimiter;
        }

        return $prefix;
    }

    private function callPrivateMethod ($object, $method, ...$args) {
        $call = function ($method, ...$args) {
            return $this->$method(...$args);
        };
        $newCall = $call->bindTo($object, get_class($object));
        return $newCall($method, ...$args);
    }

    /**
     * Sets system type for existent folders from folders map, excludes information about them from $aFoldersMap.
     *
     * @param \Aurora\Modules\Mail\Classes\FolderCollection $oFolderCollection Collection of folders.
     * @param array $aFoldersMap Describes information about system folders that weren't initialized yet.
     */
    protected function _initSystemFoldersFromFoldersMap($oFolderCollection, &$aFoldersMap)
    {
        $oFolderCollection->foreachOnlyRoot(
            function (/* @var $oFolder \Aurora\Modules\Mail\Classes\Folder */ $oFolder) use (&$aFoldersMap) {
                foreach ($aFoldersMap as $iFolderType => $aFoldersNames) {
                    if (isset($aFoldersMap[$iFolderType]) && is_array($aFoldersNames) && 
                        (in_array($oFolder->getRawFullName(), $aFoldersNames) || in_array($oFolder->getRawFullName(), $aFoldersNames))) {
                        unset($aFoldersMap[$iFolderType]);
                        if (FolderType::Custom === $oFolder->getType()) {
                            $oFolder->setType($iFolderType);
                        }
                    }
                }
            }
        );
    }

    /**
     * Initializes system folders.
     *
     * @param MailAccount $oAccount Account object.
     * @param \Aurora\Modules\Mail\Classes\FolderCollection $oFolderCollection Collection of folders.
     * @param bool $bCreateNonexistentSystemFolders Create nonexistent system folders.
     *
     * @return bool
     */
    protected function _initSystemFolders($oAccount, &$oFolderCollection, $bCreateNonexistentSystemFolders)
    {
        $bSystemFolderIsCreated = false;

        try {
            $prefix = trim($oAccount->getExtendedProp('MailCustomImapFolderPrefixPlugin::Prefix', ''));
            if (!empty(trim($prefix))) {
                $prefix = trim($prefix) . Module::$delimiter;
            }

            $aFoldersMap = [
                FolderType::Drafts => [$prefix . 'Drafts', $prefix . 'Draft'],
                FolderType::Sent => [$prefix . 'Sent', $prefix . 'Sent Items', $prefix . 'Sent Mail'],
                FolderType::Spam => [$prefix . 'Spam', $prefix . 'Junk', $prefix . 'Junk Mail', $prefix . 'Junk E-mail', $prefix . 'Bulk Mail'],
                FolderType::Trash => [$prefix . 'Trash', $prefix . 'Bin', $prefix . 'Deleted', $prefix . 'Deleted Items'],
                // if array is empty, folder will not be set and/or created from folders map
                FolderType::Template => [],
                FolderType::All => [],
            ];

            $oInbox = $oFolderCollection->getFolder('INBOX');
            if ($oInbox) {
                $oInbox->setType(FolderType::Inbox);
            }

            $prefix = $this->getPrefixForAccount($oAccount);
            $oFolderCollection->foreachWithSubFolders(
                function ($oFolder) use (&$prefixFolder, $prefix) {
                    if ($oFolder->getRawFullName() === rtrim($prefix, '/')) {
                        $prefixFolder = $oFolder;
                    }
                }
            );

            $prefixSubfoldersCollection = $oFolderCollection;
            if ($prefixFolder) {
                $prefixSubfoldersCollection = $prefixFolder->getSubFolders();
            }

            $mailManager = new \Aurora\Modules\Mail\Managers\Main\Manager($this->GetModule());
            // Tries to set system folders from database data.
            $this->callPrivateMethod($mailManager, '_initSystemFoldersFromDb', $oAccount, $prefixSubfoldersCollection, $aFoldersMap);

            // Tries to set system folders from imap flags for those folders that weren't set from database data.
            $this->callPrivateMethod($mailManager, '_initSystemFoldersFromImapFlags', $prefixSubfoldersCollection, $aFoldersMap);

            // Tries to set system folders from folders map for those folders that weren't set from database data or IMAP flags.
            $this->_initSystemFoldersFromFoldersMap($prefixSubfoldersCollection, $aFoldersMap);

            if ($bCreateNonexistentSystemFolders) {
                $bSystemFolderIsCreated = $this->callPrivateMethod($mailManager, '_createNonexistentSystemFolders', $oAccount, $prefixSubfoldersCollection, $aFoldersMap);
            }
        } catch (\Exception $oException) {
            $bSystemFolderIsCreated = false;
        }

        return $bSystemFolderIsCreated;
    }

    public function getFolders($oAccount, $bCreateUnExistenSystemFolders = true, $sParent = '')
    {
        if (empty($sParent)) {
            $prefix = trim($oAccount->getExtendedProp('MailCustomImapFolderPrefixPlugin::Prefix', ''));
            if (!empty($prefix)) {
                $sParent = $prefix . Module::$delimiter;
            }
        }
        $folderCollection = parent::getFolders($oAccount, $bCreateUnExistenSystemFolders, $sParent);
        $folderCollection->addFolder(Folder::createInstance(\MailSo\Imap\Folder::NewInstance('INBOX', '/', [])));

        return $folderCollection;
    }

        /**
     * Obtains information about particular folders.
     *
     * @param MailAccount $oAccount Account object.
     * @param array $aFolderFullNamesRaw Array containing a list of folder names to obtain information for.
     * @param boolean $bUseListStatusIfPossible Indicates if LIST-STATUS command should be used if it's supported by IMAP server.
     *
     * @return array Array containing elements like those returned by **getFolderInformation** method.
     */
    public function getFolderListInformation($oAccount, $aFolderFullNamesRaw, $bUseListStatusIfPossible)
    {
        return parent::getFolderListInformation($oAccount, $aFolderFullNamesRaw, false);
    }

       /**
     * Obtains folders order.
     *
     * @param MailAccount $oAccount Account object.
     *
     * @return array
     */
    public function getFoldersOrder($oAccount)
    {
        $aList = parent::getFoldersOrder($oAccount);

        if (count($aList) > 0) {
            $prefix = $this->getPrefixForAccount($oAccount);
            $aList = array_map(function($folder) use ($prefix) {
                return $prefix . $folder;
            }, $aList);
        }

        return $aList;
    }
}

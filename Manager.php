<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\MailCustomImapFolderPrefixPlugin;

use Aurora\Modules\Mail\Models\MailAccount;
use Aurora\Modules\Mail\Models\SystemFolder;

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

    /**
     * Obtains information about system folders from database.
     * Sets system type for existent folders, excludes information about them from $aFoldersMap.
     * Deletes information from database for nonexistent folders.
     *
     * @param MailAccount $oAccount Account object.
     * @param \Aurora\Modules\Mail\Classes\FolderCollection $oFolderCollection Collection of folders.
     * @param array $aFoldersMap Describes information about system folders that weren't initialized yet.
     */
    private function _initSystemFoldersFromDb($oAccount, $oFolderCollection, &$aFoldersMap)
    {
        $aSystemFolderEntities = $this->_getSystemFolderEntities($oAccount);

        foreach ($aSystemFolderEntities as $oSystemFolder) {
            if ($oSystemFolder->FolderFullName === '') {
                unset($aFoldersMap[$oSystemFolder->Type]);
            } else {
                $oFolder = $oFolderCollection->getFolder($oSystemFolder->FolderFullName, true);
                if ($oFolder) {
                    if (isset($aFoldersMap[$oSystemFolder->Type])) {
                        if ($oSystemFolder->Type !== \Aurora\Modules\Mail\Enums\FolderType::Template) {
                            unset($aFoldersMap[$oSystemFolder->Type]);
                        }
                        $oFolder->setType($oSystemFolder->Type);
                    }
                } elseif ($oFolderCollection->getCollectionFullFlag()) {
                    $oSystemFolder->delete();
                }
            }
        }
    }

    private function _getSystemFolderEntities($oAccount)
    {
        return SystemFolder::where(array(
            'IdAccount' => $oAccount->Id
        ))->limit(9)->get();
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
                    if (isset($aFoldersMap[$iFolderType]) && is_array($aFoldersNames) && (in_array($oFolder->getRawFullName(), $aFoldersNames) || in_array($oFolder->getRawFullName(), $aFoldersNames))) {
                        unset($aFoldersMap[$iFolderType]);
                        if (\Aurora\Modules\Mail\Enums\FolderType::Custom === $oFolder->getType()) {
                            $oFolder->setType($iFolderType);
                        }
                    }
                }
            }
        );
    }

    /**
     * Obtains information about system folders from IMAP.
     * Sets system type for obtained folders, excludes information about them from $aFoldersMap.
     *
     * @param \Aurora\Modules\Mail\Classes\FolderCollection $oFolderCollection Collection of folders.
     * @param array $aFoldersMap Describes information about system folders that weren't initialized yet.
     */
    private function _initSystemFoldersFromImapFlags($oFolderCollection, &$aFoldersMap)
    {
        $oFolderCollection->foreachWithSubFolders(
            function (/* @var $oFolder \Aurora\Modules\Mail\Classes\Folder */ $oFolder) use (&$aFoldersMap) {
                $iXListType = $oFolder->getFolderXListType();
                if (isset($aFoldersMap[$iXListType]) && \Aurora\Modules\Mail\Enums\FolderType::Custom === $oFolder->getType() && isset($aFoldersMap[$iXListType])) {
                    unset($aFoldersMap[$iXListType]);
                    $oFolder->setType($iXListType);
                }
            }
        );
    }

    /**
     * Creates system folders that weren't initialized earlier because they don't exist.
     *
     * @param MailAccount $oAccount Account object.
     * @param \Aurora\Modules\Mail\Classes\FolderCollection $oFolderCollection Collection of folders.
     * @param array $aFoldersMap Describes information about system folders that weren't initialized yet.
     */
    private function _createNonexistentSystemFolders($oAccount, $oFolderCollection, $aFoldersMap)
    {
        $bSystemFolderIsCreated = false;

        if (is_array($aFoldersMap)) {
            $sNamespace = $oFolderCollection->getNamespace();
            foreach ($aFoldersMap as $mFolderName) {
                $sFolderFullName = is_array($mFolderName) &&
                    isset($mFolderName[0]) && is_string($mFolderName[0]) && 0 < strlen($mFolderName[0]) ?
                        $mFolderName[0] : (is_string($mFolderName) && 0 < strlen($mFolderName) ? $mFolderName : '');

                if (0 < strlen($sFolderFullName)) {
                    $this->createFolderByFullName($oAccount, $sNamespace . $sFolderFullName);
                    $bSystemFolderIsCreated = true;
                }
            }
        }

        return $bSystemFolderIsCreated;
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

            $aFoldersMap = array(
                \Aurora\Modules\Mail\Enums\FolderType::Drafts => array($prefix . 'Drafts', $prefix . 'Draft'),
                \Aurora\Modules\Mail\Enums\FolderType::Sent => array($prefix . 'Sent', $prefix . 'Sent Items', $prefix . 'Sent Mail'),
                \Aurora\Modules\Mail\Enums\FolderType::Spam => array($prefix . 'Spam', $prefix . 'Junk', $prefix . 'Junk Mail', $prefix . 'Junk E-mail', $prefix . 'Bulk Mail'),
                \Aurora\Modules\Mail\Enums\FolderType::Trash => array($prefix . 'Trash', $prefix . 'Bin', $prefix . 'Deleted', $prefix . 'Deleted Items'),
                // if array is empty, folder will not be set and/or created from folders map
                \Aurora\Modules\Mail\Enums\FolderType::Template => array(),
                \Aurora\Modules\Mail\Enums\FolderType::All => array(),
            );

            $oInbox = $oFolderCollection->getFolder('INBOX');
            if ($oInbox) {
                $oInbox->setType(\Aurora\Modules\Mail\Enums\FolderType::Inbox);
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

            // Tries to set system folders from database data.
            $this->_initSystemFoldersFromDb($oAccount, $prefixSubfoldersCollection, $aFoldersMap);

            // Tries to set system folders from imap flags for those folders that weren't set from database data.
            $this->_initSystemFoldersFromImapFlags($prefixSubfoldersCollection, $aFoldersMap);

            // Tries to set system folders from folders map for those folders that weren't set from database data or IMAP flags.
            $this->_initSystemFoldersFromFoldersMap($prefixSubfoldersCollection, $aFoldersMap);

            if ($bCreateNonexistentSystemFolders) {
                $bSystemFolderIsCreated = $this->_createNonexistentSystemFolders($oAccount, $prefixSubfoldersCollection, $aFoldersMap);
            }
        } catch (\Exception $oException) {
            $bSystemFolderIsCreated = false;
        }

        return $bSystemFolderIsCreated;
    }
}

<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\MailCustomImapFolderPrefixPlugin;

use Aurora\Api;
use Aurora\Modules\Mail\Enums\FolderType;
use Aurora\System\Enums\UserRole;
use Aurora\Modules\Mail\Module as MailModule;

/**
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2023, Afterlogic Corp.
 *
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
    public static $delimiter = '/';

    protected $aRequireModules = ['Mail'];

    /**
     * Initializes Mail Module.
     *
     * @ignore
     */
    public function init()
    {
        $this->subscribeEvent('Mail::GetFolders::after', [$this, 'onAfterGetFolders']);
        $this->subscribeEvent('Mail::GetRelevantFoldersInformation::after', [$this, 'onAfterGetRelevantFoldersInformation']);
        $this->subscribeEvent('Mail::GetMessages::after', [$this, 'onAfterGetMessages']);

        $this->subscribeEvent('Mail::GetMessages::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::GetMessagesByFolders::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::GetUnifiedMailboxMessages::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::GetMessagesInfo::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::GetMessagesBodies::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::GetMessage::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::Unsubscribe::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::GetMessageByMessageID::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::SetMessagesSeen::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::SetMessageFlagged::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::SetAllMessagesSeen::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::CopyMessages::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::MoveMessages::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::DeleteMessages::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::CreateFolder::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::DeleteFolder::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::RenameFolder::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::SubscribeFolder::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::ClearFolder::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::GetMessagesByUids::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::GetMessagesFlags::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::SetAlwaysRefreshFolder::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::SetTemplateFolderType::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::UploadMessage::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::GetRelevantFoldersInformation::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::UpdateFoldersOrder::before', [$this, 'prepareArguments']);
        $this->subscribeEvent('Mail::SaveMessageAsTempFile::before', [$this, 'prepareArguments']);

        $this->subscribeEvent('System::RunEntry::before', [$this, 'onBeforeRunEntry']);
    }

    /**
     * @return Module
     */
    public static function getInstance()
    {
        return parent::getInstance();
    }

    /**
     * @return Module
     */
    public static function Decorator()
    {
        return parent::Decorator();
    }

    protected function renameSubfoldersRec($folder, $prefix, &$renamedFolders)
    {
        $subfoldersColl = $folder->getSubFolders();
        if ($subfoldersColl !== null) {
            $subfolders = & $subfoldersColl->GetAsArray();
            foreach ($subfolders as $subFolder) {
                $this->renameSubfoldersRec($subFolder, $prefix, $renamedFolders);
            }
        }
        if (substr($folder->getFullName(), 0, strlen($prefix)) == $prefix) {
            $refFolder = new \ReflectionObject($folder);
            $oImapFolderProp = $refFolder->getProperty('oImapFolder');
            $oImapFolderProp->setAccessible(true);
            $oImapFolder = $oImapFolderProp->getValue($folder);

            $refImapFolder = new \ReflectionObject($oImapFolder);
            $oImapFolderProp = $refImapFolder->getProperty('sFullNameRaw');
            $oImapFolderProp->setAccessible(true);
            $oImapFolderProp->setValue($oImapFolder, substr($oImapFolder->FullNameRaw(), strlen($prefix)));

            $renamedFolders[] = $folder;
        }
    }

    public function onAfterGetFolders($aArgs, &$mResult)
    {
        if (is_array($mResult) && isset($mResult['Folders']) && is_object($mResult['Folders'])) {
            $prefix = '';
            if (isset($aArgs['AccountID'])) {
                $prefix = $this->getPrefixForAccount((int) $aArgs['AccountID']) . self::$delimiter;
            }

            $folders = & $mResult['Folders']->GetAsArray();
            $renamedFolders = [];
            foreach ($folders as $key => $folder) {
                if ($folder->getType() !== FolderType::Inbox) {
                    $subFoldersColl = $folder->getSubFolders();
                    if ($subFoldersColl) {
                        $subFolders = $subFoldersColl->GetAsArray();
                        foreach ($subFolders as $subFolder) {
                            $this->renameSubfoldersRec($subFolder, $prefix, $renamedFolders);
                        }
                    }
                    unset($folders[$key]);
                }
            }
            $folders = array_merge($folders, $renamedFolders);
            $folders = array_values($folders);

            $oMailModule = Api::GetModule('Mail');
            if ($oMailModule instanceof MailModule) {
                $manager = $oMailModule->getMailManager();
                $oAccount = $oMailModule->getAccountsManager()->getAccountById($aArgs['AccountID']);
                \Closure::bind(
                    fn ($class) => $class->_initSystemFolders($oAccount, $mResult['Folders'], false),
                    null,
                    get_class($manager)
                )($manager);
            }
        }
    }

    protected function addPrefixToFolderName($folder, $prefix)
    {
        if ($folder !== 'INBOX' && substr($folder, 0, strlen('INBOX' . self::$delimiter)) !== 'INBOX' . self::$delimiter) {
            if (!empty($folder)) {
                if (!empty($prefix)) {
                    $folder = $prefix . self::$delimiter . $folder;
                }
            } else {
                $folder = $prefix;
            }
        }

        return $folder;
    }

    protected function removePrefixFromFolderName($folder, $prefix)
    {
        $prefix = $prefix . self::$delimiter;
        if (substr($folder, 0, strlen($prefix)) === $prefix) {
            $folder = substr($folder, strlen($prefix));
        }

        return $folder;
    }

    protected function getPrefixForAccount($AccountId)
    {
        $prefix = '';
        $oUser = Api::getAuthenticatedUser();
        if ($oUser) {
            $oAccount = MailModule::Decorator()->GetAccount($AccountId);
            if ($oAccount && $oAccount->IdUser === $oUser->Id) {
                $prefix = trim($oAccount->getExtendedProp(self::GetName() . '::Prefix', ''));
            }
        }

        return $prefix;
    }

    public function prepareArguments(&$aArgs, &$mResult)
    {
        $prefix = '';
        if (isset($aArgs['AccountID'])) {
            $prefix = $this->getPrefixForAccount((int) $aArgs['AccountID']);
        }

        if (isset($aArgs['Folder'])) {
            $aArgs['Folder'] = $this->addPrefixToFolderName($aArgs['Folder'], $prefix);
        }

        if (isset($aArgs['ToFolder'])) {
            $aArgs['ToFolder'] = $this->addPrefixToFolderName($aArgs['ToFolder'], $prefix);
        }

        if (isset($aArgs['FolderParentFullNameRaw'])) {
            $aArgs['FolderParentFullNameRaw'] = $this->addPrefixToFolderName($aArgs['FolderParentFullNameRaw'], $prefix);
        }

        if (isset($aArgs['PrevFolderFullNameRaw'])) {
            $aArgs['PrevFolderFullNameRaw'] = $this->addPrefixToFolderName($aArgs['PrevFolderFullNameRaw'], $prefix);
        }

        if (isset($aArgs['MessageFolder'])) {
            $aArgs['MessageFolder'] = $this->addPrefixToFolderName($aArgs['MessageFolder'], $prefix);
        }

        if (isset($aArgs['FolderFullName'])) {
            $aArgs['FolderFullName'] = $this->addPrefixToFolderName($aArgs['FolderFullName'], $prefix);
        }

        if (isset($aArgs['Folders']) && is_array($aArgs['Folders'])) {
            foreach ($aArgs['Folders'] as $val) {
                $val = $this->addPrefixToFolderName($val, $prefix);
            }
        }

        if (isset($aArgs['FolderList']) && is_array($aArgs['FolderList'])) {
            foreach ($aArgs['FolderList'] as $val) {
                $val = $this->addPrefixToFolderName($val, $prefix);
            }
        }
    }

    public function onAfterGetRelevantFoldersInformation(&$aArgs, &$mResult)
    {
        if ($mResult && isset($mResult['Counts'])) {
            $prefix = '';
            if (isset($aArgs['AccountID'])) {
                $prefix = $this->getPrefixForAccount((int) $aArgs['AccountID']);
            }
            $foldersInfo = [];
            foreach ($mResult['Counts'] as $key => $val) {
                if ($key !== 'INBOX' && substr($key, 0, strlen('INBOX' . self::$delimiter)) !== 'INBOX' . self::$delimiter) {
                    unset($mResult['Counts'][$key]);
                    $newKey = $this->removePrefixFromFolderName($key, $prefix);
                    if ($newKey !== $key) {
                        $foldersInfo[$key] = $val;
                    }
                }
            }
            $mResult['Counts'] = array_merge($mResult['Counts'], $foldersInfo);
        }
    }

    public function onAfterGetMessages(&$aArgs, &$mResult)
    {
        if ($mResult) {
            $prefix = '';
            if (isset($aArgs['AccountID'])) {
                $prefix = $this->getPrefixForAccount((int) $aArgs['AccountID']);
            }
            $massages = & $mResult->GetAsArray();
            foreach ($massages as $message) {
                $refMessage = new \ReflectionObject($message);
                $folderProp = $refMessage->getProperty('sFolder');
                $folderProp->setAccessible(true);
                $newFolderName = $this->removePrefixFromFolderName($message->getFolder(), $prefix);
                $folderProp->setValue($message, $newFolderName);
            };
            $mResult->FolderName = $this->removePrefixFromFolderName($mResult->FolderName, $prefix);
        }
    }

    public function onBeforeRunEntry($aArgs, &$mResult)
    {
        $aEntries = ['mail-attachment', 'mail-attachments-cookieless'];
        if (isset($aArgs['EntryName']) && in_array(strtolower($aArgs['EntryName']), $aEntries)) {
            $queryString = $_SERVER['QUERY_STRING'];
            $queryStringList = \explode('/', $queryString);
            if (isset($queryStringList[1])) {
                $sHash = (string) $queryStringList[1];
                $aValues = \Aurora\System\Api::DecodeKeyValues($sHash);
                if (isset($aValues['Folder'])) {
                    $prefix = '';
                    if (isset($aArgs['AccountID'])) {
                        $prefix = $this->getPrefixForAccount((int) $aArgs['AccountID']);
                    }
                    $aValues['Folder'] = $this->removePrefixFromFolderName($aValues['Folder'], $prefix);
                    $queryStringList[1] = \Aurora\System\Api::EncodeKeyValues($aValues);
                    $_SERVER['QUERY_STRING'] = implode('/', $queryStringList);
                }
            }

        }
    }
    /***** private functions *****/

    /**
     * Retursn account settings related to the module.
     *
     * @param int $AccountId
     *
     * @return array
     */
    public function GetAccountSettings($AccountId)
    {
        Api::checkUserRoleIsAtLeast(UserRole::NormalUser);

        $oUser = Api::getAuthenticatedUser();
        if ($oUser) {
            $oAccount = MailModule::Decorator()->GetAccount($AccountId);

            if ($oAccount) {
                return [
                    'Prefix' => trim($oAccount->getExtendedProp(self::GetName() . '::Prefix', '')),
                ];
            }
        }

        return [];
    }

    /**
     * Update for account settings related to the module.
     *
     * @param int $AccountId
     * @param string $Prefix
     *
     * @return bool
     */
    public function UpdateAccountSettings($AccountId, $Prefix)
    {
        $result = false;

        Api::checkUserRoleIsAtLeast(UserRole::NormalUser);

        $oUser = Api::getAuthenticatedUser();
        if ($oUser) {
            $oAccount = MailModule::Decorator()->GetAccount($AccountId);

            if ($oAccount) {
                $oAccount->setExtendedProp(self::GetName() . '::Prefix', trim((string) $Prefix));
                $result = $oAccount->save();
            }
        }

        return $result;
    }
}
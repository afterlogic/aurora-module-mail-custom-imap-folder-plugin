<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\MailCustomImapFolderPrefixPlugin;

use Aurora\Api;
use Aurora\Modules\Mail\Enums\FolderType;

/**
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2023, Afterlogic Corp.
 *
 * @property Settings $oModuleSettings
 *
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
    public static $prefix = 'Mail';
    public static $delimiter = '/';

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

    protected function renameSubfoldersRec(&$folder)
    {
        $prefix = self::$prefix . $folder->getDelimiter();
        $subfoldersColl = $folder->getSubFolders();
        if ($subfoldersColl !== null) {
            $subfolders = & $subfoldersColl->GetAsArray();
            foreach ($subfolders as $subFolder) {
                $this->renameSubfoldersRec($subFolder);
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
        }
    }

    public function onAfterGetFolders($aArgs, &$mResult)
    {
        if (is_array($mResult) && isset($mResult['Folders']) && is_object($mResult['Folders'])) {
            $folders = & $mResult['Folders']->GetAsArray();
            $subFolders = [];
            foreach ($folders as $key => $folder) {
                if ($folder->getType() !== FolderType::Inbox) {
                    if ($folder->getRawFullName() === self::$prefix) {
                        $subFolders = & $folder->getSubFolders()->GetAsArray();
                        foreach ($subFolders as $subFolder) {
                            $this->renameSubfoldersRec($subFolder);
                        }
                    }
                    unset($folders[$key]);
                }
            }
            $folders = array_merge($folders, $subFolders);
            $folders = array_values($folders);

            /** @var \Aurora\Modules\Mail\Module */
            $oMailModule = Api::GetModule('Mail');
            if ($oMailModule) {
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

    protected function prepareFolderName($folder)
    {
        if ($folder !== 'INBOX' && substr($folder, 0, strlen('INBOX/')) !== 'INBOX/') {
            $folder = self::$prefix . '/' . $folder;
        }

        return $folder;
    }

    public function prepareArguments(&$aArgs, &$mResult)
    {
        if (isset($aArgs['Folder'])) {
            $aArgs['Folder'] = $this->prepareFolderName($aArgs['Folder']);
        }

        if (isset($aArgs['ToFolder'])) {
            $aArgs['ToFolder'] = $this->prepareFolderName($aArgs['ToFolder']);
        }

        if (isset($aArgs['FolderParentFullNameRaw'])) {
            $aArgs['FolderParentFullNameRaw'] = $this->prepareFolderName($aArgs['FolderParentFullNameRaw']);
        }

        if (isset($aArgs['MessageFolder'])) {
            $aArgs['MessageFolder'] = $this->prepareFolderName($aArgs['MessageFolder']);
        }

        if (isset($aArgs['FolderFullName'])) {
            $aArgs['FolderFullName'] = $this->prepareFolderName($aArgs['FolderFullName']);
        }

        if (isset($aArgs['Folders']) && is_array($aArgs['Folders'])) {
            foreach ($aArgs['Folders'] as $val) {
                $val = $this->prepareFolderName($val);
            }
        }

        if (isset($aArgs['FolderList']) && is_array($aArgs['FolderList'])) {
            foreach ($aArgs['FolderList'] as $val) {
                $val = $this->prepareFolderName($val);
            }
        }
    }

    public function onAfterGetRelevantFoldersInformation(&$aArgs, &$mResult)
    {
        if ($mResult && isset($mResult['Counts'])) {
            $prefix = self::$prefix . '/';
            $foldersInfo = [];
            foreach ($mResult['Counts'] as $key => $val) {
                if ($key !== 'INBOX' && substr($key, 0, strlen('INBOX/')) !== 'INBOX/') {
                    unset($mResult['Counts'][$key]);
                    if (substr($key, 0, strlen($prefix)) === $prefix) {
                        $key = substr($key, strlen($prefix));
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
            $prefix = self::$prefix . '/';
            $massages = & $mResult->GetAsArray();
            foreach ($massages as $message) {
                $refMessage = new \ReflectionObject($message);
                $folderProp = $refMessage->getProperty('sFolder');
                $folderProp->setAccessible(true);

                if (substr($message->getFolder(), 0, strlen($prefix)) === $prefix) {
                    $newFolderName = substr($message->getFolder(), strlen($prefix));
                    $folderProp->setValue($message, $newFolderName);
                }
            };
            if (substr($mResult->FolderName, 0, strlen($prefix)) === $prefix) {
                $newFolderName = substr($mResult->FolderName, strlen($prefix));
                $mResult->FolderName = $newFolderName;
            }
        }
    }
    /***** private functions *****/
}

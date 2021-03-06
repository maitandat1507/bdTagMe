<?php

class bdTagMe_Engine
{
    const SIMPLE_CACHE_KEY_TAGGABLE_USER_GROUPS = 'bdTagMe_taggableUserGroups';

    const OPTION_MAX_TAGGED_USERS = 'maxTaggedUsers';
    const OPTION_USER_CALLBACK = 'userCallback';

    const USERS_PER_BATCH = 100;

    public function notifyTaggedUsers3(
        $contentType, $contentId, $contentUserId, $contentUserName, $alertAction,
        array $taggedUsers, array $noAlertUserIds = array(), array $noEmailUserIds = array(),
        XenForo_Model $someRandomModel = null, array $options = array())
    {
        $options = array_merge(array(self::OPTION_MAX_TAGGED_USERS => bdTagMe_Option::get('max')), $options);

        if ($someRandomModel != null) {
            /* @var $userModel XenForo_Model_User */
            $userModel = $someRandomModel->getModelFromCache('XenForo_Model_User');
        } else {
            /* @var $userModel XenForo_Model_User */
            $userModel = XenForo_Model::create('XenForo_Model_User');
        }

        $neededUserIds = array();
        $taggableUserGroups = $this->getTaggableUserGroups();

        foreach ($taggedUsers as $taggedUserId => $taggedUserName) {
            if (is_numeric($taggedUserId)) {
                $neededUserIds[] = $taggedUserId;
            } else {
                if (strpos($taggedUserId, 'ug_') === 0) {
                    $userGroupId = substr($taggedUserId, 3);

                    if (isset($taggableUserGroups[$userGroupId])) {
                        $neededUserIds += array_keys($taggableUserGroups[$userGroupId]['userIds']);
                    }
                }
            }
        }

        if (!empty($neededUserIds)) {
            if ($options[self::OPTION_MAX_TAGGED_USERS] > 0) {
                // there are limit of maximum tagged users
                $userIds = array_slice($neededUserIds, 0, $options[self::OPTION_MAX_TAGGED_USERS]);
            } else {
                $userIds = $neededUserIds;
            }
        } else {
            $userIds = array();
        }

        if (count($userIds) > self::USERS_PER_BATCH) {
            $deferredUserIds = array_slice($userIds, self::USERS_PER_BATCH);
            $userIds = array_slice($userIds, 0, self::USERS_PER_BATCH);

            XenForo_Application::defer('bdTagMe_Deferred_NotifyUserIds', array(
                $contentType, $contentId, $contentUserId, $contentUserName, $alertAction,
                $deferredUserIds,
                $noAlertUserIds, $noEmailUserIds,
                $options
            ));
        }

        return $this->notifyUserIds($contentType, $contentId, $contentUserId, $contentUserName, $alertAction, $userIds, $noAlertUserIds, $noEmailUserIds, $userModel, $options);
    }

    public function notifyUserIds($contentType, $contentId, $contentUserId, $contentUserName, $alertAction, array $userIds, array $noAlertUserIds, array $noEmailUserIds, XenForo_Model_User $userModel, array $options)
    {
        if (!empty($userIds)) {
            $fetchOptions = array();
            if (!empty($options['users']['fetchOptions'])) {
                $fetchOptions = $options['users']['fetchOptions'];
            }
            if (empty($fetchOptions['join'])) {
                $fetchOptions['join'] = 0;
            }
            $fetchOptions['join'] |= XenForo_Model_User::FETCH_USER_OPTION;
            $fetchOptions['join'] |= XenForo_Model_User::FETCH_USER_PROFILE;

            $users = $userModel->getUsersByIds($userIds, $fetchOptions);
        } else {
            $users = array();
        }

        foreach ($users as $user) {
            if ($user['user_id'] == $contentUserId) {
                // it's stupid to notify one's self
                continue;
            }

            if (!empty($options[self::OPTION_USER_CALLBACK])) {
                $userCallbackResult = call_user_func($options[self::OPTION_USER_CALLBACK], $userModel, $user, $options);
                if ($userCallbackResult === false) {
                    // user callback returns false, do not continue
                    continue;
                }
            }

            if (!$userModel->isUserIgnored($user, $contentUserId)) {
                $shouldAlert = true;
                if (!XenForo_Model_Alert::userReceivesAlert($user, $contentType, $alertAction)) {
                    $shouldAlert = false;
                }
                if (in_array($user['user_id'], $noAlertUserIds) || in_array($user['user_id'], $noEmailUserIds)) {
                    $shouldAlert = false;
                }

                if ($shouldAlert) {
                    XenForo_Model_Alert::alert($user['user_id'], $contentUserId, $contentUserName, $contentType, $contentId, $alertAction);
                }

                if (bdTagMe_Option::get('alertEmail') && !empty($user['bdtagme_email'])) {
                    $shouldEmail = true;
                    if (in_array($user['user_id'], $noEmailUserIds)) {
                        $shouldEmail = false;
                    }

                    if ($shouldEmail) {
                        /** @var bdTagMe_XenForo_Model_Alert $alertModel */
                        $alertModel = $userModel->getModelFromCache('XenForo_Model_Alert');

                        $viewLink = $alertModel->bdTagMe_getContentLink($contentType, $contentId);

                        if (!empty($viewLink)) {
                            $mail = XenForo_Mail::create('bdtagme_tagged', array(
                                'sender' => array(
                                    'user_id' => $contentUserId,
                                    'username' => $contentUserName
                                ),
                                'receiver' => $user,
                                'contentType' => $contentType,
                                'contentId' => $contentId,
                                'viewLink' => $viewLink,
                            ), $user['language_id']);

                            $mail->enableAllLanguagePreCache();
                            $mail->queue($user['email'], $user['username']);
                        }
                    }
                }
            }
        }

        return true;
    }

    public function getTaggableUserGroups()
    {
        $userGroups = XenForo_Application::getSimpleCacheData(self::SIMPLE_CACHE_KEY_TAGGABLE_USER_GROUPS);
        if (empty($userGroups)) {
            $userGroups = array();
        }

        return $userGroups;
    }

    public function setTaggableUserGroup(array $userGroup, $isTaggable, XenForo_DataWriter_UserGroup $dw)
    {
        $taggableUserGroups = $this->getTaggableUserGroups();
        $isChanged = false;

        if ($isTaggable) {
            // get users and update into the taggable list
            /** @var bdTagMe_XenForo_Model_User $userModel */
            $userModel = $dw->getModelFromCache('XenForo_Model_User');

            $taggableUserGroups[$userGroup['user_group_id']] = array(
                'user_group_id' => $userGroup['user_group_id'],
                'title' => $userGroup['title'],
                'userIds' => $userModel->bdTagMe_getUserIdsByUserGroupId($userGroup['user_group_id']),
            );
            ksort($taggableUserGroups);
            $isChanged = true;
        } else {
            // unset this user group if needed
            foreach (array_keys($taggableUserGroups) as $taggableUserGroupId) {
                if ($taggableUserGroupId == $userGroup['user_group_id']) {
                    unset($taggableUserGroups[$taggableUserGroupId]);
                    $isChanged = true;
                }
            }
        }

        if ($isChanged) {
            XenForo_Application::setSimpleCacheData(self::SIMPLE_CACHE_KEY_TAGGABLE_USER_GROUPS, $taggableUserGroups);
        }
    }

    public function updateTaggableUserGroups(array $userGroupIds, XenForo_DataWriter_User $dw)
    {
        $taggableUserGroups = $this->getTaggableUserGroups();
        $isChanged = false;

        /** @var bdTagMe_XenForo_Model_User $userModel */
        $userModel = $dw->getModelFromCache('XenForo_Model_User');

        foreach ($taggableUserGroups as &$taggableUserGroup) {
            if (in_array($taggableUserGroup['user_group_id'], $userGroupIds)) {
                // this user group need to be updated
                $taggableUserGroup['userIds'] = $userModel->bdTagMe_getUserIdsByUserGroupId($taggableUserGroup['user_group_id']);
                $isChanged = true;
            }
        }

        if ($isChanged) {
            XenForo_Application::setSimpleCacheData(self::SIMPLE_CACHE_KEY_TAGGABLE_USER_GROUPS, $taggableUserGroups);
        }
    }

    /**
     * @return bdTagMe_Engine
     */
    public static function getInstance()
    {
        static $instance = false;

        if ($instance === false) {
            $instance = new bdTagMe_Engine();
            // TODO: support code event listeners?
        }

        return $instance;
    }

    private function __construct()
    {
    }

    private function __clone()
    {
    }

}

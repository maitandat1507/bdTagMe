<?php

class bdTagMe_XenForo_Model_User extends XFCP_bdTagMe_XenForo_Model_User {
	
	public function bdTagMe_getUserIdsByUserGroupId($userGroupId) {
		return $this->_getDb()->fetchCol(
			'SELECT user_id
			FROM xf_user
			WHERE user_group_id = ? OR FIND_IN_SET(?, secondary_group_ids)',
			array($userGroupId, $userGroupId)
		);
	}
	
}
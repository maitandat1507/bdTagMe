<?php
class bdTagMe_Installer {
	/* Start auto-generated lines of code. Change made will be overwriten... */

	protected static $_tables = array();
	protected static $_patches = array(
		array(
			'table' => 'xf_user_option',
			'field' => 'bdtagme_email',
			'showTablesQuery' => 'SHOW TABLES LIKE \'xf_user_option\'',
			'showColumnsQuery' => 'SHOW COLUMNS FROM `xf_user_option` LIKE \'bdtagme_email\'',
			'alterTableAddColumnQuery' => 'ALTER TABLE `xf_user_option` ADD COLUMN `bdtagme_email` INT(10) UNSIGNED NOT NULL DEFAULT \'0\'',
			'alterTableDropColumnQuery' => 'ALTER TABLE `xf_user_option` DROP COLUMN `bdtagme_email`',
		),
	);

	public static function install($existingAddOn, $addOnData)
	{
		$db = XenForo_Application::get('db');

		foreach (self::$_tables as $table)
		{
			$db->query($table['createQuery']);
		}

		foreach (self::$_patches as $patch)
		{
			$tableExisted = $db->fetchOne($patch['showTablesQuery']);
			if (empty($tableExisted))
			{
				continue;
			}

			$existed = $db->fetchOne($patch['showColumnsQuery']);
			if (empty($existed))
			{
				$db->query($patch['alterTableAddColumnQuery']);
			}
		}
		
		self::installCustomized($existingAddOn, $addOnData);
	}

	public static function uninstall()
	{
		$db = XenForo_Application::get('db');

		foreach (self::$_patches as $patch)
		{
			$tableExisted = $db->fetchOne($patch['showTablesQuery']);
			if (empty($tableExisted))
			{
				continue;
			}

			$existed = $db->fetchOne($patch['showColumnsQuery']);
			if (!empty($existed))
			{
				$db->query($patch['alterTableDropColumnQuery']);
			}
		}

		foreach (self::$_tables as $table)
		{
			$db->query($table['dropQuery']);
		}

		self::uninstallCustomized();
	}

	/* End auto-generated lines of code. Feel free to make changes below */
	
	private static function installCustomized($existingAddOn, $addOnData) {
		$db = XenForo_Application::getDb();

		if (empty($existingAddOn))
		{
			$db->query("
				INSERT IGNORE INTO xf_permission_entry
					(user_group_id, user_id, permission_group_id, permission_id, permission_value, permission_value_int)
				SELECT user_group_id, user_id, 'general', 'bdtagme_groupTag', permission_value, 0
				FROM xf_permission_entry
				WHERE permission_group_id = 'general' AND permission_id = 'cleanSpam'
			");
		}
		
		if (XenForo_Application::$versionId > 1020000)
		{
			$db->query("UPDATE `xf_user_alert` SET action = 'tag' WHERE content_type = 'post' AND action = 'tagged'");
		}
	}
	
	private static function uninstallCustomized() {
		// customized uninstall script goes here
	}
	
}
<?php
namespace Craft;

/**
 * The class name is the UTC timestamp in the format of mYYMMDD_HHMMSS_migrationName
 */
class m150210_000001_adjust_user_photo_size extends BaseMigration
{
	/**
	 * Any migration code in here is wrapped inside of a transaction.
	 *
	 * @return bool
	 */
	public function safeUp()
	{
		Craft::log('Altering craft_users photo column to be varchar(100)...', LogLevel::Info, true);

		$column = array(AttributeType::String, 'maxLength' => 100);
		$this->alterColumn('users', 'weekStartDay', $column);

		Craft::log('Done altering craft_users photo column to be varchar(100)...', LogLevel::Info, true);

		return true;
	}
}

<?php

/**
 * The MetaModels extension allows the creation of multiple collections of custom items,
 * each with its own unique set of selectable attributes, with attribute extendability.
 * The Front-End modules allow you to build powerful listing and filtering of the
 * data in each collection.
 *
 * PHP version 5
 * @package     MetaModels
 * @subpackage  AttributeTranslatedSelect
 * @author      Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @copyright   The MetaModels team.
 * @license     LGPL.
 * @filesource
 */

/**
 * This is the MetaModelAttribute class for handling translated select attributes.
 *
 * @package	   MetaModels
 * @subpackage AttributeTranslatedSelect
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 */
class MetaModelAttributeTranslatedSelect extends MetaModelAttributeSelect implements IMetaModelAttributeTranslated
{
	/////////////////////////////////////////////////////////////////
	// interface IMetaModelAttribute
	/////////////////////////////////////////////////////////////////

	public function getAttributeSettingNames()
	{
		return array_merge(parent::getAttributeSettingNames(), array(
			'select_langcolumn'
		));
	}

	/**
	 * {@inheritdoc}
	 *
	 * Fetch filter options from foreign table.
	 *
	 */
	public function getFilterOptions($arrIds, $usedOnly)
	{
		if (($arrIds !== NULL) && empty($arrIds))
		{
			return array();
		}

		$strTableName = $this->get('select_table');
		$strColNameId = $this->get('select_id');
		$strColNameLang = $this->get('select_langcolumn');
		$strColNameWhere = ($this->get('select_where') ? html_entity_decode($this->get('select_where')) : false);
		$strLangSet = sprintf('\'%s\',\'%s\'', $this->getMetaModel()->getActiveLanguage(), $this->getMetaModel()->getFallbackLanguage());

		$arrReturn = array();

		if ($strTableName && $strColNameId)
		{
			$strColNameValue = $this->get('select_column');
			$strColNameAlias = $this->get('select_alias');
			if (!$strColNameAlias)
			{
				$strColNameAlias = $strColNameId;
			}
			$objDB = Database::getInstance();
			if ($arrIds)
			{
				$objValue = $objDB->prepare(sprintf('
					SELECT %1$s.*
					FROM %1$s
					RIGHT JOIN %3$s ON (%3$s.%4$s=%1$s.%2$s)
					WHERE %3$s.id IN (%5$s) %6$s
					AND %7$s IN (%8$s)
					GROUP BY %1$s.%2$s',
					$strTableName, // 1
					$strColNameId, // 2
					$this->getMetaModel()->getTableName(), // 3
					$this->getColName(), // 4
					implode(',', $arrIds), // 5
					($strColNameWhere ? ' AND ('.$strColNameWhere.')' : ''), //6
					$strColNameLang, // 7
					$strLangSet // 8
				))
				->execute($this->get('id'));
			} else {
				if ($usedOnly)
				{
					$strQuery = sprintf('SELECT %1$s.*
					FROM %1$s
					RIGHT JOIN %3$s ON (%3$s.%4$s=%1$s.%2$s)
					WHERE %5$s IN (%6$s) %7$s
					GROUP BY %1$s.%2$s',
					$strTableName,
					$strColNameId, // 2
					$this->getMetaModel()->getTableName(), // 3
					$this->getColName(), // 4
					$strColNameLang, // 5
					$strLangSet, // 6
					($strColNameWhere ? ' AND ('.$strColNameWhere.')' : '') //7
					);
				} else {
					$strQuery = sprintf('SELECT %1$s.*
					FROM %1$s
					WHERE %3$s IN (%4$s) %5$s
					GROUP BY %1$s.%2$s
					',
					$strTableName, // 1
					$strColNameId, // 2
					$strColNameLang, // 3
					$strLangSet, // 4
					($strColNameWhere ? ' AND ('.$strColNameWhere.')' : '') //5
					);
				}
				$objValue = $objDB->prepare($strQuery)
				->execute();
			}

			while ($objValue->next())
			{
				$arrReturn[$objValue->$strColNameAlias] = $objValue->$strColNameValue;
			}
		}
		return $arrReturn;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Search the attribute in the current language.
	 */
	public function searchFor($strPattern)
	{
		return $this->searchForInLanguages($strPattern, array($this->getMetaModel()->getActiveLanguage()));
	}

	/////////////////////////////////////////////////////////////////
	// interface IMetaModelAttributeComplex
	/////////////////////////////////////////////////////////////////

	public function getDataFor($arrIds)
	{
		$strActiveLanguage = $this->getMetaModel()->getActiveLanguage();
		$strFallbackLanguage = $this->getMetaModel()->getFallbackLanguage();

		$arrReturn = $this->getTranslatedDataFor($arrIds, $strActiveLanguage);

		// second round, fetch fallback languages if not all items could be resolved.
		if ((count($arrReturn) < count($arrIds)) && ($strActiveLanguage != $strFallbackLanguage))
		{
			$arrFallbackIds = array();
			foreach ($arrIds as $intId)
			{
				if (empty($arrReturn[$intId]))
				{
					$arrFallbackIds[] = $intId;
				}
			}

			if ($arrFallbackIds)
			{
				$arrFallbackData = $this->getTranslatedDataFor($arrFallbackIds, $strFallbackLanguage);
				// cannot use array_merge here as it would renumber the keys.
				foreach ($arrFallbackData as $intId => $arrValue)
				{
					$arrReturn[$intId] = $arrValue;
				}
			}
		}
		return $arrReturn;
	}

	public function setDataFor($arrValues)
	{
		$this->setTranslatedDataFor($arrValues, $this->getMetaNodel()->getActiveLanguage());
	}

	/////////////////////////////////////////////////////////////////
	// interface IMetaModelAttributeTranslated
	/////////////////////////////////////////////////////////////////

	/**
	 * {@inheritdoc}
	 *
	 * Search the attribute in the given languages.
	 */
	public function searchForInLanguages($strPattern, $arrLanguages = array())
	{
		$objDB = Database::getInstance();
		$strTableNameId = $this->get('select_table');
		$strColNameId = $this->get('select_id');
		$strColNameLangCode = $this->get('select_langcolumn');
		$strColValue = $this->get('select_column');
		$strColAlias = $this->get('select_alias') ? $this->get('select_alias') : $strColNameId;
		$arrReturn = array();

		if ($strTableNameId && $strColNameId)
		{
			$strMetaModelTableName = $this->getMetaModel()->getTableName();
			$strMetaModelTableNameId = $strMetaModelTableName.'_id';

			$strPattern = str_replace(array('*', '?'), array('%', '_'), $strPattern);

			// using aliased join here to resolve issue #3 for normal select attributes (SQL error for self referencing table)
			$objValue = $objDB->prepare(sprintf('SELECT sourceTable.*, %2$s.id AS %3$s FROM %1$s sourceTable RIGHT JOIN %2$s ON (sourceTable.%4$s=%2$s.%5$s) WHERE '.($arrLanguages ? '(sourceTable.%6$s IN (%7$s))' : '').' AND (sourceTable.%8$s LIKE ? OR sourceTable.%9$s LIKE ?)',
				$strTableNameId, // 1
				$strMetaModelTableName, // 2
				$strMetaModelTableNameId, // 3
				$strColNameId, // 4
				$this->getColName(), // 5
				$strColNameLangCode, // 6
				'\'' . implode('\',\'', $arrLanguages) . '\'', // 7
				$strColValue, // 8
				$strColAlias // 9
			))
			->execute($strPattern, $strPattern);
			while ($objValue->next())
			{
				$arrReturn[] = $objValue->$strMetaModelTableNameId;
			}
		}
		return $arrReturn;
	}


	public function setTranslatedDataFor($arrValues, $strLangCode)
	{
		$strMetaModelTableName = $this->getMetaModel()->getTableName();
		$strTableName = $this->get('select_table');
		$strColNameId = $this->get('select_id');
		$arrReturn = array();

		if ($strTableName && $strColNameId)
		{
			$strColNameValue = $this->get('select_column');
			$objDB = Database::getInstance();
			$strQuery = sprintf('UPDATE %1$s SET %2$s=? WHERE %1$s.id=?', $strMetaModelTableName, $this->getColName());
			foreach ($arrValues as $intItemId => $arrValue)
			{
				$objQuery = $objDB->prepare($strQuery)->execute($arrValue[$strColNameId], $intItemId);
			}
		}
	}

	/**
	 * Get values for the given items in a certain language.
	 */
	public function getTranslatedDataFor($arrIds, $strLangCode)
	{
		$objDB = Database::getInstance();
		$strTableNameId = $this->get('select_table');
		$strColNameId = $this->get('select_id');
		$strColNameLangCode = $this->get('select_langcolumn');
		$arrReturn = array();

		if ($strTableNameId && $strColNameId)
		{
			$strMetaModelTableName = $this->getMetaModel()->getTableName();
			$strMetaModelTableNameId = $strMetaModelTableName.'_id';

			// using aliased join here to resolve issue #3 for normal select attributes (SQL error for self referencing table)
			$objValue = $objDB->prepare(sprintf('SELECT sourceTable.*, %2$s.id AS %3$s FROM %1$s sourceTable LEFT JOIN %2$s ON ((sourceTable.%7$s=?) AND (sourceTable.%4$s=%2$s.%5$s)) WHERE %2$s.id IN (%6$s)',
				$strTableNameId, // 1
				$strMetaModelTableName, // 2
				$strMetaModelTableNameId, // 3
				$strColNameId, // 4
				$this->getColName(), // 5
				implode(',', $arrIds), //6
				$strColNameLangCode // 7
			))
			->execute($strLangCode);
			while ($objValue->next())
			{
				$arrReturn[$objValue->$strMetaModelTableNameId] = $objValue->row();
			}
		}
		return $arrReturn;
	}

	/**
	 * Remove values for items in a certain lanugage.
	 */
	public function unsetValueFor($arrIds, $strLangCode)
	{
		// FIXME: unimplemented
		throw new Exception('MetaModelAttributeTags::unsetValueFor() is not yet implemented, please do it or find someone who can!', 1);
	}
}
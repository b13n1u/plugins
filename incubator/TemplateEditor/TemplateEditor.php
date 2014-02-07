<?php
/**
 * i-MSCP TemplateEditor plugin
 * Copyright (C) 2014 Laurent Declercq <l.declercq@nuxwin.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 */

/**
 * Class iMSCP_Plugin_TemplateEditor
 */
class iMSCP_Plugin_TemplateEditor extends iMSCP_Plugin_Action
{
	/**
	 * Register a callback for the given event(s).
	 *
	 * @param iMSCP_Events_Manager_Interface $eventsManager
	 */
	public function register(iMSCP_Events_Manager_Interface $eventManager)
	{
		$eventManager->registerListener(
			array(
				iMSCP_Events::onBeforeInstallPlugin,
				iMSCP_Events::onBeforeUpdatePlugin,
				iMSCP_Events::onBeforeEnablePlugin,
				IMSCP_Events::onAdminScriptStart
			),
			$this
		);
	}

	/**
	 * onBeforeInstallPlugin event listener
	 *
	 * @param iMSCP_Events_Event $event
	 */
	public function onBeforeInstallPlugin($event)
	{
		if ($event->getParam('pluginName') == $this->getName()) {
			if (version_compare($event->getParam('pluginManager')->getPluginApiVersion(), '0.2.5', '<')) {
				set_page_message(
					tr('Your i-MSCP version is not compatible with this plugin. Try with a newer version.'), 'error'
				);

				$event->stopPropagation();
			}
		}
	}

	/**
	 * Plugin installation
	 *
	 * @throws iMSCP_Plugin_Exception
	 * @param iMSCP_Plugin_Manager $pluginManager
	 * @return void
	 */
	public function install(iMSCP_Plugin_Manager $pluginManager)
	{
		try {
			$this->setupDbTables($pluginManager);
			$this->syncTemplates();
		} catch (iMSCP_Exception_Database $e) {
			throw new iMSCP_Plugin_Exception(sprintf('Unable to create database schema: %s', $e->getMessage()));
		}
	}

	/**
	 * onBeforeUpdatePlugin event listener
	 *
	 * @param iMSCP_Events_Event $event
	 */
	public function onBeforeUpdatePlugin($event)
	{
		$this->onBeforeInstallPlugin($event);
	}

	/**
	 * Plugin update
	 *
	 * @throws iMSCP_Plugin_Exception
	 * @param iMSCP_Plugin_Manager $pluginManager
	 * @param string $fromVersion Version from which plugin update is initiated
	 * @param string $toVersion Version to which plugin is updated
	 * @return void
	 */
	public function update(iMSCP_Plugin_Manager $pluginManager, $fromVersion, $toVersion)
	{
		try {
			$this->setupDbTables($pluginManager);
			$this->syncTemplates();
		} catch (iMSCP_Exception_Database $e) {
			throw new iMSCP_Plugin_Exception(sprintf('Unable to update database schema: %s', $e->getMessage()));
		}
	}

	/**
	 * onBeforeEnablePlugin event listener
	 *
	 * @param iMSCP_Events_Event $event
	 */
	public function onBeforeEnablePlugin($event)
	{
		$this->onBeforeInstallPlugin($event);
	}

	/**
	 * Plugin activation
	 *
	 * This method is automatically called by the plugin manager when the plugin is being enabled (activated).
	 *
	 * @throws iMSCP_Plugin_Exception
	 * @param iMSCP_Plugin_Manager $pluginManager
	 * @return void
	 */
	public function enable(iMSCP_Plugin_Manager $pluginManager)
	{
		$this->syncTemplates();
	}

	/**
	 * Plugin uninstallation
	 *
	 * @throws iMSCP_Plugin_Exception
	 * @param iMSCP_Plugin_Manager $pluginManager
	 * @return void
	 */
	public function uninstall(iMSCP_Plugin_Manager $pluginManager)
	{
		try {
			$pluginName = $this->getName();

			execute_query('DROP TABLE IF EXISTS termplate_editor_group_admin');
			execute_query('DROP TABLE IF EXISTS template_editor_template');
			execute_query('DROP TABLE IF EXISTS template_editor_group');

			$pluginInfo = $pluginManager->getPluginInfo($pluginName);
			$pluginInfo['db_schema_version'] = '000';
			$pluginManager->updatePluginInfo($pluginName, $pluginInfo);
		} catch (iMSCP_Exception_Database $e) {
			throw new iMSCP_Plugin_Exception(sprintf('Unable to drop database table: %s', $e->getMessage()));
		}
	}

	/**
	 * Get routes
	 *
	 * @return array
	 */
	public function getRoutes()
	{
		$pluginName = $this->getName();

		return array(
			'/admin/template_editor.php' => PLUGINS_PATH . '/' . $pluginName . '/frontend/admin/template_editor.php',
		);
	}

	/**
	 * onAdminScriptStart event listener
	 *
	 * @param iMSCP_Events_Event $event
	 */
	public function onAdminScriptStart($event)
	{
		$this->setupNavigation();
	}

	/**
	 * Sync default templates with those provided by i-MSCP core
	 *
	 * @throw iMSCP_Plugin_Exception When an error occurs while syncing a group of templates
	 * @return void
	 */
	public function syncTemplates()
	{
		if($this->getConfigParam('sync_default_templates', true)) {
			$db = iMSCP_Database::getRawInstance();
			$sTs = $this->getConfigParam('service_templates', array());
			$tNs = array();
			$tGNs = array();

			if(!empty($sTs)) {
				foreach($sTs as $sTN => $tGs) {
					foreach($tGs as $tGN => $ts) {
						if(!empty($ts)) {
							$tGN = ucwords("$sTN $tGN");
							$tGNs[] = $tGN;

							try {
								$db->beginTransaction();

								exec_query(
									'INSERT IGNORE template_editor_group (group_name, group_service_name) VALUE (?,?)',
									array($tGN, $sTN)
								);

								if(!($tGI = $db->lastInsertId())) {
									$stmt = exec_query(
										'SELECT group_id FROM template_editor_group WHERE group_name = ?', $tGN
									);
									$tGI = $stmt->fields['group_id'];
								}

								foreach ($ts as $tN => $tD) {
									$tNs[] = $tN;

									if (isset($tD['path']) && is_readable($tD['path'])) {
										$tC = file_get_contents($tD['path']);

										exec_query(
											'
												REPLACE INTO template_editor_template (
													template_group_id, template_name, template_content, template_scope
												) VALUE (
													?, ?, ?, ?
												)
											',
											array($tGI, $tN, $tC, isset($tD['scope']) ? $tD['scope'] : 'system')
										);
									}
								}

								$db->commit();

							} catch(iMSCP_Exception_Database $e) {
								$db->rollBack();
								throw new iMSCP_Plugin_Exception(
									sprintf(
										'Unable to sync %s template group: %s - %s',
										$tGN, $e->getMessage(), $e->getQuery()
									),
									$e->getCode(),
									$e
								);
							}
						}
					}
				}
			}

			if(!empty($tGNs)) {
				$stGNs = implode(',', array_map('quoteValue', $tGNs));
				exec_query(
					"DELETE FROM template_editor_group WHERE group_parent_id IS NULL AND group_name NOT IN($stGNs)"
				);

				if(!empty($tNs)) {
					$tNs = implode(',', array_map('quoteValue', $tNs));
					execute_query("DELETE FROM template_editor_template WHERE template_name NOT IN($tNs)");
				}
			} else {
				exec_query("DELETE FROM template_editor_group");
			}
		}
	}

	/**
	 * Inject Links into the navigation object
	 */
	protected function setupNavigation()
	{
		if (iMSCP_Registry::isRegistered('navigation')) {
			/** @var Zend_Navigation $navigation */
			$navigation = iMSCP_Registry::get('navigation');

			if (($page = $navigation->findOneBy('uri', '/admin/settings.php'))) {
				$page->addPage(
					array(
						'label' => tr('Template Editor'),
						'uri' => '/admin/template_editor.php',
						'title_class' => 'settings',
						'order' => 7
					)
				);
			}
		}
	}

	/**
	 * Setup database tables
	 *
	 * @param iMSCP_Plugin_Manager $pluginManager
	 * @throws iMSCP_Plugin_Exception
	 */
	protected function setupDbTables(iMSCP_Plugin_Manager $pluginManager)
	{
		$pluginName = $this->getName();

		$pluginInfo = $pluginManager->getPluginInfo($pluginName);
		$dbSchemaVersion = (isset($pluginInfo['db_version'])) ? $pluginInfo['db_schema_version'] : '000';

		$sqlFiles = array();

		/** @var $fileInfo DirectoryIterator */
		foreach (new DirectoryIterator(dirname(__FILE__) . '/data') as $fileInfo) {
			if (!$fileInfo->isDot()) {
				$sqlFiles[] = $fileInfo->getRealPath();
			}
		}

		sort($sqlFiles, SORT_NATURAL | SORT_FLAG_CASE);

		foreach ($sqlFiles as $sqlFile) {
			if (preg_match('%([^/]+)\.sql$%', $sqlFile, $match) && $match[1] > $dbSchemaVersion) {
				$sqlFileContent = file_get_contents($sqlFile);
				execute_query($sqlFileContent);
				$dbSchemaVersion = $match[1];
			}
		}

		$pluginInfo['db_schema_version'] = $dbSchemaVersion;
		$pluginManager->updatePluginInfo($pluginName, $pluginInfo);
	}
}
<?php
namespace Etg24\Cleanup\Command;

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Cli\CommandController;
use TYPO3\Flow\Utility\Files;

/**
 * Command controller for garbage collection of ResourcePointers and associated physical files
 *
 * @Flow\Scope("singleton")
 */
class GarbageCommandController extends CommandController {

	/**
	 * @var \Etg24\Admin\Utility\CliUtility
	 * @Flow\inject
	 */
	protected $cliUtility;

	/**
	 * @Flow\Inject
	 * @var \Doctrine\Common\Persistence\ObjectManager
	 */
	protected $entityManager;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Persistence\PersistenceManagerInterface
	 */
	protected $persistenceManager;

	/**
	 * Runs garbage collection for the ResourcePointers and their associated
	 * 
	 * @return void
	 */
	public function collectCommand() {
		$connection = $this->entityManager->getConnection();
		$path = FLOW_PATH_DATA . "/Persistent/Resources/";

		// Find and delete all orphaned ResourcePointers
		$stmt = $connection->executeQuery(
			'SELECT `hash` ' .
			'FROM `typo3_flow_resource_resourcepointer` AS `rp` ' .
			'LEFT OUTER JOIN `typo3_flow_resource_resource` AS `r` ON `r`.`resourcepointer` = `rp`.`hash` ' .
			'WHERE `r`.`persistence_object_identifier` IS NULL '
		);

		foreach($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
			$hash = $row["hash"];

			$this->outputLine("Found orphaned ResourcePointer: %s", array($hash));

			// Remove ResoursePointer from database
			$result = $connection->executeUpdate(
				'DELETE FROM `typo3_flow_resource_resourcepointer` ' .
				'WHERE `hash` = "' . $hash . '"'
			);

			if ($result) {
				$this->outputLine("...deleted entity");
			} else {
				$this->outputLine("...COULD NOT DELETE ENTITY!");
			}
			
			// Remove physical file
			if (file_exists($path . $hash)) {
				if (unlink($path . $hash)) {
					$this->outputLine("...deleted physical file");
				} else {
					$this->outputLine("...COULD NOT DELETE PHYSICAL FILE!");
				}
			} else {
				$this->outputLine("...physical file already deleted");
			}
		}

		// Find and delete all orphaned physical files
		$files = glob($path . "*", GLOB_NOSORT);
		$files = array_map(function($file) use ($path) { return str_replace($path, "", $file); }, $files);

		// Create temporary table in memory and fill it with file hashes
		$connection->executeUpdate(
			'CREATE TEMPORARY TABLE `etg24_cleanup_command_garbage_files` (`hash` CHAR(40)) ENGINE=MEMORY'
		);

		$connection->executeUpdate(
			'INSERT INTO `etg24_cleanup_command_garbage_files` (`hash`) VALUES ("' . implode('"), ("', $files) . '")'
		);

		$stmt = $connection->executeQuery(
			'SELECT `f`.`hash` ' .
			'FROM `etg24_cleanup_command_garbage_files` AS `f` ' .
			'LEFT OUTER JOIN `typo3_flow_resource_resourcepointer` AS `rp` USING(`hash`) ' .
			'WHERE `rp`.`hash` IS NULL '
		);
		
		foreach($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
			$hash = $row["hash"];

			$this->outputLine("Found orphaned physical file: %s", array($hash));

			// Remove physical file
			if (file_exists($path . $hash)) {
				if (unlink($path . $hash)) {
					$this->outputLine("...deleted physical file");
				} else {
					$this->outputLine("...COULD NOT DELETE PHYSICAL FILE!");
				}
			} else {
				$this->outputLine("...physical file already deleted");
			}
		}
	}

}

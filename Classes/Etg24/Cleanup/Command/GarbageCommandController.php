<?php
namespace Etg24\Cleanup\Command;

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Cli\CommandController;

/**
 * Command controller for garbage collection
 *
 * @Flow\Scope("singleton")
 */
class GarbageCommandController extends CommandController {

	/**
	 * @Flow\Inject
	 * @var \Doctrine\Common\Persistence\ObjectManager
	 */
	protected $entityManager;

	/**
	 * Runs garbage collection for the ResourcePointers and their associated physical files
	 * 
	 * @return void
	 */
	public function collectResourcePointersCommand() {
		$connection = $this->entityManager->getConnection();
		$path = FLOW_PATH_DATA . "Persistent/Resources/";

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
		$files = scandir($path);
		$files = array_filter($files, function($elem){ return preg_match('/^[0-9a-f]+$/', $elem); });

		// Create temporary table in memory and fill it with physical file hashes
		$connection->executeUpdate(
			'CREATE TEMPORARY TABLE `etg24_cleanup_command_garbage_files` (`hash` CHAR(40)) ENGINE=MEMORY'
		);

		if(!empty($files)) {
			$connection->executeUpdate(
				'INSERT INTO `etg24_cleanup_command_garbage_files` (`hash`) VALUES ("' . implode('"), ("', $files) . '")'
			);
		}

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
			if (unlink($path . $hash)) {
				$this->outputLine("...deleted physical file");
			} else {
				$this->outputLine("...COULD NOT DELETE PHYSICAL FILE!");
			}
		}
	}

}

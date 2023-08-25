<?php
namespace GDA;

/**
 * Standard model for generic data access.
 * This is the base interface for the generic database access framework.
 * 
 * For a particular application, this interface should be extended 
 * to add further methods that are necessary for this application concerning database access.
 * 
 * @author Matthias Kolonko
 *
 */
interface GenericDataIF {
  
  /**
   * @return string The class name of the entity type like e.g. SqlEntity
   */
  function getHandlingUnit(): string;
  
  /* Transaction handling */
  /**
   * Starts a transaction in the DBS if necessary
   */
  function beginTransaction(): void;
  /**
   * Makes a commit in the DBS for a started transaction.
   */
  function commit(): void;
  /**
   * Makes a rollback in the DBS for a started transaction.
   */
  function rollback(): void;
  
  /* General select function */
  
  /**
   * Select the entity in the database with the given ID values.
   *
   * @param Entity $e a template with the ID values
   * @return Entity instance corresponding to given ID values in template or NULL if nothing was found.
   * @throws \Exception if the ID values have not been properly set or if more than one row has been found.
   */
  function getById(Entity $e): ?Entity;
  
  /**
   * Select an array of instances of an Entity class by providing a template instance with the values to search for.
   *
   * @param Entity $t      the template entity. The values set in this instance are used for the search.
   * @param array  $order  Array containing field names as keys and boolean values. The boolean signals whether the sort shall be ascending (true) or descending (false).
   * @return array array with the elements that have been found. The entries are of the same type as the given template $t.
   */
  function getByTemplate(Entity $t, array $order=null): array;
  
  /**
   * Counts the entries that match the given template.
   * 
   * @param Entity $t
   * @return int
   */
  function countByTemplate(Entity $t): int;
  
  /**
   * Checks if elements matching to the given template exist in the database.
   * 
   * @param Entity $t  a template entity. The values set in this instance are used for the search.
   * @return bool  true if any elements exist, otherwise false.
   */
  function checkExistenceByTemplate(Entity $t): bool;
  
  /**
   * Inserts an Entity to the database.
   *
   * @param Entity $e
   * @return Entity amended with the ID of the inserted Entity
   */
  function insert(Entity $e): Entity;
  
  /* Update Functions */
  
  /**
   * Update the given Entity
   *
   * @param Entity $e
   * @return int if possible the number of affected elements, otherwise NULL
   */
  function update(Entity $e): bool;
  
  /* Deleting Functions */
  
  /**
   * Delete a certain entity.
   * This method throws an exception if not all necessary key fields are set in the given Entity instance.
   *
   * @param Entity $e
   * @return bool indicating that entries got deleted.
   */
  function delete(Entity $e): bool;
  
  /**
   * Delete all entries of an Entity in the database that match the given template instance of that Entity.
   *
   * @param Entity $t
   * @return bool indicating that entries got deleted.
   */
  function deleteByTemplate(Entity $t): bool;
      
}

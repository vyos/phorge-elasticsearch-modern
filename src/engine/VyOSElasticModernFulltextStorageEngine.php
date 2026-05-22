<?php

/**
 * Placeholder for the engine class. Keep this abstract until E7/E8 land so
 * it is not auto-discovered as a selectable backend before it can operate.
 */
abstract class VyOSElasticModernFulltextStorageEngine
  extends PhabricatorFulltextStorageEngine {

  public function getEngineIdentifier() {
    return 'elasticsearch-modern';
  }

  public function getHostType() {
    return new VyOSElasticModernHost($this);
  }

  public function reindexAbstractDocument(
    PhabricatorSearchAbstractDocument $doc) {
    throw new Exception(pht(
      'reindexAbstractDocument() is not yet implemented on '.
      'VyOSElasticModernFulltextStorageEngine; lands in Task E7.'));
  }

  public function executeSearch(PhabricatorSavedQuery $query) {
    throw new Exception(pht(
      'executeSearch() is not yet implemented; lands in Task E8.'));
  }

  public function indexExists() {
    throw new Exception(pht(
      'indexExists() is not yet implemented; lands in Task E8.'));
  }

  public function getIndexStats() {
    throw new Exception(pht(
      'getIndexStats() is not yet implemented; lands in Task E8.'));
  }

}

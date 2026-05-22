<?php

/**
 * Placeholder for the engine class. Real implementation grows in Phase 3-4.
 * Tasks E1-E8 replace the throwing stubs below with working code.
 */
class VyOSElasticModernFulltextStorageEngine
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

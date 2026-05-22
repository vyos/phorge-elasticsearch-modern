<?php

/**
 * Minimal concrete subclass for unit-testing the abstract engine.
 * Only the abstract stubs from PhabricatorFulltextStorageEngine are
 * satisfied here; no real Elasticsearch I/O occurs in tests.
 */
final class VyOSElasticModernFulltextStorageEngineTestDouble
  extends VyOSElasticModernFulltextStorageEngine {

  public function reindexAbstractDocument(
    PhabricatorSearchAbstractDocument $doc) {
    // no-op in tests
  }

  public function executeSearch(PhabricatorSavedQuery $query) {
    return array();
  }

  public function indexExists() {
    return false;
  }

  public function getIndexStats() {
    return array();
  }

}

final class VyOSElasticModernFulltextStorageEngineTestCase
  extends PhutilTestCase {

  private function newEngine() {
    return new VyOSElasticModernFulltextStorageEngineTestDouble();
  }

  public function testSetVersionAcceptsSevenAndAbove() {
    foreach (array(7, 8, 9, 100) as $v) {
      $engine = $this->newEngine();
      $caught = null;
      try {
        $engine->setVersion($v);
      } catch (Exception $e) {
        $caught = $e;
      }
      $this->assertEqual(
        null,
        $caught,
        pht('Expected no exception for version=%d.', $v));
      $this->assertEqual($v, $engine->getVersion());
    }
  }

  public function testSetVersionRejectsBelowSeven() {
    foreach (array(0, 1, 2, 5, 6) as $v) {
      $engine = $this->newEngine();
      $caught = null;
      try {
        $engine->setVersion($v);
      } catch (Exception $e) {
        $caught = $e;
      }
      $this->assertTrue(
        $caught !== null,
        pht('Expected an exception for version=%d.', $v));
    }
  }

  public function testGetDocumentUri() {
    $engine = id(new VyOSElasticModernFulltextStorageEngine())
      ->setVersion(7);
    $uri = $engine->getDocumentUri('TASK', 'PHID-TASK-abc');
    $this->assertEqual('/_doc/PHID-TASK-abc', $uri);
  }

  public function testGetDocumentUriIgnoresType() {
    // The typeless API does not encode the doc type in the URL.
    $engine = id(new VyOSElasticModernFulltextStorageEngine())
      ->setVersion(7);
    $uri_a = $engine->getDocumentUri('TASK', 'PHID-TASK-abc');
    $uri_b = $engine->getDocumentUri('DREV', 'PHID-TASK-abc');
    $this->assertEqual($uri_a, $uri_b);
  }

}

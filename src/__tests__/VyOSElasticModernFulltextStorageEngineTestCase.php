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
    $engine = $this->newEngine()->setVersion(7);
    $uri = $engine->getDocumentUri('TASK', 'PHID-TASK-abc');
    $this->assertEqual('/_doc/PHID-TASK-abc', $uri);
  }

  public function testGetDocumentUriIgnoresType() {
    // The typeless API does not encode the doc type in the URL.
    $engine = $this->newEngine()->setVersion(7);
    $uri_a = $engine->getDocumentUri('TASK', 'PHID-TASK-abc');
    $uri_b = $engine->getDocumentUri('DREV', 'PHID-TASK-abc');
    $this->assertEqual($uri_a, $uri_b);
  }

  public function testGetSearchUri() {
    $engine = $this->newEngine()->setVersion(7);
    $this->assertEqual(
      '/_search',
      $engine->getSearchUri(array('TASK', 'DREV')));
    $this->assertEqual('/_search', $engine->getSearchUri(array('USER')));
    $this->assertEqual('/_search', $engine->getSearchUri(array()));
  }

  public function testBuildTypeFilter() {
    $engine = $this->newEngine()->setVersion(7);
    $filter = $engine->buildTypeFilter(array('TASK', 'DREV'));
    $expected = array(
      'terms' => array(
        'documentType' => array('TASK', 'DREV'),
      ),
    );
    $this->assertEqual($expected, $filter);
  }

  public function testBuildTypeFilterEmpty() {
    // Empty list still produces a filter; the caller is responsible
    // for normalizing "no types specified" to "all indexable types"
    // before calling this method. This matches the bundled engine's
    // pattern (executeSearch() normalizes before URL construction).
    $engine = $this->newEngine()->setVersion(7);
    $filter = $engine->buildTypeFilter(array());
    $this->assertEqual(
      array('terms' => array('documentType' => array())),
      $filter);
  }

  public function testBuildIndexMappingsShape() {
    $engine = $this->newEngine()->setVersion(7);

    $doc_types = array('TASK', 'DREV');
    $fields = array('title', 'body', 'comment');
    $relationships = array('authorPHID', 'projectPHID');
    $mappings = $engine->buildIndexMappings(
      $doc_types, $fields, $relationships, 'text');

    // Single typeless mapping with one 'properties' block.
    $this->assertTrue(isset($mappings['properties']));
    $this->assertFalse(isset($mappings['TASK']));
    $this->assertFalse(isset($mappings['DREV']));

    // All three text fields have the multi-analyzer shape.
    foreach (array('title', 'body', 'comment') as $field) {
      $this->assertTrue(
        isset($mappings['properties'][$field]),
        pht('Field "%s" missing from mappings.', $field));
      $this->assertEqual(
        'text',
        $mappings['properties'][$field]['type'],
        pht('Field "%s" should be type text.', $field));
      $this->assertTrue(
        isset($mappings['properties'][$field]['fields']['raw']),
        pht('Field "%s" missing raw sub-field.', $field));
      $this->assertTrue(
        isset($mappings['properties'][$field]['fields']['keywords']),
        pht('Field "%s" missing keywords sub-field.', $field));
      $this->assertTrue(
        isset($mappings['properties'][$field]['fields']['stems']),
        pht('Field "%s" missing stems sub-field.', $field));
    }

    // Both relationships emit as keyword fields with doc_values:false.
    foreach (array('authorPHID', 'projectPHID') as $rel) {
      $this->assertEqual(
        'keyword',
        $mappings['properties'][$rel]['type'],
        pht('Relationship "%s" should be keyword type.', $rel));
      $this->assertEqual(
        false,
        $mappings['properties'][$rel]['doc_values'],
        pht('Relationship "%s" should have doc_values:false.', $rel));
      $this->assertEqual(
        'date',
        $mappings['properties'][$rel.'_ts']['type'],
        pht('Relationship "%s" missing timestamp field.', $rel));
      // No include_in_all anywhere.
      $this->assertFalse(
        isset($mappings['properties'][$rel]['include_in_all']),
        pht('Relationship "%s" should not have include_in_all.', $rel));
    }

    // documentType is a keyword field inside properties.
    $this->assertEqual(
      'keyword',
      $mappings['properties']['documentType']['type']);

    // Standard date fields present.
    $this->assertEqual(
      'date',
      $mappings['properties']['dateCreated']['type']);
    $this->assertEqual(
      'date',
      $mappings['properties']['lastModified']['type']);
  }

}

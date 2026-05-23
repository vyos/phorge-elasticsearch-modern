<?php

/**
 * Placeholder for the engine class. Keep this abstract until E7/E8 land so
 * it is not auto-discovered as a selectable backend before it can operate.
 */
abstract class VyOSElasticModernFulltextStorageEngine
  extends PhabricatorFulltextStorageEngine {

  private $version = null;
  private $index;

  public function setService(PhabricatorSearchService $service) {
    $this->service = $service;  // inherited protected property
    $config = $service->getConfig();
    $index = idx($config, 'path', '/phabricator');
    $normalized = trim($index, '/');
    if ($normalized === '') {
      throw new Exception(
        pht(
          'Invalid index path "%s" in cluster.search config: '.
          'path must contain at least one non-slash character.',
          $index));
    }
    $this->index = $normalized;
    $this->setVersion(idx($config, 'version', 7));
    return $this;
  }

  public function getTimestampField() {
    return 'lastModified';
  }

  public function getTextFieldType() {
    return 'text';
  }

  public function getHostForRead() {
    return $this->getService()->getAnyHostForRole('read');
  }

  public function getHostForWrite() {
    return $this->getService()->getAnyHostForRole('write');
  }

  public function getTypeConstants($class) {
    $relationship_class = new ReflectionClass($class);
    $typeconstants = $relationship_class->getConstants();
    return array_unique(array_values($typeconstants));
  }

  public function setVersion($version) {
    $version = (int)$version;
    if ($version < 7) {
      throw new Exception(
        pht(
          'Unsupported Elasticsearch version "%d" for the '.
          '"elasticsearch-modern" engine. This engine supports version 7 '.
          'and above (Elasticsearch 7.x, 8.x, or OpenSearch 1.x/2.x/3.x). '.
          'For ES 5.x, use the bundled "elasticsearch" engine instead.',
          $version));
    }
    $this->version = $version;
    return $this;
  }

  public function getVersion() {
    if ($this->version === null) {
      throw new Exception(
        pht('Version not configured; call setVersion() or setService() first.'));
    }
    return $this->version;
  }

  public function getDocumentUri($type, $phid) {
    return '/_doc/'.$phid;
  }

  public function getSearchUri(array $types) {
    return '/_search';
  }

  public function buildTypeFilter(array $types) {
    return array(
      'terms' => array(
        'documentType' => array_values($types),
      ),
    );
  }

  public function buildIndexMappings(
    array $doc_types, array $fields, array $relationships, $text_type) {

    // These are emitted as fixed standard fields at the end of the mapping.
    // Caller-supplied $fields or $relationships must not shadow them.
    static $reserved = array('documentType', 'dateCreated', 'lastModified');

    $rel_ts_keys = array_map(function($r) { return $r.'_ts'; }, $relationships);
    $all_caller_keys = array_merge($fields, $relationships, $rel_ts_keys);

    // Check for caller-supplied names that shadow reserved fields.
    $collisions = array_intersect($all_caller_keys, $reserved);
    if ($collisions) {
      throw new Exception(
        pht(
          'buildIndexMappings(): caller-supplied field(s) "%s" collide with '.
          'reserved mapping keys.',
          implode('", "', array_values($collisions))));
    }

    // Check for duplicates within caller-supplied keys themselves
    // (e.g. a field named "foo_ts" that would clash with relationship "foo"'s
    // implicit timestamp slot).
    $counts = array_count_values($all_caller_keys);
    $duplicates = array_keys(array_filter($counts, function($c) { return $c > 1; }));
    if ($duplicates) {
      throw new Exception(
        pht(
          'buildIndexMappings(): caller-supplied key(s) "%s" appear more '.
          'than once (check for field/relationship/timestamp-slot collisions).',
          implode('", "', $duplicates)));
    }

    $properties = array();

    foreach ($fields as $field) {
      $properties[$field] = array(
        'type'   => $text_type,
        'fields' => array(
          'raw' => array(
            'type'                  => $text_type,
            'analyzer'              => 'english_exact',
            'search_analyzer'       => 'english',
            'search_quote_analyzer' => 'english_exact',
          ),
          'keywords' => array(
            'type'     => $text_type,
            'analyzer' => 'letter_stop',
          ),
          'stems' => array(
            'type'     => $text_type,
            'analyzer' => 'english_stem',
          ),
        ),
      );
    }

    foreach ($relationships as $rel) {
      $properties[$rel] = array(
        'type'       => 'keyword',
        'doc_values' => false,
      );
      $properties[$rel.'_ts'] = array(
        'type' => 'date',
      );
    }

    $properties['documentType'] = array('type' => 'keyword');
    $properties['dateCreated']  = array('type' => 'date');
    $properties['lastModified'] = array('type' => 'date');

    // The $doc_types parameter is part of the signature for symmetry
    // with the bundled engine's per-type loop, but the typeless API
    // emits one mapping shared across all doc types.
    return array('properties' => $properties);
  }

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

<?php

/**
 * Placeholder for the engine class. Keep this abstract until E7/E8 land so
 * it is not auto-discovered as a selectable backend before it can operate.
 */
abstract class VyOSElasticModernFulltextStorageEngine
  extends PhabricatorFulltextStorageEngine {

  private $version = null;
  private $index;
  private $timeout = 15;

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

  public function setTimeout($timeout) {
    $this->timeout = $timeout;
    return $this;
  }

  public function getTimeout() {
    return $this->timeout;
  }

  public function reindexAbstractDocument(
    PhabricatorSearchAbstractDocument $doc) {

    $host = $this->getHostForWrite();

    $type = $doc->getDocumentType();
    $phid = $doc->getPHID();

    // The handle query mirrors the bundled engine's pattern; it
    // populates the handle cache so subsequent field-data lookups
    // don't issue redundant queries.
    $handle = id(new PhabricatorHandleQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($phid))
      ->executeOne();

    $spec = array(
      'title'         => $doc->getDocumentTitle(),
      'dateCreated'   => $doc->getDocumentCreated(),
      'lastModified'  => $doc->getDocumentModified(),
      'documentType'  => $type,
    );

    foreach ($doc->getFieldData() as $field) {
      list($field_name, $corpus, $aux) = $field;
      if (!isset($spec[$field_name])) {
        $spec[$field_name] = array($corpus);
      } else {
        $spec[$field_name][] = $corpus;
      }
      if ($aux !== null) {
        $spec[$field_name][] = $aux;
      }
    }

    foreach ($doc->getRelationshipData() as $field) {
      list($field_name, $related_phid, $rtype, $time) = $field;
      if (!isset($spec[$field_name])) {
        $spec[$field_name] = array($related_phid);
      } else {
        $spec[$field_name][] = $related_phid;
      }
      if ($time) {
        $spec[$field_name.'_ts'] = $time;
      }
    }

    $this->executeRequest(
      $host,
      $this->getDocumentUri($type, $phid),
      $spec,
      'PUT');
  }

  private function executeRequest(
    VyOSElasticModernHost $host, $path, array $data, $method = 'GET') {

    $uri = $host->getURI($path);
    $data = phutil_json_encode($data);
    $future = new HTTPSFuture($uri, $data);
    $future->addHeader('Content-Type', 'application/json');

    if ($method !== 'GET') {
      $future->setMethod($method);
    }
    if ($this->getTimeout()) {
      $future->setTimeout($this->getTimeout());
    }

    try {
      list($body) = $future->resolvex();
    } catch (HTTPFutureResponseStatus $ex) {
      if ($ex->isTimeout() || (int)$ex->getStatusCode() > 499) {
        $host->didHealthCheck(false);
      }
      throw $ex;
    }

    // HTTP request succeeded — mark the host as healthy regardless of whether
    // the response body is valid JSON. JSON parse failure is an application
    // error, not a host-connectivity failure.
    $host->didHealthCheck(true);

    if ($method !== 'GET') {
      return null;
    }

    try {
      return phutil_json_decode($body);
    } catch (PhutilJSONParserException $ex) {
      throw new Exception(
        pht('Elasticsearch server returned invalid JSON.'),
        0,
        $ex);
    }
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

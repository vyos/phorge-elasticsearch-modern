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
    $types = $query->getParameter('types');
    if (!$types) {
      $types = array_keys(
        PhabricatorSearchApplicationSearchEngine::getIndexableDocumentTypes());
    }

    $uri = $this->getSearchUri($types);
    $spec = $this->buildSpec($query, $types);

    $exceptions = array();
    foreach ($this->service->getAllHostsForRole('read') as $host) {
      try {
        $response = $this->executeRequest($host, $uri, $spec);
        $phids = ipull($response['hits']['hits'], '_id');
        return $phids;
      } catch (Exception $e) {
        // Catches HTTPFutureResponseStatus and other network/HTTP exceptions
        // raised by executeRequest(). PHP Error subclasses (TypeError, etc.)
        // are intentionally NOT caught -- they indicate programming bugs that
        // should propagate immediately, not get aggregated into the per-host
        // failover.
        $exceptions[] = $e;
      }
    }
    throw new PhutilAggregateException(
      pht('All Fulltext Search hosts failed:'),
      $exceptions);
  }

  private function buildSpec(PhabricatorSavedQuery $query, array $types) {
    $q = new PhabricatorElasticsearchQueryBuilder();
    $query_string = $query->getParameter('query');
    if ($query_string !== null && $query_string !== '') {
      $q->addMustClause(array(
        'simple_query_string' => array(
          'query'  => $query_string,
          'fields' => array(
            PhabricatorSearchDocumentFieldType::FIELD_TITLE.'.*',
            PhabricatorSearchDocumentFieldType::FIELD_BODY.'.*',
            PhabricatorSearchDocumentFieldType::FIELD_COMMENT.'.*',
          ),
          'default_operator' => 'AND',
        ),
      ));
      $q->addShouldClause(array(
        'simple_query_string' => array(
          'query'  => $query_string,
          'fields' => array(
            '*.raw',
            PhabricatorSearchDocumentFieldType::FIELD_TITLE.'^4',
            PhabricatorSearchDocumentFieldType::FIELD_BODY.'^3',
            PhabricatorSearchDocumentFieldType::FIELD_COMMENT.'^1.2',
          ),
          'analyzer'         => 'english_exact',
          'default_operator' => 'and',
        ),
      ));
    }

    $exclude = $query->getParameter('exclude');
    if ($exclude) {
      // Correct from day one: bool.must_not, not the obsolete 'not' clause.
      // Cast to array so a single PHID scalar and an already-array of PHIDs
      // both produce a flat list for the Elasticsearch ids.values field.
      $q->addMustNotClause(array(
        'ids' => array(
          'values' => array_values((array)$exclude),
        ),
      ));
    }

    $relationship_map = array(
      PhabricatorSearchRelationship::RELATIONSHIP_AUTHOR =>
        $query->getParameter('authorPHIDs', array()),
      PhabricatorSearchRelationship::RELATIONSHIP_SUBSCRIBER =>
        $query->getParameter('subscriberPHIDs', array()),
      PhabricatorSearchRelationship::RELATIONSHIP_PROJECT =>
        $query->getParameter('projectPHIDs', array()),
      PhabricatorSearchRelationship::RELATIONSHIP_REPOSITORY =>
        $query->getParameter('repositoryPHIDs', array()),
    );

    $statuses = $query->getParameter('statuses', array());
    $statuses = array_fuse($statuses);
    $rel_open    = PhabricatorSearchRelationship::RELATIONSHIP_OPEN;
    $rel_closed  = PhabricatorSearchRelationship::RELATIONSHIP_CLOSED;
    $rel_unowned = PhabricatorSearchRelationship::RELATIONSHIP_UNOWNED;
    $include_open   = !empty($statuses[$rel_open]);
    $include_closed = !empty($statuses[$rel_closed]);
    if ($include_open && !$include_closed) {
      $q->addExistsClause($rel_open);
    } else if (!$include_open && $include_closed) {
      $q->addExistsClause($rel_closed);
    }

    if ($query->getParameter('withUnowned')) {
      $q->addExistsClause($rel_unowned);
    }

    $rel_owner = PhabricatorSearchRelationship::RELATIONSHIP_OWNER;
    if ($query->getParameter('withAnyOwner')) {
      $q->addExistsClause($rel_owner);
    } else {
      $owner_phids = $query->getParameter('ownerPHIDs', array());
      if (count($owner_phids)) {
        $q->addTermsClause($rel_owner, $owner_phids);
      }
    }

    foreach ($relationship_map as $field => $phids) {
      if (is_array($phids) && !empty($phids)) {
        $q->addTermsClause($field, $phids);
      }
    }

    // Body-level type filter (typeless API has no per-type URL segment).
    $q->addFilterClause($this->buildTypeFilter($types));

    if (!$q->getClauseCount('must')) {
      $q->addMustClause(array('match_all' => array('boost' => 1)));
    }

    $spec = array(
      '_source' => false,
      'query'   => array(
        'bool' => $q->toArray(),
      ),
    );

    if (!$query->getParameter('query')) {
      $spec['sort'] = array(
        array('dateCreated' => 'desc'),
      );
    }

    $offset = (int)$query->getParameter('offset', 0);
    $limit  = (int)$query->getParameter('limit', 101);
    if ($offset + $limit > 10000) {
      throw new Exception(pht(
        'Query offset is too large. offset+limit=%s (max=%s)',
        $offset + $limit, 10000));
    }
    $spec['from'] = $offset;
    $spec['size'] = $limit;

    return $spec;
  }

  public function indexExists(?VyOSElasticModernHost $host = null) {
    if (!$host) {
      $host = $this->getHostForRead();
    }
    try {
      $res = $this->executeRequest($host, '/_stats/', array());
      return isset($res['indices'][$this->index]);
    } catch (HTTPFutureResponseStatus $e) {
      if ($e->getStatusCode() == 404) {
        return false;
      }
      throw $e;
    }
  }

  public function indexIsSane(?VyOSElasticModernHost $host = null) {
    if (!$host) {
      $host = $this->getHostForRead();
    }
    if (!$this->indexExists($host)) {
      return false;
    }
    $cur_mapping  = $this->executeRequest($host, '/_mapping/', array());
    $cur_settings = $this->executeRequest($host, '/_settings/', array());
    $actual = array_merge(
      $cur_settings[$this->index],
      $cur_mapping[$this->index]);

    return $this->check($actual, $this->getIndexConfiguration());
  }

  public function initIndex() {
    $host = $this->getHostForWrite();
    if ($this->indexExists($host)) {
      $this->executeRequest($host, '/', array(), 'DELETE');
    }
    $data = $this->getIndexConfiguration();
    $this->executeRequest($host, '/', $data, 'PUT');
  }

  public function getIndexStats(?VyOSElasticModernHost $host = null) {
    if (!$host) {
      $host = $this->getHostForRead();
    }
    $res = $this->executeRequest($host, '/_stats/', array());
    if (!isset($res['indices'][$this->index])) {
      throw new Exception(
        pht(
          'Index "%s" not found in Elasticsearch _stats response.',
          $this->index));
    }
    $stats = $res['indices'][$this->index];
    return array(
      pht('Queries') =>
        idxv($stats, array('primaries', 'search', 'query_total')),
      pht('Documents') =>
        idxv($stats, array('total', 'docs', 'count')),
      pht('Deleted') =>
        idxv($stats, array('total', 'docs', 'deleted')),
      pht('Storage Used') =>
        phutil_format_bytes(
          idxv($stats, array('total', 'store', 'size_in_bytes'))),
    );
  }

  private function getIndexConfiguration() {
    $data = array();
    $data['settings'] = array(
      'index' => array(
        'auto_expand_replicas' => '0-2',
        'analysis' => array(
          'filter' => array(
            'english_stop' => array(
              'type'      => 'stop',
              'stopwords' => '_english_',
            ),
            'english_stemmer' => array(
              'type'     => 'stemmer',
              'language' => 'english',
            ),
            'english_possessive_stemmer' => array(
              'type'     => 'stemmer',
              'language' => 'possessive_english',
            ),
          ),
          'analyzer' => array(
            'english_exact' => array(
              'tokenizer' => 'standard',
              'filter'    => array('lowercase'),
            ),
            'letter_stop' => array(
              'tokenizer' => 'letter',
              'filter'    => array('lowercase', 'english_stop'),
            ),
            'english_stem' => array(
              'tokenizer' => 'standard',
              'filter'    => array(
                'english_possessive_stemmer',
                'lowercase',
                'english_stop',
                'english_stemmer',
              ),
            ),
          ),
        ),
      ),
    );

    $fields = $this->getTypeConstants('PhabricatorSearchDocumentFieldType');
    $relationships = $this->getTypeConstants('PhabricatorSearchRelationship');
    $doc_types = array_keys(
      PhabricatorSearchApplicationSearchEngine::getIndexableDocumentTypes());
    $text_type = $this->getTextFieldType();

    $data['mappings'] = $this->buildIndexMappings(
      $doc_types, $fields, $relationships, $text_type);

    return $data;
  }

  private function check($actual, $required, $path = '') {
    foreach ($required as $key => $value) {
      if (!array_key_exists($key, $actual)) {
        return false;
      }
      if (is_array($value)) {
        if (!is_array($actual[$key])) {
          return false;
        }
        if (!$this->check($actual[$key], $value, $path.'.'.$key)) {
          return false;
        }
        continue;
      }
      $actual[$key] = self::normalizeConfigValue($actual[$key]);
      $value = self::normalizeConfigValue($value);
      if ($actual[$key] != $value) {
        return false;
      }
    }
    return true;
  }

  private static function normalizeConfigValue($value) {
    if ($value === true) {
      return 'true';
    }
    if ($value === false) {
      return 'false';
    }
    return $value;
  }

}

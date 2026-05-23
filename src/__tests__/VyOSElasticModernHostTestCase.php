<?php

final class VyOSElasticModernHostTestEngine
  extends VyOSElasticModernFulltextStorageEngine {
}

final class VyOSElasticModernHostTestCase
  extends PhutilTestCase {

  public function testEngineIsConcreteForDispatch() {
    // Must NOT be abstract: PhutilClassMapQuery → PhutilSymbolLoader::loadObjects()
    // calls setConcreteOnly(true) which unsets abstract classes. A concrete (plain)
    // class is required for cluster.search[].type:elasticsearch-modern dispatch.
    $class = new ReflectionClass('VyOSElasticModernFulltextStorageEngine');
    $this->assertFalse($class->isAbstract());
  }

  public function testDisplayName() {
    $engine = new VyOSElasticModernHostTestEngine();
    $host = new VyOSElasticModernHost($engine);
    $this->assertEqual(
      'Elasticsearch (modern)',
      (string)$host->getDisplayName());
  }

  public function testConfigDefaults() {
    $engine = new VyOSElasticModernHostTestEngine();
    $host = new VyOSElasticModernHost($engine);
    $host->setConfig(array());
    $this->assertEqual('http', $host->getProtocol());
    $this->assertEqual('phabricator/', $host->getPath());
    $this->assertEqual(7, $host->getVersion());
  }

  public function testConfigOverrides() {
    $engine = new VyOSElasticModernHostTestEngine();
    $host = new VyOSElasticModernHost($engine);
    $host->setConfig(array(
      'host'     => 'es.example.com',
      'port'     => 9200,
      'protocol' => 'https',
      'path'     => '/myphorge',
      'version'  => 8,
    ));
    $this->assertEqual('es.example.com', $host->getHost());
    $this->assertEqual(9200, $host->getPort());
    $this->assertEqual('https', $host->getProtocol());
    $this->assertEqual('/myphorge', $host->getPath());
    $this->assertEqual(8, $host->getVersion());
  }

  public function testGetURI() {
    $engine = new VyOSElasticModernHostTestEngine();
    $host = new VyOSElasticModernHost($engine);
    $host->setConfig(array(
      'host'     => 'es.example.com',
      'port'     => 9200,
      'protocol' => 'http',
      'path'     => '/phabricator',
    ));
    $uri = (string)$host->getURI('/_doc/abc');
    $this->assertTrue(strpos($uri, 'es.example.com') !== false);
    $this->assertTrue(strpos($uri, '/_doc/abc') !== false);
  }

}
